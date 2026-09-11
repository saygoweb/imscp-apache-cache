# Apache Cache Reseller Bulk Actions Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Change the reseller Apache Cache page to a site-row bulk-action form so resellers can select multiple sites and apply Allow, Enable, Disable, or Withdraw safely.

**Architecture:** Rework the reseller page to mirror the PHP version plugin's multi-select flow: shared helpers provide reseller-visible domain rows and stable row keys, the reseller controller validates posted per-row actions and translates them into existing `apache_cache` queue states, and the reseller template becomes a form with per-row selects plus a bulk copier. Keep the current backend state machine authoritative, and treat customer-wide actions (`Allow`, `Withdraw`) separately from site-wide actions (`Enable`, `Disable`) during validation and application.

**Tech Stack:** PHP 7-style i-MSCP plugin code, Smarty-like i-MSCP templates, jQuery already used by the panel, existing shell/perl validation (`make.phar package`, `test/backend/all.t`, Vagrant smoke checks), GitHub CLI for push/PR, lowest-cost available agentic model at execution time for routine subagent work (if the model menu is unchanged, prefer `gemini-3.5-flash` before escalating).

---

## File structure map

- Modify: `frontend/common.php`
  - Add a reseller-domain query parallel to `getDomains()`.
  - Add a stable form key helper for `(domain_type, domain_id)`.
  - Add small reseller-page helpers for decoding posted keys and determining withdraw eligibility per customer if that keeps `frontend/reseller/apache_cache.php` readable.
- Modify: `frontend/reseller/apache_cache.php`
  - Replace GET link actions with POST form handling.
  - Validate submitted per-row actions against reseller-visible rows.
  - Apply site-level and customer-level actions through existing queue/status behavior.
  - Generate the new site-row table data and user-facing messages.
- Modify: `themes/default/view/reseller/apache_cache.tpl`
  - Replace the customer-row action list with a site-row form, per-row action select, select-all checkbox, and bulk action copier.
- No new packaged runtime files.
- Validation uses existing repo commands plus Vagrant panel testing on `../imscp/Vagrant`.

## Chunk 1: Reseller bulk-actions implementation

### Task 1: Add reseller domain helpers and stable row keys

**Files:**
- Modify: `frontend/common.php`
- Reference: `../imscp-php-version/frontend/common.php`
- Spec: `docs/superpowers/specs/2026-09-07-apache-cache-reseller-bulk-actions-design.md`

- [ ] **Step 1: Write the failing helper shape first**

Prove the helpers do not exist yet:

```bash
rg -n "function (getResellerDomains|domainKey|splitDomainKey)" frontend/common.php
```

Expected: no matches.

Add the smallest helper surface in `frontend/common.php` that the reseller page will need:

```php
function getResellerDomains($resellerId) { /* query every vhost for this reseller */ }
function domainKey(array $domain) { return $domain['domain_type'] . '-' . $domain['domain_id']; }
function splitDomainKey($key) { /* validate and decode posted key */ }
```

Expected: `frontend/reseller/apache_cache.php` can be rewritten to stop depending on customer-row GET links.

- [ ] **Step 2: Follow the PHP version plugin query pattern, not a custom query shape**

Mirror the union style from `../imscp-php-version/frontend/common.php`, but join:
- `admin` for customer name
- `apache_cache_perm` for allowed state
- `apache_cache` for current cache row/status

Expected columns per row:
```php
admin_id, admin_name, allowed,
domain_type, domain_id, domain_name, domain_status,
apache_cache_id, enabled, status, state
```

- [ ] **Step 3: Keep the helper behavior narrow**

Do **not** move action application into `frontend/common.php`. Keep helpers limited to:
- reseller-visible row retrieval
- row key encode/decode
- any tiny derived-state helper that keeps the reseller controller readable

Expected: `frontend/common.php` remains shared query/utilities only.

- [ ] **Step 4: Run packaging as the first syntax/inclusion smoke test**

Run:
```bash
php make.phar package
```

Expected: command succeeds and produces `SGW_ApacheCache.tgz`.

- [ ] **Step 5: Commit the helper slice**

