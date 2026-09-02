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
 * Read the posted settings, clamping anything a browser could have sent.
 *
 * @return array
 */
function readPostedSettings()
{
    $defaults = defaults();

    $intOption = function ($name, $min, $max) use ($defaults) {
        $value = isset($_POST[$name]) ? intval($_POST[$name]) : $defaults[$name];

        return max($min, min($max, $value));
    };

    return array(
        'enabled'           => isset($_POST['enabled']) ? 1 : 0,
        'wordpress_mode'    => isset($_POST['wordpress_mode']) ? 1 : 0,
        'static_expires'    => isset($_POST['static_expires']) ? 1 : 0,
        'debug_headers'     => isset($_POST['debug_headers']) ? 1 : 0,
        'ignore_no_lastmod' => isset($_POST['ignore_no_lastmod']) ? 1 : 0,
        // A minute is the shortest lifetime worth the disk write; a week is as
        // long as a customer can go without a stale page becoming a support
        // call.
        'default_expire'    => $intOption('default_expire', 60, 604800),
        'max_expire'        => $intOption('max_expire', 60, 604800),
        'max_file_size'     => $intOption('max_file_size', 1024, 104857600),
        'bypass_cookies'    => isset($_POST['bypass_cookies'])
            ? clean_input($_POST['bypass_cookies']) : '',
        'bypass_paths'      => isset($_POST['bypass_paths'])
            ? clean_input($_POST['bypass_paths']) : ''
    );
}

/**
 * Save the posted settings and hand the item to the backend.
 *
 * @param array $domain Row as returned by getDomains()
 * @param int $adminId Customer unique identifier
 * @return void
 */
function saveSettings(array $domain, $adminId)
{
    $row = getOrCreateRow($domain, $adminId);
    $settings = readPostedSettings();

    if ($settings['max_expire'] < $settings['default_expire']) {
        set_page_message(
            tr('The maximum lifetime cannot be shorter than the default lifetime.'), 'error'
        );

        return;
    }

    exec_query(
        '
            UPDATE apache_cache SET
                enabled = ?, wordpress_mode = ?, static_expires = ?,
                debug_headers = ?, ignore_no_lastmod = ?, default_expire = ?,
                max_expire = ?, max_file_size = ?, bypass_cookies = ?,
                bypass_paths = ?, status = ?, state = ?
            WHERE apache_cache_id = ?
        ',
        array(
            $settings['enabled'], $settings['wordpress_mode'], $settings['static_expires'],
            $settings['debug_headers'], $settings['ignore_no_lastmod'],
            $settings['default_expire'], $settings['max_expire'], $settings['max_file_size'],
            $settings['bypass_cookies'], $settings['bypass_paths'],
            $settings['enabled'] ? 'tochange' : 'todisable', '',
            $row['apache_cache_id']
        )
    );

    send_request();
    set_page_message(tr('Cache settings scheduled for update.'), 'success');
    redirectTo('apache_cache.php');
}

/**
 * Fill the form.
 *
 * @param TemplateEngine $tpl
 * @param array $domain Row as returned by getDomains()
 * @param array $row Cache settings
 * @return void
 */
function generatePage($tpl, array $domain, array $row)
{
    $checked = function ($value) {
        return $value ? ' checked' : '';
    };

    $tpl->assign(array
    (
        'DOMAIN_NAME'           => tohtml(decode_idna($domain['domain_name'])),
        'TYPE'                  => tohtml($domain['domain_type'], 'htmlAttr'),
        'ID'                    => tohtml($domain['domain_id'], 'htmlAttr'),
        'ENABLED'               => $checked($row['enabled']),
        'WORDPRESS_MODE'        => $checked($row['wordpress_mode']),
        'STATIC_EXPIRES'        => $checked($row['static_expires']),
        'DEBUG_HEADERS'         => $checked($row['debug_headers']),
        'IGNORE_NO_LASTMOD'     => $checked($row['ignore_no_lastmod']),
        'DEFAULT_EXPIRE'        => tohtml($row['default_expire'], 'htmlAttr'),
        'MAX_EXPIRE'            => tohtml($row['max_expire'], 'htmlAttr'),
        'MAX_FILE_SIZE'         => tohtml($row['max_file_size'], 'htmlAttr'),
        'BYPASS_COOKIES'        => tohtml($row['bypass_cookies']),
        'BYPASS_PATHS'          => tohtml($row['bypass_paths'])
    ));
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

if (!isset($_GET['type']) || !isset($_GET['id'])) {
    showBadRequestErrorPage();
}

$domain = getDomain($adminId, clean_input($_GET['type']), intval($_GET['id']));
if ($domain === false) {
    showBadRequestErrorPage();
}

if (!isSettled($domain['status'])) {
    set_page_message(tr('That domain is still being updated. Try again shortly.'), 'warning');
    redirectTo('apache_cache.php');
}

if (!empty($_POST)) {
    saveSettings($domain, $adminId);
}

$row = getOrCreateRow($domain, $adminId);

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'       => 'shared/layouts/ui.tpl',
    'page'         => '../../plugins/SGW_ApacheCache/themes/default/view/client/apache_cache_edit.tpl',
    'page_message' => 'layout'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'            => tr('Client / Domains / Apache Cache / Edit'),
    'TR_ENABLED'               => tr('Enable the cache for this domain'),
    'TR_WORDPRESS_MODE'        => tr('WordPress mode'),
    'TR_WORDPRESS_MODE_HELP'   => tr('Keeps the admin area, the login and REST endpoints, searches, form submissions and any visitor carrying a WordPress session cookie out of the cache. Leave this on for a WordPress site: WordPress does not tell caches that its pages depend on the visitor, so without it a signed-in page could be stored and served to everyone.'),
    'TR_STATIC_EXPIRES'        => tr('Give static files a browser lifetime'),
    'TR_STATIC_EXPIRES_HELP'   => tr('Adds an expiry to images, fonts, CSS and JavaScript so browsers stop re-requesting them.'),
    'TR_IGNORE_NO_LASTMOD'     => tr('Cache pages that carry no freshness information'),
    'TR_IGNORE_NO_LASTMOD_HELP' => tr('WordPress sends HTML with no Last-Modified, no ETag and no expiry, which Apache will not store by default. Turning this off will, in practice, stop pages being cached at all.'),
    'TR_DEBUG_HEADERS'         => tr('Send diagnostic headers'),
    'TR_DEBUG_HEADERS_HELP'    => tr('Adds X-Cache (HIT or MISS) and X-Imscp-Bypass to responses, so you can see what the cache is doing.'),
    'TR_DEFAULT_EXPIRE'        => tr('Default lifetime [seconds]'),
    'TR_DEFAULT_EXPIRE_HELP'   => tr('How long a page with no expiry of its own is kept. This is how long a visitor may see an out-of-date page after you publish a change.'),
    'TR_MAX_EXPIRE'            => tr('Maximum lifetime [seconds]'),
    'TR_MAX_FILE_SIZE'         => tr('Largest response to cache [bytes]'),
    'TR_BYPASS_COOKIES'        => tr('Additional cookies that bypass the cache'),
    'TR_BYPASS_COOKIES_HELP'   => tr('One cookie name prefix per line. A request carrying any of them is neither served from nor stored in the cache.'),
    'TR_BYPASS_PATHS'          => tr('Additional paths never cached'),
    'TR_BYPASS_PATHS_HELP'     => tr('One URL path prefix per line, for example /my-account.'),
    'TR_UPDATE'                => tr('Update'),
    'TR_CANCEL'                => tr('Cancel')
));

generateNavigation($tpl);
generatePage($tpl, $domain, $row);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onClientScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();
