<?php

use app\services\yfth\HqUserRoleManagementServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};
$expect = function (callable $operation, string $message, string $label) use ($assert): void {
    try { $operation(); $assert(false, $label . ':no_exception'); }
    catch (Throwable $e) { $assert(strpos($e->getMessage(), $message) !== false, $label . ':' . $e->getMessage()); }
};

if ((string)getenv('YFTH_USER_ROLE_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_USER_ROLE_REAL_FLOW_EXECUTE=1\n";
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

    $uid = 970001; $storeA = 9701; $storeB = 9702; $now = time();
    Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_role_management')->delete();
    Db::name('yfth_user_store_role')->where('uid', $uid)->delete();
    Db::name('user')->where('uid', $uid)->delete();
    Db::name('system_store')->whereIn('id', [$storeA, $storeB])->delete();
    Db::name('user')->insert([
        'uid' => $uid, 'account' => 'role_validation_user', 'nickname' => 'Role Validation',
        'phone' => '13900097001', 'status' => 1, 'user_type' => 'wechat', 'uniqid' => 'role-validation-user',
        'now_money' => '123.45', 'integral' => 678, 'add_time' => $now,
    ]);
    foreach ([$storeA => 'Role Store A', $storeB => 'Role Store B'] as $id => $name) {
        Db::name('system_store')->insert([
            'id' => $id, 'name' => $name, 'phone' => '13800000000', 'address' => 'isolated validation',
            'detailed_address' => 'isolated validation only', 'valid_time' => '00:00-23:59',
            'day_time' => '1,2,3,4,5,6,7', 'is_show' => 1, 'is_del' => 0, 'add_time' => $now,
        ]);
    }

    $service = app()->make(HqUserRoleManagementServices::class);
    $hq = ['id' => 1, 'level' => 0];
    $storeAdmin = ['id' => 9709, 'level' => 1];
    $assetBefore = Db::name('user')->where('uid', $uid)->field('now_money,integral')->find();
    $identityBefore = (int)Db::name('yfth_user_identity')->where('uid', $uid)->count();
    $membershipBefore = (int)Db::name('yfth_permanent_membership')->where('uid', $uid)->count();

    $search = $service->users(['keyword' => '13900097001', 'page' => 1, 'limit' => 20], $hq);
    $assert(count($search['list']) === 1 && (int)$search['list'][0]['uid'] === $uid, 'headquarters_searches_real_user');
    $assert((string)$search['list'][0]['phone_masked'] === '139****7001', 'admin_dto_masks_phone');
    $assert((string)$search['list'][0]['mall_balance'] === '123.45' && (string)$search['list'][0]['mall_integral'] === '678', 'headquarters_dto_reads_crmeb_assets');

    $grantA = $service->grant($uid, ['store_id' => $storeA, 'role_code' => 'franchisee', 'reason' => 'isolated grant A', 'request_id' => 'role-grant-a'], 1, $hq);
    $grantB = $service->grant($uid, ['store_id' => $storeB, 'role_code' => 'store_manager', 'reason' => 'isolated grant B', 'request_id' => 'role-grant-b'], 1, $hq);
    $grantStaff = $service->grant($uid, ['store_id' => $storeA, 'role_code' => 'store_staff', 'reason' => 'isolated grant staff', 'request_id' => 'role-grant-staff'], 1, $hq);
    $assert($grantA['changed'] && $grantB['changed'] && $grantStaff['changed'], 'headquarters_grants_three_store_roles');
    $assert((int)Db::name('yfth_user_store_role')->where('uid', $uid)->where('status', 'active')->count() === 3, 'multiple_store_roles_coexist');
    $summaries = $service->summaries([$uid], $hq);
    $assert(count($summaries[$uid]['store_roles'] ?? []) === 3, 'native_user_list_receives_yfth_role_summary');
    $replay = $service->grant($uid, ['store_id' => $storeA, 'role_code' => 'franchisee', 'reason' => 'isolated replay', 'request_id' => 'role-grant-a-replay'], 1, $hq);
    $assert(!$replay['changed'] && $replay['idempotent'], 'duplicate_grant_is_idempotent');

    $expect(function () use ($service, $uid, $storeA, $storeAdmin) {
        $service->grant($uid, ['store_id' => $storeA, 'role_code' => 'store_manager', 'reason' => 'forbidden escalation'], 9709, $storeAdmin);
    }, 'headquarter_permission_required', 'store_admin_cannot_grant_role');
    Db::name('system_store')->where('id', $storeB)->update(['is_show' => 0]);
    $expect(function () use ($service, $uid, $storeB, $hq) {
        $service->grant($uid, ['store_id' => $storeB, 'role_code' => 'store_staff', 'reason' => 'inactive store'], 1, $hq);
    }, 'store_not_active', 'inactive_store_role_rejected');
    Db::name('system_store')->where('id', $storeB)->update(['is_show' => 1]);

    $revoked = $service->revoke((int)$grantStaff['role']['id'], ['reason' => 'isolated revoke', 'request_id' => 'role-revoke-staff'], 1, $hq);
    $assert($revoked['changed'] && (string)$revoked['role']['status'] === 'disabled', 'headquarters_revokes_store_role');
    $assert((int)Db::name('yfth_user_store_role')->where('uid', $uid)->where('status', 'active')->count() === 2, 'revoke_preserves_other_store_roles');
    $revokeReplay = $service->revoke((int)$grantStaff['role']['id'], ['reason' => 'isolated revoke replay'], 1, $hq);
    $assert(!$revokeReplay['changed'] && $revokeReplay['idempotent'], 'duplicate_revoke_is_idempotent');

    $assetAfter = Db::name('user')->where('uid', $uid)->field('now_money,integral')->find();
    $assert($assetBefore === $assetAfter, 'role_changes_do_not_write_crmeb_assets');
    $assert($identityBefore === (int)Db::name('yfth_user_identity')->where('uid', $uid)->count(), 'customer_identity_is_not_overwritten');
    $assert($membershipBefore === (int)Db::name('yfth_permanent_membership')->where('uid', $uid)->count(), 'membership_is_not_overwritten');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_role_management')->where('action', 'grant')->count() === 3, 'grant_audit_written');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_role_management')->where('action', 'revoke')->count() === 1, 'revoke_audit_written');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
}

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n"); exit(1); }
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH headquarters user role management real flow verified.\n";
