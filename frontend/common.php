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

use iMSCP\Database\DatabaseMySQL;
use PDO;

/**
 * Every vhost a customer owns, of all four types, with its cache row if any.
 *
 * i-MSCP keeps the four vhost kinds in four tables; the cache rows are keyed by
 * the same (type, id) pair i-MSCP itself uses, so one union gives the whole
 * picture including alias subdomains.
 *
 * @param int $adminId Customer unique identifier
 * @return array
 */
function getDomains($adminId)
{
    $stmt = exec_query(
        "
            SELECT v.*, c.apache_cache_id, c.enabled, c.wordpress_mode, c.status, c.state
            FROM (
                SELECT 'dmn' AS domain_type, d.domain_id AS domain_id,
                    d.domain_name AS domain_name, d.domain_status AS domain_status
                FROM domain AS d
                WHERE d.domain_admin_id = ?

                UNION ALL

                SELECT 'sub', s.subdomain_id,
                    CONCAT(s.subdomain_name, '.', d.domain_name), s.subdomain_status
                FROM subdomain AS s
                JOIN domain AS d USING(domain_id)
                WHERE d.domain_admin_id = ?

                UNION ALL

                SELECT 'als', a.alias_id, a.alias_name, a.alias_status
                FROM domain_aliasses AS a
                JOIN domain AS d USING(domain_id)
                WHERE d.domain_admin_id = ?

                UNION ALL

                SELECT 'alssub', sa.subdomain_alias_id,
                    CONCAT(sa.subdomain_alias_name, '.', a.alias_name),
                    sa.subdomain_alias_status
                FROM subdomain_alias AS sa
                JOIN domain_aliasses AS a USING(alias_id)
                JOIN domain AS d USING(domain_id)
                WHERE d.domain_admin_id = ?
            ) AS v
            LEFT JOIN apache_cache AS c
                ON c.domain_type = v.domain_type AND c.domain_id = v.domain_id
            ORDER BY v.domain_name
        ",
        array($adminId, $adminId, $adminId, $adminId)
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One vhost of a customer, with its cache row if any, or false.
 *
 * @param int $adminId Customer unique identifier
 * @param string $type One of dmn, sub, als, alssub
 * @param int $id Vhost unique identifier within its type
 * @return array|false
 */
function getDomain($adminId, $type, $id)
{
    foreach (getDomains($adminId) as $row) {
        if ($row['domain_type'] === $type && (int)$row['domain_id'] === (int)$id) {
            return $row;
        }
    }

    return false;
}

/**
 * Default cache settings, used for a vhost that has never been configured.
 *
 * @return array
 */
function defaults()
{
    return array(
        'enabled'           => 0,
        'wordpress_mode'    => 1,
        'static_expires'    => 1,
        'debug_headers'     => 1,
        'ignore_no_lastmod' => 1,
        'default_expire'    => 86400,
        'max_expire'        => 172800,
        'max_file_size'     => 1048576,
        'bypass_cookies'    => '',
        'bypass_paths'      => '',
        // WordPress's XML-RPC endpoint is the one path that is attacked on
        // every site and wanted on almost none, so it is denied out of the box.
        'deny_paths'        => 'xmlrpc.php',
        'status'            => 'disabled',
        'state'             => ''
    );
}

/**
 * The cache row for a vhost, creating it from the defaults on first use.
 *
 * @param array $domain Row as returned by getDomains()
 * @param int $adminId Customer unique identifier
 * @return array
 */
function getOrCreateRow(array $domain, $adminId)
{
    if ($domain['apache_cache_id'] !== null) {
        $stmt = exec_query(
            'SELECT * FROM apache_cache WHERE apache_cache_id = ?',
            array($domain['apache_cache_id'])
        );

        return $stmt->fetchRow(PDO::FETCH_ASSOC);
    }

    $row = array_merge(defaults(), array(
        'admin_id'    => $adminId,
        'domain_type' => $domain['domain_type'],
        'domain_id'   => $domain['domain_id'],
        'domain_name' => $domain['domain_name']
    ));

    exec_query(
        '
            INSERT INTO apache_cache (
                admin_id, domain_type, domain_id, domain_name, enabled,
                wordpress_mode, static_expires, debug_headers, ignore_no_lastmod,
                default_expire, max_expire, max_file_size, bypass_cookies,
                bypass_paths, deny_paths, status, state
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ',
        array(
            $row['admin_id'], $row['domain_type'], $row['domain_id'],
            $row['domain_name'], $row['enabled'], $row['wordpress_mode'],
            $row['static_expires'], $row['debug_headers'], $row['ignore_no_lastmod'],
            $row['default_expire'], $row['max_expire'], $row['max_file_size'],
            $row['bypass_cookies'], $row['bypass_paths'], $row['deny_paths'],
            $row['status'], $row['state']
        )
    );

    $row['apache_cache_id'] = DatabaseMySQL::getInstance()->insertId();

    return $row;
}

/**
 * Map an item status onto one of the theme's status icons.
 *
 * @param string|null $status
 * @return string
 */
function statusIcon($status)
{
    if ($status === null || $status === 'disabled') {
        return 'disabled';
    }

    if ($status === 'ok') {
        return 'ok';
    }

    if (in_array($status, array(
        'toadd', 'tochange', 'toenable', 'todisable', 'todelete', 'topurge'
    ))) {
        return 'reload';
    }

    return 'error';
}

/**
 * Human readable item status.
 *
 * @param string|null $status
 * @return string
 */
function statusText($status)
{
    switch ($status) {
        case null:
        case 'disabled':
            return tr('Disabled');
        case 'ok':
            return tr('Enabled');
        case 'toadd':
        case 'tochange':
        case 'toenable':
            return tr('Applying...');
        case 'todisable':
        case 'todelete':
            return tr('Removing...');
        case 'topurge':
            return tr('Purging...');
        default:
            return tr('Error');
    }
}

/**
 * Is the item settled, i.e. is the backend done with it?
 *
 * A busy item must not be edited, or the backend would act on half of one
 * change and half of the next.
 *
 * @param string|null $status
 * @return bool
 */
function isSettled($status)
{
    return $status === null || $status === 'ok' || $status === 'disabled'
        || statusIcon($status) === 'error';
}

/**
 * Every vhost owned by any of a reseller's customers.
 *
 * @param int $resellerId Reseller unique identifier
 * @return array
 */
function getResellerDomains($resellerId)
{
    $stmt = exec_query(
        "
            SELECT v.*, ad.admin_name, COALESCE(p.allowed, 1) AS allowed,
                c.apache_cache_id, c.enabled, c.status, c.state
            FROM (
                SELECT 'dmn' AS domain_type, d.domain_id AS domain_id,
                    d.domain_admin_id AS admin_id,
                    d.domain_name AS domain_name, d.domain_status AS domain_status
                FROM domain AS d
                WHERE d.domain_admin_id IN (
                    SELECT admin_id FROM admin WHERE created_by = ? AND admin_type = 'user'
                )

                UNION ALL

                SELECT 'sub', s.subdomain_id, d.domain_admin_id,
                    CONCAT(s.subdomain_name, '.', d.domain_name), s.subdomain_status
                FROM subdomain AS s
                JOIN domain AS d USING(domain_id)
                WHERE d.domain_admin_id IN (
                    SELECT admin_id FROM admin WHERE created_by = ? AND admin_type = 'user'
                )

                UNION ALL

                SELECT 'als', a.alias_id, d.domain_admin_id,
                    a.alias_name, a.alias_status
                FROM domain_aliasses AS a
                JOIN domain AS d USING(domain_id)
                WHERE d.domain_admin_id IN (
                    SELECT admin_id FROM admin WHERE created_by = ? AND admin_type = 'user'
                )

                UNION ALL

                SELECT 'alssub', sa.subdomain_alias_id, d.domain_admin_id,
                    CONCAT(sa.subdomain_alias_name, '.', a.alias_name),
                    sa.subdomain_alias_status
                FROM subdomain_alias AS sa
                JOIN domain_aliasses AS a USING(alias_id)
                JOIN domain USING(domain_id)
                WHERE d.domain_admin_id IN (
                    SELECT admin_id FROM admin WHERE created_by = ? AND admin_type = 'user'
                )
            ) AS v
            JOIN admin AS ad ON ad.admin_id = v.admin_id
            LEFT JOIN apache_cache_perm AS p ON p.admin_id = v.admin_id
            LEFT JOIN apache_cache AS c
                ON c.domain_type = v.domain_type AND c.domain_id = v.domain_id
            ORDER BY ad.admin_name, v.domain_name
        ",
        array($resellerId, $resellerId, $resellerId, $resellerId)
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The form key identifying one vhost.
 *
 * @param array $domain Row as returned by getResellerDomains()
 * @return string
 */
function domainKey(array $domain)
{
    return $domain['domain_type'] . '-' . $domain['domain_id'];
}

/**
 * Validate and decode a posted form key.
 *
 * @param string $key An encoded key, e.g. "dmn-12"
 * @return array|false array('domain_type' => string, 'domain_id' => int), or false on failure
 */
function splitDomainKey($key)
{
    if (!is_string($key)) {
        return false;
    }

    $parts = explode('-', $key);
    if (count($parts) !== 2) {
        return false;
    }

    list($type, $id) = $parts;
    if (!in_array($type, array('dmn', 'sub', 'als', 'alssub'), true)) {
        return false;
    }

    if (!ctype_digit($id)) {
        return false;
    }

    return array(
        'domain_type' => $type,
        'domain_id'   => intval($id)
    );
}

/**
 * Does this customer have any domains that are currently unsettled?
 *
 * @param int $customerId Customer unique identifier
 * @return bool
 */
function hasUnsettledDomains($customerId)
{
    foreach (getDomains($customerId) as $domain) {
        if (!isSettled($domain['status'])) {
            return true;
        }
    }

    return false;
}