Run:
```bash
git add frontend/common.php
git commit -m "Add reseller domain helpers for Apache cache" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

Expected: one commit containing only helper-layer changes.

### Task 2: Replace reseller GET actions with validated POST handling

**Files:**
- Modify: `frontend/reseller/apache_cache.php`
- Reference: `../imscp-php-version/frontend/reseller/php_version.php`
- Reference: `frontend/common.php`
- Spec: `docs/superpowers/specs/2026-09-07-apache-cache-reseller-bulk-actions-design.md`

- [ ] **Step 1: Write the failing controller outline**

Prove the reseller page still uses the old GET flow:

```bash
rg -n "\\\$_GET\\['action'\\]|\\\$_GET\\['customer_id'\\]|function handleAction" frontend/reseller/apache_cache.php
```

Expected: matches show the current GET-driven path is still in place.

Replace `handleAction()` with a POST-oriented `handleSubmit()` structure:

```php
function handleSubmit($resellerId)
{
    if (!isset($_POST['submit'])) {
        return;
    }

    $wanted = isset($_POST['action']) && is_array($_POST['action'])
        ? $_POST['action'] : array();
}
```

Expected: the file no longer assumes `$_GET['customer_id']` + `$_GET['action']`.

- [ ] **Step 2: Validate the submitted keys before doing any work**

Build the current reseller-visible row map once:

```php
$domains = getResellerDomains($resellerId);
$visible = array();
foreach ($domains as $domain) {
    $visible[domainKey($domain)] = $domain;
}
```

Then reject the whole request when any submitted key:
- is not in `$visible`
- has an unknown action value
- creates a per-customer mixed-action conflict from the spec

Before validating, normalize the posted map by dropping any `''` values if the
browser submitted blank selects anyway.

Expected: malformed or tampered POSTs fail before any database update.

- [ ] **Step 3: Implement site-level action application**

For `enable` / `disable` rows only:
- reject `enable` for not-allowed customers with an error message
- skip rows that became unsettled after render
- detect and ignore no-op site actions before writing anything:
  - `enable` on an already enabled row
  - `disable` on an already disabled row
- call `getOrCreateRow()`
- write `enabled = 1` + `status = 'toenable'` or `enabled = 0` + `status = 'todisable'`

Keep counters for:
- enabled sites
- disabled sites
- busy skipped rows
- row-level errors

Expected: the backend still receives work exclusively through queue states.

- [ ] **Step 4: Implement customer-level action application**

For `allow` / `withdraw` rows:
- map posted rows to unique customer IDs
- `allow`: ignore customers already allowed, otherwise upsert `apache_cache_perm.allowed = 1`
- `withdraw`: skip the customer if any of their current rows is unsettled, otherwise upsert `allowed = 0` and reuse a helper that disables all settled domains for that customer
- `withdraw`: ignore customers already not allowed unless the selected flow is meant only to disable selected sites, which it is not in this spec

Keep separate counters for:
- allowed customers
- withdrawn customers
- customers skipped from withdraw because one domain is busy

Expected: customer-wide behavior is deduplicated and explicit.

- [ ] **Step 5: Keep messaging specific and multi-result friendly**

Emit separate page messages for:
- sites queued for enable
- sites queued for disable
- customers allowed
- customers withdrawn
- rows/customers skipped because work is already in progress
- row-level enable errors for not-allowed customers
- nothing-to-change cases

Expected: large mixed submissions report partial outcomes clearly.

- [ ] **Step 6: Trigger backend work once per successful submission**

Call `send_request()` once, after all loops, only if at least one site-level or withdraw-induced site disable was queued.

Expected: no duplicate backend wakeups.

- [ ] **Step 7: Re-run repo-local checks for the controller slice**

Run:
```bash
php make.phar package && cd test/backend && sudo perl all.t
```

Expected:
- packaging succeeds
- Perl backend config test passes

- [ ] **Step 8: Commit the controller slice**

Run:
```bash
git add frontend/reseller/apache_cache.php frontend/common.php
git commit -m "Rework reseller Apache cache actions to POST bulk flow" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

Expected: one commit for controller/query behavior.

### Task 3: Replace the reseller template with a site-row bulk form

**Files:**
- Modify: `themes/default/view/reseller/apache_cache.tpl`
- Modify: `frontend/reseller/apache_cache.php`
- Reference: `../imscp-php-version/themes/default/view/reseller/php_version.tpl`

- [ ] **Step 1: Change the template data contract before styling details**

Update `generatePage()` in `frontend/reseller/apache_cache.php` to assign per-row values needed by the new template:

