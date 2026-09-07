<div class="info">{TR_INTRO}</div>

<form name="apache_cache_reseller" id="apache_cache_reseller" method="post"
      action="apache_cache.php" onsubmit="return confirmWithdraw(this);">

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
            <td><div class="icon i_{ALLOWED_ICON}">{ALLOWED}</div></td>
            <td><div class="icon i_{ENABLED_ICON}">{ENABLED}</div></td>
            <td><div class="icon i_{STATE_ICON}">{STATE}</div></td>
            <td>{ACTION_SELECT}</td>
        </tr>
        <!-- EDP: domain_item -->
        </tbody>
    </table>
    <!-- EDP: domain_list -->

    <div class="buttons">
        <label for="bulk_action">{TR_BULK_ACTION}</label>
        <select name="bulk_action" id="bulk_action">
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
</form>

<script>
function getRow(node) {
    while (node && node.tagName !== 'TR') {
        node = node.parentNode;
    }

    return node;
}

function applyBulkAction(form) {
    var action = form.elements.bulk_action.value;

    if (!action) {
        return false;
    }

    var rows = form.querySelectorAll('input[type="checkbox"][name^="bulk["]:checked');

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

function confirmWithdraw(form) {
    var selects = form.querySelectorAll('select[name^="action["]');

    for (var i = 0; i < selects.length; i++) {
        if (!selects[i].disabled && selects[i].value === 'withdraw') {
            return confirm('{TR_WITHDRAW_CONFIRM}');
        }
    }

    return true;
}

var selectAll = document.getElementById('apache_cache_all');
if (selectAll) {
    selectAll.addEventListener('change', function () {
        var checkboxes = document.querySelectorAll('input[type="checkbox"][name^="bulk["]:not(:disabled)');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = this.checked;
        }
    });
}
</script>
