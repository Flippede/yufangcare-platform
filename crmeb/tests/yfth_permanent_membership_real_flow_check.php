<?php

use app\services\user\UserAuthServices;
use crmeb\services\CacheService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if ((string)getenv('YFTH_PERMANENT_MEMBERSHIP_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_execute=1\n";
    exit(0);
}

$app = new class() extends App {
    public function loadEnv(string $envName = ''): void
    {
        parent::loadEnv($envName);
        foreach (['HOSTNAME'=>'hostname','HOSTPORT'=>'hostport','USERNAME'=>'username','PASSWORD'=>'password','DATABASE'=>'database','PREFIX'=>'prefix','CHARSET'=>'charset'] as $env => $key) {
            $value = getenv('YFTH_REAL_FLOW_DB_' . $env);
            if ($value !== false) $this->env->set('database.' . $key, $value);
        }
        if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') $this->env->set('database.password', '');
        $this->env->set('cache.driver', 'file');
    }
};
$app->initialize();

$failures = [];
$passes = [];
$notes = [];
$server = [];
$assert = function (bool $ok, string $label) use (&$failures, &$passes): void {
    $ok ? $passes[] = $label : $failures[] = $label;
};

try {
    $version = (string)(Db::query('SELECT VERSION() version')[0]['version'] ?? '');
    $database = (string)(Db::query('SELECT DATABASE() db')[0]['db'] ?? '');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(test|sandbox|validation)/i', $database), 'isolated_database_name:' . $database);

    $run = 'PM' . date('His') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $fixture = pmSeed($run);
    CacheService::clearAll();
    $server = pmStartServer($notes);
    $base = $server['base_url'];
    $tokens = [];
    foreach ($fixture['users'] as $key => $uid) $tokens[$key] = pmUserToken($uid);
    $ctxManagerA = '?role_code=store_manager&store_id=' . $fixture['stores']['A'];
    $ctxManagerB = '?role_code=store_manager&store_id=' . $fixture['stores']['B'];
    $ctxStaffA = '?role_code=store_staff&store_id=' . $fixture['stores']['A'];

    foreach ([
        '/api/yfth/store_workbench/permanent_membership',
        '/api/yfth/store_workbench/permanent_membership/1/bind',
        '/api/yfth/store_workbench/permanent_membership/1/payment',
        '/api/yfth/store_workbench/permanent_membership/1/confirmation_code',
        '/api/yfth/permanent_membership/confirm',
    ] as $legacyPath) {
        pmExpectFail(pmRequest('POST', $base . $legacyPath . (strpos($legacyPath, 'store_workbench') !== false ? $ctxManagerA : ''), $tokens['manager_a']), 'legacy_write_route_retired:' . $legacyPath, $assert);
    }

    $identityFirst = pmExpectOk(pmRequest('POST', $base . '/api/yfth/permanent_membership/identity_code', $tokens['customer']), 'identity_code_first', $assert)['data'];
    $identitySecond = pmExpectOk(pmRequest('POST', $base . '/api/yfth/permanent_membership/identity_code', $tokens['customer']), 'identity_code_refresh', $assert)['data'];
    $assert($identityFirst['token'] !== $identitySecond['token'], 'identity_code_refreshes_plaintext');
    $assert((int)Db::name('yfth_business_dynamic_code')->where('target_uid', $fixture['users']['customer'])->where('scene', 'customer_identity')->where('status', 'replaced')->count() === 1, 'old_identity_code_replaced');
    $assert((int)Db::name('yfth_business_dynamic_code')->where('token_hash', $identitySecond['token'])->count() === 0, 'identity_plaintext_never_stored');

    $applied = pmExpectOk(pmRequest('POST', $base . '/api/yfth/permanent_membership/apply', $tokens['customer'], [
        'idempotency_key' => $run . '-apply',
    ]), 'customer_offline_membership_apply', $assert)['data'];
    $assert($applied['status'] === 'pending_store_review' && (int)$applied['store_id'] === $fixture['stores']['A'], 'application_uses_authoritative_b1');
    $assert((int)$applied['amount_cents'] === $fixture['package_amount_cent'], 'application_snapshots_current_package_price');
    $repeatApply = pmExpectOk(pmRequest('POST', $base . '/api/yfth/permanent_membership/apply', $tokens['customer'], [
        'idempotency_key' => $run . '-apply-repeat',
    ]), 'repeat_apply_returns_pending_application', $assert)['data'];
    $assert((int)$repeatApply['id'] === (int)$applied['id'], 'repeat_apply_is_idempotent');

    pmExpectFail(pmRequest('POST', $base . '/api/yfth/store_workbench/permanent_membership/' . $applied['id'] . '/approve' . $ctxManagerB, $tokens['manager_b'], [
        'idempotency_key' => $run . '-cross-approve',
    ]), 'other_store_cannot_approve', $assert);
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $fixture['users']['customer'])->count() === 0, 'cross_store_approve_has_no_membership_write');

    $approved = pmExpectOk(pmRequest('POST', $base . '/api/yfth/store_workbench/permanent_membership/' . $applied['id'] . '/approve' . $ctxManagerA, $tokens['manager_a'], [
        'idempotency_key' => $run . '-approve',
    ]), 'authoritative_store_manager_approves', $assert)['data'];
    $assert(($approved['changed'] ?? false) === true && (int)$approved['membership']['store_id'] === $fixture['stores']['A'], 'approval_activates_membership');
    $relation = (array)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $fixture['users']['customer'])->find();
    $assert(($relation['status'] ?? '') === 'closed' && ($relation['close_reason'] ?? '') === 'membership_activated', 'c1_c2_relation_closes_after_reward');
    $accrual = (array)Db::name('yfth_commission_accrual')->where([
        'source_type' => 'offline_membership_activation',
        'buyer_uid' => $fixture['users']['customer'],
    ])->find();
    $assert((int)($accrual['c1_uid'] ?? 0) === $fixture['users']['referrer'], 'offline_activation_rewards_real_c1');
    $assert((int)($accrual['base_amount_cent'] ?? 0) === $fixture['package_amount_cent'], 'offline_reward_uses_application_amount_snapshot');
    $assert((int)($accrual['c1_ratio_bps'] ?? 0) === 1500, 'first_valid_referral_uses_15_percent');
    $assert((int)($accrual['c1_amount_cent'] ?? 0) === intdiv($fixture['package_amount_cent'] * 1500, 10000), 'first_reward_amount_is_exact');
    $memberCount = (int)Db::name('yfth_permanent_membership')->where('uid', $fixture['users']['customer'])->count();
    $accrualCount = (int)Db::name('yfth_commission_accrual')->where('buyer_uid', $fixture['users']['customer'])->count();
    $approvedAgain = pmExpectOk(pmRequest('POST', $base . '/api/yfth/store_workbench/permanent_membership/' . $applied['id'] . '/approve' . $ctxManagerA, $tokens['manager_a'], [
        'idempotency_key' => $run . '-approve-repeat',
    ]), 'repeat_approve_returns_existing_result', $assert)['data'];
    $assert(($approvedAgain['idempotent'] ?? false) === true, 'repeat_approve_reports_idempotent');
    $assert($memberCount === (int)Db::name('yfth_permanent_membership')->where('uid', $fixture['users']['customer'])->count(), 'repeat_approve_does_not_duplicate_membership');
    $assert($accrualCount === (int)Db::name('yfth_commission_accrual')->where('buyer_uid', $fixture['users']['customer'])->count(), 'repeat_approve_does_not_duplicate_commission');

    $directCode = pmExpectOk(pmRequest('POST', $base . '/api/yfth/permanent_membership/identity_code', $tokens['direct']), 'direct_customer_identity_code', $assert)['data'];
    $direct = pmExpectOk(pmRequest('POST', $base . '/api/yfth/store_workbench/permanent_membership/activate_identity' . $ctxStaffA, $tokens['staff_a'], [
        'identity_token' => $directCode['token'],
        'idempotency_key' => $run . '-staff-scan',
    ]), 'store_staff_scans_same_store_identity', $assert)['data'];
    $assert(($direct['changed'] ?? false) === true && (int)$direct['membership']['store_id'] === $fixture['stores']['A'], 'staff_scan_activates_same_store_member');
    $assert((int)Db::name('yfth_commission_accrual')->where('buyer_uid', $fixture['users']['direct'])->count() === 0, 'customer_without_c1_gets_no_15_25_60_reward');

    $crossCode = pmExpectOk(pmRequest('POST', $base . '/api/yfth/permanent_membership/identity_code', $tokens['cross']), 'cross_store_identity_code', $assert)['data'];
    pmExpectFail(pmRequest('POST', $base . '/api/yfth/store_workbench/permanent_membership/activate_identity' . $ctxManagerA, $tokens['manager_a'], [
        'identity_token' => $crossCode['token'],
        'idempotency_key' => $run . '-cross-scan',
    ]), 'cross_store_identity_activation_rejected', $assert);
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $fixture['users']['cross'])->count() === 0, 'cross_store_scan_has_no_membership_write');

    $rejected = pmExpectOk(pmRequest('POST', $base . '/api/yfth/permanent_membership/apply', $tokens['rejected'], [
        'idempotency_key' => $run . '-reject-apply',
    ]), 'rejectable_application_created', $assert)['data'];
    $rejectedResult = pmExpectOk(pmRequest('POST', $base . '/api/yfth/store_workbench/permanent_membership/' . $rejected['id'] . '/reject' . $ctxStaffA, $tokens['staff_a'], [
        'reason' => 'offline payment not confirmed',
        'idempotency_key' => $run . '-reject',
    ]), 'store_staff_can_reject_own_store_application', $assert)['data'];
    $assert($rejectedResult['status'] === 'rejected', 'rejected_application_status');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $fixture['users']['rejected'])->count() === 0, 'rejection_does_not_create_member');

    pmExpectFail(pmRequest('POST', $base . '/api/yfth/permanent_membership/apply', $tokens['unbound'], [
        'idempotency_key' => $run . '-unbound',
    ]), 'unbound_customer_cannot_apply', $assert);

    $listA = pmExpectOk(pmRequest('GET', $base . '/api/yfth/store_workbench/permanent_membership' . $ctxManagerA, $tokens['manager_a']), 'store_a_application_list', $assert)['data'];
    $assert(pmOnlyStore($listA['list'] ?? [], $fixture['stores']['A']), 'store_list_isolated_to_authorized_store');
    pmExpectFail(pmRequest('GET', $base . '/api/yfth/store_workbench/permanent_membership?role_code=store_manager&store_id=' . $fixture['stores']['B'], $tokens['manager_a']), 'manager_cannot_forge_other_store_context', $assert);

    $me = pmExpectOk(pmRequest('GET', $base . '/api/yfth/permanent_membership/me', $tokens['customer']), 'member_reads_own_status', $assert)['data'];
    $assert(($me['is_permanent_member'] ?? false) === true && ($me['has_referral_qualification'] ?? false) === true, 'activated_customer_is_permanent_member_and_can_refer');
    $assert(!array_key_exists('uid', $me['membership'] ?? []) && !array_key_exists('source_type', $me['membership'] ?? []), 'customer_membership_dto_hides_internal_fields');
} catch (Throwable $e) {
    $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
} finally {
    if ($server) pmStopServer($server, $notes);
}

