<?php

namespace iMSCP\Database {
    class DatabaseMySQL
    {
        public static function getInstance()
        {
            return new self();
        }

        public function insertId()
        {
            return $GLOBALS['mock']['last_insert_id'];
        }
    }
}

namespace {
    use SGW_ApacheCache as Cache;

    class FakeStatement
    {
        private array $rows;

        public function __construct(array $rows)
        {
            $this->rows = array_values($rows);
        }

        public function fetchAll($mode = null)
        {
            return $this->rows;
        }

        public function fetch($mode = null)
        {
            return $this->rows[0] ?? false;
        }

        public function fetchRow($mode = null)
        {
            return $this->rows[0] ?? false;
        }
    }

    function resetMock(array $lockStatuses)
    {
        $GLOBALS['mock'] = array(
            'domains' => array(
                array(
                    'domain_type' => 'dmn',
                    'domain_id' => 1,
                    'domain_name' => 'example.com',
                    'status' => 'ok',
                    'apache_cache_id' => 11,
                    'enabled' => 1
                ),
                array(
                    'domain_type' => 'sub',
                    'domain_id' => 2,
                    'domain_name' => 'www.example.com',
                    'status' => 'ok',
                    'apache_cache_id' => 12,
                    'enabled' => 1
                )
            ),
            'apache_cache' => array(
                11 => array('apache_cache_id' => 11, 'enabled' => 1, 'status' => 'ok'),
                12 => array('apache_cache_id' => 12, 'enabled' => 1, 'status' => 'ok')
            ),
            'apache_cache_perm' => array(
                99 => array('admin_id' => 99, 'allowed' => 1)
            ),
            'queries' => array(),
            'in_transaction' => false,
            'pending' => array(),
            'lock_statuses' => array_values($lockStatuses),
            'lock_calls' => 0,
            'last_insert_id' => 1000
        );
    }

    function applyPending()
    {
        foreach ($GLOBALS['mock']['pending'] as $change) {
            switch ($change['table']) {
                case 'apache_cache':
                    $GLOBALS['mock']['apache_cache'][$change['id']]['enabled'] = $change['enabled'];
                    $GLOBALS['mock']['apache_cache'][$change['id']]['status'] = $change['status'];
                    break;

                case 'apache_cache_perm':
                    $GLOBALS['mock']['apache_cache_perm'][$change['admin_id']]['allowed'] = $change['allowed'];
                    break;
            }
        }

        $GLOBALS['mock']['pending'] = array();
    }

    function rollbackPending()
    {
        $GLOBALS['mock']['pending'] = array();
    }

