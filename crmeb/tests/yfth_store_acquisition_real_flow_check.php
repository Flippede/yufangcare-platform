<?php

use app\services\yfth\StoreAcquisitionServices;
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

if ((string)getenv('YFTH_STORE_ACQUISITION_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_STORE_ACQUISITION_REAL_FLOW_EXECUTE=1\n";
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

    $storeId = 9821;
    $managerUid = 982001;
    $staffUid = 982002;
    $customerUid = 982003;
    $otherUid = 982004;
    $now = time();
    $uids = [$managerUid, $staffUid, $customerUid, $otherUid];
    Db::name('yfth_store_acquisition_acceptance')->whereIn('customer_uid', $uids)->delete();
    Db::name('yfth_store_acquisition_code')->where('store_id', $storeId)->delete();
    Db::name('yfth_customer_relation')->whereIn('uid', $uids)->delete();
    Db::name('yfth_hq_active_referral_event')->whereIn('referrer_uid', $uids)->whereOr('referred_uid', 'in', $uids)->delete();
    Db::name('yfth_hq_active_referral_current')->whereIn('referrer_uid', $uids)->whereOr('referred_uid', 'in', $uids)->delete();
    Db::name('yfth_hq_customer_attribution_event')->whereIn('uid', $uids)->delete();
    Db::name('yfth_hq_customer_attribution_current')->whereIn('uid', $uids)->delete();
    Db::name('yfth_user_store_role')->whereIn('uid', $uids)->delete();
    Db::name('user')->whereIn('uid', $uids)->delete();
    Db::name('system_store')->where('id', $storeId)->delete();

    Db::name('system_store')->insert([
        'id' => $storeId, 'name' => 'Store acquisition validation', 'phone' => '13800000000',
        'address' => 'isolated validation', 'detailed_address' => 'isolated validation only',
        'valid_time' => '00:00-23:59', 'day_time' => '1,2,3,4,5,6,7',
        'is_show' => 1, 'is_del' => 0, 'add_time' => $now,
    ]);
    foreach ($uids as $uid) {
        Db::name('user')->insert([
            'uid' => $uid, 'account' => 'acquisition_validation_' . $uid,
            'nickname' => 'Acquisition ' . $uid, 'phone' => '139' . substr((string)$uid, -8),
            'status' => 1, 'user_type' => 'wechat', 'uniqid' => 'acquisition-validation-' . $uid,
            'spread_uid' => 0, 'add_time' => $now,
        ]);
    }
    foreach ([[$managerUid, 'store_manager'], [$staffUid, 'store_staff']] as $role) {
        Db::name('yfth_user_store_role')->insert([
            'uid' => $role[0], 'store_id' => $storeId, 'role_code' => $role[1],
            'status' => 'active', 'permission_scope' => '', 'add_time' => $now, 'update_time' => $now,
        ]);
    }

    $token = bin2hex(random_bytes(32));
    $codeId = (int)Db::name('yfth_store_acquisition_code')->insertGetId([
        'code_no' => 'YFSAC' . $now, 'token_hash' => hash('sha256', $token), 'store_id' => $storeId,
        'issuer_uid' => $staffUid, 'issuer_role_code' => 'store_staff', 'status' => 'active',
        'issued_at' => $now, 'expires_at' => $now + 3600, 'invalidated_at' => 0,
        'active_key' => $staffUid . ':' . $storeId . ':store_staff', 'request_id' => 'acquisition-code-validation',
        'add_time' => $now, 'update_time' => $now,
    ]);
    $service = app()->make(StoreAcquisitionServices::class);
    $resolved = $service->resolve($token);
    $assert((int)$resolved['store_id'] === $storeId && (string)$resolved['issuer_role_code'] === 'store_staff', 'staff_code_resolves_safe_store_context');

    $accepted = $service->accept($customerUid, [
        'acquisition_token' => $token, 'idempotency_key' => 'acquisition-accept-validation',
        'request_id' => 'acquisition-accept-validation',
    ]);
    $assert($accepted['accepted'] && !$accepted['idempotent_replay'], 'customer_accepts_staff_store_code');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $customerUid)->where('store_id', $storeId)->where('status', 'active')->count() === 1, 'customer_authoritatively_bound_to_store');
    $assert((int)Db::name('yfth_customer_relation')->where('uid', $customerUid)->where('store_id', $storeId)->where('status', 'active')->count() === 1, 'bound_customer_visible_in_store_crm');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $customerUid)->count() === 0, 'store_code_does_not_create_member_referral');
    $assert((int)Db::name('user')->where('uid', $customerUid)->value('spread_uid') === 0, 'store_code_does_not_write_crmeb_spread');

    $replay = $service->accept($customerUid, [
        'acquisition_token' => $token, 'idempotency_key' => 'acquisition-accept-validation-replay',
        'request_id' => 'acquisition-accept-validation-replay',
    ]);
    $assert($replay['idempotent_replay'], 'repeat_accept_is_idempotent');
    $expect(function () use ($service, $staffUid, $token) {
        $service->accept($staffUid, ['acquisition_token' => $token, 'idempotency_key' => 'self-bind-validation']);
    }, 'store_acquisition_self_bind_forbidden', 'employee_self_bind_rejected');

    app()->make(\app\services\yfth\HqCustomerAttributionServices::class)->ensurePlaceholder($otherUid);
    Db::name('yfth_hq_customer_attribution_current')->where('uid', $otherUid)->update([
        'store_id' => $storeId, 'status' => 'closed', 'authority_version' => 1,
        'source_type' => 'membership_activation', 'source_id' => 'historical',
        'status_reason_code' => 'membership_refunded', 'closed_at' => $now, 'update_time' => $now,
    ]);
    $expect(function () use ($service, $otherUid, $token) {
        $service->accept($otherUid, ['acquisition_token' => $token, 'idempotency_key' => 'historical-bind-validation']);
    }, 'attribution_current_store_inconsistent', 'non_pristine_customer_rejected');

    Db::name('yfth_user_store_role')->where('uid', $staffUid)->where('store_id', $storeId)->update(['status' => 'disabled']);
    $expect(function () use ($service, $token) { $service->resolve($token); }, 'store_acquisition_issuer_role_inactive', 'revoked_employee_invalidates_code');
    $assert((int)Db::name('yfth_store_acquisition_acceptance')->where('code_id', $codeId)->where('customer_uid', $customerUid)->count() === 1, 'single_acceptance_fact_persisted');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
}

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n"); exit(1); }
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH store acquisition real flow verified.\n";
