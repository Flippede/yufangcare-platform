<?php

use crmeb\services\CacheService;
use crmeb\utils\JwtAuth;
use think\App;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = new class() extends App {
        public function loadEnv(string $envName = ''): void
        {
            parent::loadEnv($envName);
            foreach ([
                'YFTH_REAL_FLOW_DB_HOSTNAME' => 'database.hostname',
                'YFTH_REAL_FLOW_DB_HOSTPORT' => 'database.hostport',
                'YFTH_REAL_FLOW_DB_USERNAME' => 'database.username',
                'YFTH_REAL_FLOW_DB_PASSWORD' => 'database.password',
                'YFTH_REAL_FLOW_DB_DATABASE' => 'database.database',
                'YFTH_REAL_FLOW_DB_PREFIX' => 'database.prefix',
                'YFTH_REAL_FLOW_DB_CHARSET' => 'database.charset',
                'YFTH_REAL_FLOW_CACHE_DRIVER' => 'cache.driver',
                'YFTH_REAL_FLOW_CACHE_PREFIX' => 'cache.cache_prefix',
                'YFTH_REAL_FLOW_REDIS_HOSTNAME' => 'redis.redis_hostname',
                'YFTH_REAL_FLOW_REDIS_PORT' => 'redis.port',
                'YFTH_REAL_FLOW_REDIS_PASSWORD' => 'redis.redis_password',
                'YFTH_REAL_FLOW_REDIS_SELECT' => 'redis.select',
            ] as $envKey => $configKey) {
                $value = getenv($envKey);
                if ($value !== false) {
                    $this->env->set($configKey, $value);
                }
            }
            if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') {
                $this->env->set('database.password', '');
            }
            if (getenv('YFTH_REAL_FLOW_CACHE_DRIVER') === false) {
                $this->env->set('cache.driver', 'file');
            }
        }
    };
    $app->initialize();
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] application_bootstrap_failed:' . $e->getMessage() . "\n");
    exit(1);
}

$failures = [];
$passes = [];
$notes = [];

$assert = function ($condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $message;
        return;
    }
    $failures[] = $message;
};

$executeFlow = (string)getenv('YFTH_FRANCHISE_CUSTOMER_REAL_FLOW_EXECUTE') === '1';
$mysqlVersion = 'not_executed';
$database = '';
$prefix = '';
$cacheDriver = 'not_executed';

if (!$executeFlow) {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_FRANCHISE_CUSTOMER_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
} else {
    $versionRow = fcQuery('SELECT VERSION() AS version');
    $mysqlVersion = (string)($versionRow[0]['version'] ?? '');
    $assert($mysqlVersion !== '', 'mysql_version_available');
    $assert(stripos($mysqlVersion, 'mariadb') === false, 'mysql_vendor_is_not_mariadb');
    $assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);

    $connection = Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $connection . '.database');
    $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');
    $cacheDriver = (string)Config::get('cache.default');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
    $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);

    try {
        fcEnsureRuntimeTables($prefix);
        fcAssertSchema($assert, $database, $prefix);
        fcRunHttpFlow($assert, $notes);
    } catch (Throwable $e) {
        $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
    }
}

