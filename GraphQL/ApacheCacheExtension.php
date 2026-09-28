<?php
namespace iMSCP\Plugin\SGW_ApacheCache\GraphQL;

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

use iMSCP\Plugin\SGW_ApacheCache\SGW_ApacheCache;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Extension\Extension;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionContext;
use iMSCP\Plugin\SGW_GraphQL\Extension\VirtualHostRef;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

// This plugin's own business logic. Loaded here rather than assumed: nothing
// on the GraphQL request path has already required it the way the client and
// reseller pages do for themselves.
require_once __DIR__ . '/../frontend/common.php';

/**
 * Adds the Apache disk cache's settings to SGW_GraphQL's API: a field on
 * Domain, Subdomain and DomainAlias to read them, and two mutations to change
 * them - matching, and reusing, exactly what the client pages already do.
 *
 * Every rule here is the frontend's own, called through \SGW_ApacheCache's
 * functions rather than re-implemented: mergeSettings()/clampSettings() for
 * the bounds, validateSettings() for "max_expire >= default_expire",
 * writeSettings() for the update, applyAction() for purge, and
 * SGW_ApacheCache::customerHasApacheCache() for the reseller's per-customer
 * permission. A reseller's own allow/withdraw action is not part of this API;
 * see the plugin's README for that gap.
 */
final class ApacheCacheExtension implements Extension
{
    /** Every GraphQL field this extension serves, in the frontend's own column names. */
    const FIELDS = array(
        'enabled'          => 'enabled',
        'wordpressMode'    => 'wordpress_mode',
        'staticExpires'    => 'static_expires',
        'debugHeaders'     => 'debug_headers',
        'ignoreNoLastmod'  => 'ignore_no_lastmod',
        'defaultExpire'    => 'default_expire',
        'maxExpire'        => 'max_expire',
        'maxFileSize'      => 'max_file_size',
        'bypassCookies'    => 'bypass_cookies',
        'bypassPaths'      => 'bypass_paths',
        'denyPaths'        => 'deny_paths'
    );

    public function getName(): string
    {
        return 'SGW_ApacheCache';
    }

    public function getSdl(): string
    {
        return '
            """
            Apache disk cache settings for one vhost, exactly as SGW_ApacheCache
            stores them. A vhost the plugin has never been asked to configure has
            no row of its own; this reads as the defaults it would be given,
            rather than null, so a client never has to special-case "not set up
            yet" - which is indistinguishable from "set up with the defaults".
            """
            type ApacheCacheSetting {
              "Whether the cache is turned on for this vhost."
              enabled: Boolean!
              "Keeps signed-in visitors, comment authors and shopping carts out of the cache."
              wordpressMode: Boolean!
              "Gives static files (images, fonts, CSS, JavaScript) a browser lifetime."
              staticExpires: Boolean!
              "Sends X-Cache and X-Imscp-Bypass diagnostic headers on every response."
              debugHeaders: Boolean!
              "Caches pages that carry no freshness information of their own, such as WordPress HTML."
              ignoreNoLastmod: Boolean!
              "How long a page with no expiry of its own is kept, in seconds."
              defaultExpire: Int!
              "The longest a page may ever be kept, in seconds."
              maxExpire: Int!
              "The largest response the cache will store, in bytes."
              maxFileSize: Int!
              "Cookie name prefixes that bypass the cache, one per line."
              bypassCookies: String!
              "URL path prefixes never cached, one per line."
              bypassPaths: String!
              "URL path fragments refused outright with 403, comma separated."
              denyPaths: String!
              "The plugin\'s own row for this vhost, so a client can poll until it settles."
              provisioning: Provisioning!
            }

            extend type Domain { apacheCache: ApacheCacheSetting! }
            extend type Subdomain { apacheCache: ApacheCacheSetting! }
            extend type DomainAlias { apacheCache: ApacheCacheSetting! }

            """
            Every field but id is optional. An omitted field leaves that setting
            unchanged, so flipping the cache on or off needs only enabled.
            """
            input ApacheCacheUpdateInput {
              "A Domain, Subdomain or DomainAlias."
              id: ID!
              enabled: Boolean
              wordpressMode: Boolean
              staticExpires: Boolean
              debugHeaders: Boolean
              ignoreNoLastmod: Boolean
              defaultExpire: Int
              maxExpire: Int
              maxFileSize: Int
              bypassCookies: String
              bypassPaths: String
              denyPaths: String
            }

            extend type Mutation {
              apacheCacheUpdate(input: ApacheCacheUpdateInput!): VirtualHost!
              "Empty the cache for this vhost, without changing any other setting."
              apacheCachePurge(id: ID!): VirtualHost!
            }
        ';
    }

