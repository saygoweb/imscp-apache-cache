# Apache Cache reseller bulk actions design

## Goal

Change the reseller Apache Cache panel from a customer-row action list into a
site-row bulk-action screen. Resellers must be able to tick multiple sites and
run bulk **Enable**, **Disable**, or **Withdraw** actions.

The new screen should follow the same multiple-select concept as the PHP
version reseller panel, while preserving Apache Cache's existing state machine
and customer-permission rules.

## Requested behavior

- Show one row per reseller-controlled site/vhost, not one row per customer.
- Allow selecting multiple rows with per-row checkboxes plus a select-all
  control.
- Provide a bulk-action control below the table with:
  - **Enable**: enable Apache Cache only on the selected sites.
  - **Disable**: disable Apache Cache only on the selected sites.
  - **Withdraw**: treat the selected rows as a customer selection, deduplicate
    by customer, withdraw Apache Cache for each affected customer, and disable
    the cache on all of that customer's domains.
- Keep unsettled rows visible but not selectable, so the reseller cannot stack
  a second change on top of a queued or in-progress backend action.

## Existing constraints to preserve

### Authoritative backend state

Apache Cache changes must continue to flow through the existing queued status
values (`toenable`, `todisable`, and related states), rather than bypassing the
backend and writing Apache configuration directly.

### Domain model

The plugin's domain model is keyed by `(domain_type, domain_id)` across `dmn`,
`sub`, `als`, and `alssub`. The reseller view must continue to operate across
that full set of site types.

### Customer permission semantics

Permission to use Apache Cache is still customer-wide. The reseller UI may list
individual sites, but **Withdraw** remains a customer-level operation and must
disable the cache across all domains for affected customers.

## Proposed UI

### Table layout

The reseller page becomes a form-based table like the PHP version reseller
screen. Each row contains:

- a checkbox used for bulk selection
- customer name
- site name
- site type
- whether the customer is allowed to use Apache Cache
- the site's current cache status

Busy rows render as disabled controls so they remain visible but cannot be
selected.

### Bulk action controls

Below the table, render:

- a bulk-action `<select>`
- a button that applies the chosen action to the ticked rows
- a normal submit button

The client-side helper script follows the PHP version pattern:

- the select-all checkbox toggles all enabled row checkboxes
- the bulk-action button copies the chosen action into the selected rows' hidden
  inputs so the reseller can see exactly what will be submitted before pressing
  Apply

For Apache Cache, each selected row needs only an action rather than a freeform
value like a PHP version, so the hidden input can store one of:

- `enable`
- `disable`
- `withdraw`

The final submitted payload therefore expresses the reseller's visible choices
per selected row.

### Confirmation behavior

Only **Withdraw** is destructive beyond the selected row set, so it should keep
an explicit confirmation that explains it will withdraw the feature for the
affected customers and disable the cache on all of their domains.

## Proposed server-side flow

### Data source

Add a reseller-domain query that returns every vhost controlled by the reseller,
including:

- customer ID
- customer name
- domain type
- domain ID
- domain name
- permission state from `apache_cache_perm`
- cache row information from `apache_cache`

This mirrors the customer-wide query that exists today, but expands it into the
per-site shape required by the new UI.

### Request shape

Convert the reseller page from link-triggered GET actions to a POST form. The
form submission carries:

- the reseller-selected site keys
- the action chosen for each selected site
- the submit marker

As with the PHP version page, submitted keys must be validated against the
current reseller-visible domain list rather than trusting posted identifiers.

### Applying Enable and Disable

For each selected site:

1. Validate that the row belongs to one of the reseller's customers.
2. Ignore rows that are no longer present in the reseller-visible set.
3. Reject **Enable** for rows whose customer is not allowed to use Apache
   Cache.
4. Skip unsettled rows and count them for warning output.
5. Create the cache row on first use via the existing row-creation helper.
6. Set `enabled` and queue `toenable` or `todisable` for that specific site.

If at least one site changes, call `send_request()` once after the loop.

### Applying Withdraw

For selected rows marked **Withdraw**:

1. Map rows to customer IDs.
2. Deduplicate the customer IDs.
3. Revoke permission once per customer in `apache_cache_perm`.
4. Reuse the existing customer-wide disable behavior so each affected
   customer's domains are disabled through the normal queue states.

This keeps the existing semantics intact: a customer without the feature should
not retain active Apache Cache configuration on any of their sites.

## Messages and partial results

The reseller should get specific feedback rather than a single generic success:

- how many selected sites were queued for enable
- how many selected sites were queued for disable
- how many customers were withdrawn
- how many rows were skipped because work was already in progress
- when nothing was selectable or no effective change was requested

Mixed results are expected for large selections, so success and warning
messages may appear together.

## Error handling

- If the submission includes keys outside the reseller's own visible site list,
  treat it as a bad request.
- If an **Enable** action is submitted for a customer whose permission is not
  granted, report it as an error rather than silently enabling the site.
- If no selectable rows are chosen, show an informational "Nothing to change"
  style message.
- If the same customer appears in multiple withdrawn rows, withdraw only once
  for that customer.

## Implementation boundaries

### Frontend/reseller page

Responsible for:

- rendering the site-row table
- rendering bulk controls
- validating and applying submitted reseller actions
- translating submitted site keys back to validated reseller-owned rows

### Shared frontend helpers

Responsible for:

- domain-list queries and row creation helpers already shared by the plugin
- any new helper needed to list reseller-visible domains in the same unioned
  `(domain_type, domain_id)` model

### Backend

No behavior change is required in the backend engine. The reseller page should
continue to express work only by updating rows and queue statuses that the
backend already understands.

## Testing plan

### Repo-local validation

- package the plugin with `make.phar package`
- run existing repo checks that are relevant to the changed behavior

### Vagrant integration validation

Using the sibling `../imscp/Vagrant` environment:

1. bring up the Debian 13 i-MSCP box
2. deploy the modified plugin with the existing deployment helper
3. log into the reseller panel and verify mixed selections across multiple
   customers
4. verify **Enable** changes only the selected sites
5. verify **Disable** changes only the selected sites
6. verify **Withdraw** revokes permission once per affected customer and
   disables all of that customer's domains
7. verify busy rows remain visible but cannot be selected
8. verify not-allowed rows cannot be enabled successfully

## Out of scope

- changing the backend queue model
- adding new Apache Cache settings
- changing the customer-facing Apache Cache pages
- introducing new packaged runtime dependencies