foreach ($notes as $note) echo "[NOTE] {$note}\n";
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] Offline membership application, store approval, identity scan, and automatic C1 reward verified.\n";

function pmSeed(string $run): array
{
    $users = [];
    foreach (['referrer','customer','direct','cross','rejected','unbound','manager_a','manager_b','staff_a'] as $key) {
        $users[$key] = pmCreateUser($run, $key);
    }
    $stores = ['A' => pmCreateStore($run, 'A'), 'B' => pmCreateStore($run, 'B')];
    pmGrantRole($users['manager_a'], $stores['A'], 'store_manager', $run);
    pmGrantRole($users['manager_b'], $stores['B'], 'store_manager', $run);
    pmGrantRole($users['staff_a'], $stores['A'], 'store_staff', $run);
    $customerAttr = pmAttribution($users['customer'], $stores['A'], $run . '-customer');
    pmAttribution($users['referrer'], $stores['A'], $run . '-referrer');
    pmAttribution($users['direct'], $stores['A'], $run . '-direct');
    pmAttribution($users['cross'], $stores['B'], $run . '-cross');
    pmAttribution($users['rejected'], $stores['A'], $run . '-rejected');
    pmReferral($users['referrer'], $users['customer'], $stores['A'], $customerAttr, $run);
    pmPublishedMemberPackage($run);
    pmPublishedReferralRule($run);
    $rule = app()->make(\app\services\yfth\PackageTemplateServices::class)->managedMemberRule();
    $amountCent = pmMoneyToCent((string)$rule['package_price']);
    if ($amountCent <= 0) throw new RuntimeException('managed_member_package_price_invalid');
    return ['users' => $users, 'stores' => $stores, 'package_amount_cent' => $amountCent];
}

