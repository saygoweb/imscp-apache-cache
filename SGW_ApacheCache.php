<?php
namespace iMSCP\Plugin\SGW_ApacheCache;
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

use iMSCP\Event\Event;
use iMSCP\Event\EventManagerInterface;
use iMSCP\Event\Events;
use iMSCP\Plugin\AbstractPlugin;
use iMSCP\Plugin\PluginException;
use iMSCP\Plugin\PluginManager;
use iMSCP\Registry;

/**
 * Apache disk cache plugin.
 *
 * Gives each domain, subdomain, alias and alias subdomain a switch that turns
 * on mod_cache_disk for that vhost, with a WordPress mode that keeps logged-in
 * and stateful requests out of the cache.
 */
class SGW_ApacheCache extends AbstractPlugin
{
    /**
     * Statuses that mean the backend has nothing left to do for an item
     */
    const SETTLED_STATUSES = array('ok', 'disabled');

    /**
     * Statuses the backend consumes
     */
    const PENDING_STATUSES = array(
        'toadd', 'tochange', 'toenable', 'todisable', 'todelete', 'topurge'
    );

    /**
     * Plugin initialization
     *
     * @return void
     */
    public function init()
    {
        l10n_addTranslations(__DIR__ . '/l10n', 'Array', $this->getName());
    }

    /**
     * Register event listeners
     *
     * @param EventManagerInterface $eventsManager
     * @return void
     */
    public function register(EventManagerInterface $eventsManager)
    {
        $eventsManager->registerListener(
            array(
                Events::onResellerScriptStart,
                Events::onClientScriptStart,
                // A vhost that goes away must take its cache config with it.
                Events::onAfterDeleteDomainAlias,
                Events::onAfterDeleteSubdomain,
                Events::onAfterDeleteCustomer
            ),
            $this
        );
    }

