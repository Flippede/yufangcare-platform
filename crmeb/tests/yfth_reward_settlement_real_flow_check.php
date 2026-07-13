<?php

if ((string)getenv('YFTH_REWARD_SETTLEMENT_REAL_FLOW_EXECUTE') !== '1') {
    fwrite(STDERR, "Set YFTH_REWARD_SETTLEMENT_REAL_FLOW_EXECUTE=1 with an isolated MySQL 8 database to run this check.\n");
    exit(2);
}

require_once __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

use app\services\yfth\DirectReferralRewardSettlementServices;
use app\services\yfth\DirectReferralRewardServices;
use think\facade\Db;

$app = packageMembershipReferralBootTestApp();
$version = (string)Db::query('SELECT VERSION() AS version')[0]['version'];
if (strpos($version, '8.0.') !== 0) {
    throw new RuntimeException('isolated_mysql_8_required');
}
$now = time();
$suffix = $now . bin2hex(random_bytes(3));
$referrerUid = random_int(300000000, 399999999);
$referredUid = $referrerUid + 1;
$otherReferrerUid = $referrerUid + 2;
$storeId = random_int(910000, 919999);
$operatorUid = $referrerUid + 100;
$candidate = [
    'candidate_no' => 'YFTESTSETTLE' . $suffix,
    'candidate_type' => 'mall_consumption',
    'referrer_uid' => $referrerUid,
    'referred_uid' => $referredUid,
    'store_id' => $storeId,
    'relation_id' => 1,
    'source_business_type' => 'store_order',
    'source_business_id' => $suffix,
    'source_unique_key' => hash('sha256', 'stage4-real-flow-' . $suffix),
    'actual_paid_amount_cent' => 980000,
    'ratio_bps' => 1500,
    'reward_amount_cent' => 147000,
    'rule_version_id' => 1,
    'status' => 'pending',
    'responsibility_type' => 'store_mall_revenue',
    'add_time' => $now,
    'update_time' => $now,
];
$candidateId = (int)Db::name('yfth_direct_referral_reward_candidate')->insertGetId($candidate);
$packageCandidate = array_merge($candidate, [
    'candidate_no' => 'YFTESTPACKAGE' . $suffix,
    'candidate_type' => 'package_activation',
    'source_business_type' => 'package_instance',
    'source_business_id' => $suffix,
    'source_unique_key' => hash('sha256', 'stage4-package-real-flow-' . $suffix),
    'reward_sequence_no' => 1,
]);
$packageCandidateId = (int)Db::name('yfth_direct_referral_reward_candidate')->insertGetId($packageCandidate);
$service = $app->make(DirectReferralRewardSettlementServices::class);
$directRewards = $app->make(DirectReferralRewardServices::class);
$store = ['uid' => $operatorUid, 'role_code' => 'store_manager', 'store_id' => $storeId];
$confirmed = $service->confirmByStore($candidateId, $store, ['request_id' => 'stage4-confirm-' . $suffix, 'remark' => 'verified']);
if (($confirmed['candidate']['status'] ?? '') !== 'confirmed') {
    throw new RuntimeException('confirm_failed');
}
$confirmReplay = $service->confirmByStore($candidateId, $store, ['request_id' => 'stage4-confirm-replay-' . $suffix, 'remark' => 'verified']);
if (empty($confirmReplay['idempotent_replay']) || ($confirmReplay['candidate']['status'] ?? '') !== 'confirmed') {
    throw new RuntimeException('confirm_idempotency_failed');
}
$packageConfirmed = $service->confirmByStore($packageCandidateId, $store, ['request_id' => 'stage4-package-confirm-' . $suffix, 'remark' => 'verified']);
if (($packageConfirmed['candidate']['candidate_type'] ?? '') !== 'package_activation' || ($packageConfirmed['candidate']['status'] ?? '') !== 'confirmed') {
    throw new RuntimeException('package_candidate_confirm_failed');
}
$settled = $service->settleByStore($candidateId, $store, ['request_id' => 'stage4-settle-' . $suffix, 'offline_ref_no' => 'OFF-' . $suffix, 'remark' => 'offline settlement recorded']);
if (($settled['candidate']['status'] ?? '') !== 'settled' || empty($settled['candidate']['settlement']['settlement_no'])) {
    throw new RuntimeException('settle_failed');
}
$replay = $service->settleByStore($candidateId, $store, ['request_id' => 'stage4-settle-' . $suffix, 'offline_ref_no' => 'OFF-' . $suffix, 'remark' => 'offline settlement recorded']);
if (empty($replay['idempotent_replay']) || Db::name('yfth_direct_referral_reward_settlement_ledger')->where('candidate_id', $candidateId)->count() !== 1) {
    throw new RuntimeException('settle_idempotency_failed');
}
Db::name('yfth_direct_referral_reward_candidate')->where('id', $packageCandidateId)->update(['status' => 'cancelled', 'update_time' => time()]);
try {
    $service->settleByStore($packageCandidateId, $store, ['request_id' => 'stage4-cancelled-' . $suffix, 'offline_ref_no' => 'OFF-CANCELLED', 'remark' => 'must fail']);
    throw new RuntimeException('cancelled_candidate_settled');
} catch (\Throwable $e) {
    if ($e->getMessage() === 'cancelled_candidate_settled') {
        throw $e;
    }
}
try {
    $service->confirmByStore($candidateId, ['uid' => $operatorUid + 1, 'role_code' => 'store_manager', 'store_id' => $storeId + 1], ['request_id' => 'stage4-cross-' . $suffix]);
    throw new RuntimeException('cross_store_not_rejected');
} catch (\Throwable $e) {
    if ($e->getMessage() === 'cross_store_not_rejected') {
        throw $e;
    }
}

