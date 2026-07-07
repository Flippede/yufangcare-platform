<?php

use app\services\user\UserAuthServices;
use crmeb\services\CacheService;
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
                'YFTH_REAL_FLOW_REDIS_HOSTNAME' => 'redis.redis_hostname',
                'YFTH_REAL_FLOW_REDIS_PORT' => 'redis.port',
                'YFTH_REAL_FLOW_REDIS_PASSWORD' => 'redis.redis_password',
                'YFTH_REAL_FLOW_REDIS_SELECT' => 'redis.select',
                'YFTH_REAL_FLOW_CACHE_PREFIX' => 'cache.cache_prefix',
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

$query = function (string $sql, array $bind = []) use (&$failures) {
    try {
        return Db::query($sql, $bind);
    } catch (Throwable $e) {
        $failures[] = 'mysql_query_failed:' . $e->getMessage();
        return [];
    }
};

$executeFlow = (string)getenv('YFTH_STORE_WORKBENCH_REAL_FLOW_EXECUTE') === '1';
$mysqlVersion = 'not_executed';
$database = '';
$prefix = '';
$cacheDriver = 'not_executed';

if ($executeFlow) {
    $versionRow = $query('SELECT VERSION() AS version');
    $mysqlVersion = (string)($versionRow[0]['version'] ?? '');
    $assert($mysqlVersion !== '', 'mysql_version_available');
    $assert(stripos($mysqlVersion, 'mariadb') === false, 'mysql_vendor_is_not_mariadb');
    $assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);

    $connection = Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $connection . '.database');
    $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');
    $cacheDriver = (string)Config::get('cache.default');

    foreach ([
        'user',
        'system_store',
        'store_order',
        'store_order_cart_info',
        'yfth_user_identity',
        'yfth_user_store_role',
        'yfth_service_appointment',
        'yfth_service_appointment_slot',
        'yfth_service_benefit_lock',
        'yfth_service_dynamic_code',
        'yfth_service_writeoff_record',
        'yfth_benefit_item',
        'yfth_idempotency_record',
    ] as $table) {
        $fullTable = $prefix . $table;
        $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $fullTable]);
        $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'real_table_exists:' . $fullTable);
    }

    foreach ([
        [$prefix . 'yfth_service_dynamic_code', 'uniq_yfth_svc_code_store_digital_active'],
        [$prefix . 'yfth_service_writeoff_record', 'uniq_yfth_svc_writeoff_active'],
        [$prefix . 'yfth_idempotency_record', 'uniq_yfth_idem_key'],
    ] as $index) {
        $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$database, $index[0], $index[1]]);
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }

    if ($cacheDriver === 'redis') {
        $cacheKey = 'yfth_store_workbench_real_flow_probe_' . bin2hex(random_bytes(4));
        $cacheValue = 'ok_' . time();
        $cacheOk = CacheService::set($cacheKey, $cacheValue, 60, 'yfth_store_workbench_validation')
            && CacheService::get($cacheKey) === $cacheValue;
        $assert($cacheOk, 'redis_cache_probe_ok');
        CacheService::delete($cacheKey);
    } else {
        $notes[] = 'redis_probe_not_executed_cache_driver:' . $cacheDriver;
    }
} else {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_STORE_WORKBENCH_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
}

$serviceSource = (string)file_get_contents(dirname(__DIR__) . '/app/services/yfth/StoreWorkbenchBusinessAdapterServices.php');
$assert(strpos($serviceSource, 'yfth_operator_context') !== false, 'operator_context_source_present');
$assert(strpos($serviceSource, 'yfth_admin_context') === false, 'store_adapter_does_not_emit_admin_context');
$assert(strpos($serviceSource, "'level' => 99") === false, 'store_adapter_does_not_emit_fake_admin_level');
$assert(strpos($serviceSource, "'id' => \$uid") === false, 'store_adapter_does_not_emit_fake_admin_id');
$assert(strpos($serviceSource, 'exceptionWriteoff(') === false, 'store_adapter_has_no_headquarter_exception_writeoff_call');
$assert(strpos($serviceSource, 'admin_token') === false, 'store_adapter_has_no_admin_token_reference');

if ($executeFlow) {
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
    $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);
    if (!$failures) {
        try {
            vfRunStoreWorkbenchHttpFlow($assert, $notes);
        } catch (Throwable $e) {
            $failures[] = 'real_http_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
        }
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
    echo "[OK] YFTH store workbench adapter real checks verified on MySQL {$mysqlVersion} with cache driver {$cacheDriver}.\n";
} else {
    echo "[OK] YFTH store workbench adapter source checks passed; real HTTP/MySQL/Redis flow skipped.\n";
}

