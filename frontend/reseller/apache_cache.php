<?php
/**
 * i-MSCP SGW_ApacheCache plugin
 * Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace SGW_ApacheCache;

use iMSCP\Event\EventAggregator;
use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_ApacheCache\SGW_ApacheCache;
use iMSCP\TemplateEngine;
use PDO;

require_once __DIR__ . '/../common.php';

/***********************************************************************************************************************
 * Functions
 */

/**
 * Turn the cache on or off across every vhost a customer owns.
 *
 * Rows are created for vhosts that have never been configured, so that a
 * customer who has never opened the page still gets the bulk change.
 *
 * @param int $customerId Customer unique identifier
 * @param bool $enable
 * @return int Number of vhosts affected
 */
function bulkSet($customerId, $enable)
{
    $count = 0;

    foreach (getDomains($customerId) as $domain) {
        // A vhost the backend is mid-way through is left alone rather than
        // having a second change stacked on top of it.
        if (!isSettled($domain['status'])) {
            continue;
        }

        $row = getOrCreateRow($domain, $customerId);

        exec_query(
            'UPDATE apache_cache SET enabled = ?, status = ? WHERE apache_cache_id = ?',
            array(
                $enable ? 1 : 0,
                $enable ? 'toenable' : 'todisable',
                $row['apache_cache_id']
            )
        );
        $count++;
    }

    return $count;
}

/**
 * Apply submitted bulk actions.
 *
 * @param int $resellerId Reseller unique identifier
 * @return void
 */
