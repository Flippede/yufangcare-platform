<?php

use app\services\system\admin\SystemAdminServices;
use app\services\user\UserAuthServices;
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
    fwrite(STDERR, '[FAIL] application_bootstrap_failed:' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$failures = [];
$passes = [];
$notes = [];
$GLOBALS['hqr_snapshot_checks'] = 0;
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

if ((string)getenv('YFTH_HQ_AUTHORITY_READONLY_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_HQ_AUTHORITY_READONLY_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

$fixture = [];
$server = [];
try {
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $connection = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $connection . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    foreach ([
        'yfth_hq_customer_attribution_current', 'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current', 'yfth_hq_active_referral_event', 'yfth_idempotency_record',
    ] as $table) {
        $assert(Db::name($table)->limit(1)->count() >= 0, 'readonly_table_available:' . $table);
    }

    $runId = 'HQR' . date('His') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $fixture = hqrSeedFixture($runId);
    $server = hqrStartServer($notes);
    $baseUrl = $server['base_url'];

    $userTokens = [];
    foreach ($fixture['users'] as $key => $uid) {
        $userTokens[$key] = hqrUserToken($uid);
    }
    $adminTokens = [];
    foreach ($fixture['admins'] as $key => $admin) {
        $adminTokens[$key] = hqrAdminToken((int)$admin['id'], (string)$admin['pwd']);
    }

    hqrExpectFailure(hqrReadonlyRequest('GET', $baseUrl . '/api/yfth/hq_authority/me', ''), 'user_unauthenticated_rejected', $assert);
    $none = hqrExpectOk(hqrReadonlyRequest('GET', $baseUrl . '/api/yfth/hq_authority/me', $userTokens['none']), 'user_without_current_ok', $assert);
    $assert(($none['data']['has_attribution'] ?? true) === false, 'user_without_current_is_unassigned');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $fixture['users']['none'])->count() === 0, 'user_read_creates_no_placeholder');

    foreach (['active', 'paused', 'pristine', 'historical', 'closed'] as $state) {
        $response = hqrExpectOk(hqrReadonlyRequest('GET', $baseUrl . '/api/yfth/hq_authority/me', $userTokens[$state]), 'user_state_' . $state . '_ok', $assert);
        hqrAssertUserDto($response['data'] ?? [], $assert, 'user_state_' . $state);
        $expected = $state === 'pristine' || $state === 'historical' ? 'unassigned' : $state;
        $assert((string)($response['data']['attribution_status'] ?? '') === $expected, 'user_state_' . $state . '_status');
    }
    $active = hqrExpectOk(hqrReadonlyRequest('GET', $baseUrl . '/api/yfth/hq_authority/me?uid=' . $fixture['users']['closed'], $userTokens['active']), 'user_client_uid_ignored', $assert);
    $assert((string)($active['data']['attribution_status'] ?? '') === 'active', 'authenticated_uid_is_authoritative');
    $assert(($active['data']['has_active_referral'] ?? false) === true, 'user_active_referral_is_boolean_true');

    $managerQuery = http_build_query(['role_code' => 'store_manager', 'store_id' => $fixture['stores']['A']]);
    $franchiseQuery = http_build_query(['role_code' => 'franchisee', 'store_id' => $fixture['stores']['A']]);
    $staffQuery = http_build_query(['role_code' => 'store_staff', 'store_id' => $fixture['stores']['A']]);
    $storePath = $baseUrl . '/api/yfth/store_workbench/customer_attribution';
    $managerList = hqrExpectOk(hqrReadonlyRequest('GET', $storePath . '?' . $managerQuery, $userTokens['manager']), 'store_manager_list_ok', $assert);
    $franchiseList = hqrExpectOk(hqrReadonlyRequest('GET', $storePath . '?' . $franchiseQuery, $userTokens['franchisee']), 'franchisee_list_ok', $assert);
    $assert(count($managerList['data']['list'] ?? []) === count($franchiseList['data']['list'] ?? []), 'manager_and_franchisee_same_store_scope');
    $listedIds = array_map('intval', array_column($managerList['data']['list'] ?? [], 'attribution_id'));
    $notes[] = 'store_listed_attribution_ids:' . json_encode($listedIds);
    $notes[] = 'fixture_attribution_ids:' . json_encode($fixture['attributions']);
    $assert(in_array($fixture['attributions']['active'], $listedIds, true) && in_array($fixture['attributions']['paused'], $listedIds, true), 'store_lists_active_and_paused');
    $assert(!in_array($fixture['attributions']['historical'], $listedIds, true) && !in_array($fixture['attributions']['closed'], $listedIds, true), 'store_excludes_historical_and_closed');
    hqrAssertStoreDto(($managerList['data']['list'][0] ?? []), $assert, 'store_list_dto');

    hqrExpectFailure(hqrReadonlyRequest('GET', $storePath . '?' . $staffQuery, $userTokens['staff']), 'store_staff_rejected', $assert);
    hqrExpectFailure(hqrReadonlyRequest('GET', $storePath . '?role_code=service_mentor', $userTokens['mentor']), 'service_mentor_rejected', $assert);
    hqrExpectFailure(hqrReadonlyRequest('GET', $storePath . '?role_code=customer', $userTokens['none']), 'ordinary_customer_rejected', $assert);
    hqrExpectFailure(hqrReadonlyRequest('GET', $storePath . '?role_code=store_manager&store_id=' . $fixture['stores']['B'], $userTokens['manager']), 'client_cross_store_context_rejected', $assert);
    hqrExpectFailure(hqrReadonlyRequest('GET', $storePath . '/' . $fixture['attributions']['store_b'] . '?' . $managerQuery, $userTokens['manager']), 'cross_store_attribution_detail_rejected', $assert);
    hqrExpectOk(hqrReadonlyRequest('GET', $storePath . '/' . $fixture['attributions']['active'] . '?' . $managerQuery, $userTokens['manager']), 'same_store_attribution_detail_ok', $assert);

    $adminBase = $baseUrl . '/adminapi/yfth/hq_authority';
    hqrExpectFailure(hqrReadonlyRequest('GET', $adminBase . '/attribution', ''), 'admin_unauthenticated_rejected', $assert);
    hqrExpectFailure(hqrReadonlyRequest('GET', $adminBase . '/attribution', $adminTokens['no_permission']), 'admin_without_api_permission_rejected', $assert);
    hqrExpectFailure(hqrReadonlyRequest('GET', $adminBase . '/attribution', $adminTokens['store_scope']), 'store_scoped_admin_rejected_from_hq_global', $assert);
    $adminAttr = hqrExpectOk(hqrReadonlyRequest('GET', $adminBase . '/attribution?status=active&page=1&limit=20', $adminTokens['ordinary']), 'hq_attribution_list_ok', $assert);
    hqrAssertAdminAttributionDto(($adminAttr['data']['list'][0] ?? []), $assert, 'hq_attribution_dto');
    $adminRef = hqrExpectOk(hqrReadonlyRequest('GET', $adminBase . '/referral?page=1&limit=20', $adminTokens['ordinary']), 'hq_referral_list_ok', $assert);
    hqrAssertAdminReferralDto(($adminRef['data']['list'][0] ?? []), $assert, 'hq_referral_dto');
    hqrExpectOk(hqrReadonlyRequest('GET', $adminBase . '/attribution/' . $fixture['attributions']['active'], $adminTokens['ordinary']), 'hq_attribution_detail_ok', $assert);
    hqrExpectOk(hqrReadonlyRequest('GET', $adminBase . '/referral/' . $fixture['referral'], $adminTokens['ordinary']), 'hq_referral_detail_ok', $assert);

    hqrExpectFailure(hqrReadonlyRequest('GET', $adminBase . '/attribution/' . $fixture['attributions']['active'] . '/events', $adminTokens['ordinary']), 'ordinary_view_cannot_read_attribution_events', $assert);
    hqrExpectFailure(hqrReadonlyRequest('GET', $adminBase . '/referral/' . $fixture['referral'] . '/events', $adminTokens['ordinary']), 'ordinary_view_cannot_read_referral_events', $assert);
    $attrEvents = hqrExpectOk(hqrReadonlyRequest('GET', $adminBase . '/attribution/' . $fixture['attributions']['active'] . '/events', $adminTokens['audit']), 'audit_attribution_events_ok', $assert);
    $refEvents = hqrExpectOk(hqrReadonlyRequest('GET', $adminBase . '/referral/' . $fixture['referral'] . '/events', $adminTokens['audit']), 'audit_referral_events_ok', $assert);
    hqrAssertEventDto(($attrEvents['data']['list'][0] ?? []), $assert, 'attribution_event_dto');
    hqrAssertEventDto(($refEvents['data']['list'][0] ?? []), $assert, 'referral_event_dto');

    $assert($GLOBALS['hqr_snapshot_checks'] >= 25, 'five_table_snapshots_cover_each_http_request:' . $GLOBALS['hqr_snapshot_checks']);
} catch (Throwable $e) {
    $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
} finally {
    if ($server) {
        hqrStopServer($server, $notes);
    }
    if ($fixture) {
        try {
            hqrCleanupFixture($fixture);
            $notes[] = 'temporary_fixture_cleanup:success';
        } catch (Throwable $e) {
            $failures[] = 'fixture_cleanup_failed:' . $e->getMessage();
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
echo '[OK] YFTH headquarters authority Stage 1B read-only HTTP flow verified on isolated MySQL.' . PHP_EOL;

function hqrReadonlyRequest(string $method, string $url, string $token): array
{
    $before = hqrAuthoritySnapshot();
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($token !== '') {
        $headers[] = 'Authori-zation: Bearer ' . $token;
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $code = (int)$match[1];
    }
    $after = hqrAuthoritySnapshot();
    if ($before !== $after) {
        throw new RuntimeException('readonly_snapshot_changed:' . $url);
    }
    $GLOBALS['hqr_snapshot_checks']++;
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['http_code' => $code, 'body' => (string)$body, 'json' => is_array($json) ? $json : []];
}

function hqrAuthoritySnapshot(): string
{
    $snapshot = [];
    foreach ([
        'yfth_hq_customer_attribution_current', 'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current', 'yfth_hq_active_referral_event', 'yfth_idempotency_record',
    ] as $table) {
        $snapshot[$table] = Db::name($table)->order('id asc')->select()->toArray();
    }
    return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

function hqrExpectOk(array $response, string $label, callable $assert): array
{
    $json = $response['json'];
    $ok = $response['http_code'] >= 200 && $response['http_code'] < 300 && (int)($json['status'] ?? 0) === 200;
    $assert($ok, $label);
    if (!$ok) {
        throw new RuntimeException($label . ':' . substr($response['body'], 0, 400));
    }
    return $json;
}

function hqrExpectFailure(array $response, string $label, callable $assert): void
{
    $json = $response['json'];
    $ok = !($response['http_code'] >= 200 && $response['http_code'] < 300 && (int)($json['status'] ?? 0) === 200);
    $assert($ok, $label);
}

function hqrAssertUserDto(array $row, callable $assert, string $label): void
{
    foreach (['has_attribution', 'attribution_status', 'attribution_status_label', 'bound_at', 'paused_at', 'closed_at', 'store', 'has_active_referral', 'tips'] as $key) {
        $assert(array_key_exists($key, $row), $label . ':contains_' . $key);
    }
    hqrAssertForbidden($row, ['uid', 'referrer_uid', 'relation_no', 'source_type', 'source_id', 'source_unique_key', 'authority_version', 'relation_version', 'event_no', 'operator_uid', 'request_id', 'status_reason_code', 'reason', 'idempotency_key'], $assert, $label);
    $assert(is_bool($row['has_active_referral'] ?? null), $label . ':referral_is_boolean');
}

function hqrAssertStoreDto(array $row, callable $assert, string $label): void
{
    foreach (['attribution_id', 'customer', 'attribution_status', 'attribution_status_label', 'bound_at', 'paused_at', 'source_label', 'has_active_referral'] as $key) {
        $assert(array_key_exists($key, $row), $label . ':contains_' . $key);
    }
    hqrAssertForbidden($row, ['uid', 'phone', 'source_id', 'source_unique_key', 'authority_version', 'relation_version', 'event_no', 'operator_uid', 'request_id', 'reason', 'idempotency_key'], $assert, $label);
    $phone = (string)($row['customer']['phone_masked'] ?? '');
    $assert($phone === '' || strpos($phone, '****') !== false, $label . ':phone_masked');
}

function hqrAssertAdminAttributionDto(array $row, callable $assert, string $label): void
{
    foreach (['attribution_id', 'uid', 'customer', 'store_id', 'store', 'attribution_status', 'source_label', 'has_active_referral', 'data_anomaly'] as $key) {
        $assert(array_key_exists($key, $row), $label . ':contains_' . $key);
    }
    hqrAssertForbidden($row, ['source_id', 'source_unique_key', 'authority_version', 'relation_version', 'event_no', 'operator_uid', 'request_id', 'reason', 'idempotency_key'], $assert, $label);
}

function hqrAssertAdminReferralDto(array $row, callable $assert, string $label): void
{
    foreach (['referral_id', 'relation_display', 'referrer_uid', 'referrer', 'referred_uid', 'referred', 'store_id', 'relation_status', 'source_label'] as $key) {
        $assert(array_key_exists($key, $row), $label . ':contains_' . $key);
    }
    hqrAssertForbidden($row, ['relation_no', 'source_id', 'source_unique_key', 'authority_version', 'relation_version', 'event_no', 'operator_uid', 'request_id', 'reason', 'idempotency_key'], $assert, $label);
}

function hqrAssertEventDto(array $row, callable $assert, string $label): void
{
    foreach (['event_no', 'event_type', 'source_type', 'source_id', 'operator_uid', 'operator_role_code', 'request_id', 'before_status', 'after_status', 'event_time'] as $key) {
        $assert(array_key_exists($key, $row), $label . ':contains_' . $key);
    }
    hqrAssertForbidden($row, ['source_unique_key', 'idempotency_key', 'request_hash', 'reason', 'phone', 'openid', 'unionid'], $assert, $label);
}

function hqrAssertForbidden(array $row, array $keys, callable $assert, string $label): void
{
    foreach ($keys as $key) {
        $assert(!array_key_exists($key, $row), $label . ':excludes_' . $key);
    }
}

function hqrSeedFixture(string $runId): array
{
    $users = [];
    foreach (['none', 'active', 'paused', 'pristine', 'historical', 'closed', 'store_b', 'referrer', 'manager', 'franchisee', 'staff', 'mentor'] as $label) {
        $users[$label] = hqrCreateUser($runId, $label);
    }
    $stores = ['A' => hqrCreateStore($runId, 'A'), 'B' => hqrCreateStore($runId, 'B')];
    hqrGrantStoreRole($users['manager'], $stores['A'], 'store_manager', $runId);
    hqrGrantStoreRole($users['franchisee'], $stores['A'], 'franchisee', $runId);
    hqrGrantStoreRole($users['staff'], $stores['A'], 'store_staff', $runId);
    hqrGrantIdentity($users['mentor'], 'service_mentor', $runId);

    $attributions = [];
    $attributions['active'] = hqrAttribution($users['active'], $stores['A'], 'active', 1, $runId . 'active');
    $attributions['paused'] = hqrAttribution($users['paused'], $stores['A'], 'paused', 2, $runId . 'paused');
    $attributions['pristine'] = hqrAttribution($users['pristine'], 0, 'unassigned', 0, $runId . 'pristine');
    $attributions['historical'] = hqrAttribution($users['historical'], 0, 'unassigned', 2, $runId . 'historical');
    $attributions['closed'] = hqrAttribution($users['closed'], 0, 'closed', 2, $runId . 'closed');
    $attributions['store_b'] = hqrAttribution($users['store_b'], $stores['B'], 'active', 1, $runId . 'storeb');
    $attributions['referrer'] = hqrAttribution($users['referrer'], $stores['A'], 'active', 1, $runId . 'referrer');
    $referral = hqrReferral($users['referrer'], $users['active'], $stores['A'], $attributions['active'], $runId);

    $menuIds = Db::name('system_menus')->whereIn('unique_auth', [
        'yfth-hq-authority-readonly-index', 'yfth-hq-authority-attribution-list', 'yfth-hq-authority-attribution-detail',
        'yfth-hq-authority-referral-list', 'yfth-hq-authority-referral-detail',
        'yfth-hq-authority-attribution-audit', 'yfth-hq-authority-referral-audit',
    ])->column('id', 'unique_auth');
    if (count($menuIds) !== 7) {
        throw new RuntimeException('stage1b_permissions_not_migrated');
    }
    $ordinaryRules = array_values(array_intersect_key($menuIds, array_flip([
        'yfth-hq-authority-readonly-index', 'yfth-hq-authority-attribution-list', 'yfth-hq-authority-attribution-detail',
        'yfth-hq-authority-referral-list', 'yfth-hq-authority-referral-detail',
    ])));
    $auditRules = array_values($menuIds);
    $ordinaryRole = hqrCreateAdminRole($runId . 'ordinary', $ordinaryRules);
    $auditRole = hqrCreateAdminRole($runId . 'audit', $auditRules);
    $emptyRole = hqrCreateAdminRole($runId . 'none', []);
    $admins = [
        'ordinary' => hqrCreateAdmin($runId, 'ordinary', $ordinaryRole),
        'audit' => hqrCreateAdmin($runId, 'audit', $auditRole),
        'no_permission' => hqrCreateAdmin($runId, 'none', $emptyRole),
        'store_scope' => hqrCreateAdmin($runId, 'store', $ordinaryRole),
    ];
    foreach (['ordinary', 'audit', 'no_permission'] as $key) {
        hqrGrantAdminScope((int)$admins[$key]['id'], 0, 'headquarter_operator', $runId);
    }
    hqrGrantAdminScope((int)$admins['store_scope']['id'], $stores['A'], 'store_manager', $runId);
    return compact('runId', 'users', 'stores', 'attributions', 'referral', 'admins') + [
        'roles' => [$ordinaryRole, $auditRole, $emptyRole],
    ];
}

function hqrCreateUser(string $runId, string $label): int
{
    return (int)Db::name('user')->insertGetId([
        'account' => substr(strtolower($runId . $label), 0, 32), 'pwd' => md5($runId . $label),
        'real_name' => 'Runtime ' . $label, 'nickname' => 'Runtime ' . $label,
        'avatar' => '', 'phone' => '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'add_time' => time(), 'last_time' => time(), 'status' => 1, 'user_type' => 'h5',
        'login_type' => 'h5', 'uniqid' => md5($runId . $label . random_int(1, 999999)), 'is_del' => 0,
    ]);
}

function hqrCreateStore(string $runId, string $label): int
{
    return (int)Db::name('system_store')->insertGetId([
        'name' => 'Runtime Store ' . $label . ' ' . $runId, 'introduction' => 'Stage 1B validation',
        'phone' => '13800000000', 'address' => '上海市测试区', 'detailed_address' => 'Validation Road',
        'image' => '', 'oblong_image' => '', 'latitude' => '31.2304', 'longitude' => '121.4737',
        'valid_time' => '', 'day_time' => '09:00-21:00', 'add_time' => time(), 'is_show' => 1, 'is_del' => 0,
    ]);
}

function hqrGrantStoreRole(int $uid, int $storeId, string $role, string $runId): void
{
    Db::name('yfth_user_store_role')->insert([
        'uid' => $uid, 'store_id' => $storeId, 'role_code' => $role,
        'permission_scope' => json_encode(['run_id' => $runId]), 'status' => 'active',
        'start_time' => time() - 60, 'end_time' => time() + 3600, 'creator_uid' => 0,
        'active_key' => $uid . ':' . $storeId . ':' . $role, 'add_time' => time(), 'update_time' => time(),
    ]);
}

function hqrGrantIdentity(int $uid, string $role, string $runId): void
{
    Db::name('yfth_user_identity')->insert([
        'uid' => $uid, 'role_code' => $role, 'status' => 'active', 'source_type' => 'runtime_validation',
        'source_id' => 0, 'effective_time' => time() - 60, 'expire_time' => time() + 3600,
        'active_key' => $uid . ':' . $role . ':' . $runId, 'add_time' => time(), 'update_time' => time(),
    ]);
}

function hqrAttribution(int $uid, int $storeId, string $status, int $version, string $key): int
{
    $now = time();
    $id = (int)Db::name('yfth_hq_customer_attribution_current')->insertGetId([
        'uid' => $uid, 'store_id' => $storeId, 'status' => $status,
        'status_reason_code' => $version === 0 ? 'initial_placeholder' : ($status === 'paused' ? 'temporary_risk_pause' : ($status === 'unassigned' ? 'store_terminated_no_successor' : '')),
        'authority_version' => $version, 'source_type' => $version ? 'direct_referral' : '', 'source_id' => $version ? $key : '',
        'bound_at' => $version ? $now - 600 : 0, 'paused_at' => $status === 'paused' ? $now - 120 : 0,
        'closed_at' => in_array($status, ['unassigned', 'closed'], true) && $version ? $now - 60 : 0,
        'close_reason' => $status === 'closed' ? 'account_closed' : '', 'add_time' => $now - 600, 'update_time' => $now,
    ]);
    for ($v = 1; $v <= $version; $v++) {
        $final = $v === $version;
        Db::name('yfth_hq_customer_attribution_event')->insert([
            'event_no' => 'HAE' . strtoupper(substr(hash('sha256', $key . $v), 0, 24)),
            'attribution_current_id' => $id, 'uid' => $uid, 'authority_version' => $v,
            'event_type' => $v === 1 ? 'attribution_created' : 'attribution_' . $status,
            'before_store_id' => $v === 1 ? 0 : $storeId, 'after_store_id' => $final ? $storeId : $storeId,
            'before_status' => $v === 1 ? 'unassigned' : 'active', 'after_status' => $final ? $status : 'active',
            'before_status_reason_code' => $v === 1 ? 'initial_placeholder' : '',
            'after_status_reason_code' => $final && $status === 'paused' ? 'temporary_risk_pause' : '',
            'source_type' => 'direct_referral', 'source_id' => $key . ':' . $v,
            'source_unique_key' => hash('sha256', $key . ':attr:' . $v),
            'operator_uid' => 0, 'operator_role_code' => 'runtime_validation', 'reason' => 'private test reason',
            'request_id' => 'readonly:' . $key . ':' . $v, 'add_time' => $now - 600 + $v,
        ]);
    }
    return $id;
}

function hqrReferral(int $referrerUid, int $referredUid, int $storeId, int $attributionId, string $runId): int
{
    $now = time();
    $relationNo = 'HRR' . strtoupper(substr(hash('sha256', $runId), 0, 24));
    $id = (int)Db::name('yfth_hq_active_referral_current')->insertGetId([
        'relation_no' => $relationNo, 'referrer_uid' => $referrerUid, 'referred_uid' => $referredUid,
        'store_id' => $storeId, 'attribution_current_id' => $attributionId, 'status' => 'active',
        'active_referred_uid' => $referredUid, 'source_type' => 'direct_referral', 'source_id' => $runId,
        'source_unique_key' => hash('sha256', $runId . ':relation'), 'started_at' => $now - 300,
        'paused_at' => 0, 'closed_at' => 0, 'close_reason' => '', 'relation_version' => 1,
        'request_id' => 'readonly:' . $runId, 'add_time' => $now - 300, 'update_time' => $now,
    ]);
    Db::name('yfth_hq_active_referral_event')->insert([
        'event_no' => 'HRE' . strtoupper(substr(hash('sha256', $runId . 'event'), 0, 24)),
        'referral_current_id' => $id, 'relation_no' => $relationNo, 'relation_version' => 1,
        'referrer_uid' => $referrerUid, 'referred_uid' => $referredUid, 'store_id' => $storeId,
        'event_type' => 'relation_created', 'before_status' => '', 'after_status' => 'active',
        'source_type' => 'direct_referral', 'source_id' => $runId,
        'source_unique_key' => hash('sha256', $runId . ':relation:event'),
        'operator_uid' => 0, 'operator_role_code' => 'runtime_validation', 'reason' => 'private test reason',
        'request_id' => 'readonly:' . $runId . ':event', 'add_time' => $now - 300,
    ]);
    return $id;
}

function hqrCreateAdminRole(string $name, array $rules): int
{
    return (int)Db::name('system_role')->insertGetId([
        'role_name' => substr($name, 0, 32), 'rules' => implode(',', array_map('intval', $rules)), 'level' => 1, 'status' => 1,
    ]);
}

function hqrCreateAdmin(string $runId, string $label, int $roleId): array
{
    $pwd = password_hash('yfth-' . $runId . '-' . $label, PASSWORD_BCRYPT);
    $id = (int)Db::name('system_admin')->insertGetId([
        'account' => substr(strtolower('hqr_' . $label . '_' . $runId), 0, 32), 'head_pic' => '', 'pwd' => $pwd,
        'real_name' => substr('HQR ' . $label, 0, 16), 'roles' => (string)$roleId, 'last_ip' => '127.0.0.1',
        'last_time' => 0, 'add_time' => time(), 'login_count' => 0, 'level' => 1, 'status' => 1,
        'division_id' => 0, 'is_del' => 0,
    ]);
    return compact('id', 'pwd');
}

function hqrGrantAdminScope(int $adminId, int $storeId, string $role, string $runId): void
{
    Db::name('yfth_admin_store_scope')->insert([
        'admin_id' => $adminId, 'store_id' => $storeId, 'role_code' => $role,
        'permission_scope' => json_encode(['run_id' => $runId]), 'status' => 'active', 'start_time' => 0, 'end_time' => 0,
        'created_uid' => $adminId, 'updated_uid' => $adminId, 'disabled_uid' => 0, 'disabled_time' => 0,
        'close_reason' => '', 'active_key' => $adminId . ':' . $storeId . ':' . $role,
        'add_time' => time(), 'update_time' => time(),
    ]);
}

function hqrUserToken(int $uid): string
{
    $token = app()->make(UserAuthServices::class)->createToken($uid, 'api');
    return (string)$token['token'];
}

function hqrAdminToken(int $id, string $pwd): string
{
    $token = app()->make(SystemAdminServices::class)->createToken($id, 'admin', $pwd);
    return (string)$token['token'];
}

function hqrStartServer(array &$notes): array
{
    $root = dirname(__DIR__);
    $public = $root . DIRECTORY_SEPARATOR . 'public';
    $installLock = $public . DIRECTORY_SEPARATOR . 'install.lock';
    $createdInstallLock = false;
    if (!is_file($installLock)) {
        file_put_contents($installLock, 'hq_authority_readonly_validation');
        $createdInstallLock = true;
    }
    $host = '127.0.0.1';
    $port = (int)(getenv('YFTH_HQ_AUTHORITY_READONLY_PORT') ?: 18141);
    $router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_hq_readonly_router_' . getmypid() . '.php';
    hqrWriteRouter($router, $root);
    $php = trim((string)getenv('YFTH_HQ_AUTHORITY_READONLY_PHP')) ?: PHP_BINARY;
    $phpArgs = trim((string)getenv('YFTH_HQ_AUTHORITY_READONLY_PHP_ARGS'));
    $command = [$php];
    if ($phpArgs !== '') {
        $command = array_merge($command, preg_split('/\s+/', $phpArgs, -1, PREG_SPLIT_NO_EMPTY));
    }
    $command = array_merge($command, ['-S', $host . ':' . $port, '-t', sys_get_temp_dir(), $router]);
    $env = array_merge($_ENV, [
        'DATABASE_HOSTNAME' => (string)Config::get('database.connections.mysql.hostname'),
        'DATABASE_HOSTPORT' => (string)Config::get('database.connections.mysql.hostport'),
        'DATABASE_USERNAME' => (string)Config::get('database.connections.mysql.username'),
        'DATABASE_PASSWORD' => (string)Config::get('database.connections.mysql.password'),
        'DATABASE_DATABASE' => (string)Config::get('database.connections.mysql.database'),
        'DATABASE_PREFIX' => (string)Config::get('database.connections.mysql.prefix'),
        'DATABASE_CHARSET' => (string)Config::get('database.connections.mysql.charset'),
        'CACHE_DRIVER' => 'file',
    ]);
    foreach (['SystemRoot', 'WINDIR', 'PATH', 'PATHEXT', 'TEMP', 'TMP', 'PHPRC'] as $key) {
        if (getenv($key) !== false) {
            $env[$key] = (string)getenv($key);
        }
    }
    if ((string)Config::get('database.connections.mysql.password') === '') {
        $env['DATABASE_PASSWORD_EMPTY'] = '1';
    }
    $stdout = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_hq_readonly_http_stdout.log';
    $stderr = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_hq_readonly_http_stderr.log';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'a'], 2 => ['file', $stderr, 'a']], $pipes, sys_get_temp_dir(), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('local_php_server_start_failed');
    }
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $socket = @fsockopen($host, $port, $errno, $error, 0.25);
        if (is_resource($socket)) {
            fclose($socket);
            $notes[] = 'local_php_server_started:' . $host . ':' . $port;
            return compact('process', 'router', 'installLock', 'createdInstallLock') + ['base_url' => 'http://' . $host . ':' . $port];
        }
        usleep(250000);
    }
    proc_terminate($process);
    throw new RuntimeException('local_php_server_not_ready');
}

function hqrStopServer(array $server, array &$notes): void
{
    if (isset($server['process']) && is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
        $notes[] = 'local_php_server_stopped';
    }
    if (!empty($server['createdInstallLock'])) {
        @unlink($server['installLock']);
    }
    @unlink($server['router']);
}

function hqrWriteRouter(string $router, string $root): void
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
            'DATABASE_HOSTNAME' => 'database.hostname', 'DATABASE_HOSTPORT' => 'database.hostport',
            'DATABASE_USERNAME' => 'database.username', 'DATABASE_PASSWORD' => 'database.password',
            'DATABASE_DATABASE' => 'database.database', 'DATABASE_PREFIX' => 'database.prefix',
            'DATABASE_CHARSET' => 'database.charset', 'CACHE_DRIVER' => 'cache.driver',
        ] as $envKey => $configKey) {
            $value = getenv($envKey);
            if ($value !== false) $this->env->set($configKey, $value);
        }
        if ((string)getenv('DATABASE_PASSWORD_EMPTY') === '1') $this->env->set('database.password', '');
    }
};
$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
PHP;
    file_put_contents($router, str_replace(['__AUTOLOAD__', '__ROOT__'], [var_export($autoload, true), var_export($root, true)], $code));
}

function hqrCleanupFixture(array $fixture): void
{
    $uids = array_values($fixture['users']);
    $adminIds = array_map(function ($row) { return (int)$row['id']; }, $fixture['admins']);
    Db::name('yfth_hq_active_referral_event')->where('referral_current_id', $fixture['referral'])->delete();
    Db::name('yfth_hq_active_referral_current')->where('id', $fixture['referral'])->delete();
    Db::name('yfth_hq_customer_attribution_event')->whereIn('uid', $uids)->delete();
    Db::name('yfth_hq_customer_attribution_current')->whereIn('uid', $uids)->delete();
    Db::name('yfth_user_store_role')->whereIn('uid', $uids)->delete();
    Db::name('yfth_user_identity')->whereIn('uid', $uids)->delete();
    Db::name('yfth_admin_store_scope')->whereIn('admin_id', $adminIds)->delete();
    Db::name('system_admin')->whereIn('id', $adminIds)->delete();
    Db::name('system_role')->whereIn('id', $fixture['roles'])->delete();
    Db::name('system_store')->whereIn('id', array_values($fixture['stores']))->delete();
    Db::name('user')->whereIn('uid', $uids)->delete();
}