function pmPublishedMemberPackage(string $run): void
{
    $now = time();
    $existing = (array)Db::name('yfth_package_template')->where('package_code', 'YFTH-MEMBER-PACKAGE-V1')->find();
    $templateData = [
        'package_code' => 'YFTH-MEMBER-PACKAGE-V1',
        'package_name' => 'PM 9800 Member Package ' . $run,
        'package_title' => 'Offline membership validation package',
        'package_type' => 'health_package',
        'base_price' => '9800.00',
        'currency' => 'CNY',
        'benefit_months' => 10,
        'service_summary' => 'isolated runtime validation only',
        'agreement_title' => 'Validation agreement',
        'agreement_content' => 'Validation agreement content',
        'status' => 'published',
        'current_rule_version_id' => 0,
        'publish_time' => $now,
        'sort' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ];
    if ($existing) {
        $templateId = (int)$existing['id'];
        unset($templateData['add_time']);
        Db::name('yfth_package_rule_version')->where('template_id', $templateId)->where('status', 'published')->update([
            'status' => 'retired', 'active_key' => null, 'update_time' => $now,
        ]);
        Db::name('yfth_package_template')->where('id', $templateId)->update($templateData);
    } else {
        $templateId = (int)Db::name('yfth_package_template')->insertGetId($templateData);
    }
    $versionNo = (int)Db::name('yfth_package_rule_version')->where('template_id', $templateId)->max('version_no') + 1;
    $ruleId = (int)Db::name('yfth_package_rule_version')->insertGetId([
        'template_id' => $templateId,
        'version_no' => $versionNo,
        'rule_code' => 'PM-' . substr(hash('sha256', $run), 0, 24),
        'status' => 'published',
        'package_price' => '9800.00',
        'month_count' => 10,
        'grants_permanent_membership' => 1,
        'benefit_rule_snapshot' => '{}',
        'agreement_title' => 'Validation agreement',
        'agreement_content_summary' => 'Validation agreement content',
        'agreement_content_hash' => hash('sha256', 'Validation agreement content'),
        'created_uid' => 1,
        'publish_uid' => 1,
        'publish_time' => $now,
        'effective_time' => $now - 1,
        'expire_time' => 0,
        'active_key' => $templateId . ':published',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_package_template')->where('id', $templateId)->update([
        'current_rule_version_id' => $ruleId,
        'update_time' => $now,
    ]);
}

