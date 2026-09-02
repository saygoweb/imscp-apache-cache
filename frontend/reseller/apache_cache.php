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
 * Apply an action requested from the list.
 *
 * @param int $resellerId Reseller unique identifier
 * @return void
 */
function handleAction($resellerId)
{
    if (!isset($_GET['action'], $_GET['customer_id'])) {
        return;
    }

    $action = clean_input($_GET['action']);
    $customerId = intval($_GET['customer_id']);

    if (!ownsCustomer($resellerId, $customerId)) {
        showBadRequestErrorPage();
    }

    switch ($action) {
        case 'allow':
        case 'deny':
            $allowed = ($action === 'allow') ? 1 : 0;
            exec_query(
                '
                    INSERT INTO apache_cache_perm (admin_id, allowed) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE allowed = ?
                ',
                array($customerId, $allowed, $allowed)
            );

            // Withdrawing the feature has to take the running caches with it,
            // otherwise the customer keeps the cache but loses the switch.
            if (!$allowed) {
                $count = bulkSet($customerId, false);
                send_request();
                set_page_message(
                    tr('Apache cache withdrawn, and disabled on %d domain(s).', $count), 'success'
                );
            } else {
                set_page_message(tr('Apache cache made available to the customer.'), 'success');
            }
            break;

        case 'enable_all':
        case 'disable_all':
            $enable = ($action === 'enable_all');

            if ($enable && !SGW_ApacheCache::customerHasApacheCache($customerId)) {
                set_page_message(
                    tr('This customer is not allowed to use the Apache cache.'), 'error'
                );
                break;
            }

            $count = bulkSet($customerId, $enable);
            send_request();
            set_page_message(
                $enable
                    ? tr('Cache scheduled to be enabled on %d domain(s).', $count)
                    : tr('Cache scheduled to be disabled on %d domain(s).', $count),
                'success'
            );
            break;

        default:
            showBadRequestErrorPage();
    }

    redirectTo('apache_cache.php');
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
        $link = 'apache_cache.php?customer_id=' . $customer['admin_id'] . '&action=';

        $tpl->assign(array(
            'CUSTOMER_NAME'  => tohtml(decode_idna($customer['admin_name'])),
            'ALLOWED'        => $allowed ? tr('yes') : tr('no'),
            'ALLOWED_ICON'   => $allowed ? 'ok' : 'disabled',
            'ENABLED_COUNT'  => tohtml($customer['enabled_count']),
            'PERM_LINK'      => tohtml($link . ($allowed ? 'deny' : 'allow'), 'htmlAttr'),
            'PERM_LABEL'     => $allowed ? tr('Withdraw') : tr('Allow'),
            'PERM_ICON'      => $allowed ? 'close' : 'ok',
            // Only withdrawing is destructive, so only withdrawing confirms.
            'PERM_ONCLICK'   => $allowed
                ? tohtml("return confirm('" . tojs(tr('Withdrawing the feature also disables the cache on all of this customer\'s domains. Continue?')) . "');", 'htmlAttr')
                : '',
            'ENABLE_LINK'    => tohtml($link . 'enable_all', 'htmlAttr'),
            'DISABLE_LINK'   => tohtml($link . 'disable_all', 'htmlAttr')
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

handleAction($resellerId);

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
