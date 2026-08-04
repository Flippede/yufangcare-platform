<?php

use app\services\yfth\CustomerServiceServices;
use app\services\yfth\MemberPointsServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_CUSTOMER_SERVICE_POINTS_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_CUSTOMER_SERVICE_POINTS_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$expect = function (callable $operation, string $message, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ':no_exception');
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $message) !== false, $label . ':' . $e->getMessage());
    }
};

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $uid = 996000001;
    $serviceUid = 996000002;
    $otherServiceUid = 996000003;
    $storeA = 99601;
    $storeB = 99602;
    cleanupCustomerServicePointsFixtures([$uid, $serviceUid, $otherServiceUid], [$storeA, $storeB]);
    $now = time();
    foreach ([$uid, $serviceUid, $otherServiceUid] as $fixtureUid) {
        Db::name('user')->insert([
            'uid' => $fixtureUid, 'account' => 'csp_' . $fixtureUid, 'nickname' => 'CSP ' . $fixtureUid,
            'phone' => '19' . substr((string)$fixtureUid . '000000000', 0, 9), 'status' => 1,
            'user_type' => 'wechat', 'uniqid' => 'csp' . $fixtureUid, 'integral' => 0, 'add_time' => $now,
        ]);
    }
    foreach ([$storeA => 'CSP Store A', $storeB => 'CSP Store B'] as $storeId => $name) {
        Db::name('system_store')->insert([
            'id' => $storeId, 'name' => $name, 'phone' => '19900000000', 'address' => 'isolated validation',
            'detailed_address' => 'isolated validation only', 'valid_time' => '00:00-23:59',
            'day_time' => '1,2,3,4,5,6,7', 'is_show' => 1, 'is_del' => 0, 'add_time' => $now,
        ]);
    }

    $points = app()->make(MemberPointsServices::class);
    $accrual = [
        'id' => 996100001, 'c1_uid' => $uid, 'c1_ratio_bps' => 1500, 'order_id' => 996200001,
        'rule_version_id' => 1, 'source_type' => 'package_activation',
    ];
    Db::transaction(function () use ($points, $accrual) { $points->creditAccrual($accrual, 147000); });
    $assert((int)Db::name('user')->where('uid', $uid)->value('integral') === 1470, 'package_reward_1470_yuan_becomes_1470_points');
    Db::transaction(function () use ($points, $accrual) { $points->creditAccrual($accrual, 147000); });
    $assert((int)Db::name('yfth_member_points_ledger')->where('uid', $uid)->count() === 1, 'points_credit_is_idempotent');
    Db::transaction(function () use ($points, $accrual) { $points->reverseAccrual($accrual, 47000, 'package_invalidated', 'reverse:1'); });
    $assert((int)Db::name('user')->where('uid', $uid)->value('integral') === 1000, 'points_reversal_reduces_shared_mall_integral');
    Db::transaction(function () use ($points, $accrual) { $points->reverseAccrual($accrual, 47000, 'package_invalidated', 'reverse:1'); });
    $assert((int)Db::name('yfth_member_points_ledger')->where('uid', $uid)->count() === 2, 'points_reversal_is_idempotent');

    $productId = (int)Db::name('store_product')->where(['is_show' => 1, 'is_del' => 0])->value('id');
    if ($productId <= 0) throw new RuntimeException('isolated_product_fixture_required');
    Db::name('yfth_member_points_config')->where('config_key', 'default')->update([
        'enabled' => 1, 'max_deduction_bps' => 8000, 'min_cash_cent' => 10, 'update_time' => $now,
    ]);
    $cart = [['product_id' => $productId, 'productInfo' => ['id' => $productId]]];
    $policy = $points->deductionPolicy($cart, '100.00');
    $assert(!empty($policy['eligible']) && (string)$policy['max_deduction'] === '80.00', 'ordinary_product_uses_configured_80_percent_cap');
    $assert(empty($points->deductionPolicy([['product_id' => $productId, 'yfth_channel' => 'procurement']], '100.00')['eligible']), 'procurement_never_uses_member_points');
    Db::name('yfth_member_points_product_rule')->insert([
        'product_id' => $productId, 'enabled' => 0, 'max_deduction_bps' => 0,
        'operator_uid' => 1, 'add_time' => $now, 'update_time' => $now,
    ]);
    $assert(empty($points->deductionPolicy($cart, '100.00')['eligible']), 'disabled_product_rule_fails_closed');

    $customerService = app()->make(CustomerServiceServices::class);
    $first = $customerService->grant($serviceUid, ['store_id' => $storeA, 'reason' => 'isolated validation', 'request_id' => 'csp-a'], 1);
    $replay = $customerService->grant($serviceUid, ['store_id' => $storeA, 'reason' => 'isolated validation', 'request_id' => 'csp-a-replay'], 1);
    $assert((int)$first['id'] === (int)$replay['id'], 'customer_service_store_grant_is_idempotent');
    $customerService->grant($serviceUid, ['store_id' => $storeB, 'reason' => 'isolated validation', 'request_id' => 'csp-b'], 1);
    $workbench = $customerService->workbench($serviceUid);
    $assert((int)$workbench['assigned_store_count'] === 2 && count($workbench['stores']) === 2, 'one_customer_service_can_serve_many_b_stores');
    $assert(!isset($workbench['customers']) && !isset($workbench['earnings']) && !isset($workbench['withdrawal']), 'customer_service_cannot_read_c_users_or_finance');
    $expect(function () use ($customerService, $otherServiceUid, $storeA) {
        $customerService->grant($otherServiceUid, ['store_id' => $storeA, 'reason' => 'isolated conflict'], 1);
    }, 'store_customer_service_already_assigned', 'one_store_has_only_one_current_customer_service');
    $customerService->revokeBinding((int)$first['id'], 'isolated revoke', 1);
    $assert((int)Db::name('yfth_user_identity')->where(['uid' => $serviceUid, 'role_code' => 'customer_service', 'status' => 'active'])->count() === 1,
        'customer_service_identity_remains_with_another_store');
    $lastBindingId = (int)Db::name('yfth_customer_service_store_binding')->where([
        'customer_service_uid' => $serviceUid, 'store_id' => $storeB, 'status' => 'active',
    ])->value('id');
    $customerService->revokeBinding($lastBindingId, 'isolated final revoke', 1);
    $assert((int)Db::name('yfth_user_identity')->where(['uid' => $serviceUid, 'role_code' => 'customer_service', 'status' => 'active'])->count() === 0,
        'last_store_revoke_disables_customer_service_identity');

    cleanupCustomerServicePointsFixtures([$uid, $serviceUid, $otherServiceUid], [$storeA, $storeB]);
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH customer service and member points real flow verified.\n";

function cleanupCustomerServicePointsFixtures(array $uids, array $storeIds): void
{
    Db::name('yfth_customer_service_store_binding')->whereIn('customer_service_uid', $uids)->delete();
    Db::name('yfth_user_identity')->whereIn('uid', $uids)->where('role_code', 'customer_service')->delete();
    Db::name('yfth_member_points_ledger')->whereIn('uid', $uids)->delete();
    Db::name('yfth_member_points_account')->whereIn('uid', $uids)->delete();
    Db::name('yfth_member_points_product_rule')->where('operator_uid', 1)->delete();
    Db::name('yfth_audit_event')->where('business_domain', 'customer_service')->where('operator_uid', 1)->delete();
    Db::name('user_bill')->whereIn('uid', $uids)->delete();
    Db::name('user')->whereIn('uid', $uids)->delete();
    Db::name('system_store')->whereIn('id', $storeIds)->delete();
}