```php
DOMAIN_KEY
CUSTOMER_NAME
DOMAIN_NAME
DOMAIN_KIND
ALLOWED
ALLOWED_ICON
STATUS
STATUS_ICON
ROW_DISABLED
ACTION_OPTIONS
```

Expected: the controller renders domain rows rather than customer rows.

Also update:
- the template dynamic blocks from `customer_list` / `customer_item` to domain-row equivalents
- the reseller `TR_*` strings so the page title, intro text, headers, bulk labels, and confirm text match the new workflow
- the empty-state block/message so it speaks about reseller-visible sites rather
  than customer rows

- [ ] **Step 2: Rewrite the template to the PHP version plugin shape**

Replace the customer action table with a form table containing:
- select-all checkbox column
- customer column
- domain column
- type column
- allowed column
- status column
- per-row action select column
- a bulk-action `<select>` below the table
- a bulk-apply button below the table
- the normal submit button below the table

Expected: the reseller sees one row per site, not one row per customer.

- [ ] **Step 3: Keep per-row action options aligned with the approved contract**

Render row action options as:
- allowed + settled: blank, Enable, Disable, Withdraw only when every domain for that customer is settled
- not-allowed + settled: blank, Allow
- unsettled: disabled checkbox + disabled select

Expected: the UI does not advertise actions that are impossible in normal flow.

- [ ] **Step 4: Add the bulk action copier**

Follow the PHP version script pattern:

```javascript
$("#apache_cache_all").on("change", function () {
    $(".apache_cache_pick:not(:disabled)").prop("checked", this.checked);
});

$("#apache_cache_bulk_apply").on("click", function () {
    var action = $("#apache_cache_bulk").val();

    $(".apache_cache_pick:checked").each(function () {
        $("select[data-key='" + $(this).val() + "'] option[value='" + action + "']").length &&
            $("select[data-key='" + $(this).val() + "']").val(action);
    });
});
```

Expected: bulk controls only copy valid options into checked rows.

- [ ] **Step 5: Add the withdraw confirmation to submit, not to copy**

Confirm only when the final submitted form includes at least one `withdraw` row action.

Expected: copying `withdraw` into row selects is reversible; the destructive confirmation happens only on Apply.

- [ ] **Step 6: Submit only changed row actions**

Before form submission, disable or strip blank per-row action selects so the
POST carries only non-blank `action[key]` entries, matching the spec. Keep the
server-side blank-value filter from Task 2 as a defensive backstop rather than
the primary mechanism.

Expected: normal form submits align with the approved request contract.

- [ ] **Step 7: Run repo-local checks again**

Run:
```bash
php make.phar package && cd test/backend && sudo perl all.t
```

Expected:
- package build succeeds
- backend Perl test still passes

- [ ] **Step 8: Commit the template slice**