function handleSubmit($resellerId)
{
    if (!isset($_POST['submit'])) {
        return;
    }

    $wanted = isset($_POST['action']) && is_array($_POST['action'])
        ? $_POST['action'] : array();

    $normalized = array();
    foreach ($wanted as $key => $action) {
        if ($action === '') {
            continue;
        }

        $normalized[$key] = $action;
    }
    $wanted = $normalized;

    if (!$wanted) {
        set_page_message(tr('Nothing to change.'), 'info');
        redirectTo('apache_cache.php');
        return;
    }

    $domains = getResellerDomains($resellerId);
    $visible = array();
    foreach ($domains as $domain) {
        $visible[domainKey($domain)] = $domain;
    }

    $siteActions = array();
    $customerActions = array();
    $customerPermissions = array();

    foreach ($wanted as $key => $action) {
        if (!is_string($key) || !isset($visible[$key])) {
            showBadRequestErrorPage();
            return;
        }

        if (!is_string($action) || !in_array($action, array('allow', 'enable', 'disable', 'withdraw'), true)) {
            showBadRequestErrorPage();
            return;
        }

        $customerId = (int)$visible[$key]['admin_id'];
        $customerPermissions[$customerId] = (bool)$visible[$key]['allowed'];

        if (!isset($customerActions[$customerId])) {
            $customerActions[$customerId] = array(
                'allow'    => false,
                'withdraw' => false,
                'site'     => false
            );
        }

        switch ($action) {
            case 'allow':
                $customerActions[$customerId]['allow'] = true;
                break;

            case 'withdraw':
                $customerActions[$customerId]['withdraw'] = true;
                break;

            default:
                $customerActions[$customerId]['site'] = true;
                $siteActions[] = array(
                    'action' => $action,
                    'domain' => $visible[$key]
                );
        }
    }

    foreach ($customerActions as $actions) {
        if ($actions['site'] && ($actions['allow'] || $actions['withdraw'])) {
            showBadRequestErrorPage();
            return;
        }

        if ($actions['allow'] && $actions['withdraw']) {
            showBadRequestErrorPage();
            return;
        }
    }

    $enabledSites = 0;
    $disabledSites = 0;
    $allowedCustomers = 0;
    $withdrawnCustomers = 0;
    $disabledByWithdraw = 0;
    $busySites = 0;
    $busyCustomers = 0;
    $rowErrors = 0;
    $needsBackendRequest = false;

    foreach ($siteActions as $entry) {
        $domain = $entry['domain'];
        $action = $entry['action'];
        $customerId = (int)$domain['admin_id'];
        $enabled = !empty($domain['enabled']);

        if ($action === 'enable' && !$customerPermissions[$customerId]) {
            $rowErrors++;
            continue;
        }

        if (!isSettled($domain['status'])) {
            $busySites++;
            continue;
        }

        if ($action === 'enable' && $enabled) {
            continue;
        }

        if ($action === 'disable' && !$enabled) {
            continue;
        }

        $row = getOrCreateRow($domain, $customerId);

        exec_query(
            'UPDATE apache_cache SET enabled = ?, status = ? WHERE apache_cache_id = ?',
            array(
                $action === 'enable' ? 1 : 0,
                $action === 'enable' ? 'toenable' : 'todisable',
                $row['apache_cache_id']
            )
        );

        if ($action === 'enable') {
            $enabledSites++;
        } else {
            $disabledSites++;
        }

        $needsBackendRequest = true;
    }

    foreach ($customerActions as $customerId => $actions) {
        if (!$actions['allow']) {
            continue;
        }

        if ($customerPermissions[$customerId]) {
            continue;
        }

        exec_query(
            '
                INSERT INTO apache_cache_perm (admin_id, allowed) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE allowed = ?
            ',
            array($customerId, 1, 1)
        );

        $allowedCustomers++;
    }

    foreach ($customerActions as $customerId => $actions) {
        if (!$actions['withdraw']) {
            continue;
        }

        if (!$customerPermissions[$customerId]) {
            continue;
        }

        if (hasUnsettledDomains($customerId)) {
            $busyCustomers++;
            continue;
        }

        exec_query(
            '
                INSERT INTO apache_cache_perm (admin_id, allowed) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE allowed = ?
            ',
            array($customerId, 0, 0)
        );

        $count = bulkSet($customerId, false);
        if ($count > 0) {
            $disabledByWithdraw += $count;
            $needsBackendRequest = true;
        }

        $withdrawnCustomers++;
    }

    if ($needsBackendRequest) {
        send_request();
    }

    if ($enabledSites > 0) {
        set_page_message(tr('Cache scheduled to be enabled on %d site(s).', $enabledSites), 'success');
    }

    if ($disabledSites > 0) {
        set_page_message(tr('Cache scheduled to be disabled on %d site(s).', $disabledSites), 'success');
    }

    if ($allowedCustomers > 0) {
        set_page_message(
            tr('Apache cache made available to %d customer(s).', $allowedCustomers),
            'success'
        );
    }

    if ($withdrawnCustomers > 0) {
        set_page_message(
            tr(
                'Apache cache withdrawn from %d customer(s), and disabled on %d domain(s).',
                $withdrawnCustomers,
                $disabledByWithdraw
            ),
            'success'
        );
    }

    if ($busySites > 0) {
        set_page_message(
            tr('Skipped %d selected site(s) because the backend is already working on them.', $busySites),
            'warning'
        );
    }

    if ($busyCustomers > 0) {
        set_page_message(
            tr(
                'Skipped withdraw for %d customer(s) because one or more of their domains is already being processed.',
                $busyCustomers
            ),
            'warning'
        );
    }

    if ($rowErrors > 0) {
        set_page_message(
            tr(
                'Apache cache cannot be enabled on %d selected site(s) because the customer is not allowed to use it.',
                $rowErrors
            ),
            'error'
        );
    }

    if (!$enabledSites && !$disabledSites && !$allowedCustomers && !$withdrawnCustomers
        && !$busySites && !$busyCustomers && !$rowErrors
    ) {
        set_page_message(tr('Nothing to change.'), 'info');
    }

    redirectTo('apache_cache.php');
    return;
}

