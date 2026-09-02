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
    // Root below which each domain gets its own cache directory, so that one
    // domain can be purged without touching another.
    'cache_root' => '/var/cache/apache2/imscp',

    // Total on-disk size htcacheclean trims each domain's cache back to.
    'cache_size_limit' => '256M',

    // How often htcacheclean runs.
    'clean_interval' => '30min',

    // Customers may use the cache unless a reseller says otherwise.
    'allowed_by_default' => true
);
