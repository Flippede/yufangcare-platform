<?php

if ((string)getenv('YFTH_REWARD_SETTLEMENT_REAL_FLOW_EXECUTE') !== '1') {
    fwrite(STDERR, "Set YFTH_REWARD_SETTLEMENT_REAL_FLOW_EXECUTE=1 with an isolated MySQL 8 database to run this check.\n");
    exit(2);
}

require_once __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

use app\services\yfth\DirectReferralRewardSettlementServices;
use think\facade\Db;

$app = packageMembershipReferralBootTestApp();
$version = (string)Db::query('SELECT VERSION() AS version')[0]['version'];
if (strpos($version, '8.0.') !== 0) {
    throw new RuntimeException('isolated_mysql_8_required');
}
$now = time();
$candidate = [
    'candidate_no' => 'YFTESTSETTLE' . $now,
    'candidate_type' => 'mall_consumption',
    'referrer_uid' => 91001,
    'referred_uid' => 91002,
    'store_id' => 910,
    'relation_id' => 1,
    'source_business_type' => 'store_order',
    'source_business_id' => '91001',
    'source_unique_key' => hash('sha256', 'stage4-real-flow-' . $now),
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
    'candidate_no' => 'YFTESTPACKAGE' . $now,
    'candidate_type' => 'package_activation',
    'source_business_type' => 'package_instance',
    'source_business_id' => '91002',
    'source_unique_key' => hash('sha256', 'stage4-package-real-flow-' . $now),
    'reward_sequence_no' => 1,
]);
$packageCandidateId = (int)Db::name('yfth_direct_referral_reward_candidate')->insertGetId($packageCandidate);
$service = $app->make(DirectReferralRewardSettlementServices::class);
$store = ['uid' => 92001, 'role_code' => 'store_manager', 'store_id' => 910];
$confirmed = $service->confirmByStore($candidateId, $store, ['request_id' => 'stage4-confirm-' . $now, 'remark' => 'verified']);
if (($confirmed['candidate']['status'] ?? '') !== 'confirmed') {
    throw new RuntimeException('confirm_failed');
}
$packageConfirmed = $service->confirmByStore($packageCandidateId, $store, ['request_id' => 'stage4-package-confirm-' . $now, 'remark' => 'verified']);
if (($packageConfirmed['candidate']['candidate_type'] ?? '') !== 'package_activation' || ($packageConfirmed['candidate']['status'] ?? '') !== 'confirmed') {
    throw new RuntimeException('package_candidate_confirm_failed');
}
$settled = $service->settleByStore($candidateId, $store, ['request_id' => 'stage4-settle-' . $now, 'offline_ref_no' => 'OFF-' . $now, 'remark' => 'offline settlement recorded']);
if (($settled['candidate']['status'] ?? '') !== 'settled' || empty($settled['candidate']['settlement']['settlement_no'])) {
    throw new RuntimeException('settle_failed');
}
$replay = $service->settleByStore($candidateId, $store, ['request_id' => 'stage4-settle-' . $now, 'offline_ref_no' => 'OFF-' . $now, 'remark' => 'offline settlement recorded']);
if (empty($replay['idempotent_replay']) || Db::name('yfth_direct_referral_reward_settlement_ledger')->where('candidate_id', $candidateId)->count() !== 1) {
    throw new RuntimeException('settle_idempotency_failed');
}
Db::name('yfth_direct_referral_reward_candidate')->where('id', $packageCandidateId)->update(['status' => 'cancelled', 'update_time' => time()]);
try {
    $service->settleByStore($packageCandidateId, $store, ['request_id' => 'stage4-cancelled-' . $now, 'offline_ref_no' => 'OFF-CANCELLED', 'remark' => 'must fail']);
    throw new RuntimeException('cancelled_candidate_settled');
} catch (\Throwable $e) {
    if ($e->getMessage() === 'cancelled_candidate_settled') {
        throw $e;
    }
}
try {
    $service->confirmByStore($candidateId, ['uid' => 92002, 'role_code' => 'store_manager', 'store_id' => 911], ['request_id' => 'stage4-cross-' . $now]);
    throw new RuntimeException('cross_store_not_rejected');
} catch (\Throwable $e) {
    if ($e->getMessage() === 'cross_store_not_rejected') {
        throw $e;
    }
}
if (Db::name('yfth_audit_event')->where('object_type', 'direct_referral_reward_candidate')->where('object_id', (string)$candidateId)->count() < 2) {
    throw new RuntimeException('audit_missing');
}
echo "YFTH reward settlement isolated real flow passed\n";
