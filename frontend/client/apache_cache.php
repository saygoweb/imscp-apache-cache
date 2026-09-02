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

require_once __DIR__ . '/../common.php';

/***********************************************************************************************************************
 * Functions
 */

/**
 * Apply an action requested from the list.
 *
 * @param int $adminId Customer unique identifier
 * @return void
 */
function handleAction($adminId)
{
    if (!isset($_GET['action'], $_GET['type'], $_GET['id'])) {
        return;
    }

    $action = clean_input($_GET['action']);
    $type = clean_input($_GET['type']);
    $id = intval($_GET['id']);

    $domain = getDomain($adminId, $type, $id);
    if ($domain === false) {
        showBadRequestErrorPage();
    }

    // Acting on an item the backend is still working on would race it.
    if (!isSettled($domain['status'])) {
        set_page_message(tr('That domain is still being updated. Try again shortly.'), 'warning');
        redirectTo('apache_cache.php');
    }

    $row = getOrCreateRow($domain, $adminId);

    switch ($action) {
        case 'enable':
            exec_query(
                'UPDATE apache_cache SET enabled = 1, status = ? WHERE apache_cache_id = ?',
                array('toenable', $row['apache_cache_id'])
            );
            set_page_message(tr('Cache scheduled to be enabled for %s.', $domain['domain_name']), 'success');
            break;
        case 'disable':
            exec_query(
                'UPDATE apache_cache SET enabled = 0, status = ? WHERE apache_cache_id = ?',
                array('todisable', $row['apache_cache_id'])
            );
            set_page_message(tr('Cache scheduled to be disabled for %s.', $domain['domain_name']), 'success');
            break;
        case 'purge':
            exec_query(
                'UPDATE apache_cache SET status = ? WHERE apache_cache_id = ?',
                array('topurge', $row['apache_cache_id'])
            );
            set_page_message(tr('Cache scheduled to be purged for %s.', $domain['domain_name']), 'success');
            break;
        default:
            showBadRequestErrorPage();
    }

    send_request();
    redirectTo('apache_cache.php');
}

/**
 * Fill the domain table.
 *
 * @param TemplateEngine $tpl
 * @param int $adminId Customer unique identifier
 * @return void
 */
function generatePage($tpl, $adminId)
{
    $labels = array(
        'dmn'    => tr('Domain'),
        'sub'    => tr('Subdomain'),
        'als'    => tr('Alias'),
        'alssub' => tr('Alias subdomain')
    );

    $rows = getDomains($adminId);

    if (!$rows) {
        $tpl->assign(array(
            'DOMAIN_LIST' => '',
            'NO_DOMAINS'  => tr('You have no domains yet.')
        ));
        $tpl->parse('NO_DOMAINS_BLOCK', 'no_domains_block');

        return;
    }

    $tpl->assign('NO_DOMAINS_BLOCK', '');

    $defaults = defaults();

    foreach ($rows as $row) {
        // A vhost nobody has configured yet has no cache row, so show the
        // settings it would be given rather than a column full of nulls.
        if ($row['apache_cache_id'] === null) {
            $row = array_merge($row, $defaults);
        }

        $enabled = (bool)$row['enabled'];
        $settled = isSettled($row['status']);
        $link = 'apache_cache.php?type=' . $row['domain_type'] . '&id=' . $row['domain_id'];

        $tpl->assign(array(
            'DOMAIN_NAME'    => tohtml(decode_idna($row['domain_name'])),
            'DOMAIN_KIND'    => tohtml($labels[$row['domain_type']]),
            'STATUS'         => tohtml(statusText($row['status'])),
            'STATUS_ICON'    => statusIcon($row['status']),
            'WORDPRESS_MODE' => $row['wordpress_mode'] ? tr('yes') : tr('no'),
            'NOTE'           => tohtml($row['state']),
            'EDIT_LINK'      => tohtml('apache_cache_edit.php?type=' . $row['domain_type']
                . '&id=' . $row['domain_id'], 'htmlAttr'),
            'TOGGLE_LINK'    => tohtml($link . '&action=' . ($enabled ? 'disable' : 'enable'), 'htmlAttr'),
            'TOGGLE_LABEL'   => $enabled ? tr('Disable') : tr('Enable'),
            'TOGGLE_ICON'    => $enabled ? 'close' : 'ok',
            'PURGE_LINK'     => tohtml($link . '&action=purge', 'htmlAttr')
        ));

        // Only a settled item may be acted on; while the backend is working on
        // one, its actions are replaced by a plain "updating" note.
        if ($settled) {
            // Purging only makes sense while the cache is actually on.
            if ($enabled) {
                $tpl->parse('PURGE_ACTION', 'purge_action');
            } else {
                $tpl->assign('PURGE_ACTION', '');
            }

            $tpl->parse('DOMAIN_ACTIONS', 'domain_actions');
            $tpl->assign('DOMAIN_BUSY', '');
        } else {
            $tpl->assign('DOMAIN_ACTIONS', '');
            $tpl->parse('DOMAIN_BUSY', 'domain_busy');
        }

        $tpl->parse('DOMAIN_ITEM', '.domain_item');
    }
}

/***********************************************************************************************************************
 * Main
 */

EventAggregator::getInstance()->dispatch(Events::onClientScriptStart);
check_login('user');

$adminId = intval($_SESSION['user_id']);

if (!SGW_ApacheCache::customerHasApacheCache($adminId)) {
    showBadRequestErrorPage();
}

handleAction($adminId);

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'           => 'shared/layouts/ui.tpl',
    'page'             => '../../plugins/SGW_ApacheCache/themes/default/view/client/apache_cache.tpl',
    'page_message'     => 'layout',
    'no_domains_block' => 'page',
    'domain_list'      => 'page',
    'domain_item'      => 'domain_list',
    'domain_actions'   => 'domain_item',
    'domain_busy'      => 'domain_item',
    'purge_action'     => 'domain_actions'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'      => tr('Client / Domains / Apache Cache'),
    'TR_INTRO'           => tr('Serve pages from the Apache disk cache. WordPress mode keeps signed-in visitors, comment authors and shopping carts out of the cache.'),
    'TR_DOMAIN_NAME'     => tr('Domain'),
    'TR_DOMAIN_KIND'     => tr('Type'),
    'TR_STATUS'          => tr('Cache'),
    'TR_WORDPRESS_MODE'  => tr('WordPress mode'),
    'TR_NOTE'            => tr('Notes'),
    'TR_ACTION'          => tr('Actions'),
    'TR_EDIT'            => tr('Edit'),
    'TR_PURGE'           => tr('Purge'),
    'TR_PURGE_CONFIRM'   => tr('Purge the cache for this domain?'),
    'TR_BUSY'            => tr('Updating...')
));

generateNavigation($tpl);
generatePage($tpl, $adminId);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onClientScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();