    public function getResolvers(ExtensionContext $context): array
    {
        $read = static function ($source, array $args, $ctx) use ($context) {
            $context->requireScope($ctx, Scope::DOMAINS_READ);
            $vhost = $context->virtualHost($source);

            return $context->loader()->keyed(
                'SGW_ApacheCache:apache_cache',
                $vhost->getKind() . ':' . $vhost->getKey(),
                static function (array $keys) use ($context) {
                    $where = array();
                    $bind = array();

                    foreach ($keys as $key) {
                        list($kind, $id) = explode(':', $key, 2);
                        $where[] = '(domain_type = ? AND domain_id = ?)';
                        $bind[] = $kind;
                        $bind[] = (int)$id;
                    }

                    $found = array();
                    foreach ($context->db()->rows(
                        'SELECT * FROM apache_cache WHERE ' . implode(' OR ', $where),
                        $bind
                    ) as $row) {
                        $found[$row['domain_type'] . ':' . $row['domain_id']] = $row;
                    }

                    return $found;
                }
            )->then(static function ($row) {
                return self::present($row);
            });
        };

        return array(
            'Domain.apacheCache'      => $read,
            'Subdomain.apacheCache'   => $read,
            'DomainAlias.apacheCache' => $read,

            'Mutation.apacheCacheUpdate' => static function ($source, array $args, $ctx) use ($context) {
                $input = (array)$args['input'];

                $vhost = $context->targetVirtualHost(
                    $context->identity($ctx), $input['id'] ?? null, Scope::DOMAINS_WRITE, 'input.id'
                );

                Guard::requireFeature(
                    SGW_ApacheCache::customerHasApacheCache($vhost->getOwnerId()), 'apacheCache'
                );

                $domain = self::domainRow($vhost);

                // The frontend's own busy check (isSettled()): a null status
                // means no row exists yet, which is settled by definition.
                Guard::requireState((string)($domain['status'] ?? 'disabled'), array(
                    Provisioning::STATE_OK, Provisioning::STATE_DISABLED, Provisioning::STATE_ERROR
                ));

                $row = \SGW_ApacheCache\getOrCreateRow($domain, $vhost->getOwnerId());

                $overrides = array();
                foreach (self::FIELDS as $gqlField => $column) {
                    if (array_key_exists($gqlField, $input) && $input[$gqlField] !== null) {
                        $overrides[$column] = $input[$gqlField];
                    }
                }

                $settings = \SGW_ApacheCache\mergeSettings($overrides, $row);

                $error = \SGW_ApacheCache\validateSettings($settings);
                if ($error !== null) {
                    throw Guard::badInput('input', $error);
                }

                \SGW_ApacheCache\writeSettings($row, $settings);

                $context->core()->sendRequest();
                $context->loader()->reset();

                return $context->virtualHostReference($vhost);
            },

            'Mutation.apacheCachePurge' => static function ($source, array $args, $ctx) use ($context) {
                $vhost = $context->targetVirtualHost(
                    $context->identity($ctx), $args['id'] ?? null, Scope::DOMAINS_WRITE, 'id'
                );

                Guard::requireFeature(
                    SGW_ApacheCache::customerHasApacheCache($vhost->getOwnerId()), 'apacheCache'
                );

                $domain = self::domainRow($vhost);

                Guard::requireState((string)($domain['status'] ?? 'disabled'), array(
                    Provisioning::STATE_OK, Provisioning::STATE_DISABLED, Provisioning::STATE_ERROR
                ));

                $row = \SGW_ApacheCache\getOrCreateRow($domain, $vhost->getOwnerId());
                \SGW_ApacheCache\applyAction($row, 'purge');

                $context->core()->sendRequest();
                $context->loader()->reset();

                return $context->virtualHostReference($vhost);
            }
        );
    }

    public function getComplexity(): array
    {
        // No list fields, so nothing to charge beyond the default.
        return array();
    }

    /**
     * The vhost as \SGW_ApacheCache\getDomain() sees it - the shape its own
     * functions (getOrCreateRow(), and the "is this settled" question) expect.
     *
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException NOT_FOUND on the
     *         inconsistency of targetVirtualHost() having already vouched for
     *         this vhost while the frontend's own tables disagree.
     */
    private static function domainRow(VirtualHostRef $vhost): array
    {
        $domain = \SGW_ApacheCache\getDomain($vhost->getOwnerId(), $vhost->getKind(), $vhost->getKey());

        if ($domain === false) {
            throw Guard::notFound();
        }

        return $domain;
    }

    /**
     * A cache row, or null for a vhost with none yet, as the API's shape.
     *
     * @param array|null $row As apache_cache stores it
     * @return array
     */
    private static function present(?array $row): array
    {
        $settings = $row === null ? \SGW_ApacheCache\defaults() : $row;

        return array(
            'enabled'         => (bool)$settings['enabled'],
            'wordpressMode'   => (bool)$settings['wordpress_mode'],
            'staticExpires'   => (bool)$settings['static_expires'],
            'debugHeaders'    => (bool)$settings['debug_headers'],
            'ignoreNoLastmod' => (bool)$settings['ignore_no_lastmod'],
            'defaultExpire'   => (int)$settings['default_expire'],
            'maxExpire'       => (int)$settings['max_expire'],
            'maxFileSize'     => (int)$settings['max_file_size'],
            'bypassCookies'   => (string)$settings['bypass_cookies'],
            'bypassPaths'     => (string)$settings['bypass_paths'],
            'denyPaths'       => (string)$settings['deny_paths'],
            'provisioning'    => TypeResolver::provisioning($row === null ? null : $row['status'])
        );
    }
}
