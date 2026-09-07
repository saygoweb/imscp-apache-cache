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
 * The reseller's customers, with their permission and cache counts.
 *
 * @param int $resellerId Reseller unique identifier
 * @return array
 */
function getCustomers($resellerId)
{
    $stmt = exec_query(
        '
            SELECT a.admin_id, a.admin_name,
                COALESCE(p.allowed, 1) AS allowed,
                (
                    SELECT COUNT(*) FROM apache_cache AS c
                    WHERE c.admin_id = a.admin_id AND c.enabled = 1
                ) AS enabled_count
            FROM admin AS a
            LEFT JOIN apache_cache_perm AS p ON p.admin_id = a.admin_id
            WHERE a.created_by = ? AND a.admin_type = ?
            ORDER BY a.admin_name
        ',
        array($resellerId, 'user')
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Is this customer one of the reseller's own?
 *
 * @param int $resellerId Reseller unique identifier
 * @param int $customerId Customer unique identifier
 * @return bool
 */
function ownsCustomer($resellerId, $customerId)
{
    $stmt = exec_query(
        'SELECT COUNT(admin_id) AS cnt FROM admin WHERE admin_id = ? AND created_by = ? AND admin_type = ?',
        array($customerId, $resellerId, 'user')
    );
    $row = $stmt->fetchRow(PDO::FETCH_ASSOC);

    return $row['cnt'] > 0;
}

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

/**
 * Fill the customer table.
 *
 * @param TemplateEngine $tpl
 * @param int $resellerId Reseller unique identifier
 * @return void
 */
function generatePage($tpl, $resellerId)
{
    $customers = getCustomers($resellerId);

    if (!$customers) {
        $tpl->assign(array(
            'CUSTOMER_LIST' => '',
            'NO_CUSTOMERS'  => tr('You have no customers yet.')
        ));
        $tpl->parse('NO_CUSTOMERS_BLOCK', 'no_customers_block');

        return;
    }

    $tpl->assign('NO_CUSTOMERS_BLOCK', '');

    foreach ($customers as $customer) {
        $allowed = (bool)$customer['allowed'];
        $link = 'apache_cache.php';

        $tpl->assign(array(
            'CUSTOMER_NAME'  => tohtml(decode_idna($customer['admin_name'])),
            'ALLOWED'        => $allowed ? tr('yes') : tr('no'),
            'ALLOWED_ICON'   => $allowed ? 'ok' : 'disabled',
            'ENABLED_COUNT'  => tohtml($customer['enabled_count']),
            'PERM_LINK'      => tohtml($link, 'htmlAttr'),
            'PERM_LABEL'     => $allowed ? tr('Withdraw') : tr('Allow'),
            'PERM_ICON'      => $allowed ? 'close' : 'ok',
            // Only withdrawing is destructive, so only withdrawing confirms.
            'PERM_ONCLICK'   => $allowed
                ? tohtml("return confirm('" . tojs(tr('Withdrawing the feature also disables the cache on all of this customer\'s domains. Continue?')) . "');", 'htmlAttr')
                : '',
            'ENABLE_LINK'    => tohtml($link, 'htmlAttr'),
            'DISABLE_LINK'   => tohtml($link, 'htmlAttr')
        ));

        if ($allowed) {
            $tpl->parse('BULK_ACTIONS', 'bulk_actions');
        } else {
            $tpl->assign('BULK_ACTIONS', '');
        }

        $tpl->parse('CUSTOMER_ITEM', '.customer_item');
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
    'layout'             => 'shared/layouts/ui.tpl',
    'page'               => '../../plugins/SGW_ApacheCache/themes/default/view/reseller/apache_cache.tpl',
    'page_message'       => 'layout',
    'no_customers_block' => 'page',
    'customer_list'      => 'page',
    'customer_item'      => 'customer_list',
    'bulk_actions'       => 'customer_item'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'     => tr('Reseller / Customers / Apache Cache'),
    'TR_INTRO'          => tr('Decide which customers may use the Apache disk cache, and switch it on or off across all of a customer\'s domains at once.'),
    'TR_CUSTOMER'       => tr('Customer'),
    'TR_ALLOWED'        => tr('Allowed'),
    'TR_ENABLED_COUNT'  => tr('Domains cached'),
    'TR_ACTION'         => tr('Actions'),
    'TR_ENABLE_ALL'     => tr('Enable on all domains'),
    'TR_DISABLE_ALL'    => tr('Disable on all domains'),
    'TR_ENABLE_CONFIRM' => tr('Enable the cache on every domain this customer owns?'),
    'TR_DISABLE_CONFIRM' => tr('Disable the cache on every domain this customer owns?')
));

generateNavigation($tpl);
generatePage($tpl, $resellerId);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onResellerScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();
