<?php

if ((string)getenv('YFTH_RELEASE_CANDIDATE_REAL_FLOW_EXECUTE') !== '1') {
    fwrite(STDERR, "Set YFTH_RELEASE_CANDIDATE_REAL_FLOW_EXECUTE=1 with an isolated MySQL 8 database to run this check.\n");
    exit(2);
}

require_once __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

use app\listener\yfth\MallConsumptionRewardPayListener;
use app\services\yfth\DirectReferralRewardServices;
use app\services\yfth\DirectReferralRewardSettlementServices;
use app\services\yfth\HqAuthorityMutation;
use app\services\yfth\HqAuthoritySource;
use app\services\yfth\HqCustomerAttributionServices;
use app\services\yfth\PackageMembershipActivationCoordinator;
use app\services\yfth\PackageMembershipReferralServices;
use app\services\yfth\PackageMembershipServices;
use think\facade\Config;
use think\facade\Db;

$app = packageMembershipReferralBootTestApp();
$version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
$default = (string)Config::get('database.default');
$database = (string)Config::get('database.connections.' . $default . '.database');
if ((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') !== '1'
    || strpos($version, '8.0.') !== 0
    || !preg_match('/(validation|sandbox|test)/i', $database)) {
    throw new RuntimeException('isolated_mysql_8_required');
}

$storeId = 9701;
$c1 = 970001;
$c2 = 970002;
$now = time();
$assert = function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'yfth_direct_referral_reward_settlement_ledger', 'yfth_direct_referral_reward_candidate',
    'yfth_direct_referral_rule_version', 'yfth_direct_referral_invite',
    'yfth_permanent_membership_event', 'yfth_permanent_membership',
    'yfth_hq_active_referral_event', 'yfth_hq_active_referral_current',
    'yfth_hq_customer_attribution_event', 'yfth_hq_customer_attribution_current',
    'yfth_idempotency_record', 'yfth_audit_event',
] as $table) {
    Db::name($table)->delete(true);
}
Db::name('store_order')->whereBetween('uid', [$c1, $c2])->delete();
Db::name('user')->whereBetween('uid', [$c1, $c2])->delete();
Db::name('system_store')->where('id', $storeId)->delete();

foreach ([$c1, $c2] as $uid) {
    Db::name('user')->insert([
        'uid' => $uid,
        'account' => 'rc' . $uid,
        'nickname' => 'Release candidate ' . $uid,
        'phone' => '139' . substr((string)$uid . '000000', 0, 8),
        'status' => 1,
        'user_type' => 'wechat',
        'uniqid' => 'rc' . $uid,
        'add_time' => $now,
    ]);
}
Db::name('system_store')->insert([
    'id' => $storeId,
    'name' => 'Release candidate B1',
    'phone' => '13800000000',
    'address' => 'isolated validation',
    'detailed_address' => 'isolated validation only',
    'valid_time' => '00:00-23:59',
    'day_time' => '1,2,3,4,5,6,7',
    'is_show' => 1,
    'is_del' => 0,
    'add_time' => $now,
]);

$attribution = $app->make(HqCustomerAttributionServices::class);
$membership = $app->make(PackageMembershipServices::class);
$referral = $app->make(PackageMembershipReferralServices::class);
$reward = $app->make(DirectReferralRewardServices::class);
$settlement = $app->make(DirectReferralRewardSettlementServices::class);
$activation = $app->make(PackageMembershipActivationCoordinator::class);
$mallPayListener = $app->make(MallConsumptionRewardPayListener::class);

$mutation = new HqAuthorityMutation(
    HqAuthoritySource::fromTrusted('historical_package_activation', $c1),
    $c1,
    'customer',
    'release_candidate_seed',
    'release-candidate-c1-attribution',
    'release-candidate-c1-attribution'
);
$attribution->assignFirst($c1, $storeId, $mutation);
Db::transaction(function () use ($membership, $c1, $storeId, $now) {
    $membership->grantFromPackageInTransaction([
        'id' => $c1,
        'uid' => $c1,
        'store_id' => $storeId,
        'rule_version_id' => 1,
    ], [
        'order_pay_price' => '5980.00',
        'currency' => 'CNY',
        'paid_time' => $now,
    ], $c1, 'historical_package_activation', 'release-candidate-c1-membership');
});
$assert(!empty($membership->effectiveMembership($c1)['is_member']), 'c1_permanent_membership_missing');

$rule = $reward->saveRule([
    'package_ratio_first_bps' => 1500,
    'package_ratio_second_bps' => 2500,
    'package_ratio_third_bps' => 6000,
    'mall_consumption_enabled' => 1,
    'mall_consumption_ratio_bps' => 700,
], 1);
$rule = $reward->publishRule((int)$rule['id'], 1);
$assert((string)$rule['status'] === 'published', 'reward_rule_not_published');

$invite = $referral->issueInvite($c1, ['request_id' => 'release-candidate-invite']);
$referral->acceptInvite($c2, (string)$invite['invite_token'], [
    'idempotency_key' => 'release-candidate-accept',
    'request_id' => 'release-candidate-accept',
]);
$activeReferral = (array)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $c2)->find();
$assert((string)($activeReferral['status'] ?? '') === 'active' && (int)($activeReferral['store_id'] ?? 0) === $storeId,
    'c2_active_referral_or_b1_missing');