foreach ($notes as $note) {
    echo "[NOTE] {$note}\n";
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

foreach ($passes as $pass) {
    echo "[PASS] {$pass}\n";
}
if ($executeFlow) {
    echo "[OK] YFTH franchise customer real flow verified on MySQL {$mysqlVersion} with cache driver {$cacheDriver}.\n";
} else {
    echo "[OK] YFTH franchise customer source checks passed; real HTTP/MySQL flow skipped.\n";
}

function fcRunHttpFlow(callable $assert, array &$notes): void
{
    $runId = fcRunId();
    $server = fcMaybeStartServer($notes);
    $baseUrl = (string)($server['base_url'] ?? getenv('YFTH_FRANCHISE_CUSTOMER_API_BASE') ?: 'http://127.0.0.1:18121');
    $notes[] = 'real_flow_run_id:' . $runId;
    $notes[] = 'local_api_base:' . $baseUrl;

    try {
        $fixture = fcSeedFixture($runId);
        $tokens = [];
        foreach ($fixture['users'] as $key => $uid) {
            $tokens[$key] = fcCreateApiToken($uid);
        }
        $notes[] = 'temporary_user_tokens_created_for_roles:customer,mentor,staff_a,manager_a,franchisee,staff_b';

        fcExpectHttpFailure(
            fcPost($baseUrl, $tokens['staff_a'], 'relation', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['uid' => $fixture['users']['customer_uid_only'], 'customer_status' => 'potential']),
            'naked_uid_binding_forbidden',
            $assert
        );
        $assert(fcCount('yfth_customer_relation', ['uid' => $fixture['users']['customer_uid_only']]) === 0, 'naked_uid_binding_creates_no_relation');

        fcExpectHttpFailure(
            fcPost($baseUrl, $tokens['staff_a'], 'relation', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['source' => 'order', 'reference_id' => $fixture['orders']['staff_allowed'], 'store_id' => $fixture['stores']['A']]),
            'direct_store_id_body_binding_forbidden',
            $assert
        );
        $assert(fcCount('yfth_customer_relation', ['uid' => $fixture['users']['customer_staff_allowed']]) === 0, 'direct_store_id_body_creates_no_relation');

        fcExpectHttpFailure(
            fcPost($baseUrl, $tokens['customer'], 'relation', ['role_code' => 'customer'], ['source' => 'order', 'reference_id' => $fixture['orders']['staff_allowed']]),
            'plain_customer_forbidden',
            $assert
        );
        fcExpectHttpFailure(
            fcPost($baseUrl, $tokens['mentor'], 'relation', ['role_code' => 'service_mentor'], ['source' => 'order', 'reference_id' => $fixture['orders']['staff_allowed']]),
            'service_mentor_forbidden',
            $assert
        );

        $staffBind = fcExpectHttpOk(
            fcPost($baseUrl, $tokens['staff_a'], 'relation', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['source' => 'order', 'reference_id' => $fixture['orders']['staff_allowed']]),
            'store_staff_same_store_order_bind_ok',
            $assert
        );
        $staffRelationId = (int)($staffBind['data']['relation']['id'] ?? 0);
        $staffRelation = fcFindOne('yfth_customer_relation', ['id' => $staffRelationId]);
        $assert((int)($staffRelation['store_id'] ?? 0) === $fixture['stores']['A'], 'order_relation_store_id_is_store_a');
        $assert((string)($staffRelation['source'] ?? '') === 'order', 'order_relation_source_recorded');
        $assert((int)($staffRelation['reference_id'] ?? 0) === $fixture['orders']['staff_allowed'], 'order_relation_reference_recorded');
        $assert(fcCount('yfth_audit_event', ['business_domain' => 'yfth_franchise_customer', 'object_type' => 'customer_relation', 'object_id' => (string)$staffRelationId, 'action' => 'bind']) === 1, 'order_bind_audit_recorded');
        fcAssertCustomerDtoSafe($staffBind['data']['relation'] ?? [], $assert, 'staff_bind_relation_dto');

        fcExpectHttpFailure(
            fcPost($baseUrl, $tokens['staff_a'], 'relation', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['source' => 'order', 'reference_id' => $fixture['orders']['store_b_order']]),
            'cross_store_order_forbidden',
            $assert
        );
        $assert(fcCount('yfth_customer_relation', ['uid' => $fixture['users']['customer_cross_order']]) === 0, 'cross_store_order_creates_no_relation');

        $managerBind = fcExpectHttpOk(
            fcPost($baseUrl, $tokens['manager_a'], 'relation', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['source' => 'appointment', 'reference_id' => $fixture['appointments']['manager_allowed']]),
            'store_manager_same_store_appointment_bind_ok',
            $assert
        );
        $managerRelationId = (int)($managerBind['data']['relation']['id'] ?? 0);
        $managerRelation = fcFindOne('yfth_customer_relation', ['id' => $managerRelationId]);
        $assert((string)($managerRelation['source'] ?? '') === 'appointment', 'appointment_relation_source_recorded');
        $assert((int)($managerRelation['reference_id'] ?? 0) === $fixture['appointments']['manager_allowed'], 'appointment_relation_reference_recorded');

        fcExpectHttpFailure(
            fcPost($baseUrl, $tokens['staff_a'], 'relation', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['source' => 'appointment', 'reference_id' => $fixture['appointments']['store_b_appointment']]),
            'cross_store_appointment_forbidden',
            $assert
        );
        $assert(fcCount('yfth_customer_relation', ['uid' => $fixture['users']['customer_cross_appointment']]) === 0, 'cross_store_appointment_creates_no_relation');

        fcExpectHttpFailure(
            fcPost($baseUrl, $tokens['staff_b'], 'relation', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['B']], ['source' => 'order', 'reference_id' => $fixture['orders']['already_bound_store_b']]),
            'already_bound_customer_cannot_be_taken_by_store_b',
            $assert
        );
        $assert(fcCount('yfth_customer_relation', ['uid' => $fixture['users']['customer_staff_allowed'], 'store_id' => $fixture['stores']['B']]) === 0, 'already_bound_store_b_creates_no_relation');

        $franchiseeBind = fcExpectHttpOk(
            fcPost($baseUrl, $tokens['franchisee'], 'relation', ['role_code' => 'franchisee', 'store_id' => $fixture['stores']['A']], ['source' => 'writeoff', 'reference_id' => $fixture['writeoffs']['franchisee_allowed']]),
            'franchisee_same_store_writeoff_bind_ok',
            $assert
        );
        $franchiseeRelation = fcFindOne('yfth_customer_relation', ['id' => (int)($franchiseeBind['data']['relation']['id'] ?? 0)]);
        $assert((string)($franchiseeRelation['source'] ?? '') === 'writeoff', 'writeoff_relation_source_recorded');

        fcAssertDuplicateActiveKeyRejected((int)$staffRelation['uid'], $fixture['stores']['B'], $assert);

        $list = fcExpectHttpOk(
            fcGet($baseUrl, $tokens['staff_a'], 'list', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A'], 'page' => 1, 'limit' => 20]),
            'customer_list_ok',
            $assert
        );
        $assert(count($list['data']['list'] ?? []) >= 3, 'customer_list_contains_store_a_relations');
        foreach (($list['data']['list'] ?? []) as $index => $row) {
            fcAssertCustomerDtoSafe($row, $assert, 'customer_list_row_' . $index);
        }

        $detail = fcExpectHttpOk(
            fcGet($baseUrl, $tokens['staff_a'], (string)$staffRelationId, ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]),
            'customer_detail_ok',
            $assert
        );
        fcAssertCustomerDtoSafe($detail['data']['customer'] ?? [], $assert, 'customer_detail_dto');
        foreach (($detail['data']['follow_records'] ?? []) as $index => $row) {
            fcAssertFollowDtoSafe($row, $assert, 'follow_row_' . $index);
        }
    } finally {
        fcCleanupRun($runId, $notes);
        fcStopServer($server, $notes);
    }
}