function vfRunStoreWorkbenchHttpFlow(callable $assert, array &$notes): void
{
    $runId = vfRunId();
    $server = vfMaybeStartServer($notes);
    $baseUrl = (string)($server['base_url'] ?? getenv('YFTH_STORE_WORKBENCH_API_BASE') ?: 'http://127.0.0.1:18081');
    $notes[] = 'real_flow_run_id:' . $runId;
    $notes[] = 'local_api_base:' . $baseUrl;

    try {
        $fixture = vfSeedFixture($runId);
        $tokens = [];
        foreach ($fixture['users'] as $key => $uid) {
            $tokens[$key] = vfCreateApiToken($uid);
        }
        $notes[] = 'temporary_user_tokens_created_for_roles:customer,mentor,staff_a,manager_a,franchisee,staff_b,revoked,disabled_store';

        vfExpectHttpFailure(vfGet($baseUrl, $tokens['customer'], 'overview', ['role_code' => 'customer']), 'customer_overview_forbidden', $assert);
        vfExpectHttpFailure(vfGet($baseUrl, $tokens['mentor'], 'overview', ['role_code' => 'service_mentor']), 'service_mentor_overview_forbidden', $assert);
        vfExpectHttpFailure(vfGet($baseUrl, $tokens['revoked'], 'overview', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'revoked_store_role_forbidden', $assert);
        vfExpectHttpFailure(vfGet($baseUrl, $tokens['disabled_store'], 'overview', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['D']]), 'disabled_store_role_forbidden', $assert);

        $staffOverview = vfExpectHttpOk(vfGet($baseUrl, $tokens['staff_a'], 'overview', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'staff_a_overview_ok', $assert);
        $assert(($staffOverview['data']['permissions']['can_manage_appointment'] ?? true) === false, 'staff_a_cannot_manage_appointment');
        $assert(($staffOverview['data']['permissions']['headquarter_exception_writeoff'] ?? true) === false, 'store_workbench_headquarter_exception_false');

        $staffList = vfExpectHttpOk(vfGet($baseUrl, $tokens['staff_a'], 'appointments', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'staff_a_appointment_list_ok', $assert);
        $ids = array_map('intval', array_column($staffList['data']['list'] ?? [], 'id'));
        $assert(in_array($fixture['appointments']['pending_confirm'], $ids, true), 'staff_a_sees_store_a_appointment');
        $assert(!in_array($fixture['appointments']['store_b_pending'], $ids, true), 'staff_a_does_not_see_store_b_appointment');
        vfExpectHttpOk(vfGet($baseUrl, $tokens['staff_a'], 'appointments/' . $fixture['appointments']['pending_confirm'], ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'staff_a_appointment_detail_ok', $assert);
        vfExpectHttpFailure(vfPost($baseUrl, $tokens['staff_a'], 'appointments/' . $fixture['appointments']['staff_denied'] . '/confirm', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['reason' => 'staff_denied']), 'staff_a_confirm_denied', $assert);
        $assert((string)vfRow('yfth_service_appointment', $fixture['appointments']['staff_denied'])['status'] === 'pending_confirm', 'staff_denied_confirm_no_status_change');

        vfExpectHttpFailure(vfGet($baseUrl, $tokens['manager_a'], 'appointments/' . $fixture['appointments']['store_b_pending'], ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']]), 'manager_a_cross_store_appointment_detail_forbidden', $assert);
        vfExpectHttpFailure(vfPost($baseUrl, $tokens['manager_a'], 'appointments/' . $fixture['appointments']['store_b_pending'] . '/confirm', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['reason' => 'cross_store']), 'manager_a_cross_store_confirm_forbidden', $assert);
        $assert((string)vfRow('yfth_service_appointment', $fixture['appointments']['store_b_pending'])['status'] === 'pending_confirm', 'cross_store_confirm_no_status_change');

        vfExpectHttpOk(vfPost($baseUrl, $tokens['manager_a'], 'appointments/' . $fixture['appointments']['pending_confirm'] . '/confirm', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['reason' => 'manager_confirm', 'idempotency_key' => 'confirm_' . $runId]), 'manager_a_confirm_ok', $assert);
        $confirmed = vfRow('yfth_service_appointment', $fixture['appointments']['pending_confirm']);
        $slot = vfRow('yfth_service_appointment_slot', (int)$confirmed['slot_id']);
        $assert((string)$confirmed['status'] === 'confirmed', 'confirm_db_status_confirmed');
        $assert((int)$slot['locked_count'] === 0 && (int)$slot['occupied_count'] === 1, 'confirm_slot_locked_to_occupied');
        $assert(vfCount('yfth_service_appointment_event', ['appointment_id' => $fixture['appointments']['pending_confirm'], 'operator_type' => 'user_store_role', 'operator_id' => $fixture['users']['manager_a'], 'event_type' => 'confirm']) === 1, 'confirm_event_operator_user_store_role');

        vfExpectHttpOk(vfPost($baseUrl, $tokens['manager_a'], 'appointments/' . $fixture['appointments']['pending_reject'] . '/reject', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['reason' => 'manager_reject', 'idempotency_key' => 'reject_' . $runId]), 'manager_a_reject_ok', $assert);
        $assert((string)vfRow('yfth_service_appointment', $fixture['appointments']['pending_reject'])['status'] === 'rejected', 'reject_db_status_rejected');
        $assert((string)vfFindOne('yfth_service_benefit_lock', ['appointment_id' => $fixture['appointments']['pending_reject']])['status'] === 'released', 'reject_benefit_lock_released');

        vfExpectHttpOk(vfPost($baseUrl, $tokens['manager_a'], 'appointments/' . $fixture['appointments']['confirmed_cancel'] . '/cancel', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['reason' => 'manager_cancel', 'idempotency_key' => 'cancel_' . $runId]), 'manager_a_cancel_ok', $assert);
        $cancelled = vfRow('yfth_service_appointment', $fixture['appointments']['confirmed_cancel']);
        $cancelSlot = vfRow('yfth_service_appointment_slot', (int)$cancelled['slot_id']);
        $assert((string)$cancelled['status'] === 'cancelled', 'cancel_db_status_cancelled');
        $assert((int)$cancelSlot['occupied_count'] === 0, 'cancel_slot_occupied_released');

        vfExpectHttpOk(vfGet($baseUrl, $tokens['franchisee'], 'overview', ['role_code' => 'franchisee', 'store_id' => $fixture['stores']['A']]), 'franchisee_store_a_overview_ok', $assert);
        vfExpectHttpOk(vfGet($baseUrl, $tokens['franchisee'], 'overview', ['role_code' => 'franchisee', 'store_id' => $fixture['stores']['B']]), 'franchisee_store_b_overview_ok', $assert);
        vfExpectHttpFailure(vfGet($baseUrl, $tokens['franchisee'], 'overview', ['role_code' => 'franchisee', 'store_id' => $fixture['stores']['C']]), 'franchisee_store_c_forbidden', $assert);
        vfExpectHttpFailure(vfGet($baseUrl, $tokens['franchisee'], 'overview', ['role_code' => 'franchisee']), 'franchisee_all_store_context_forbidden', $assert);

        vfClearDigitalAttemptCache($fixture['users']['staff_a'], $fixture['stores']['A'], $notes);
        vfExpectHttpOk(vfPost($baseUrl, $tokens['staff_a'], 'writeoff/precheck', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['digital_code' => $fixture['codes']['digital_a']]), 'staff_a_digital_precheck_ok', $assert);
        vfExpectHttpFailure(vfPost($baseUrl, $tokens['staff_a'], 'writeoff/precheck', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['digital_code' => $fixture['codes']['digital_b']]), 'staff_a_cross_store_digital_precheck_forbidden', $assert);

        vfExpectHttpOk(vfPost($baseUrl, $tokens['staff_a'], 'writeoff/digital', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['digital_code' => $fixture['codes']['digital_a'], 'idempotency_key' => 'digital_' . $runId]), 'staff_a_digital_writeoff_ok', $assert);
        vfExpectHttpOk(vfPost($baseUrl, $tokens['staff_a'], 'writeoff/digital', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['digital_code' => $fixture['codes']['digital_a'], 'idempotency_key' => 'digital_' . $runId]), 'staff_a_digital_writeoff_idempotent_replay_ok', $assert);
        vfAssertWriteoffClosed($fixture['appointments']['digital_writeoff'], $fixture['users']['staff_a'], 'digital_code', $assert);
        for ($i = 1; $i <= 6; $i++) {
            vfExpectHttpFailure(vfPost($baseUrl, $tokens['staff_a'], 'writeoff/precheck', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['digital_code' => '99999' . $i]), 'staff_a_wrong_digital_attempt_' . $i . '_fails', $assert);
        }

        vfExpectHttpOk(vfPost($baseUrl, $tokens['manager_a'], 'writeoff/precheck', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['qr_token' => $fixture['codes']['qr_a']]), 'manager_a_qr_precheck_ok', $assert);
        vfExpectHttpOk(vfPost($baseUrl, $tokens['manager_a'], 'writeoff/token', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['qr_token' => $fixture['codes']['qr_a'], 'idempotency_key' => 'qr_' . $runId]), 'manager_a_qr_writeoff_ok', $assert);
        vfExpectHttpOk(vfPost($baseUrl, $tokens['manager_a'], 'writeoff/token', ['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']], ['qr_token' => $fixture['codes']['qr_a'], 'idempotency_key' => 'qr_' . $runId]), 'manager_a_qr_writeoff_idempotent_replay_ok', $assert);
        vfAssertWriteoffClosed($fixture['appointments']['qr_writeoff'], $fixture['users']['manager_a'], 'qr_code', $assert);
        vfExpectHttpFailure(vfPost($baseUrl, $tokens['staff_a'], 'writeoff/token', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['qr_token' => $fixture['codes']['qr_b'], 'idempotency_key' => 'cross_qr_' . $runId]), 'staff_a_cross_store_qr_forbidden', $assert);

        $recordList = vfExpectHttpOk(vfGet($baseUrl, $tokens['staff_a'], 'writeoff/records', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'staff_a_writeoff_record_list_ok', $assert);
        $recordIds = array_map('intval', array_column($recordList['data']['list'] ?? [], 'id'));
        $assert(count($recordIds) >= 2, 'staff_a_writeoff_records_include_store_a_records');

        $ordersBefore = vfOrderReadOnlySnapshot($fixture);
        $orderList = vfExpectHttpOk(vfGet($baseUrl, $tokens['staff_a'], 'orders', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'staff_a_order_list_ok', $assert);
        $orderIds = array_map('intval', array_column($orderList['data']['list'] ?? [], 'id'));
        $assert(in_array($fixture['orders']['A'], $orderIds, true), 'staff_a_order_list_contains_store_a');
        $assert(!in_array($fixture['orders']['B'], $orderIds, true), 'staff_a_order_list_excludes_store_b');
        vfAssertOrderWhitelist($orderList['data']['list'][0] ?? [], false, $assert, 'order_list');
        $orderDetail = vfExpectHttpOk(vfGet($baseUrl, $tokens['staff_a'], 'orders/' . $fixture['orders']['A'], ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'staff_a_order_detail_ok', $assert);
        vfAssertOrderWhitelist($orderDetail['data']['order'] ?? [], true, $assert, 'order_detail');
        vfExpectHttpFailure(vfGet($baseUrl, $tokens['staff_a'], 'orders/' . $fixture['orders']['B'], ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]), 'staff_a_cross_store_order_forbidden', $assert);
        $assert(vfOrderReadOnlySnapshot($fixture) === $ordersBefore, 'order_read_only_before_after_snapshot_unchanged');

        vfExpectHttpFailure(vfPost($baseUrl, $tokens['staff_a'], 'writeoff/exception', ['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']], ['appointment_id' => $fixture['appointments']['qr_writeoff'], 'reason' => 'not_allowed']), 'user_store_workbench_exception_route_unavailable', $assert);
    } finally {
        vfCleanupRun($runId, $notes);
        vfStopServer($server, $notes);
    }
}

function vfRunId(): string
{
    $provided = trim((string)getenv('YFTH_REAL_FLOW_RUN_ID'));
    if ($provided !== '') {
        return preg_replace('/[^A-Za-z0-9]/', '', $provided);
    }
    return 'SWB' . date('His') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function vfMaybeStartServer(array &$notes): array
{
    if ((string)getenv('YFTH_STORE_WORKBENCH_START_SERVER') !== '1') {
        return [];
    }
    $root = dirname(__DIR__);
    $public = $root . DIRECTORY_SEPARATOR . 'public';
    $serverRoot = sys_get_temp_dir();
    $installLock = $public . DIRECTORY_SEPARATOR . 'install.lock';
    $createdInstallLock = false;
    if (!is_file($installLock)) {
        file_put_contents($installLock, 'store_workbench_validation');
        $createdInstallLock = true;
    }
    $php = trim((string)getenv('YFTH_STORE_WORKBENCH_PHP')) ?: PHP_BINARY;
    $phpArgs = trim((string)getenv('YFTH_STORE_WORKBENCH_PHP_ARGS'));
    $host = trim((string)getenv('YFTH_STORE_WORKBENCH_HOST')) ?: '127.0.0.1';
    $port = (int)(getenv('YFTH_STORE_WORKBENCH_PORT') ?: 18081);
    $baseUrl = 'http://' . $host . ':' . $port;
    $router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_store_workbench_router_' . getmypid() . '_' . $port . '.php';
    vfWriteIsolatedRouter($router, $root);
    $cmd = [$php];
    if ($phpArgs !== '') {
        $cmd = array_merge($cmd, preg_split('/\s+/', $phpArgs, -1, PREG_SPLIT_NO_EMPTY));
    }
    $cmd = array_merge($cmd, ['-S', $host . ':' . $port, '-t', $serverRoot, $router]);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_store_workbench_http_stdout.log', 'a'],
        2 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_store_workbench_http_stderr.log', 'a'],
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

function vfStopServer(array $server, array &$notes): void
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

function vfWriteIsolatedRouter(string $router, string $root): void
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

function vfSeedFixture(string $runId): array
{
    $now = time();
    $users = [
        'customer' => vfCreateUser($runId, 'customer'),
        'mentor' => vfCreateUser($runId, 'mentor'),
        'staff_a' => vfCreateUser($runId, 'staffa'),
        'manager_a' => vfCreateUser($runId, 'managera'),
        'franchisee' => vfCreateUser($runId, 'franchisee'),
        'staff_b' => vfCreateUser($runId, 'staffb'),
        'revoked' => vfCreateUser($runId, 'revoked'),
        'disabled_store' => vfCreateUser($runId, 'disabled'),
    ];
    vfGrantIdentity($users['mentor'], 'service_mentor', $runId);

    $stores = [
        'A' => vfCreateStore($runId, 'A', true),
        'B' => vfCreateStore($runId, 'B', true),
        'C' => vfCreateStore($runId, 'C', true),
        'D' => vfCreateStore($runId, 'D', false),
    ];
    foreach (['A', 'B', 'C'] as $key) {
        vfGrantStoreFoundation($stores[$key], $runId . $key);
    }

    vfGrantStoreRole($users['staff_a'], $stores['A'], 'store_staff', $runId);
    vfGrantStoreRole($users['manager_a'], $stores['A'], 'store_manager', $runId);
    vfGrantStoreRole($users['franchisee'], $stores['A'], 'franchisee', $runId);
    vfGrantStoreRole($users['franchisee'], $stores['B'], 'franchisee', $runId);
    vfGrantStoreRole($users['staff_b'], $stores['B'], 'store_staff', $runId);
    vfGrantStoreRole($users['revoked'], $stores['A'], 'store_staff', $runId, 'disabled');
    vfGrantStoreRole($users['disabled_store'], $stores['D'], 'store_staff', $runId);

    $projectId = Db::name('yfth_service_project')->insertGetId([
        'service_code' => 'SWB_SVC_' . $runId,
        'service_name' => 'Store Workbench Validation Service ' . $runId,
        'service_type' => 'health_service',
        'service_desc' => 'store workbench real flow service',
        'suggested_duration_minutes' => 30,
        'allow_benefit' => 1,
        'required_benefit_type' => 'service',
        'required_benefit_template_ids' => '',
        'allow_paid' => 0,
        'status' => 'active',
        'sort' => 1,
        'created_uid' => 0,
        'updated_uid' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $storeServices = [
        'A' => vfCreateStoreService($stores['A'], $projectId, $runId . 'A'),
        'B' => vfCreateStoreService($stores['B'], $projectId, $runId . 'B'),
    ];

    $appointments = [];
    $appointments['pending_confirm'] = vfCreateAppointment($users['customer'], $stores['A'], $storeServices['A'], $projectId, 'pending_confirm', $runId, 'CONFIRM');
    $appointments['pending_reject'] = vfCreateAppointment($users['customer'], $stores['A'], $storeServices['A'], $projectId, 'pending_confirm', $runId, 'REJECT');
    $appointments['confirmed_cancel'] = vfCreateAppointment($users['customer'], $stores['A'], $storeServices['A'], $projectId, 'confirmed', $runId, 'CANCEL');
    $appointments['staff_denied'] = vfCreateAppointment($users['customer'], $stores['A'], $storeServices['A'], $projectId, 'pending_confirm', $runId, 'STAFF');
    $appointments['digital_writeoff'] = vfCreateAppointment($users['customer'], $stores['A'], $storeServices['A'], $projectId, 'confirmed', $runId, 'DIGI');
    $appointments['qr_writeoff'] = vfCreateAppointment($users['customer'], $stores['A'], $storeServices['A'], $projectId, 'confirmed', $runId, 'QR');
    $appointments['store_b_pending'] = vfCreateAppointment($users['customer'], $stores['B'], $storeServices['B'], $projectId, 'pending_confirm', $runId, 'BPND');
    $appointments['store_b_writeoff'] = vfCreateAppointment($users['customer'], $stores['B'], $storeServices['B'], $projectId, 'confirmed', $runId, 'BQR');

    $codes = [
        'digital_a' => '123451',
        'digital_b' => '223451',
        'qr_a' => 'qr_' . strtolower($runId) . '_a',
        'qr_b' => 'qr_' . strtolower($runId) . '_b',
    ];
    vfCreateDynamicCode($appointments['digital_writeoff'], $users['customer'], $stores['A'], 'qr_unused_' . $runId . '_digital', $codes['digital_a']);
    vfCreateDynamicCode($appointments['qr_writeoff'], $users['customer'], $stores['A'], $codes['qr_a'], '323451');
    vfCreateDynamicCode($appointments['store_b_writeoff'], $users['customer'], $stores['B'], $codes['qr_b'], $codes['digital_b']);

    $orders = [
        'A' => vfCreateOrder($users['customer'], $stores['A'], $runId, 'A'),
        'B' => vfCreateOrder($users['customer'], $stores['B'], $runId, 'B'),
        'C' => vfCreateOrder($users['customer'], $stores['C'], $runId, 'C'),
    ];

    return compact('users', 'stores', 'storeServices', 'appointments', 'codes', 'orders');
}

function vfCreateUser(string $runId, string $label, int $status = 1): int
{
    $now = time();
    return (int)Db::name('user')->insertGetId([
        'account' => substr('swb_' . strtolower($label) . '_' . strtolower($runId), 0, 32),
        'pwd' => md5($runId . $label),
        'real_name' => 'Runtime ' . $label,
        'nickname' => 'Runtime ' . $label,
        'phone' => '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'add_time' => $now,
        'last_time' => $now,
        'status' => $status,
        'user_type' => 'h5',
        'login_type' => 'h5',
        'uniqid' => md5($runId . $label . random_int(1, 999999)),
    ]);
}

function vfCreateStore(string $runId, string $label, bool $active): int
{
    $now = time();
    return (int)Db::name('system_store')->insertGetId([
        'name' => 'Runtime Store ' . $label . ' ' . $runId,
        'introduction' => 'store workbench validation store',
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
        'is_show' => $active ? 1 : 0,
        'is_del' => 0,
    ]);
}

function vfGrantStoreFoundation(int $storeId, string $suffix): void
{
    $now = time();
    $subjectId = (int)Db::name('yfth_business_subject')->insertGetId([
        'subject_type' => 'store_company',
        'subject_name' => 'Runtime Subject ' . $suffix,
        'credit_code' => 'SWB' . strtoupper(substr(md5($suffix), 0, 15)),
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
        'is_sales_subject' => 1,
        'is_service_subject' => 1,
        'is_payment_subject' => 1,
        'is_fulfillment_subject' => 1,
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
        'scope' => '',
        'start_time' => $now - 3600,
        'expire_time' => $now + 86400,
        'status' => 'active',
        'audit_uid' => 0,
        'audit_time' => $now,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    foreach (['reservation_service', 'order_writeoff'] as $code) {
        Db::name('yfth_store_capability')->insert([
            'store_id' => $storeId,
            'capability_code' => $code,
            'source_qualification_id' => $qualificationId,
            'source_authorization' => 'health_service',
            'status' => 'active',
            'effective_time' => $now - 3600,
            'expire_time' => $now + 86400,
            'active_key' => $storeId . ':' . $code,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }
}

function vfGrantIdentity(int $uid, string $roleCode, string $runId): void
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

function vfGrantStoreRole(int $uid, int $storeId, string $roleCode, string $runId, string $status = 'active'): void
{
    $now = time();
    Db::name('yfth_user_store_role')->insert([
        'uid' => $uid,
        'store_id' => $storeId,
        'role_code' => $roleCode,
        'permission_scope' => json_encode(['runtime' => $runId, 'store_id' => $storeId], JSON_UNESCAPED_UNICODE),
        'status' => $status,
        'start_time' => $now - 3600,
        'end_time' => $now + 86400,
        'creator_uid' => 0,
        'active_key' => $status === 'active' ? $uid . ':' . $storeId . ':' . $roleCode : null,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function vfCreateStoreService(int $storeId, int $projectId, string $suffix): int
{
    $now = time();
    return (int)Db::name('yfth_store_service')->insertGetId([
        'store_id' => $storeId,
        'service_project_id' => $projectId,
        'service_alias' => 'Runtime Service ' . $suffix,
        'service_description' => 'runtime validation binding',
        'duration_minutes' => 30,
        'requires_confirmation' => 1,
        'appointment_enabled' => 1,
        'advance_min_minutes' => 0,
        'advance_max_days' => 30,
        'cancel_deadline_minutes' => 0,
        'default_capacity' => 5,
        'timezone' => 'Asia/Shanghai',
        'status' => 'active',
        'active_key' => $storeId . ':' . $projectId,
        'created_uid' => 0,
        'updated_uid' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function vfCreateAppointment(int $uid, int $storeId, int $storeServiceId, int $projectId, string $status, string $runId, string $label): int
{
    $now = time();
    $serviceDate = (int)date('Ymd');
    $startTime = $now - 600;
    $endTime = $now + 1200;
    $startMinute = max(0, (int)date('H') * 60 + (int)date('i') - 10);
    $endMinute = min(1440, $startMinute + 30);
    $slotId = (int)Db::name('yfth_service_appointment_slot')->insertGetId([
        'store_id' => $storeId,
        'store_service_id' => $storeServiceId,
        'service_project_id' => $projectId,
        'service_date' => $serviceDate,
        'start_minute' => $startMinute,
        'end_minute' => $endMinute,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'capacity' => 5,
        'locked_count' => $status === 'pending_confirm' ? 1 : 0,
        'occupied_count' => $status === 'confirmed' ? 1 : 0,
        'status' => 'available',
        'slot_key' => $storeServiceId . ':' . $serviceDate . ':' . $startMinute . ':' . $label . ':' . $runId,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $benefit = vfCreateBenefitBundle($uid, $storeId, $runId, $label);
    $appointmentId = (int)Db::name('yfth_service_appointment')->insertGetId([
        'appointment_no' => 'APPT' . $runId . $label,
        'uid' => $uid,
        'store_id' => $storeId,
        'store_service_id' => $storeServiceId,
        'service_project_id' => $projectId,
        'slot_id' => $slotId,
        'package_instance_id' => $benefit['instance_id'],
        'benefit_plan_id' => $benefit['plan_id'],
        'benefit_period_id' => $benefit['period_id'],
        'benefit_item_id' => $benefit['item_id'],
        'service_date' => $serviceDate,
        'start_minute' => $startMinute,
        'end_minute' => $endMinute,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'duration_minutes' => 30,
        'status' => $status,
        'confirm_mode' => 'manual',
        'source_type' => 'package_5980_benefit',
        'user_note' => 'runtime validation',
        'idempotency_key' => 'seed_' . $runId . '_' . $label,
        'store_snapshot' => json_encode(['store_id' => $storeId, 'store_name' => 'Runtime Store'], JSON_UNESCAPED_UNICODE),
        'service_snapshot' => json_encode(['project' => ['service_name' => 'Runtime Service'], 'store_service_id' => $storeServiceId], JSON_UNESCAPED_UNICODE),
        'benefit_snapshot' => json_encode(['benefit_name' => 'Runtime Service Benefit'], JSON_UNESCAPED_UNICODE),
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_service_benefit_lock')->insert([
        'uid' => $uid,
        'appointment_id' => $appointmentId,
        'package_instance_id' => $benefit['instance_id'],
        'benefit_plan_id' => $benefit['plan_id'],
        'benefit_period_id' => $benefit['period_id'],
        'benefit_item_id' => $benefit['item_id'],
        'status' => 'locked',
        'consume_status' => 'none',
        'locked_time' => $now,
        'active_key' => (string)$benefit['item_id'],
        'add_time' => $now,
        'update_time' => $now,
    ]);
    return $appointmentId;
}

function vfCreateBenefitBundle(int $uid, int $storeId, string $runId, string $label): array
{
    $now = time();
    $purchaseId = (int)Db::name('yfth_package_purchase')->insertGetId([
        'purchase_no' => 'PUR' . $runId . $label,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 1,
        'rule_version_id' => 1,
        'product_id' => 0,
        'product_attr_unique' => '',
        'order_id' => random_int(1000000, 9999999),
        'order_sn' => 'ORDSN' . $runId . $label,
        'expected_pay_price' => '5980.00',
        'order_pay_price' => '5980.00',
        'payment_scene' => 'package_5980',
        'purchase_status' => 'paid',
        'activation_status' => 'activated',
        'source' => 'runtime_validation',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $instanceId = (int)Db::name('yfth_package_instance')->insertGetId([
        'instance_no' => 'INS' . $runId . $label,
        'purchase_id' => $purchaseId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 1,
        'rule_version_id' => 1,
        'order_id' => random_int(1000000, 9999999),
        'order_sn' => 'ORDI' . $runId . $label,
        'plan_id' => 0,
        'status' => 'active',
        'refund_status' => 'none',
        'fulfilled_count' => 0,
        'start_time' => $now - 3600,
        'end_time' => $now + 86400,
        'activated_time' => $now,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $planId = (int)Db::name('yfth_benefit_plan')->insertGetId([
        'plan_no' => 'PLAN' . $runId . $label,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 1,
        'rule_version_id' => 1,
        'month_count' => 10,
        'status' => 'active',
        'start_time' => $now - 3600,
        'end_time' => $now + 86400,
        'opened_month_no' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_package_instance')->where('id', $instanceId)->update(['plan_id' => $planId, 'update_time' => $now]);
    $periodId = (int)Db::name('yfth_benefit_period')->insertGetId([
        'plan_id' => $planId,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'month_no' => 1,
        'period_code' => 'PER' . $runId . $label,
        'period_start_time' => $now - 3600,
        'period_end_time' => $now + 86400,
        'open_at' => $now - 3600,
        'expire_at' => $now + 86400,
        'status' => 'available',
        'total_item_count' => 1,
        'fulfilled_item_count' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $benefitTemplateId = (int)Db::name('yfth_benefit_template')->insertGetId([
        'benefit_code' => 'BENE' . $runId . $label,
        'benefit_name' => 'Runtime Service Benefit',
        'benefit_type' => 'service',
        'fulfillment_type' => 'manual',
        'unit' => 'item',
        'status' => 'active',
        'sort' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $itemId = (int)Db::name('yfth_benefit_item')->insertGetId([
        'plan_id' => $planId,
        'period_id' => $periodId,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'month_no' => 1,
        'benefit_template_id' => $benefitTemplateId,
        'benefit_code' => 'BENE' . $runId . $label,
        'benefit_name' => 'Runtime Service Benefit',
        'benefit_type' => 'service',
        'quantity_total' => '1.00',
        'quantity_available' => '1.00',
        'quantity_used' => '0.00',
        'available_time' => $now - 3600,
        'expire_time' => $now + 86400,
        'status' => 'available',
        'fulfillment_status' => 'none',
        'source_rule_id' => abs(crc32($runId . $label)) ?: 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    return ['instance_id' => $instanceId, 'plan_id' => $planId, 'period_id' => $periodId, 'item_id' => $itemId];
}

function vfCreateDynamicCode(int $appointmentId, int $uid, int $storeId, string $token, string $digital): void
{
    $now = time();
    $digitalHash = hash('sha256', $digital);
    Db::name('yfth_service_dynamic_code')->insert([
        'appointment_id' => $appointmentId,
        'uid' => $uid,
        'store_id' => $storeId,
        'token_hash' => hash('sha256', $token),
        'digital_code_hash' => $digitalHash,
        'status' => 'issued',
        'issued_time' => $now,
        'expire_time' => $now + 300,
        'max_attempts' => 5,
        'active_key' => (string)$appointmentId,
        'digital_active_key' => $storeId . ':' . $digitalHash,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function vfClearDigitalAttemptCache(int $operatorId, int $storeId, array &$notes): void
{
    foreach (['127.0.0.1', '::1', 'cli', ''] as $ip) {
        CacheService::delete('yfth:writeoff:digital_attempt:' . $operatorId . ':' . $storeId . ':' . hash('sha256', $ip));
    }
    $notes[] = 'digital_attempt_cache_cleared_for_operator_store:' . $operatorId . ':' . $storeId;
}

function vfCreateOrder(int $uid, int $storeId, string $runId, string $label): int
{
    $now = time();
    $orderId = (int)Db::name('store_order')->insertGetId([
        'order_id' => 'SWB' . $runId . $label,
        'uid' => $uid,
        'real_name' => 'Runtime Customer',
        'user_phone' => '13912345678',
        'user_address' => 'Runtime Full Address ' . $label,
        'total_num' => 1,
        'total_price' => '128.00',
        'total_postage' => '0.00',
        'pay_price' => '128.00',
        'pay_postage' => '0.00',
        'paid' => 1,
        'pay_time' => $now,
        'pay_type' => 'weixin',
        'add_time' => $now,
        'status' => 0,
        'refund_status' => 0,
        'delivery_type' => 'express',
        'mark' => 'customer remark should not leak raw',
        'remark' => 'admin remark should never leak',
        'unique' => md5($runId . $label . random_int(1, 999999)),
        'store_id' => $storeId,
        'shipping_type' => 1,
        'is_del' => 0,
        'is_system_del' => 0,
    ]);
    Db::name('store_order_cart_info')->insert([
        'oid' => $orderId,
        'uid' => $uid,
        'cart_id' => 'cart_' . $runId . $label,
        'product_id' => random_int(1000, 9999),
        'cart_num' => 1,
        'refund_num' => 0,
        'surplus_num' => 1,
        'cart_info' => json_encode([
            'cart_num' => 1,
            'truePrice' => '128.00',
            'productInfo' => [
                'store_name' => 'Runtime Product ' . $label,
                'image' => '/runtime/product.png',
                'price' => '128.00',
                'attrInfo' => ['suk' => 'standard', 'price' => '128.00'],
            ],
        ], JSON_UNESCAPED_UNICODE),
        'unique' => md5('cart' . $runId . $label),
    ]);
    return $orderId;
}

function vfCreateApiToken(int $uid): string
{
    $token = app()->make(UserAuthServices::class)->createToken($uid, 'api');
    return (string)$token['token'];
}

function vfGet(string $baseUrl, string $token, string $path, array $query = []): array
{
    return vfHttp('GET', $baseUrl, $token, $path, $query, []);
}

function vfPost(string $baseUrl, string $token, string $path, array $query = [], array $data = []): array
{
    return vfHttp('POST', $baseUrl, $token, $path, $query, $data);
}

function vfHttp(string $method, string $baseUrl, string $token, string $path, array $query, array $data): array
{
    $url = rtrim($baseUrl, '/') . '/api/yfth/store_workbench/' . ltrim($path, '/');
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

function vfExpectHttpOk(array $response, string $label, callable $assert): array
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

function vfExpectHttpFailure(array $response, string $label, callable $assert): void
{
    $json = $response['json'];
    $ok = !($response['http_code'] >= 200 && $response['http_code'] < 300 && (int)($json['status'] ?? 0) === 200);
    $assert($ok, $label . ':http_failure');
}

function vfRow(string $table, int $id): array
{
    $row = Db::name($table)->where('id', $id)->find();
    if (!$row) {
        throw new RuntimeException('fixture_row_not_found:' . $table . ':' . $id);
    }
    return $row;
}

function vfFindOne(string $table, array $where): array
{
    $row = Db::name($table)->where($where)->find();
    if (!$row) {
        throw new RuntimeException('fixture_row_not_found:' . $table);
    }
    return $row;
}

function vfCount(string $table, array $where): int
{
    return (int)Db::name($table)->where($where)->count();
}

function vfAssertWriteoffClosed(int $appointmentId, int $operatorId, string $method, callable $assert): void
{
    $appointment = vfRow('yfth_service_appointment', $appointmentId);
    $recordCount = vfCount('yfth_service_writeoff_record', ['appointment_id' => $appointmentId, 'status' => 'succeeded']);
    $eventCount = vfCount('yfth_service_appointment_event', ['appointment_id' => $appointmentId, 'event_type' => 'completed']);
    $lock = vfFindOne('yfth_service_benefit_lock', ['appointment_id' => $appointmentId]);
    $item = vfRow('yfth_benefit_item', (int)$appointment['benefit_item_id']);
    $record = vfFindOne('yfth_service_writeoff_record', ['appointment_id' => $appointmentId, 'status' => 'succeeded']);
    $assert((string)$appointment['status'] === 'completed', $method . '_appointment_completed_once');
    $assert((int)$appointment['writeoff_operator_id'] === $operatorId, $method . '_operator_id_is_user_uid');
    $assert((string)$appointment['writeoff_operator_type'] === 'user_store_role', $method . '_operator_type_user_store_role');
    $assert($recordCount === 1, $method . '_writeoff_record_once');
    $assert($eventCount === 1, $method . '_completed_event_once');
    $assert((string)$lock['status'] === 'consumed', $method . '_benefit_lock_consumed');
    $assert((string)$item['status'] === 'used' && (string)$item['quantity_available'] === '0.00', $method . '_benefit_item_used_once');
    $assert((string)$record['writeoff_method'] === $method, $method . '_record_method');
    $assert((string)$record['operator_type'] === 'user_store_role', $method . '_record_operator_type_user_store_role');
}

function vfOrderReadOnlySnapshot(array $fixture): array
{
    $ids = array_values($fixture['orders']);
    $rows = Db::name('store_order')->whereIn('id', $ids)->field('id,paid,status,refund_status,shipping_type,pay_price,pay_time,is_del,is_system_del')->select()->toArray();
    usort($rows, function ($a, $b) {
        return (int)$a['id'] <=> (int)$b['id'];
    });
    return $rows;
}

function vfAssertOrderWhitelist(array $row, bool $detail, callable $assert, string $label): void
{
    foreach ([
        'user_phone',
        'user_address',
        'openid',
        'unionid',
        'trade_no',
        'remark',
        'mark',
        'refund_reason',
        'refund_reason_wap',
        'request_id',
        'idempotency_key',
        'token',
        'admin_id',
    ] as $key) {
        $assert(!array_key_exists($key, $row), $label . '_must_not_contain_' . $key);
    }
    $assert(array_key_exists('user_phone_masked', $row), $label . '_contains_masked_phone');
    if ($detail) {
        $assert(array_key_exists('user_address_masked', $row), $label . '_contains_masked_address');
    }
}

function vfCleanupRun(string $runId, array &$notes): void
{
    try {
        $appointmentIds = Db::name('yfth_service_appointment')
            ->whereLike('appointment_no', 'APPT' . $runId . '%')
            ->column('id');
        $slotIds = Db::name('yfth_service_appointment')
            ->whereLike('appointment_no', 'APPT' . $runId . '%')
            ->column('slot_id');
        $orderIds = Db::name('store_order')
            ->whereLike('order_id', 'SWB' . $runId . '%')
            ->column('id');
        $projectIds = Db::name('yfth_service_project')
            ->whereLike('service_code', 'SWB_SVC_' . $runId . '%')
            ->column('id');
        $storeIds = Db::name('system_store')
            ->whereLike('name', '% ' . $runId)
            ->column('id');
        $userIds = Db::name('user')
            ->whereLike('account', '%_' . strtolower($runId))
            ->column('uid');

        if ($appointmentIds) {
            Db::name('yfth_service_writeoff_record')->whereIn('appointment_id', $appointmentIds)->delete();
            Db::name('yfth_service_dynamic_code')->whereIn('appointment_id', $appointmentIds)->delete();
            Db::name('yfth_service_appointment_event')->whereIn('appointment_id', $appointmentIds)->delete();
            Db::name('yfth_service_benefit_lock')->whereIn('appointment_id', $appointmentIds)->delete();
            Db::name('yfth_service_appointment')->whereIn('id', $appointmentIds)->delete();
        }
        if ($slotIds) {
            Db::name('yfth_service_appointment_slot')->whereIn('id', array_filter(array_map('intval', $slotIds)))->delete();
        }
        foreach ([
            'yfth_benefit_item' => ['benefit_code', 'BENE' . $runId . '%'],
            'yfth_benefit_period' => ['period_code', 'PER' . $runId . '%'],
            'yfth_benefit_plan' => ['plan_no', 'PLAN' . $runId . '%'],
            'yfth_package_instance' => ['instance_no', 'INS' . $runId . '%'],
            'yfth_package_purchase' => ['purchase_no', 'PUR' . $runId . '%'],
            'yfth_benefit_template' => ['benefit_code', 'BENE' . $runId . '%'],
        ] as $table => $filter) {
            Db::name($table)->whereLike($filter[0], $filter[1])->delete();
        }
        if ($projectIds) {
            Db::name('yfth_store_service')->whereIn('service_project_id', $projectIds)->delete();
            Db::name('yfth_service_project')->whereIn('id', $projectIds)->delete();
        }
        if ($orderIds) {
            Db::name('store_order_cart_info')->whereIn('oid', $orderIds)->delete();
            Db::name('store_order')->whereIn('id', $orderIds)->delete();
        }
        if ($userIds) {
            Db::name('yfth_user_identity')->whereIn('uid', $userIds)->delete();
            Db::name('yfth_user_store_role')->whereIn('uid', $userIds)->delete();
            Db::name('user')->whereIn('uid', $userIds)->delete();
        }
        if ($storeIds) {
            Db::name('yfth_store_capability')->whereIn('store_id', $storeIds)->delete();
            Db::name('yfth_store_qualification')->whereIn('store_id', $storeIds)->delete();
            Db::name('yfth_store_subject')->whereIn('store_id', $storeIds)->delete();
            Db::name('system_store')->whereIn('id', $storeIds)->delete();
        }
        Db::name('yfth_business_subject')->whereLike('subject_name', 'Runtime Subject ' . $runId . '%')->delete();
        Db::name('yfth_idempotency_record')->whereLike('idempotency_key', '%' . $runId . '%')->delete();
        $notes[] = 'temporary_fixture_cleanup_completed:' . $runId;
    } catch (Throwable $e) {
        $notes[] = 'temporary_fixture_cleanup_failed:' . $e->getMessage();
    }
}