function pmCreateUser(string $run, string $key): int
{
    return (int)Db::name('user')->insertGetId([
        'account' => substr(strtolower($run . $key), 0, 32), 'pwd' => md5($run . $key),
        'real_name' => 'PM ' . $key, 'nickname' => 'PM ' . $key, 'avatar' => '',
        'phone' => '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'add_time' => time(), 'last_time' => time(), 'status' => 1,
        'user_type' => 'h5', 'login_type' => 'h5', 'uniqid' => md5($run . $key . random_int(1, 999999)), 'is_del' => 0,
    ]);
}

function pmCreateStore(string $run, string $key): int
{
    return (int)Db::name('system_store')->insertGetId([
        'name' => 'PM Store ' . $key . ' ' . $run, 'introduction' => 'offline membership validation',
        'phone' => '13800000000', 'address' => 'Validation City', 'detailed_address' => 'Validation Road',
        'image' => '', 'oblong_image' => '', 'latitude' => '31.2304', 'longitude' => '121.4737',
        'valid_time' => '', 'day_time' => '09:00-21:00', 'add_time' => time(), 'is_show' => 1, 'is_del' => 0,
    ]);
}

function pmGrantRole(int $uid, int $storeId, string $role, string $run): void
{
    Db::name('yfth_user_store_role')->insert([
        'uid' => $uid, 'store_id' => $storeId, 'role_code' => $role,
        'permission_scope' => '', 'status' => 'active', 'start_time' => time() - 60,
        'end_time' => time() + 3600, 'creator_uid' => 0,
        'active_key' => $uid . ':' . $storeId . ':' . $role,
        'add_time' => time(), 'update_time' => time(),
    ]);
}

