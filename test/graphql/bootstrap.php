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

// The exact path the panel loads when SGW_GraphQL is enabled. require_once
// dedupes it there; any other copy - a second clone, a relative path into this
// checkout - fatals with "Cannot redeclare composerRequire..." the moment both
// are loaded in the same process.
$graphqlAutoload = '/var/www/imscp-plugins/imscp-graphql/vendor/autoload.php';

if (!is_file($graphqlAutoload)) {
    fwrite(STDERR,
        "SGW_ApacheCache: $graphqlAutoload not found.\n" .
        "Run this suite where the imscp-graphql checkout is mounted at " .
        "/var/www/imscp-plugins/imscp-graphql with its vendor/ installed - the " .
        "imscp-imscp dev container, or the docker/ci/plugin-test.sh harness.\n"
    );
    exit(1);
}

require_once $graphqlAutoload;

// Prepended, so this checkout's classes are found before the panel's own
// autoloader gets a chance to answer for iMSCP\Plugin\SGW_ApacheCache\ - which
// it does by resolving to whatever is linked into gui/plugins, not this
// worktree.
$pluginRoot = dirname(__DIR__, 2);

spl_autoload_register(static function ($class) use ($pluginRoot) {
    // The most specific prefix first: this test directory is lower-case on
    // disk (test/graphql) but its namespace segment is not (Test\GraphQL),
    // and PSR-4-style resolution is case-sensitive on a case-sensitive
    // filesystem - the same mismatch IntegrationTestCase's docblock notes for
    // SGW_GraphQL's own test/integration.
    $prefixes = array(
        'iMSCP\\Plugin\\SGW_ApacheCache\\Test\\GraphQL\\' => __DIR__,
        'iMSCP\\Plugin\\SGW_ApacheCache\\'                => $pluginRoot
    );

    foreach ($prefixes as $prefix => $base) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $path = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($path)) {
            require $path;

            return true;
        }
    }

    return false;
}, true, true);
