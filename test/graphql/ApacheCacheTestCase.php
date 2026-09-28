<?php
namespace iMSCP\Plugin\SGW_ApacheCache\Test\GraphQL;

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

use iMSCP\Database\DatabaseMySQL;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

/**
 * AuthzTestCase, plus making sure apache_cache exists.
 *
 * This plugin may not be installed on the box the suite runs on - its table
 * only exists once the plugin has actually been installed through the panel -
 * so this runs its own sql/ migrations once, from setUpBeforeClass(), before
 * the fixture opens its transaction. DDL commits whatever transaction is open
 * when it runs, so doing this any later would commit the fixture's rows to the
 * database instead of rolling them back.
 */
abstract class ApacheCacheTestCase extends AuthzTestCase
{
    public static function setUpBeforeClass(): void
    {
        // Skips the whole class when the panel is not installed here, exactly
        // as the graphql suite's own IntegrationTestCase does; nothing below
        // runs when it does.
        parent::setUpBeforeClass();

        self::ensureSchema();
    }

    private static function ensureSchema(): void
    {
        $pdo = DatabaseMySQL::getPDO();

        $exists = $pdo->query("SHOW TABLES LIKE 'apache_cache'")->rowCount() > 0;
        if ($exists) {
            return;
        }

        $migrations = glob(dirname(__DIR__, 2) . '/sql/*.php');
        natsort($migrations);

        foreach ($migrations as $file) {
            $migration = require $file;
            if (!isset($migration['up'])) {
                continue;
            }

            // A migration's 'up' is several statements in one string, exactly
            // as AbstractPlugin::migrateDb() runs it: prepare()+execute(), then
            // drain every rowset a multi-statement query leaves behind
            // (https://bugs.php.net/bug.php?id=61613), rather than exec(),
            // which does not run reliably against several statements at once.
            $statement = $pdo->prepare($migration['up']);
            $statement->execute();
            while ($statement->nextRowset()) {
                // Draining only.
            }
        }
    }
}