$orderId = rcCreatePaidOrder($c2, '123.45', 'mall-before-package');
$mallPayListener->handle([(array)Db::name('store_order')->where('id', $orderId)->find()]);
$mallCandidate = (array)Db::name('yfth_direct_referral_reward_candidate')
    ->where('source_business_id', (string)$orderId)->where('candidate_type', 'mall_consumption')->find();
$assert((int)($mallCandidate['id'] ?? 0) > 0 && (int)$mallCandidate['referrer_uid'] === $c1
    && (int)$mallCandidate['store_id'] === $storeId && (string)$mallCandidate['status'] === 'pending',
    'mall_payment_candidate_missing');

Db::transaction(function () use ($activation, $c2, $storeId, $now) {
    $result = $activation->activateInTransaction([
        'id' => $c2,
        'uid' => $c2,
        'store_id' => $storeId,
        'rule_version_id' => 1,
    ], [
        'grants_permanent_membership' => 1,
        'order_pay_price' => '5980.00',
        'currency' => 'CNY',
        'paid_time' => $now,
    ], $c2);
    if (empty($result['membership_created']) || empty($result['relation_closed']) || empty($result['reward_candidate_created'])) {
        throw new RuntimeException('package_activation_chain_incomplete');
    }
});
$assert(!empty($membership->effectiveMembership($c2)['is_member']), 'c2_permanent_membership_missing');
$assert((string)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $c2)->value('status') === 'closed',
    'package_activation_did_not_close_referral');
$packageCandidate = (array)Db::name('yfth_direct_referral_reward_candidate')
    ->where('referred_uid', $c2)->where('candidate_type', 'package_activation')->find();
$assert((int)($packageCandidate['id'] ?? 0) > 0 && (int)$packageCandidate['ratio_bps'] === 1500,
    'package_candidate_snapshot_missing');

$laterOrderId = rcCreatePaidOrder($c2, '66.00', 'mall-after-package');
$mallPayListener->handle([(array)Db::name('store_order')->where('id', $laterOrderId)->find()]);
$assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$laterOrderId)->count() === 0,
    'closed_referral_generated_later_mall_candidate');

$fundingBefore = Db::name('user')->whereIn('uid', [$c1, $c2])
    ->order('uid asc')->column('now_money,brokerage_price,integral', 'uid');
$storeContext = ['uid' => 970101, 'role_code' => 'store_manager', 'store_id' => $storeId];
$confirmed = $settlement->confirmByStore((int)$mallCandidate['id'], $storeContext, [
    'request_id' => 'release-candidate-confirm', 'remark' => 'B1 confirmed offline responsibility',
]);
$settled = $settlement->settleByStore((int)$mallCandidate['id'], $storeContext, [
    'request_id' => 'release-candidate-settle', 'offline_ref_no' => 'RC-OFFLINE-001',
    'remark' => 'B1 recorded offline settlement',
]);
$assert((string)($confirmed['candidate']['status'] ?? '') === 'confirmed'
    && (string)($settled['candidate']['status'] ?? '') === 'settled'
    && !empty($settled['candidate']['settlement']['settlement_no']), 'store_settlement_chain_failed');
$fundingAfter = Db::name('user')->whereIn('uid', [$c1, $c2])
    ->order('uid asc')->column('now_money,brokerage_price,integral', 'uid');
$assert($fundingBefore === $fundingAfter, 'settlement_changed_crmeb_funding_fields');

$userRows = $reward->userCandidates($c1)['list'] ?? [];
$storeRows = $settlement->storeCandidates($storeId, [])['list'] ?? [];
$hqRows = $settlement->headquartersCandidates(['store_id' => $storeId])['list'] ?? [];
$assert((bool)array_filter($userRows, function ($row) use ($mallCandidate) {
    return (string)$row['candidate_no'] === (string)$mallCandidate['candidate_no'] && (string)$row['status'] === 'settled';
}), 'c1_cannot_read_own_settlement_status');
$assert((bool)array_filter($storeRows, function ($row) use ($mallCandidate) {
    return (int)$row['id'] === (int)$mallCandidate['id'] && (int)$row['store_id'] === 9701;
}), 'b1_cannot_read_own_candidate');
$assert((bool)array_filter($hqRows, function ($row) use ($mallCandidate) {
    return (int)$row['id'] === (int)$mallCandidate['id'];
}), 'headquarters_cannot_read_candidate');

echo "YFTH release candidate isolated core flow passed\n";

function rcCreatePaidOrder(int $uid, string $amount, string $suffix): int
{
    $now = time();
    return (int)Db::name('store_order')->insertGetId([
        'order_id' => 'RC' . strtoupper(substr(hash('sha256', $suffix . microtime(true)), 0, 20)),
        'uid' => $uid,
        'pay_price' => $amount,
        'total_price' => $amount,
        'paid' => 1,
        'pay_time' => $now,
        'pay_type' => 'test',
        'add_time' => $now,
        'unique' => substr(hash('md5', $suffix . microtime(true)), 0, 32),
        'store_id' => 0,
        'pid' => 0,
        'status' => 0,
        'refund_status' => 0,
        'refund_price' => '0.00',
        'is_del' => 0,
        'is_system_del' => 0,
        'is_cancel' => 0,
    ]);
}
