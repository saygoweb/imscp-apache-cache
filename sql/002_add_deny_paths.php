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

// Paths refused outright rather than merely kept out of the cache. Existing
// rows get the same default a new one would, since xmlrpc.php is a liability
// on every WordPress site and none of them asked to keep it reachable.
return array(
    'up'   => "
        ALTER TABLE `apache_cache`
        ADD `deny_paths` TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci AFTER `bypass_paths`;

        UPDATE `apache_cache` SET `deny_paths` = 'xmlrpc.php';
    ",
    'down' => "
        ALTER TABLE `apache_cache` DROP COLUMN `deny_paths`;
    "
);
