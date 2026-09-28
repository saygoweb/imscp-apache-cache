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

use GraphQL\Type\Schema;
use iMSCP\Plugin\SGW_ApacheCache\GraphQL\ApacheCacheExtension;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionRegistry;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * The extension against the seeded fixture: reads on each vhost kind and
 * batching, both mutations' happy paths across the three roles that may
 * write, and the authorisation and validation refusals spec section 8.1
 * asks every mutation for.
 */
class ApacheCacheExtensionTest extends ApacheCacheTestCase
{
    private function container(): Container
    {
        $registry = new ExtensionRegistry();
        $registry->register(new ApacheCacheExtension());

        return Container::forTesting(
            // SGW_GraphQL's own directory, which holds schema/schema.graphql -
            // not this plugin's. The same path bootstrap.php requires its
            // autoload from.
            '/var/www/imscp-plugins/imscp-graphql',
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db,
            (array)\iMSCP\Registry::get('config'),
            $this->core,
            $this->probe,
            $this->sqlServer,
            null,
            null,
            $registry
        );
    }

    protected function schema(): Schema
    {
        return $this->container()->schemaFactory()->create();
    }

    /**
     * A cache row, inserted directly - the shape an already-configured vhost
     * would have, without going through the mutation under test.
     *
     * @return int apache_cache_id
     */
    private function insertRow(string $kind, int $id, int $adminId, string $domainName, array $overrides = array()): int
    {
        $row = array_merge(
            \SGW_ApacheCache\defaults(),
            array('admin_id' => $adminId, 'domain_type' => $kind, 'domain_id' => $id, 'domain_name' => $domainName),
            $overrides
        );

        $columns = array(
            'admin_id', 'domain_type', 'domain_id', 'domain_name', 'enabled', 'wordpress_mode',
            'static_expires', 'debug_headers', 'ignore_no_lastmod', 'default_expire', 'max_expire',
            'max_file_size', 'bypass_cookies', 'bypass_paths', 'deny_paths', 'status', 'state'
        );

        $values = array();
        foreach ($columns as $column) {
            $values[] = $row[$column];
        }

        $statement = $this->db->pdo()->prepare(
            'INSERT INTO apache_cache (`' . implode('`, `', $columns) . '`) VALUES ('
                . $this->db->placeholders(count($columns)) . ')'
        );
        $statement->execute($values);

        return (int)$this->db->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    private function rowFor(string $kind, int $id): ?array
    {
        return $this->db->row(
            'SELECT * FROM apache_cache WHERE domain_type = ? AND domain_id = ?', array($kind, $id)
        );
    }

    private function readSetting(string $tag, int $key, string $who = 'customer', array $scopes = array()): array
    {
        $result = $this->execute(
            'query($id: ID!) { node(id: $id) {
                ... on Domain { apacheCache { enabled denyPaths defaultExpire provisioning { state } } }
                ... on Subdomain { apacheCache { enabled denyPaths defaultExpire provisioning { state } } }
                ... on DomainAlias { apacheCache { enabled denyPaths defaultExpire provisioning { state } } }
            } }',
            array('id' => GlobalId::encode($tag, $key)),
            $this->fixture->identity($who, $scopes)
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));

        return $result['data']['node']['apacheCache'];
    }

    private function update(array $input, string $who = 'customer', array $scopes = array()): array
    {
        return $this->execute(
            'mutation($input: ApacheCacheUpdateInput!) {
                apacheCacheUpdate(input: $input) { name provisioning { state } }
            }',
            array('input' => $input),
            $this->fixture->identity($who, $scopes)
        );
    }

    private function purge(string $id, string $who = 'customer', array $scopes = array()): array
    {
        return $this->execute(
            'mutation($id: ID!) { apacheCachePurge(id: $id) { name } }',
            array('id' => $id),
            $this->fixture->identity($who, $scopes)
        );
    }

    public function testANeverConfiguredVhostReadsAsTheDefaultsNotNull(): void
    {
        $setting = $this->readSetting(NodeType::DOMAIN, $this->fixture->domainId());

        self::assertFalse($setting['enabled']);
        self::assertSame('xmlrpc.php', $setting['denyPaths']);
        self::assertSame('DISABLED', $setting['provisioning']['state']);
    }

    public function testEachVhostKindReadsItsOwnRow(): void
    {
        $this->insertRow(
            'sub', $this->fixture->subdomainId(), $this->fixture->customerId(),
            $this->fixture->subdomainName(), array('enabled' => 1, 'status' => 'ok', 'default_expire' => 900)
        );
        self::assertSame(
            array('enabled' => true, 'default_expire' => 900, 'state' => 'OK'),
            $this->extract($this->readSetting(NodeType::SUBDOMAIN, $this->fixture->subdomainId()))
        );

        $this->insertRow(
            'als', $this->fixture->aliasId(), $this->fixture->customerId(),
            $this->fixture->aliasName(), array('enabled' => 0, 'status' => 'disabled')
        );
        self::assertSame(
            array('enabled' => false, 'default_expire' => 86400, 'state' => 'DISABLED'),
            $this->extract($this->readSetting(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()))
        );

        // An alias subdomain is the schema's Subdomain, same as a real one.
        $this->insertRow(
            'alssub', $this->fixture->aliasSubdomainId(), $this->fixture->customerId(),
            'blog.' . $this->fixture->aliasName(), array('enabled' => 1, 'status' => 'toenable')
        );
        self::assertSame(
            array('enabled' => true, 'default_expire' => 86400, 'state' => 'PENDING'),
            $this->extract($this->readSetting(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()))
        );
    }

    private function extract(array $setting): array
    {
        return array(
            'enabled'        => $setting['enabled'],
            'default_expire' => $setting['defaultExpire'],
            'state'          => $setting['provisioning']['state']
        );
    }

    public function testAReadAsksForItsScope(): void
    {
        $result = $this->execute(
            'query($id: ID!) { node(id: $id) { ... on Domain { apacheCache { enabled } } } }',
            array('id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId())),
            // DOMAINS_WRITE admits the object but not this field, which asks
            // for DOMAINS_READ.
            $this->fixture->identity('customer', array(Scope::DOMAINS_WRITE))
        );

        self::assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'], json_encode($result));
    }

    /**
     * Reading a domain's own field, its subdomains' and its aliases' - three
     * vhosts of two different kinds - in one document costs the same as
     * reading just the domain's, because the bucket is keyed and flushed once
     * per level rather than once per vhost. An N+1 would cost one query more
     * per extra vhost, and this fixture only has one of each, so a second
     * subdomain is added directly to make the difference visible.
     */
    public function testApacheCacheIsBatchedAcrossVhosts(): void
    {
        $one = $this->countQueriesReadingSubdomains();

        $extra = $this->db->row(
            'SELECT * FROM subdomain WHERE subdomain_id = ?', array($this->fixture->subdomainId())
        );
        unset($extra['subdomain_id']);
        $extra['subdomain_name'] = 'blog';
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO subdomain (`' . implode('`, `', array_keys($extra)) . '`) VALUES ('
                . $this->db->placeholders(count($extra)) . ')'
        );
        $statement->execute(array_values($extra));

        $two = $this->countQueriesReadingSubdomains();

        self::assertSame(
            $one, $two,
            'Reading apacheCache for a second subdomain cost more queries; '
                . 'the field is being resolved per vhost instead of per level.'
        );
    }

    private function countQueriesReadingSubdomains(): int
    {
        $schema = $this->schema();
        $document = 'query($id: ID!) { node(id: $id) {
            ... on Domain { subdomains { apacheCache { enabled } } }
        } }';
        $variables = array('id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()));
        $context = array('identity' => $this->fixture->identity('customer'));
        $response = null;

        $count = $this->db->countQueries(function () use ($schema, $document, $variables, $context, &$response) {
            $response = \GraphQL\GraphQL::executeQuery($schema, $document, null, $context, $variables)->toArray();
        });

        self::assertArrayNotHasKey('errors', $response, json_encode($response));

        return $count;
    }

    /**
     * @dataProvider mayWrite
     */
    public function testTheOwnerTheirResellerAndTheAdministratorMayUpdateIt(string $who): void
    {
        $result = $this->update(array(
            'id' => GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
            'enabled' => true, 'defaultExpire' => 120, 'maxExpire' => 3600
        ), $who);

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame($this->fixture->subdomainName(), $result['data']['apacheCacheUpdate']['name']);
        self::assertSame(1, $this->core->requests);

        $row = $this->rowFor('sub', $this->fixture->subdomainId());
        self::assertNotNull($row);
        self::assertSame('1', $row['enabled']);
        self::assertSame('120', $row['default_expire']);
        self::assertSame('3600', $row['max_expire']);
        self::assertSame('tochange', $row['status']);
    }

    public function mayWrite(): array
    {
        return array('customer' => array('customer'), 'reseller' => array('reseller'), 'admin' => array('admin'));
    }

    public function testAnUpdateOnlyTouchesTheFieldsItSends(): void
    {
        $this->insertRow(
            'sub', $this->fixture->subdomainId(), $this->fixture->customerId(), $this->fixture->subdomainName(),
            array('enabled' => 0, 'bypass_paths' => '/my-account', 'default_expire' => 500)
        );

        $result = $this->update(array(
            'id' => GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()), 'enabled' => true
        ));
        self::assertArrayNotHasKey('errors', $result, json_encode($result));

        $row = $this->rowFor('sub', $this->fixture->subdomainId());
        self::assertSame('1', $row['enabled']);
        // Untouched fields survive the partial update.
        self::assertSame('/my-account', $row['bypass_paths']);
        self::assertSame('500', $row['default_expire']);
    }

    public function testPurgeSchedulesTheCacheToBeEmptiedWithoutChangingSettings(): void
    {
        $this->insertRow(
            'dmn', $this->fixture->domainId(), $this->fixture->customerId(), $this->fixture->domainName(),
            array('enabled' => 1, 'status' => 'ok')
        );

        $result = $this->purge(GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()));

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame($this->fixture->domainName(), $result['data']['apacheCachePurge']['name']);
        self::assertSame(1, $this->core->requests);

        $row = $this->rowFor('dmn', $this->fixture->domainId());
        self::assertSame('topurge', $row['status']);
        self::assertSame('1', $row['enabled']);
    }

    /**
     * @dataProvider mayNotReach
     */
    public function testAnybodyElseIsToldTheVhostDoesNotExist(string $who): void
    {
        $result = $this->update(array(
            'id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()), 'enabled' => true
        ), $who);

        self::assertSame('NOT_FOUND', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame(0, $this->core->requests);
    }

    public function mayNotReach(): array
    {
        return array(
            'sibling'       => array('sibling'),
            'otherCustomer' => array('otherCustomer'),
            'otherReseller' => array('otherReseller')
        );
    }

    public function testAReadOnlyCredentialMayNotWrite(): void
    {
        $result = $this->update(array(
            'id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()), 'enabled' => true
        ), 'customer', array(Scope::DOMAINS_READ));

        self::assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame(0, $this->core->requests);
    }

    public function testAPendingRowRefusesTheWriteWithConflict(): void
    {
        $this->insertRow(
            'dmn', $this->fixture->domainId(), $this->fixture->customerId(), $this->fixture->domainName(),
            array('status' => 'toenable')
        );

        $result = $this->update(array(
            'id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()), 'enabled' => true
        ));

        self::assertSame('CONFLICT', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame(0, $this->core->requests);
    }

    public function testAFeatureGateRefusalWhenTheCustomerIsWithheldTheFeature(): void
    {
        $this->db->pdo()->prepare('INSERT INTO apache_cache_perm (admin_id, allowed) VALUES (?, 0)')
            ->execute(array($this->fixture->customerId()));

        $result = $this->update(array(
            'id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()), 'enabled' => true
        ));

        self::assertSame('FEATURE_UNAVAILABLE', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame(0, $this->core->requests);
    }

    public function testAMaximumShorterThanTheDefaultIsBadInput(): void
    {
        $result = $this->update(array(
            'id' => GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
            'defaultExpire' => 3600, 'maxExpire' => 120
        ));

        self::assertSame('BAD_USER_INPUT', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame('input', $result['errors'][0]['extensions']['field']);
        self::assertSame(0, $this->core->requests);
    }

    public function testTheExtensionIsKeptAndLogsNothing(): void
    {
        $container = $this->container();
        $container->schemaFactory()->create();

        $names = array();
        foreach ($container->extensions() as $loaded) {
            $names[] = $loaded->getName();
        }

        self::assertContains('SGW_ApacheCache', $names);
        self::assertSame(array(), $this->core->logs, 'The extension logged something while loading.');
    }
}