function fcAssertSchema(callable $assert, string $database, string $prefix): void
{
    foreach ([
        'user',
        'system_store',
        'store_order',
        'yfth_user_identity',
        'yfth_user_store_role',
        'yfth_business_subject',
        'yfth_store_subject',
        'yfth_store_qualification',
        'yfth_store_capability',
        'yfth_customer_relation',
        'yfth_customer_follow_record',
        'yfth_service_appointment',
        'yfth_service_writeoff_record',
        'yfth_package_instance',
        'yfth_audit_event',
    ] as $table) {
        $fullTable = $prefix . $table;
        $rows = fcQuery('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $fullTable]);
        $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'real_table_exists:' . $fullTable);
    }

    foreach ([
        [$prefix . 'yfth_customer_relation', 'reference_id'],
    ] as $column) {
        $rows = fcQuery('SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$database, $column[0], $column[1]]);
        $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'real_column_exists:' . $column[0] . '.' . $column[1]);
    }

    foreach ([
        [$prefix . 'yfth_customer_relation', 'uniq_yfth_customer_relation_active'],
        [$prefix . 'yfth_customer_relation', 'idx_yfth_customer_relation_source_ref'],
    ] as $index) {
        $rows = fcQuery('SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$database, $index[0], $index[1]]);
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

function fcEnsureRuntimeTables(string $prefix): void
{
    $t = function (string $name) use ($prefix): string {
        return '`' . str_replace('`', '``', $prefix . $name) . '`';
    };

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('user')} (
        `uid` int unsigned NOT NULL AUTO_INCREMENT,
        `account` varchar(64) NOT NULL DEFAULT '',
        `pwd` varchar(128) NOT NULL DEFAULT '',
        `real_name` varchar(64) NOT NULL DEFAULT '',
        `nickname` varchar(128) NOT NULL DEFAULT '',
        `avatar` varchar(255) NOT NULL DEFAULT '',
        `phone` varchar(32) NOT NULL DEFAULT '',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `last_time` int unsigned NOT NULL DEFAULT 0,
        `status` tinyint NOT NULL DEFAULT 1,
        `user_type` varchar(32) NOT NULL DEFAULT 'h5',
        `login_type` varchar(32) NOT NULL DEFAULT 'h5',
        `uniqid` varchar(64) NOT NULL DEFAULT '',
        `is_del` tinyint NOT NULL DEFAULT 0,
        PRIMARY KEY (`uid`),
        KEY `idx_user_status` (`status`, `is_del`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('system_store')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `name` varchar(128) NOT NULL DEFAULT '',
        `introduction` varchar(255) NOT NULL DEFAULT '',
        `phone` varchar(32) NOT NULL DEFAULT '',
        `address` varchar(255) NOT NULL DEFAULT '',
        `detailed_address` varchar(255) NOT NULL DEFAULT '',
        `image` varchar(255) NOT NULL DEFAULT '',
        `oblong_image` varchar(255) NOT NULL DEFAULT '',
        `latitude` varchar(32) NOT NULL DEFAULT '',
        `longitude` varchar(32) NOT NULL DEFAULT '',
        `valid_time` varchar(255) NOT NULL DEFAULT '',
        `day_time` varchar(255) NOT NULL DEFAULT '',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `is_show` tinyint NOT NULL DEFAULT 1,
        `is_del` tinyint NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('store_order')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `order_id` varchar(64) NOT NULL DEFAULT '',
        `uid` int unsigned NOT NULL DEFAULT 0,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `real_name` varchar(64) NOT NULL DEFAULT '',
        `user_phone` varchar(32) NOT NULL DEFAULT '',
        `user_address` varchar(255) NOT NULL DEFAULT '',
        `total_num` int unsigned NOT NULL DEFAULT 0,
        `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
        `pay_price` decimal(12,2) NOT NULL DEFAULT 0.00,
        `paid` tinyint NOT NULL DEFAULT 0,
        `pay_time` int unsigned NOT NULL DEFAULT 0,
        `pay_type` varchar(32) NOT NULL DEFAULT '',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `status` tinyint NOT NULL DEFAULT 0,
        `refund_status` tinyint NOT NULL DEFAULT 0,
        `pid` int unsigned NOT NULL DEFAULT 0,
        `is_del` tinyint NOT NULL DEFAULT 0,
        `is_system_del` tinyint NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_store_order_store` (`store_id`, `paid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_user_identity')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `uid` int unsigned NOT NULL DEFAULT 0,
        `role_code` varchar(32) NOT NULL DEFAULT '',
        `status` varchar(24) NOT NULL DEFAULT 'active',
        `source_type` varchar(48) NOT NULL DEFAULT '',
        `source_id` int unsigned NOT NULL DEFAULT 0,
        `effective_time` int unsigned NOT NULL DEFAULT 0,
        `expire_time` int unsigned NOT NULL DEFAULT 0,
        `active_key` varchar(191) DEFAULT NULL,
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_yfth_identity_active` (`active_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_user_store_role')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `uid` int unsigned NOT NULL DEFAULT 0,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `role_code` varchar(32) NOT NULL DEFAULT '',
        `permission_scope` text,
        `status` varchar(24) NOT NULL DEFAULT 'active',
        `start_time` int unsigned NOT NULL DEFAULT 0,
        `end_time` int unsigned NOT NULL DEFAULT 0,
        `creator_uid` int unsigned NOT NULL DEFAULT 0,
        `active_key` varchar(191) DEFAULT NULL,
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_yfth_user_store_role_active` (`active_key`),
        KEY `idx_yfth_user_store_role_uid` (`uid`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_business_subject')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `subject_type` varchar(32) NOT NULL DEFAULT '',
        `subject_name` varchar(128) NOT NULL DEFAULT '',
        `credit_code` varchar(64) NOT NULL DEFAULT '',
        `legal_person` varchar(64) NOT NULL DEFAULT '',
        `contact_name` varchar(64) NOT NULL DEFAULT '',
        `contact_phone` varchar(32) NOT NULL DEFAULT '',
        `registered_address` varchar(255) NOT NULL DEFAULT '',
        `status` varchar(24) NOT NULL DEFAULT 'active',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_store_subject')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `subject_id` int unsigned NOT NULL DEFAULT 0,
        `store_type` varchar(32) NOT NULL DEFAULT '',
        `subject_role` varchar(32) NOT NULL DEFAULT '',
        `status` varchar(24) NOT NULL DEFAULT 'active',
        `effective_time` int unsigned NOT NULL DEFAULT 0,
        `expire_time` int unsigned NOT NULL DEFAULT 0,
        `active_key` varchar(191) DEFAULT NULL,
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_store_subject_store` (`store_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_store_qualification')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `subject_id` int unsigned NOT NULL DEFAULT 0,
        `qualification_type` varchar(48) NOT NULL DEFAULT '',
        `certificate_no` varchar(64) NOT NULL DEFAULT '',
        `start_time` int unsigned NOT NULL DEFAULT 0,
        `expire_time` int unsigned NOT NULL DEFAULT 0,
        `status` varchar(24) NOT NULL DEFAULT 'active',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_qualification_store` (`store_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_store_capability')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `capability_code` varchar(48) NOT NULL DEFAULT '',
        `source_qualification_id` int unsigned NOT NULL DEFAULT 0,
        `source_authorization` varchar(48) NOT NULL DEFAULT '',
        `status` varchar(24) NOT NULL DEFAULT 'active',
        `effective_time` int unsigned NOT NULL DEFAULT 0,
        `expire_time` int unsigned NOT NULL DEFAULT 0,
        `active_key` varchar(191) DEFAULT NULL,
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_capability_store` (`store_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_customer_relation')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `uid` int unsigned NOT NULL DEFAULT 0,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `owner_uid` int unsigned NOT NULL DEFAULT 0,
        `source` varchar(48) NOT NULL DEFAULT 'order',
        `reference_id` int unsigned NOT NULL DEFAULT 0,
        `customer_status` varchar(32) NOT NULL DEFAULT 'potential',
        `status` varchar(24) NOT NULL DEFAULT 'active',
        `bind_time` int unsigned NOT NULL DEFAULT 0,
        `create_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        `active_key` varchar(191) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_yfth_customer_relation_active` (`active_key`),
        KEY `idx_yfth_customer_relation_store_status` (`store_id`, `status`, `customer_status`),
        KEY `idx_yfth_customer_relation_uid` (`uid`),
        KEY `idx_yfth_customer_relation_owner` (`owner_uid`),
        KEY `idx_yfth_customer_relation_source_ref` (`source`, `reference_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_customer_follow_record')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `customer_relation_id` int unsigned NOT NULL DEFAULT 0,
        `uid` int unsigned NOT NULL DEFAULT 0,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `operator_uid` int unsigned NOT NULL DEFAULT 0,
        `follow_type` varchar(32) NOT NULL DEFAULT 'other',
        `content` text,
        `next_follow_time` int unsigned NOT NULL DEFAULT 0,
        `create_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_follow_relation_time` (`customer_relation_id`, `create_time`),
        KEY `idx_yfth_follow_uid` (`uid`),
        KEY `idx_yfth_follow_store_time` (`store_id`, `create_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_service_appointment')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `appointment_no` varchar(64) NOT NULL DEFAULT '',
        `uid` int unsigned NOT NULL DEFAULT 0,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `status` varchar(32) NOT NULL DEFAULT 'confirmed',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_appointment_store` (`store_id`, `status`),
        KEY `idx_yfth_appointment_uid` (`uid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_service_writeoff_record')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `writeoff_no` varchar(64) NOT NULL DEFAULT '',
        `appointment_id` int unsigned NOT NULL DEFAULT 0,
        `uid` int unsigned NOT NULL DEFAULT 0,
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `status` varchar(32) NOT NULL DEFAULT 'succeeded',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_writeoff_store` (`store_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_package_instance')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `uid` int unsigned NOT NULL DEFAULT 0,
        `status` varchar(32) NOT NULL DEFAULT 'active',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_package_uid_status` (`uid`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    Db::execute("CREATE TABLE IF NOT EXISTS {$t('yfth_audit_event')} (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `business_domain` varchar(48) NOT NULL DEFAULT '',
        `object_type` varchar(48) NOT NULL DEFAULT '',
        `object_id` varchar(64) NOT NULL DEFAULT '',
        `action` varchar(64) NOT NULL DEFAULT '',
        `before_state` text,
        `after_state` text,
        `operator_uid` int unsigned NOT NULL DEFAULT 0,
        `role_code` varchar(32) NOT NULL DEFAULT '',
        `store_id` int unsigned NOT NULL DEFAULT 0,
        `request_id` varchar(64) NOT NULL DEFAULT '',
        `reason` varchar(255) NOT NULL DEFAULT '',
        `ip` varchar(64) NOT NULL DEFAULT '',
        `add_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_yfth_audit_object` (`business_domain`, `object_type`, `object_id`),
        KEY `idx_yfth_audit_store` (`store_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function fcSeedFixture(string $runId): array
{
    $users = [
        'customer' => fcCreateUser($runId, 'customer'),
        'mentor' => fcCreateUser($runId, 'mentor'),
        'staff_a' => fcCreateUser($runId, 'staffa'),
        'manager_a' => fcCreateUser($runId, 'managera'),
        'franchisee' => fcCreateUser($runId, 'franchisee'),
        'staff_b' => fcCreateUser($runId, 'staffb'),
        'customer_uid_only' => fcCreateUser($runId, 'uidonly'),
        'customer_staff_allowed' => fcCreateUser($runId, 'staffcust'),
        'customer_cross_order' => fcCreateUser($runId, 'crossorder'),
        'customer_manager_allowed' => fcCreateUser($runId, 'managercust'),
        'customer_cross_appointment' => fcCreateUser($runId, 'crossappt'),
        'customer_franchisee_allowed' => fcCreateUser($runId, 'francust'),
    ];
    fcGrantIdentity($users['mentor'], 'service_mentor', $runId);

    $stores = [
        'A' => fcCreateStore($runId, 'A'),
        'B' => fcCreateStore($runId, 'B'),
    ];
    fcGrantStoreFoundation($stores['A'], $runId . 'A');
    fcGrantStoreFoundation($stores['B'], $runId . 'B');

    fcGrantStoreRole($users['staff_a'], $stores['A'], 'store_staff', $runId);
    fcGrantStoreRole($users['manager_a'], $stores['A'], 'store_manager', $runId);
    fcGrantStoreRole($users['franchisee'], $stores['A'], 'franchisee', $runId);
    fcGrantStoreRole($users['staff_b'], $stores['B'], 'store_staff', $runId);

    $orders = [
        'staff_allowed' => fcCreateOrder($users['customer_staff_allowed'], $stores['A'], $runId, 'staff_allowed'),
        'store_b_order' => fcCreateOrder($users['customer_cross_order'], $stores['B'], $runId, 'store_b_order'),
        'already_bound_store_b' => fcCreateOrder($users['customer_staff_allowed'], $stores['B'], $runId, 'already_bound'),
    ];
    $appointments = [
        'manager_allowed' => fcCreateAppointment($users['customer_manager_allowed'], $stores['A'], $runId, 'manager_allowed'),
        'store_b_appointment' => fcCreateAppointment($users['customer_cross_appointment'], $stores['B'], $runId, 'store_b_appointment'),
        'writeoff_appointment' => fcCreateAppointment($users['customer_franchisee_allowed'], $stores['A'], $runId, 'writeoff_appointment'),
    ];
    $writeoffs = [
        'franchisee_allowed' => fcCreateWriteoff($appointments['writeoff_appointment'], $users['customer_franchisee_allowed'], $stores['A'], $runId, 'franchisee_allowed'),
    ];
    Db::name('yfth_package_instance')->insert([
        'uid' => $users['customer_staff_allowed'],
        'status' => 'active',
        'add_time' => time(),
        'update_time' => time(),
    ]);

    return compact('users', 'stores', 'orders', 'appointments', 'writeoffs');
}

function fcCreateUser(string $runId, string $label): int
{
    $now = time();
    return (int)Db::name('user')->insertGetId([
        'account' => substr('fc_' . strtolower($label) . '_' . strtolower($runId), 0, 32),
        'pwd' => md5($runId . $label),
        'real_name' => 'Runtime ' . $label,
        'nickname' => 'Runtime ' . $label,
        'avatar' => '/runtime/avatar.png',
        'phone' => '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'add_time' => $now,
        'last_time' => $now,
        'status' => 1,
        'user_type' => 'h5',
        'login_type' => 'h5',
        'uniqid' => md5($runId . $label . random_int(1, 999999)),
        'is_del' => 0,
    ]);
}

function fcCreateStore(string $runId, string $label): int
{
    $now = time();
    return (int)Db::name('system_store')->insertGetId([
        'name' => 'Runtime Store ' . $label . ' ' . $runId,
        'introduction' => 'franchise customer validation store',
        'phone' => '1380000' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT),
        'address' => 'Shanghai',
        'detailed_address' => 'Runtime Validation Road ' . $label,
        'image' => '',
        'oblong_image' => '',
        'latitude' => '31.2304',
        'longitude' => '121.4737',
        'valid_time' => '',
        'day_time' => '09:00-21:00',
        'add_time' => $now,
        'is_show' => 1,
        'is_del' => 0,
    ]);
}

function fcGrantStoreFoundation(int $storeId, string $suffix): void
{
    $now = time();
    $subjectId = (int)Db::name('yfth_business_subject')->insertGetId([
        'subject_type' => 'store_company',
        'subject_name' => 'Runtime Subject ' . $suffix,
        'credit_code' => 'FC' . strtoupper(substr(md5($suffix), 0, 15)),
        'legal_person' => 'Runtime',
        'contact_name' => 'Runtime',
        'contact_phone' => '13800000000',
        'registered_address' => 'Runtime Address',
        'status' => 'active',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_store_subject')->insert([
        'store_id' => $storeId,
        'subject_id' => $subjectId,
        'store_type' => 'franchise',
        'subject_role' => 'host',
        'status' => 'active',
        'effective_time' => $now - 3600,
        'expire_time' => $now + 86400,
        'active_key' => $storeId . ':host',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $qualificationId = (int)Db::name('yfth_store_qualification')->insertGetId([
        'store_id' => $storeId,
        'subject_id' => $subjectId,
        'qualification_type' => 'health_service',
        'certificate_no' => 'CERT' . strtoupper(substr(md5($suffix), 0, 12)),
        'start_time' => $now - 3600,
        'expire_time' => $now + 86400,
        'status' => 'active',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_store_capability')->insert([
        'store_id' => $storeId,
        'capability_code' => 'franchise_customer',
        'source_qualification_id' => $qualificationId,
        'source_authorization' => 'health_service',
        'status' => 'active',
        'effective_time' => $now - 3600,
        'expire_time' => $now + 86400,
        'active_key' => $storeId . ':franchise_customer',
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function fcGrantIdentity(int $uid, string $roleCode, string $runId): void
{
    $now = time();
    Db::name('yfth_user_identity')->insert([
        'uid' => $uid,
        'role_code' => $roleCode,
        'status' => 'active',
        'source_type' => 'runtime_validation',
        'source_id' => 0,
        'effective_time' => $now - 3600,
        'expire_time' => $now + 86400,
        'active_key' => $uid . ':' . $roleCode . ':runtime_validation:0',
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function fcGrantStoreRole(int $uid, int $storeId, string $roleCode, string $runId): void
{
    $now = time();
    Db::name('yfth_user_store_role')->insert([
        'uid' => $uid,
        'store_id' => $storeId,
        'role_code' => $roleCode,
        'permission_scope' => json_encode(['runtime' => $runId, 'store_id' => $storeId], JSON_UNESCAPED_UNICODE),
        'status' => 'active',
        'start_time' => $now - 3600,
        'end_time' => $now + 86400,
        'creator_uid' => 0,
        'active_key' => $uid . ':' . $storeId . ':' . $roleCode,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function fcCreateOrder(int $uid, int $storeId, string $runId, string $label): int
{
    $now = time();
    return (int)Db::name('store_order')->insertGetId([
        'order_id' => 'FC' . strtoupper(substr($runId . '_' . $label, 0, 28)),
        'uid' => $uid,
        'store_id' => $storeId,
        'real_name' => 'Runtime Customer',
        'user_phone' => '13912345678',
        'user_address' => 'Runtime Full Address ' . $label,
        'total_num' => 1,
        'total_price' => '128.00',
        'pay_price' => '128.00',
        'paid' => 1,
        'pay_time' => $now,
        'pay_type' => 'weixin',
        'add_time' => $now,
        'status' => 0,
        'refund_status' => 0,
        'pid' => 0,
        'is_del' => 0,
        'is_system_del' => 0,
    ]);
}

function fcCreateAppointment(int $uid, int $storeId, string $runId, string $label): int
{
    $now = time();
    return (int)Db::name('yfth_service_appointment')->insertGetId([
        'appointment_no' => 'FCAPPT' . strtoupper(substr($runId . '_' . $label, 0, 24)),
        'uid' => $uid,
        'store_id' => $storeId,
        'status' => 'confirmed',
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function fcCreateWriteoff(int $appointmentId, int $uid, int $storeId, string $runId, string $label): int
{
    $now = time();
    return (int)Db::name('yfth_service_writeoff_record')->insertGetId([
        'writeoff_no' => 'FCWO' . strtoupper(substr($runId . '_' . $label, 0, 28)),
        'appointment_id' => $appointmentId,
        'uid' => $uid,
        'store_id' => $storeId,
        'status' => 'succeeded',
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function fcAssertDuplicateActiveKeyRejected(int $uid, int $storeId, callable $assert): void
{
    try {
        Db::name('yfth_customer_relation')->insert([
            'uid' => $uid,
            'store_id' => $storeId,
            'owner_uid' => 0,
            'source' => 'order',
            'reference_id' => 999999,
            'customer_status' => 'potential',
            'status' => 'active',
            'bind_time' => time(),
            'create_time' => time(),
            'update_time' => time(),
            'active_key' => (string)$uid,
        ]);
        $assert(false, 'duplicate_active_key_rejected_by_mysql');
    } catch (Throwable $e) {
        $assert(true, 'duplicate_active_key_rejected_by_mysql');
    }
}

function fcAssertCustomerDtoSafe(array $row, callable $assert, string $label): void
{
    foreach ([
        'uid',
        'store_id',
        'owner_uid',
        'bind_time',
        'create_time',
        'update_time',
        'status',
        'phone',
        'address',
        'user_address',
        'openid',
        'unionid',
        'pay_price',
        'pay_type',
        'paid',
    ] as $key) {
        $assert(!array_key_exists($key, $row), $label . ':does_not_return_' . $key);
    }
    $assert(array_key_exists('phone_masked', $row), $label . ':returns_phone_masked');
    $assert((string)($row['phone_masked'] ?? '') === '' || strpos((string)$row['phone_masked'], '****') !== false, $label . ':phone_is_masked');
    foreach (['nickname', 'avatar', 'source', 'customer_status', 'package_status', 'service_status', 'latest_follow_time'] as $key) {
        $assert(array_key_exists($key, $row), $label . ':contains_' . $key);
    }
}

function fcAssertFollowDtoSafe(array $row, callable $assert, string $label): void
{
    foreach (['customer_relation_id', 'uid', 'store_id', 'operator_uid', 'create_time', 'update_time'] as $key) {
        $assert(!array_key_exists($key, $row), $label . ':does_not_return_' . $key);
    }
}

function fcCreateApiToken(int $uid): string
{
    $token = app()->make(JwtAuth::class)->createToken($uid, 'api', ['pwd' => md5('')]);
    return (string)$token['token'];
}

function fcGet(string $baseUrl, string $token, string $path, array $query = []): array
{
    return fcHttp('GET', $baseUrl, $token, $path, $query, []);
}

function fcPost(string $baseUrl, string $token, string $path, array $query = [], array $data = []): array
{
    return fcHttp('POST', $baseUrl, $token, $path, $query, $data);
}

function fcHttp(string $method, string $baseUrl, string $token, string $path, array $query, array $data): array
{
    $url = rtrim($baseUrl, '/') . '/api/yfth/customer/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $headers = [
        'Authori-zation: Bearer ' . $token,
        'Authorization: Bearer ' . $token,
        'X-YFTH-Role: ' . (string)($query['role_code'] ?? ''),
        'X-YFTH-Store-Id: ' . (string)($query['store_id'] ?? 0),
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ];
    if ($method !== 'GET') {
        $options['http']['content'] = http_build_query($data);
    }
    $body = @file_get_contents($url, false, stream_context_create($options));
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    $json = is_string($body) ? json_decode($body, true) : null;
    return [
        'method' => $method,
        'url' => $url,
        'http_code' => $code,
        'body' => is_string($body) ? $body : '',
        'json' => is_array($json) ? $json : [],
    ];
}

function fcExpectHttpOk(array $response, string $label, callable $assert): array
{
    $json = $response['json'];
    $ok = $response['http_code'] >= 200
        && $response['http_code'] < 300
        && (int)($json['status'] ?? 0) === 200;
    $assert($ok, $label . ':http_ok');
    if (!$ok) {
        $assert(false, $label . ':body:' . substr($response['body'], 0, 300));
    }
    return $json;
}

function fcExpectHttpFailure(array $response, string $label, callable $assert): void
{
    $json = $response['json'];
    $ok = !($response['http_code'] >= 200 && $response['http_code'] < 300 && (int)($json['status'] ?? 0) === 200);
    $assert($ok, $label . ':http_failure');
    if (!$ok) {
        $assert(false, $label . ':unexpected_body:' . substr($response['body'], 0, 300));
    }
}

function fcFindOne(string $table, array $where): array
{
    $row = Db::name($table)->where($where)->find();
    return is_array($row) ? $row : [];
}

function fcCount(string $table, array $where): int
{
    return (int)Db::name($table)->where($where)->count();
}

function fcQuery(string $sql, array $bind = []): array
{
    return Db::query($sql, $bind);
}

function fcRunId(): string
{
    $provided = trim((string)getenv('YFTH_REAL_FLOW_RUN_ID'));
    if ($provided !== '') {
        return preg_replace('/[^A-Za-z0-9]/', '', $provided);
    }
    return 'FC' . date('His') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function fcMaybeStartServer(array &$notes): array
{
    if ((string)getenv('YFTH_FRANCHISE_CUSTOMER_START_SERVER') !== '1') {
        return [];
    }
    $root = dirname(__DIR__);
    $public = $root . DIRECTORY_SEPARATOR . 'public';
    $serverRoot = sys_get_temp_dir();
    $installLock = $public . DIRECTORY_SEPARATOR . 'install.lock';
    $createdInstallLock = false;
    if (!is_file($installLock)) {
        file_put_contents($installLock, 'franchise_customer_validation');
        $createdInstallLock = true;
    }
    $php = trim((string)getenv('YFTH_FRANCHISE_CUSTOMER_PHP')) ?: PHP_BINARY;
    $phpArgs = trim((string)getenv('YFTH_FRANCHISE_CUSTOMER_PHP_ARGS'));
    $host = trim((string)getenv('YFTH_FRANCHISE_CUSTOMER_HOST')) ?: '127.0.0.1';
    $port = (int)(getenv('YFTH_FRANCHISE_CUSTOMER_PORT') ?: 18121);
    $baseUrl = 'http://' . $host . ':' . $port;
    $router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_franchise_customer_router_' . getmypid() . '_' . $port . '.php';
    fcWriteIsolatedRouter($router, $root);
    $cmd = [$php];
    if ($phpArgs !== '') {
        $cmd = array_merge($cmd, preg_split('/\s+/', $phpArgs, -1, PREG_SPLIT_NO_EMPTY));
    }
    $cmd = array_merge($cmd, ['-S', $host . ':' . $port, '-t', $serverRoot, $router]);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_franchise_customer_http_stdout.log', 'a'],
        2 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_franchise_customer_http_stderr.log', 'a'],
    ];
    $env = array_merge($_ENV, [
        'DATABASE_HOSTNAME' => (string)Config::get('database.connections.mysql.hostname'),
        'DATABASE_HOSTPORT' => (string)Config::get('database.connections.mysql.hostport'),
        'DATABASE_USERNAME' => (string)Config::get('database.connections.mysql.username'),
        'DATABASE_PASSWORD' => (string)Config::get('database.connections.mysql.password'),
        'DATABASE_DATABASE' => (string)Config::get('database.connections.mysql.database'),
        'DATABASE_PREFIX' => (string)Config::get('database.connections.mysql.prefix'),
        'DATABASE_CHARSET' => (string)Config::get('database.connections.mysql.charset'),
        'CACHE_DRIVER' => (string)Config::get('cache.default'),
        'CACHE_CACHE_PREFIX' => (string)Config::get('cache.stores.redis.prefix'),
        'REDIS_REDIS_HOSTNAME' => (string)Config::get('cache.stores.redis.host'),
        'REDIS_PORT' => (string)Config::get('cache.stores.redis.port'),
        'REDIS_REDIS_PASSWORD' => (string)Config::get('cache.stores.redis.password'),
        'REDIS_SELECT' => (string)Config::get('cache.stores.redis.select'),
    ]);
    foreach (['SystemRoot', 'WINDIR', 'PATH', 'PATHEXT', 'TEMP', 'TMP'] as $systemEnvKey) {
        if (getenv($systemEnvKey) !== false) {
            $env[$systemEnvKey] = (string)getenv($systemEnvKey);
        }
    }
    if (getenv('PHPRC') !== false) {
        $env['PHPRC'] = (string)getenv('PHPRC');
    }
    if ((string)Config::get('database.connections.mysql.password') === '') {
        $env['DATABASE_PASSWORD_EMPTY'] = '1';
    }
    $process = proc_open($cmd, $descriptors, $pipes, $serverRoot, $env);
    if (!is_resource($process)) {
        if ($createdInstallLock) {
            @unlink($installLock);
        }
        @unlink($router);
        throw new RuntimeException('local_php_server_start_failed');
    }
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.25);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(250000);
    }
    if (!$ready) {
        proc_terminate($process);
        if ($createdInstallLock) {
            @unlink($installLock);
        }
        @unlink($router);
        throw new RuntimeException('local_php_server_not_ready:' . $baseUrl);
    }
    $notes[] = 'local_php_server_started:' . $baseUrl;
    return [
        'process' => $process,
        'base_url' => $baseUrl,
        'install_lock' => $installLock,
        'created_install_lock' => $createdInstallLock,
        'router' => $router,
    ];
}

function fcStopServer(array $server, array &$notes): void
{
    if (!empty($server['process']) && is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
        $notes[] = 'local_php_server_stopped';
    }
    if (!empty($server['created_install_lock']) && !empty($server['install_lock'])) {
        @unlink($server['install_lock']);
        $notes[] = 'temporary_install_lock_removed';
    }
    if (!empty($server['router'])) {
        @unlink($server['router']);
        $notes[] = 'temporary_router_removed';
    }
}

function fcWriteIsolatedRouter(string $router, string $root): void
{
    $autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $code = <<<'PHP'
<?php
namespace think;

require __AUTOLOAD__;

$_SERVER['DOCUMENT_ROOT'] = __ROOT__ . DIRECTORY_SEPARATOR . 'public';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['PATH_INFO'] = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$app = new class(__ROOT__) extends App {
    public function loadEnv(string $envName = ''): void
    {
        foreach ([
            'DATABASE_HOSTNAME' => 'database.hostname',
            'DATABASE_HOSTPORT' => 'database.hostport',
            'DATABASE_USERNAME' => 'database.username',
            'DATABASE_PASSWORD' => 'database.password',
            'DATABASE_DATABASE' => 'database.database',
            'DATABASE_PREFIX' => 'database.prefix',
            'DATABASE_CHARSET' => 'database.charset',
            'CACHE_DRIVER' => 'cache.driver',
            'CACHE_CACHE_PREFIX' => 'cache.cache_prefix',
            'REDIS_REDIS_HOSTNAME' => 'redis.redis_hostname',
            'REDIS_PORT' => 'redis.port',
            'REDIS_REDIS_PASSWORD' => 'redis.redis_password',
            'REDIS_SELECT' => 'redis.select',
        ] as $envKey => $configKey) {
            $value = getenv($envKey);
            if ($value !== false) {
                $this->env->set($configKey, $value);
            }
        }
        if ((string)getenv('DATABASE_PASSWORD_EMPTY') === '1') {
            $this->env->set('database.password', '');
        }
        if (getenv('CACHE_DRIVER') === false) {
            $this->env->set('cache.driver', 'file');
        }
    }
};

$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
PHP;
    $code = str_replace(
        ['__AUTOLOAD__', '__ROOT__'],
        [var_export($autoload, true), var_export($root, true)],
        $code
    );
    file_put_contents($router, $code);
}

function fcCleanupRun(string $runId, array &$notes): void
{
    foreach ([
        'yfth_audit_event',
        'yfth_customer_follow_record',
        'yfth_customer_relation',
        'yfth_service_writeoff_record',
        'yfth_service_appointment',
        'yfth_package_instance',
        'store_order',
        'yfth_user_store_role',
        'yfth_user_identity',
        'yfth_store_capability',
        'yfth_store_qualification',
        'yfth_store_subject',
        'yfth_business_subject',
        'system_store',
        'user',
    ] as $table) {
        try {
            $tableName = Db::name($table)->getTable();
            Db::execute('DELETE FROM `' . str_replace('`', '``', $tableName) . '` WHERE 1=1');
        } catch (Throwable $e) {
            $notes[] = 'cleanup_skipped:' . $table . ':' . $e->getMessage();
        }
    }
    CacheService::delete('system_config_station_open');
    $notes[] = 'fixture_cleanup_completed:' . $runId;
}
