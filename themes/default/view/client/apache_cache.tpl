<div class="info">{TR_INTRO}</div>

<!-- BDP: no_domains_block -->
<div class="static_info">{NO_DOMAINS}</div>
<!-- EDP: no_domains_block -->

<!-- BDP: domain_list -->
<table class="firstColFixed datatable">
    <thead>
    <tr>
        <th>{TR_STATUS}</th>
        <th>{TR_DOMAIN_NAME}</th>
        <th>{TR_DOMAIN_KIND}</th>
        <th>{TR_WORDPRESS_MODE}</th>
        <th>{TR_NOTE}</th>
        <th>{TR_ACTION}</th>
    </tr>
    </thead>
    <tbody>
    <!-- BDP: domain_item -->
    <tr>
        <td><div class="icon i_{STATUS_ICON}">{STATUS}</div></td>
        <td>{DOMAIN_NAME}</td>
        <td>{DOMAIN_KIND}</td>
        <td>{WORDPRESS_MODE}</td>
        <td>{NOTE}</td>
        <td>
            <!-- BDP: domain_actions -->
            <a class="icon i_edit" href="{EDIT_LINK}" title="{TR_EDIT}">{TR_EDIT}</a>
            <a class="icon i_{TOGGLE_ICON}" href="{TOGGLE_LINK}" title="{TOGGLE_LABEL}">{TOGGLE_LABEL}</a>
            <!-- BDP: purge_action -->
            <a class="icon i_delete" href="{PURGE_LINK}" title="{TR_PURGE}"
               onclick="return confirm('{TR_PURGE_CONFIRM}');">{TR_PURGE}</a>
            <!-- EDP: purge_action -->
            <!-- EDP: domain_actions -->
            <!-- BDP: domain_busy -->
            <span class="icon i_reload">{TR_BUSY}</span>
            <!-- EDP: domain_busy -->
        </td>
    </tr>
    <!-- EDP: domain_item -->
    </tbody>
</table>
<!-- EDP: domain_list -->