    function exec_query($sql, array $params = array())
    {
        $GLOBALS['mock']['queries'][] = array($sql, $params);

        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        if (stripos($normalized, 'SELECT v.*, c.apache_cache_id') === 0) {
            $rows = array();
            foreach ($GLOBALS['mock']['domains'] as $domain) {
                $domain['allowed'] = 1;
                $domain['wordpress_mode'] = 1;
                $domain['state'] = '';
                $rows[] = $domain;
            }

            return new FakeStatement($rows);
        }

        if (stripos($normalized, 'SELECT apache_cache_id, status FROM apache_cache WHERE domain_type = ? AND domain_id = ? FOR UPDATE') === 0) {
            $index = $GLOBALS['mock']['lock_calls']++;
            $status = $GLOBALS['mock']['lock_statuses'][$index] ?? 'ok';
            $domainType = $params[0];
            $domainId = $params[1];

            foreach ($GLOBALS['mock']['domains'] as $domain) {
                if ($domain['domain_type'] === $domainType && (int)$domain['domain_id'] === (int)$domainId) {
                    return new FakeStatement(array(
                        array(
                            'apache_cache_id' => $domain['apache_cache_id'],
                            'status' => $status
                        )
                    ));
                }
            }

            return new FakeStatement(array());
        }

        if (stripos($normalized, 'SELECT * FROM apache_cache WHERE apache_cache_id = ?') === 0) {
            $id = $params[0];
            return new FakeStatement(array($GLOBALS['mock']['apache_cache'][$id] ?? array()));
        }

        if (stripos($normalized, 'START TRANSACTION') === 0) {
            $GLOBALS['mock']['in_transaction'] = true;
            return new FakeStatement(array());
        }

        if (stripos($normalized, 'ROLLBACK') === 0) {
            rollbackPending();
            $GLOBALS['mock']['in_transaction'] = false;
            return new FakeStatement(array());
        }

        if (stripos($normalized, 'COMMIT') === 0) {
            applyPending();
            $GLOBALS['mock']['in_transaction'] = false;
            return new FakeStatement(array());
        }

        if (stripos($normalized, 'UPDATE apache_cache SET enabled = ?, status = ? WHERE apache_cache_id = ?') === 0) {
            $change = array(
                'table' => 'apache_cache',
                'id' => $params[2],
                'enabled' => $params[0],
                'status' => $params[1]
            );

            if ($GLOBALS['mock']['in_transaction']) {
                $GLOBALS['mock']['pending'][] = $change;
            } else {
                $GLOBALS['mock']['apache_cache'][$change['id']]['enabled'] = $change['enabled'];
                $GLOBALS['mock']['apache_cache'][$change['id']]['status'] = $change['status'];
            }

            return new FakeStatement(array());
        }

        if (stripos($normalized, 'INSERT INTO apache_cache_perm') === 0) {
            $change = array(
                'table' => 'apache_cache_perm',
                'admin_id' => $params[0],
                'allowed' => $params[1]
            );

            if ($GLOBALS['mock']['in_transaction']) {
                $GLOBALS['mock']['pending'][] = $change;
            } else {
                $GLOBALS['mock']['apache_cache_perm'][$change['admin_id']]['allowed'] = $change['allowed'];
            }

            return new FakeStatement(array());
        }

        if (stripos($normalized, 'INSERT INTO apache_cache (') === 0) {
            $row = array(
                'apache_cache_id' => $GLOBALS['mock']['last_insert_id']++,
                'admin_id' => $params[0],
                'domain_type' => $params[1],
                'domain_id' => $params[2],
                'domain_name' => $params[3],
                'enabled' => $params[4],
                'status' => $params[15]
            );
            $GLOBALS['mock']['apache_cache'][$row['apache_cache_id']] = $row;
            return new FakeStatement(array());
        }

        return new FakeStatement(array());
    }

    function expect($condition, $message)
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    require_once __DIR__ . '/../../frontend/common.php';

    resetMock(array('ok', 'todisable'));

    $result = Cache\withdrawCustomer(99);

    expect($result === false, 'withdraw should fail when a customer becomes unsettled');
    expect($GLOBALS['mock']['apache_cache_perm'][99]['allowed'] === 1, 'permission must stay allowed on failure');
    expect($GLOBALS['mock']['apache_cache'][11]['status'] === 'ok', 'first domain change must be rolled back');
    expect($GLOBALS['mock']['apache_cache'][12]['status'] === 'ok', 'second domain must not be changed');
    expect(in_array('ROLLBACK', array_map(fn($entry) => $entry[0], $GLOBALS['mock']['queries']), true), 'rollback must be issued');
    expect(!in_array('COMMIT', array_map(fn($entry) => $entry[0], $GLOBALS['mock']['queries']), true), 'commit must not be issued on failure');

    resetMock(array('ok', 'ok'));

    $result = Cache\withdrawCustomer(99);

    expect($result === 2, 'withdraw should disable every settled domain');
    expect($GLOBALS['mock']['apache_cache_perm'][99]['allowed'] === 0, 'permission must be revoked after a full withdraw');
    expect($GLOBALS['mock']['apache_cache'][11]['status'] === 'todisable', 'first domain must be queued for disable');
    expect($GLOBALS['mock']['apache_cache'][12]['status'] === 'todisable', 'second domain must be queued for disable');
    expect($GLOBALS['mock']['apache_cache'][11]['enabled'] === 0, 'first domain must be disabled');
    expect($GLOBALS['mock']['apache_cache'][12]['enabled'] === 0, 'second domain must be disabled');
    expect(in_array('COMMIT', array_map(fn($entry) => $entry[0], $GLOBALS['mock']['queries']), true), 'commit must be issued on success');

    exit(0);
}