function pmAttribution(int $uid, int $storeId, string $key): int
{
    $now = time();
    $id = (int)Db::name('yfth_hq_customer_attribution_current')->insertGetId([
        'uid' => $uid, 'store_id' => $storeId, 'status' => 'active', 'status_reason_code' => '',
        'authority_version' => 1, 'source_type' => 'runtime_validation', 'source_id' => $key,
        'bound_at' => $now - 100, 'paused_at' => 0, 'closed_at' => 0, 'close_reason' => '',
        'add_time' => $now - 100, 'update_time' => $now,
    ]);
    Db::name('yfth_hq_customer_attribution_event')->insert([
        'event_no' => 'HAE' . strtoupper(substr(hash('sha256', $key), 0, 24)),
        'attribution_current_id' => $id, 'uid' => $uid, 'authority_version' => 1,
        'event_type' => 'attribution_created', 'before_store_id' => 0, 'after_store_id' => $storeId,
        'before_status' => 'unassigned', 'after_status' => 'active',
        'before_status_reason_code' => 'initial_placeholder', 'after_status_reason_code' => '',
        'source_type' => 'runtime_validation', 'source_id' => $key,
        'source_unique_key' => hash('sha256', $key . ':attr'), 'operator_uid' => 1,
        'operator_role_code' => 'runtime_validation', 'reason' => 'validation',
        'request_id' => $key, 'add_time' => $now - 100,
    ]);
    return $id;
}

function pmReferral(int $referrer, int $referred, int $storeId, int $attributionId, string $key): void
{
    $now = time();
    $no = 'HRR' . strtoupper(substr(hash('sha256', $key), 0, 24));
    $id = (int)Db::name('yfth_hq_active_referral_current')->insertGetId([
        'relation_no' => $no, 'referrer_uid' => $referrer, 'referred_uid' => $referred,
        'store_id' => $storeId, 'attribution_current_id' => $attributionId, 'status' => 'active',
        'active_referred_uid' => $referred, 'source_type' => 'runtime_validation', 'source_id' => $key,
        'source_unique_key' => hash('sha256', $key . ':relation'), 'started_at' => $now - 100,
        'paused_at' => 0, 'closed_at' => 0, 'close_reason' => '', 'relation_version' => 1,
        'request_id' => $key, 'add_time' => $now - 100, 'update_time' => $now,
    ]);
    Db::name('yfth_hq_active_referral_event')->insert([
        'event_no' => 'HRE' . strtoupper(substr(hash('sha256', $key . 'event'), 0, 24)),
        'referral_current_id' => $id, 'relation_no' => $no, 'relation_version' => 1,
        'referrer_uid' => $referrer, 'referred_uid' => $referred, 'store_id' => $storeId,
        'event_type' => 'relation_created', 'before_status' => '', 'after_status' => 'active',
        'source_type' => 'runtime_validation', 'source_id' => $key,
        'source_unique_key' => hash('sha256', $key . ':event'), 'operator_uid' => 1,
        'operator_role_code' => 'runtime_validation', 'reason' => 'validation',
        'request_id' => $key, 'add_time' => $now - 100,
    ]);
}

function pmPublishedReferralRule(string $run): void
{
    $now = time();
    Db::name('yfth_direct_referral_rule_version')->where('active_key', 'published')->update([
        'status' => 'retired', 'active_key' => null, 'update_time' => $now,
    ]);
    $version = (int)Db::name('yfth_direct_referral_rule_version')->max('version_no') + 1;
    Db::name('yfth_direct_referral_rule_version')->insert([
        'rule_no' => 'PMDR' . substr(hash('sha256', $run), 0, 24), 'version_no' => $version,
        'status' => 'published', 'package_ratio_first_bps' => 1500,
        'package_ratio_second_bps' => 2500, 'package_ratio_third_bps' => 6000,
        'package_observation_days' => 0, 'mall_consumption_enabled' => 0,
        'mall_consumption_ratio_bps' => 0, 'effective_at' => $now - 1, 'expires_at' => 0,
        'active_key' => 'published', 'created_uid' => 1, 'published_uid' => 1,
        'published_at' => $now, 'add_time' => $now, 'update_time' => $now,
    ]);
}

function pmMoneyToCent(string $amount): int
{
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) return 0;
    return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
}

function pmUserToken(int $uid): string
{
    return (string)app()->make(UserAuthServices::class)->createToken($uid, 'api')['token'];
}

function pmRequest(string $method, string $url, string $token, array $data = []): array
{
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'Authori-zation: Bearer ' . $token;
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $method === 'POST' ? http_build_query($data) : '',
        'ignore_errors' => true, 'timeout' => 30,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) $code = (int)$matches[1];
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['http_code' => $code, 'body' => (string)$body, 'json' => is_array($json) ? $json : []];
}