Run:
```bash
git add themes/default/view/reseller/apache_cache.tpl frontend/reseller/apache_cache.php
git commit -m "Add reseller site bulk action form" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

Expected: one commit for reseller UI rendering/JS.

### Task 4: Validate on the Vagrant box and finish the branch

**Files:**
- Modify if needed after testing: `frontend/common.php`
- Modify if needed after testing: `frontend/reseller/apache_cache.php`
- Modify if needed after testing: `themes/default/view/reseller/apache_cache.tpl`
- Reference environment: `../imscp/Vagrant`

- [ ] **Step 1: Boot or reuse the Vagrant environment**

Run:
```bash
cd ../imscp/Vagrant && vagrant up imscp_debian_trixie --provider=libvirt
```

Expected: the Debian 13 test VM is running.

- [ ] **Step 2: Deploy the working tree into the box**

On a fresh VM, prepare the fixture site first:

```bash
sudo /usr/local/src/imscp-apache-cache/tools/setup-test-site.sh
```

Expected: the test WordPress site and Apache fixtures exist for the smoke tests.

Then, inside the VM or via your usual workflow, run:
```bash
sudo /usr/local/src/imscp-apache-cache/tools/deploy.sh
```

Expected: plugin files are copied into the panel plugin directory and `imscp_panel` is restarted.

- [ ] **Step 3: Re-run the existing live cache smoke tests before UI testing**

Run inside the repo in the VM:
```bash
./test/cache-matrix.sh
./test/logged-in-leak.sh
```

Expected:
- both scripts print `all checks passed`

- [ ] **Step 4: Perform reseller panel regression in the browser**

Verify these exact cases:
1. select two sites from one allowed customer and bulk-copy **Enable**, then Apply
2. select one of those sites and bulk-copy **Disable**, then Apply
3. select one site from a second allowed customer and bulk-copy **Withdraw**, then Apply
4. verify all domains for that withdrawn customer are disabled, not just the selected row
5. for a now-not-allowed customer, verify the row offers **Allow** but not **Enable**
6. select one row for that not-allowed customer, bulk-copy **Allow**, then Apply
7. verify the permission row flips back to allowed and a later single-row **Enable** succeeds
8. verify a busy row cannot be checked or changed
9. verify submitting one changed row for a not-allowed customer with a forged
   `enable` action produces the expected row-level error message and queues no
   site change
10. verify leaving every row on the blank action yields the expected
   `Nothing to change` informational message
11. verify a mixed submission that both changes one valid row and skips one
   newly busy row shows both success and warning messages

Expected:
- enable/disable affect only selected sites
- withdraw is customer-wide
- allow restores customer permission and unblocks a later enable
- forged enable on a not-allowed row is reported as an error, not applied
- blank submissions report nothing-to-change
- mixed valid+busy submissions show combined success/warning feedback
- the UI contract matches the spec

- [ ] **Step 5: Prove the whole-request rejection rules**

Capture the authenticated reseller session cookie from the browser once, then
run a concrete POST shape against the panel endpoint. Use a real visible row key
first, for example `dmn-12`, then alter one field per check:

```bash
curl -sS -i \
  -b 'PHPSESSID=<copied-session-cookie>' \
  -X POST \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'action[dmn-12]=enable&submit=Apply' \
  http://127.0.0.1/plugins/SGW_ApacheCache/frontend/reseller/apache_cache.php
```

Then verify these malformed variants:
1. a POST with an unknown `action[key]` value is rejected and applies nothing
2. a POST that mixes `withdraw` and `enable` for one customer is rejected and applies nothing
3. a POST with a forged key for another reseller/customer is rejected and applies nothing
4. a POST that mixes `allow` and `withdraw` for one customer is rejected and applies nothing
5. a POST that mixes `allow` and `enable` for one customer is rejected and applies nothing

Before each malformed request, record the current DB state for the targeted
customer and row, for example:

```sql
SELECT allowed FROM apache_cache_perm WHERE admin_id = <customer_id>;
SELECT enabled, status FROM apache_cache
WHERE domain_type = 'dmn' AND domain_id = 12;
```

After each malformed request, run the same queries again and confirm they are
unchanged.

Expected:
- the response shows the panel's bad-request or equivalent rejection outcome
- the request is rejected atomically
- no `apache_cache_perm` or `apache_cache` rows change for that submission
- rows or customers that merely became busy after page render are **not** part
  of this rejection class; they are covered by the browser regression flow and
  should be skipped with warnings instead

- [ ] **Step 6: If Vagrant testing exposes a bug, fix only that bug and repeat Step 3 + Step 5**

Run:
```bash
php make.phar package && cd test/backend && sudo perl all.t
```

Expected: repo-local checks still pass before re-deploying.

- [ ] **Step 7: Re-run both reseller browser scenarios and rejection probes after a bug fix**

Repeat:
- Step 4 browser regression flow
- Step 5 whole-request rejection checks

Expected: the original feature scenarios and the invalid-request guards both still hold after the fix.

- [ ] **Step 8: Package the final branch state**

Run:
```bash
php make.phar package
```

Expected: `SGW_ApacheCache.tgz` is produced from the final code.

- [ ] **Step 9: Create the final implementation commit if testing required follow-up fixes**

Run:
```bash
git add frontend/common.php frontend/reseller/apache_cache.php themes/default/view/reseller/apache_cache.tpl
git commit -m "Polish reseller Apache cache bulk actions" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

Expected: only use this step if Vagrant validation required extra fixes after the earlier commits.

- [ ] **Step 10: Push the branch**

Run:
```bash
git push -u origin feat/reseller-multiple
```

Expected: branch is available on GitHub.

- [ ] **Step 11: Open the pull request with GitHub CLI**

Run:
```bash
gh pr create \
  --base main \
  --head feat/reseller-multiple \
  --title "Add reseller bulk actions for Apache cache" \
  --body-file docs/superpowers/specs/2026-09-07-apache-cache-reseller-bulk-actions-design.md
```

Expected: GitHub returns the new PR URL.