    /**
     * Plugin installation
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @return void
     */
    public function install(PluginManager $pluginManager)
    {
        try {
            $this->migrateDb('up');
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Plugin update
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @param string $fromVersion
     * @param string $toVersion
     * @return void
     */
    public function update(PluginManager $pluginManager, $fromVersion, $toVersion)
    {
        try {
            $this->migrateDb('up');
            $this->clearTranslations();
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Plugin uninstallation
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @return void
     */
    public function uninstall(PluginManager $pluginManager)
    {
        try {
            $this->migrateDb('down');
            $this->clearTranslations();
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * onResellerScriptStart event listener
     *
     * @return void
     */
    public function onResellerScriptStart()
    {
        $this->setupNavigation('reseller');
    }

    /**
     * onClientScriptStart event listener
     *
     * @return void
     */
    public function onClientScriptStart()
    {
        if (self::customerHasApacheCache(intval($_SESSION['user_id']))) {
            $this->setupNavigation('client');
        }
    }

    /**
     * onAfterDeleteCustomer event listener
     *
     * @param Event $event
     * @return void
     */
    public function onAfterDeleteCustomer(Event $event)
    {
        exec_query(
            'UPDATE apache_cache SET status = ? WHERE admin_id = ?',
            array('todelete', $event->getParam('customerId'))
        );
        exec_query(
            'DELETE FROM apache_cache_perm WHERE admin_id = ?',
            array($event->getParam('customerId'))
        );
    }

    /**
     * onAfterDeleteDomainAlias event listener
     *
     * @param Event $event
     * @return void
     */
    public function onAfterDeleteDomainAlias(Event $event)
    {
        exec_query(
            'UPDATE apache_cache SET status = ? WHERE domain_type = ? AND domain_id = ?',
            array('todelete', 'als', $event->getParam('domainAliasId'))
        );
    }

    /**
     * onAfterDeleteSubdomain event listener
     *
     * @param Event $event
     * @return void
     */
    public function onAfterDeleteSubdomain(Event $event)
    {
        // The event carries 'sub' or 'alssub', which are the same names this
        // plugin keys its rows by.
        exec_query(
            'UPDATE apache_cache SET status = ? WHERE domain_type = ? AND domain_id = ?',
            array(
                'todelete',
                $event->getParam('subdomainType'),
                $event->getParam('subdomainId')
            )
        );
    }

    /**
     * Get routes
     *
     * @return array
     */
    public function getRoutes()
    {
        $pluginDir = $this->getPluginManager()->pluginGetRootDir() . '/' . $this->getName();

        return array(
            '/client/apache_cache.php'      => $pluginDir . '/frontend/client/apache_cache.php',
            '/client/apache_cache_edit.php' => $pluginDir . '/frontend/client/apache_cache_edit.php',
            '/reseller/apache_cache.php'    => $pluginDir . '/frontend/reseller/apache_cache.php'
        );
    }

    /**
     * Get status of items with errors
     *
     * @return array
     */
    public function getItemWithErrorStatus()
    {
        $stmt = exec_query(
            "
                SELECT apache_cache_id AS item_id, domain_name AS item_name,
                    'apache_cache' AS `table`, 'status' AS field
                FROM apache_cache
                WHERE status NOT IN(?, ?, ?, ?, ?, ?, ?, ?)
            ",
            array_merge(self::SETTLED_STATUSES, self::PENDING_STATUSES)
        );

        if ($stmt->rowCount()) {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return array();
    }

    /**
     * Set status of the given plugin item to 'tochange'
     *
     * @param string $table Table name
     * @param string $field Status field name
     * @param int $itemId Item unique identifier
     * @return void
     */
    public function changeItemStatus($table, $field, $itemId)
    {
        if ($table == 'apache_cache' && $field == 'status') {
            exec_query(
                'UPDATE apache_cache SET status = ? WHERE apache_cache_id = ?',
                array('tochange', $itemId)
            );
        }
    }

    /**
     * Return count of requests in progress
     *
     * @return int
     */
    public function getCountRequests()
    {
        $stmt = exec_query(
            'SELECT COUNT(apache_cache_id) AS cnt FROM apache_cache WHERE status IN (?, ?, ?, ?, ?, ?)',
            self::PENDING_STATUSES
        );
        $row = $stmt->fetchRow(\PDO::FETCH_ASSOC);

        return $row['cnt'];
    }

    /**
     * Is the Apache cache feature available to the given customer?
     *
     * Available to everyone unless a reseller has explicitly withdrawn it,
     * which is recorded as a row in apache_cache_perm.
     *
     * @param int $customerId Customer unique identifier
     * @return bool
     */
    public static function customerHasApacheCache($customerId)
    {
        static $hasAccess = array();

        if (!array_key_exists($customerId, $hasAccess)) {
            $stmt = exec_query(
                'SELECT allowed FROM apache_cache_perm WHERE admin_id = ?',
                array($customerId)
            );
            $row = $stmt->fetchRow(\PDO::FETCH_ASSOC);
            $hasAccess[$customerId] = ($row === false) ? true : (bool)$row['allowed'];
        }

        return $hasAccess[$customerId];
    }

    /**
     * Inject links into the navigation object
     *
     * @param string $level UI level (reseller|client)
     * @return void
     */
    protected function setupNavigation($level)
    {
        if (!Registry::isRegistered('navigation')) {
            return;
        }

        /** @var \Zend_Navigation $navigation */
        $navigation = Registry::get('navigation');

        if ($level == 'reseller') {
            if (($page = $navigation->findOneBy('uri', '/reseller/users.php'))) {
                $page->addPage(array(
                    'label'              => tr('Apache Cache'),
                    'uri'                => '/reseller/apache_cache.php',
                    'title_class'        => 'users',
                    'privilege_callback' => array('name' => 'resellerHasCustomers')
                ));
            }
        } elseif ($level == 'client') {
            if (($page = $navigation->findOneBy('uri', '/client/domains_manage.php'))) {
                $page->addPage(array(
                    'label'       => tr('Apache Cache'),
                    'uri'         => '/client/apache_cache.php',
                    'title_class' => 'domains',
                    // The edit page has to be a navigation page in its own
                    // right: the shared layout renders its heading from the
                    // active page, and dies if the current URI matches none.
                    'pages'       => array(
                        'apache_cache_edit' => array(
                            'label'       => tr('Edit cache settings'),
                            'uri'         => '/client/apache_cache_edit.php',
                            'title_class' => 'domains'
                        )
                    )
                ));
            }
        }
    }

    /**
     * Clear translations if any
     *
     * @return void
     */
    protected function clearTranslations()
    {
        /** @var \Zend_Translate $translator */
        $translator = Registry::get('translator');

        if ($translator->hasCache()) {
            $translator->clearCache($this->getName());
        }
    }
}
