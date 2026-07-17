<?php

use app\services\yfth\HqUserDebugPurgeServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$expect = function (callable $operation, string $message, string $label) use ($assert): void {
    try { $operation(); $assert(false, $label . ':no_exception'); }
    catch (Throwable $e) { $assert(strpos($e->getMessage(), $message) !== false, $label . ':' . $e->getMessage()); }
};

if ((string)getenv('YFTH_USER_DEBUG_PURGE_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_USER_DEBUG_PURGE_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    Config::set(['user_debug_purge_enabled' => true], 'yfth');
    $uid = 989901;
    $now = time();
    Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_debug_purge')->where('object_id', (string)$uid)->delete();
    Db::name('yfth_permanent_membership_event')->where('uid', $uid)->delete();
    Db::name('yfth_permanent_membership')->where('uid', $uid)->delete();
    Db::name('yfth_hq_customer_attribution_event')->where('uid', $uid)->delete();
    Db::name('yfth_hq_customer_attribution_current')->where('uid', $uid)->delete();
    Db::name('user')->where('uid', $uid)->delete();
    Db::name('user')->insert([
        'uid' => $uid, 'account' => 'debug_purge_validation', 'nickname' => 'Debug Purge Validation',
        'phone' => '13900989901', 'status' => 1, 'user_type' => 'wechat',
        'uniqid' => 'debug-purge-validation', 'add_time' => $now,
    ]);
    $currentId = (int)Db::name('yfth_hq_customer_attribution_current')->insertGetId([
        'uid' => $uid, 'store_id' => 0, 'status' => 'unassigned', 'status_reason_code' => 'initial_placeholder',
        'authority_version' => 0, 'source_type' => 'debug_validation', 'source_id' => 'debug-validation',
        'add_time' => $now, 'update_time' => $now,
    ]);
    Db::name('yfth_hq_customer_attribution_event')->insert([
        'event_no' => 'DEBUG-PURGE-' . $uid, 'attribution_current_id' => $currentId, 'uid' => $uid,
        'authority_version' => 0, 'event_type' => 'placeholder_created', 'after_status' => 'unassigned',
        'source_type' => 'debug_validation', 'source_id' => 'debug-validation',
        'source_unique_key' => hash('sha256', 'debug-purge-' . $uid), 'request_id' => 'debug-purge-validation',
        'add_time' => $now,
    ]);

    $service = app()->make(HqUserDebugPurgeServices::class);
    $hq = ['id' => 1, 'level' => 0];
    $preflight = $service->preflight($uid, $hq);
    $assert($preflight['can_purge'] && !$preflight['blocking_references'], 'disposable_user_preflight_allows_only_known_references');
    $assert($preflight['confirmation_phrase'] === '确认删除', 'simple_confirmation_phrase_exposed');

    Db::name('yfth_permanent_membership')->insert([
        'membership_no' => 'DEBUG-MEMBER-' . $uid, 'uid' => $uid, 'store_id' => 1,
        'source_type' => 'debug_validation', 'status' => 'active', 'activated_at' => $now,
        'request_id' => 'debug-member-validation', 'add_time' => $now, 'update_time' => $now,
    ]);
    $blocked = $service->preflight($uid, $hq);
    $assert(!$blocked['can_purge'], 'membership_fact_blocks_hard_delete');
    $expect(function () use ($service, $uid, $blocked, $hq) {
        $service->purge($uid, [
            'confirmation' => $blocked['confirmation_phrase'],
        ], 1, $hq);
    }, 'debug_user_purge_blocked_by_business_facts', 'blocked_user_cannot_be_deleted');
    Db::name('yfth_permanent_membership')->where('uid', $uid)->delete();

    $ready = $service->preflight($uid, $hq);
    $expect(function () use ($service, $uid, $ready, $hq) {
        $service->purge($uid, [
            'confirmation' => '删除确认',
        ], 1, $hq);
    }, 'debug_user_purge_phrase_invalid', 'exact_confirmation_phrase_required');
    $result = $service->purge($uid, [
        'confirmation' => $ready['confirmation_phrase'],
    ], 1, $hq);
    $assert($result['deleted'], 'safe_debug_user_purge_completed');
    $assert((int)Db::name('user')->where('uid', $uid)->count() === 0, 'user_row_deleted');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $uid)->count() === 0, 'attribution_current_deleted');
    $assert((int)Db::name('yfth_hq_customer_attribution_event')->where('uid', $uid)->count() === 0, 'attribution_event_deleted');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_debug_purge')->where('object_id', (string)$uid)->count() === 1, 'purge_operation_audited');
} catch (Throwable $e) {
    $failures[] = 'unexpected_exception:' . $e->getMessage();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH debug user purge real flow verified.\n";
