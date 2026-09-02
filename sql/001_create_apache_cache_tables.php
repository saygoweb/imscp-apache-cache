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

return array(
    // A row per cacheable vhost. (domain_type, domain_id) is how i-MSCP itself
    // identifies a vhost, and it covers all four types including alias
    // subdomains ('alssub').
    'up'   => "
        CREATE TABLE IF NOT EXISTS `apache_cache` (
            `apache_cache_id`   int(11) unsigned NOT NULL AUTO_INCREMENT,
            `admin_id`          int(11) unsigned NOT NULL,
            `domain_type`       enum('dmn','sub','als','alssub') COLLATE utf8_unicode_ci NOT NULL,
            `domain_id`         int(11) unsigned NOT NULL,
            `domain_name`       varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `enabled`           tinyint(1) NOT NULL DEFAULT '0',
            `wordpress_mode`    tinyint(1) NOT NULL DEFAULT '1',
            `static_expires`    tinyint(1) NOT NULL DEFAULT '1',
            `debug_headers`     tinyint(1) NOT NULL DEFAULT '1',
            `ignore_no_lastmod` tinyint(1) NOT NULL DEFAULT '1',
            `default_expire`    int(11) unsigned NOT NULL DEFAULT '300',
            `max_expire`        int(11) unsigned NOT NULL DEFAULT '86400',
            `max_file_size`     int(11) unsigned NOT NULL DEFAULT '1048576',
            `bypass_cookies`    text COLLATE utf8_unicode_ci,
            `bypass_paths`      text COLLATE utf8_unicode_ci,
            `status`            varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `state`             varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
            PRIMARY KEY (`apache_cache_id`),
            UNIQUE KEY `apache_cache_domain` (`domain_type`, `domain_id`),
            KEY `apache_cache_admin_id` (`admin_id`),
            KEY `apache_cache_status` (`status`(15))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

        CREATE TABLE IF NOT EXISTS `apache_cache_perm` (
            `admin_id` int(11) unsigned NOT NULL,
            `allowed`  tinyint(1) NOT NULL DEFAULT '1',
            PRIMARY KEY (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ",
    'down' => "
        DROP TABLE IF EXISTS `apache_cache_perm`;
        DROP TABLE IF EXISTS `apache_cache`;
    "
);