function generatePage($tpl, $resellerId)
{
    $domains = getResellerDomains($resellerId);

    if (!$domains) {
        $tpl->assign(array(
            'DOMAIN_LIST' => '',
            'NO_DOMAINS'  => tr('You have no domains yet.')
        ));
        $tpl->parse('NO_DOMAINS_BLOCK', 'no_domains_block');

        return;
    }

    $labels = array(
        'dmn'    => tr('Domain'),
        'sub'    => tr('Subdomain'),
        'als'    => tr('Alias'),
        'alssub' => tr('Alias subdomain')
    );

    $customerSettled = array();
    foreach ($domains as $domain) {
        $customerId = (int)$domain['admin_id'];

        if (!isset($customerSettled[$customerId])) {
            $customerSettled[$customerId] = true;
        }

        if (!isSettled($domain['status'])) {
            $customerSettled[$customerId] = false;
        }
    }

    $tpl->assign('NO_DOMAINS_BLOCK', '');

    foreach ($domains as $domain) {
        $allowed = (bool)$domain['allowed'];
        $enabled = !empty($domain['enabled']);
        $settled = isSettled($domain['status']);
        $customerId = (int)$domain['admin_id'];
        $canWithdraw = $allowed && !empty($customerSettled[$customerId]);
        $key = tohtml(domainKey($domain), 'htmlAttr');

        $options = array(
            '<option value=""></option>'
        );

        if ($allowed) {
            $options[] = '<option value="enable">' . tohtml(tr('Enable')) . '</option>';
            $options[] = '<option value="disable">' . tohtml(tr('Disable')) . '</option>';

            if ($canWithdraw) {
                $options[] = '<option value="withdraw">' . tohtml(tr('Withdraw')) . '</option>';
            }
        } else {
            $options[] = '<option value="allow">' . tohtml(tr('Allow')) . '</option>';
        }

        $tpl->assign(array(
            'CUSTOMER_NAME' => tohtml(decode_idna($domain['admin_name'])),
            'DOMAIN_NAME'   => tohtml(decode_idna($domain['domain_name'])),
            'DOMAIN_KIND'   => tohtml($labels[$domain['domain_type']]),
            'ALLOWED'       => $allowed ? tr('yes') : tr('no'),
            'ALLOWED_ICON'  => $allowed ? 'ok' : 'disabled',
            'ENABLED'       => $enabled ? tr('yes') : tr('no'),
            'ENABLED_ICON'  => $enabled ? 'ok' : 'disabled',
            'STATE'         => statusText($domain['status']),
            'STATE_ICON'    => statusIcon($domain['status']),
            'BULK_CHECKBOX' => '<input type="checkbox" class="apache_cache_pick"' . (!$settled ? ' disabled' : '') . '>',
            'ACTION_SELECT' => '<select name="action[' . $key . ']"' . (!$settled ? ' disabled' : '') . '>' . implode('', $options) . '</select>'
        ));

        $tpl->parse('DOMAIN_ITEM', '.domain_item');
    }
}

/***********************************************************************************************************************
 * Main
 */

EventAggregator::getInstance()->dispatch(Events::onResellerScriptStart);
check_login('reseller');

$resellerId = intval($_SESSION['user_id']);

handleSubmit($resellerId);

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'           => 'shared/layouts/ui.tpl',
    'page'             => '../../plugins/SGW_ApacheCache/themes/default/view/reseller/apache_cache.tpl',
    'page_message'     => 'layout',
    'no_domains_block' => 'page',
    'domain_list'      => 'page',
    'domain_item'      => 'domain_list'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'       => tr('Reseller / Apache Cache'),
    'TR_INTRO'            => tr('Manage Apache cache access and per-domain cache actions for your customers.'),
    'TR_CUSTOMER'         => tr('Customer'),
    'TR_DOMAIN'           => tr('Domain'),
    'TR_DOMAIN_KIND'      => tr('Type'),
    'TR_ALLOWED'          => tr('Allowed'),
    'TR_ENABLED'          => tr('Enabled'),
    'TR_STATE'            => tr('State'),
    'TR_ACTION'           => tr('Action'),
    'TR_SELECT'           => tr('Select'),
    'TR_SELECT_ALL'       => tr('Select all'),
    'TR_BULK_ACTION'      => tr('Bulk action'),
    'TR_BULK_APPLY'       => tr('Apply to selected'),
    'TR_UPDATE'           => tr('Update'),
    'TR_ALLOW'            => tr('Allow'),
    'TR_ENABLE'           => tr('Enable'),
    'TR_DISABLE'          => tr('Disable'),
    'TR_WITHDRAW'         => tr('Withdraw'),
    'TR_WITHDRAW_CONFIRM' => tojs(tr('Withdrawing the feature also disables the cache on all of this customer\'s domains. Continue?'))
)); 

generateNavigation($tpl);
generatePage($tpl, $resellerId);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onResellerScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();