// User reads are restricted to the referrer's own candidates and omit internal fields.
Db::name('yfth_permanent_membership')->insert([
    'membership_no' => 'YFTESTMEMBER' . $suffix,
    'uid' => $referrerUid,
    'store_id' => $storeId,
    'source_package_instance_id' => $referrerUid,
    'source_purchase_id' => $referrerUid,
    'source_rule_version_id' => 1,
    'actual_paid_amount_cent' => 980000,
    'currency' => 'CNY',
    'status' => 'active',
    'authority_version' => 1,
    'source_type' => 'stage4_real_flow',
    'activated_at' => $now,
    'request_id' => substr(hash('sha256', 'stage4-membership-' . $suffix), 0, 64),
    'add_time' => $now,
    'update_time' => $now,
]);
$otherCandidate = array_merge($candidate, [
    'candidate_no' => 'YFTESTOTHER' . $suffix,
    'referrer_uid' => $otherReferrerUid,
    'source_business_id' => 'other-' . $suffix,
    'source_unique_key' => hash('sha256', 'stage4-other-' . $suffix),
]);
Db::name('yfth_direct_referral_reward_candidate')->insert($otherCandidate);
$userCandidates = $directRewards->userCandidates($referrerUid);
$userNumbers = array_column($userCandidates['list'] ?? [], 'candidate_no');
if (!in_array((string)$candidate['candidate_no'], $userNumbers, true)
    || in_array((string)$otherCandidate['candidate_no'], $userNumbers, true)) {
    throw new RuntimeException('user_candidate_scope_failed');
}
foreach ($userCandidates['list'] as $row) {
    foreach (['referrer_uid', 'referred_uid', 'reward_sequence_no', 'rule_version_id', 'source_business_id'] as $forbidden) {
        if (array_key_exists($forbidden, $row)) {
            throw new RuntimeException('user_candidate_dto_leak_' . $forbidden);
        }
    }
}

// A full refund cancels pending and confirmed mall candidates, but never reverses settlement.
$makeRefundCandidate = function (string $state) use ($candidate, $suffix, $now, $referredUid, $directRewards) {
    $orderSn = 'YFTESTREFUND' . strtoupper($state) . $suffix;
    $orderId = (int)Db::name('store_order')->insertGetId([
        'order_id' => substr($orderSn, 0, 32),
        'uid' => $referredUid,
        'paid' => 1,
        'pay_time' => $now,
        'pay_price' => '98.00',
        'refund_status' => 2,
        'refund_price' => '98.00',
        'add_time' => $now,
        'unique' => md5($orderSn),
    ]);
    $row = array_merge($candidate, [
        'candidate_no' => 'YFTESTREF' . strtoupper($state) . $suffix,
        'source_business_id' => (string)$orderId,
        'source_unique_key' => hash('sha256', 'mall_consumption|store_order|' . $orderId),
        'status' => $state,
        'add_time' => time(),
        'update_time' => time(),
    ]);
    $candidateId = (int)Db::name('yfth_direct_referral_reward_candidate')->insertGetId($row);
    return [$candidateId, substr($orderSn, 0, 32)];
};
[$pendingRefundCandidateId, $pendingOrderSn] = $makeRefundCandidate('pending');
[$confirmedRefundCandidateId, $confirmedOrderSn] = $makeRefundCandidate('confirmed');
[$settledRefundCandidateId, $settledOrderSn] = $makeRefundCandidate('settled');
foreach ([[$pendingRefundCandidateId, $pendingOrderSn], [$confirmedRefundCandidateId, $confirmedOrderSn]] as $refundCase) {
    $result = $directRewards->cancelMallOrderCandidateAfterFullRefund($refundCase[1]);
    if (empty($result['changed'])
        || (string)Db::name('yfth_direct_referral_reward_candidate')->where('id', $refundCase[0])->value('status') !== 'cancelled') {
        throw new RuntimeException('full_refund_unsettled_candidate_not_cancelled');
    }
}
$settledRefund = $directRewards->cancelMallOrderCandidateAfterFullRefund($settledOrderSn);
if (!empty($settledRefund['changed'])
    || (string)($settledRefund['reason'] ?? '') !== 'mall_consumption_candidate_not_unsettled'
    || (string)Db::name('yfth_direct_referral_reward_candidate')->where('id', $settledRefundCandidateId)->value('status') !== 'settled') {
    throw new RuntimeException('full_refund_settled_candidate_reversed');
}
try {
    $service->correctByHeadquarters($candidateId, 1, ['request_id' => 'stage4-settled-correct-' . $suffix, 'reason' => 'must fail']);
    throw new RuntimeException('settled_candidate_reverted');
} catch (\Throwable $e) {
    if ($e->getMessage() === 'settled_candidate_reverted') {
        throw $e;
    }
}
if (Db::name('yfth_audit_event')->where('object_type', 'direct_referral_reward_candidate')->where('object_id', (string)$candidateId)->count() < 2) {
    throw new RuntimeException('audit_missing');
}
echo "YFTH reward settlement isolated real flow passed\n";