function pmExpectOk(array $response, string $label, callable $assert): array
{
    $ok = $response['http_code'] >= 200 && $response['http_code'] < 300 && (int)($response['json']['status'] ?? 0) === 200;
    $assert($ok, $label);
    if (!$ok) throw new RuntimeException($label . ':' . substr($response['body'], 0, 500));
    return $response['json'];
}

function pmExpectFail(array $response, string $label, callable $assert): void
{
    $ok = !($response['http_code'] >= 200 && $response['http_code'] < 300 && (int)($response['json']['status'] ?? 0) === 200);
    $assert($ok, $label);
    if (!$ok) throw new RuntimeException($label . ':unexpected_success');
}

function pmOnlyStore(array $rows, int $storeId): bool
{
    return $rows !== [] && count(array_filter($rows, function ($row) use ($storeId) {
        return (int)($row['store_id'] ?? 0) !== $storeId;
    })) === 0;
}

function pmStartServer(array &$notes): array
{
    $root = dirname(__DIR__);
    $lock = $root . '/public/install.lock';
    $created = false;
    if (!is_file($lock)) {
        file_put_contents($lock, 'offline_membership_validation');
        $created = true;
    }
    $router = sys_get_temp_dir() . '/yfth_pm_router_' . getmypid() . '.php';
    $autoload = $root . '/vendor/autoload.php';
    $code = <<<'PHP'
<?php
namespace think;
require __AUTOLOAD__;
$_SERVER['DOCUMENT_ROOT']=__ROOT__.DIRECTORY_SEPARATOR.'public';
$_SERVER['SCRIPT_FILENAME']=$_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.'index.php';
$_SERVER['SCRIPT_NAME']='/index.php';
$_SERVER['PHP_SELF']='/index.php';
$_SERVER['PATH_INFO']=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$app=new class(__ROOT__) extends App {
    public function loadEnv(string $envName=''):void {
        parent::loadEnv($envName);
        foreach (['HOSTNAME'=>'hostname','HOSTPORT'=>'hostport','USERNAME'=>'username','PASSWORD'=>'password','DATABASE'=>'database','PREFIX'=>'prefix','CHARSET'=>'charset'] as $env => $key) {
            $value = getenv('YFTH_REAL_FLOW_DB_' . $env);
            if ($value !== false) $this->env->set('database.' . $key, $value);
        }
        if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') $this->env->set('database.password', '');
        $this->env->set('cache.driver','file');
    }
};
$http=$app->http;$response=$http->run();$response->send();$http->end($response);
PHP;
    file_put_contents($router, str_replace(['__AUTOLOAD__','__ROOT__'], [var_export($autoload, true), var_export($root, true)], $code));
    $php = trim((string)getenv('YFTH_PERMANENT_MEMBERSHIP_PHP')) ?: PHP_BINARY;
    $ini = trim((string)getenv('YFTH_PERMANENT_MEMBERSHIP_PHP_INI'));
    $command = [$php];
    if ($ini !== '') $command = array_merge($command, ['-c', $ini]);
    $command = array_merge($command, ['-S', '127.0.0.1:18152', '-t', sys_get_temp_dir(), $router]);
    $stdout = sys_get_temp_dir() . '/yfth_pm_http.out.log';
    $stderr = sys_get_temp_dir() . '/yfth_pm_http.err.log';
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $process = proc_open($command, [0=>['pipe','r'],1=>['file',$stdout,'a'],2=>['file',$stderr,'a']], $pipes, sys_get_temp_dir(), array_merge($environment, $_ENV));
    if (!is_resource($process)) throw new RuntimeException('http_server_start_failed');
    for ($i = 0; $i < 40; $i++) {
        $socket = @fsockopen('127.0.0.1', 18152, $errno, $error, .25);
        if (is_resource($socket)) {
            fclose($socket);
            $notes[] = 'http_server_started:18152';
            return compact('process', 'router', 'lock', 'created') + ['base_url' => 'http://127.0.0.1:18152'];
        }
        usleep(250000);
    }
    throw new RuntimeException('http_server_not_ready');
}

function pmStopServer(array $server, array &$notes): void
{
    if (isset($server['process']) && is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
        $notes[] = 'http_server_stopped';
    }
    if (!empty($server['created'])) @unlink($server['lock']);
    @unlink($server['router']);
}
