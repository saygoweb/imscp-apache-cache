<div class="info">{TR_INTRO}</div>

<form name="apache_cache_reseller" id="apache_cache_reseller" method="post"
      action="apache_cache.php" onsubmit="return prepareFormSubmit(this);">

    <!-- BDP: no_domains_block -->
    <div class="static_info">{NO_DOMAINS}</div>
    <!-- EDP: no_domains_block -->

    <!-- BDP: domain_list -->
    <table class="firstColFixed datatable">
        <thead>
        <tr>
            <th><input type="checkbox" id="apache_cache_all" title="{TR_SELECT_ALL}"></th>
            <th>{TR_CUSTOMER}</th>
            <th>{TR_DOMAIN}</th>
            <th>{TR_DOMAIN_KIND}</th>
            <th>{TR_ALLOWED}</th>
            <th>{TR_ENABLED}</th>
            <th>{TR_STATE}</th>
            <th>{TR_ACTION}</th>
        </tr>
        </thead>
        <tbody>
        <!-- BDP: domain_item -->
        <tr>
            <td>{BULK_CHECKBOX}</td>
            <td>{CUSTOMER_NAME}</td>
            <td>{DOMAIN_NAME}</td>
            <td>{DOMAIN_KIND}</td>
            <td><div class="icon i_{ALLOWED_ICON}">{ALLOWED}</div></td>
            <td><div class="icon i_{ENABLED_ICON}">{ENABLED}</div></td>
            <td><div class="icon i_{STATE_ICON}">{STATE}</div></td>
            <td>{ACTION_SELECT}</td>
        </tr>
        <!-- EDP: domain_item -->
        </tbody>
    </table>

    <div class="buttons">
        <label for="bulk_action">{TR_BULK_ACTION}</label>
        <select id="bulk_action">
            <option value=""></option>
            <option value="allow">{TR_ALLOW}</option>
            <option value="enable">{TR_ENABLE}</option>
            <option value="disable">{TR_DISABLE}</option>
            <option value="withdraw">{TR_WITHDRAW}</option>
        </select>
        <input type="button" value="{TR_BULK_APPLY}" onclick="return applyBulkAction(this.form);">
    </div>

    <div class="buttons">
        <input name="submit" type="submit" value="{TR_UPDATE}">
    </div>
    <!-- EDP: domain_list -->
</form>

<script>
function getRow(node) {
    while (node && node.tagName !== 'TR') {
        node = node.parentNode;
    }

    return node;
}

function applyBulkAction(form) {
    var bulkSelect = document.getElementById('bulk_action');
    var action = bulkSelect ? bulkSelect.value : '';

    if (!action) {
        return false;
    }

    var rows = form.querySelectorAll('input.apache_cache_pick:checked');

    for (var i = 0; i < rows.length; i++) {
        var checkbox = rows[i];
        var row = getRow(checkbox);
        var select = row ? row.querySelector('select[name^="action["]') : null;

        if (!select || select.disabled) {
            continue;
        }

        var supported = false;
        for (var j = 0; j < select.options.length; j++) {
            if (select.options[j].value === action) {
                supported = true;
                break;
            }
        }

        if (supported) {
            select.value = action;
        }
    }

    return false;
}

function prepareFormSubmit(form) {
    var selects = form.querySelectorAll('select[name^="action["]');
    var hasWithdraw = false;
    for (var i = 0; i < selects.length; i++) {
        if (!selects[i].disabled && selects[i].value === 'withdraw') {
            hasWithdraw = true;
            break;
        }
    }

    if (hasWithdraw) {
        if (!confirm('{TR_WITHDRAW_CONFIRM}')) {
            return false;
        }
    }

    // Disable empty selects so they are not posted
    for (var i = 0; i < selects.length; i++) {
        if (selects[i].value === '') {
            selects[i].disabled = true;
        }
    }

    return true;
}

var selectAll = document.getElementById('apache_cache_all');
if (selectAll) {
    selectAll.addEventListener('change', function () {
        var checkboxes = document.querySelectorAll('input.apache_cache_pick:not(:disabled)');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = this.checked;
        }
    });
}
</script>
