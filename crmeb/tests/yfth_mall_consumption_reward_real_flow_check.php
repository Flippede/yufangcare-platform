<?php

use app\listener\yfth\MallConsumptionRewardCustomEventListener;
use app\listener\yfth\MallConsumptionRewardPayListener;
use app\services\yfth\DirectReferralRewardServices;
use app\services\yfth\HqActiveReferralServices;
use app\services\yfth\HqAuthorityMutation;
use app\services\yfth\HqAuthoritySource;
use app\services\yfth\HqCustomerAttributionServices;
use app\services\yfth\PackageMembershipReferralServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

if ((string)getenv('YFTH_MALL_CONSUMPTION_REWARD_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_MALL_CONSUMPTION_REWARD_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test|local|dev|v1)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    st3Cleanup();
    st3CreateFixtures();

    $storeA = 9301;
    $storeB = 9302;
    $c1 = 930001;
    $c2 = 930002;
    $otherMember = 930006;
    $attribution = app()->make(HqCustomerAttributionServices::class);
    $referrals = app()->make(PackageMembershipReferralServices::class);
    $reward = app()->make(DirectReferralRewardServices::class);
    $payListener = app()->make(MallConsumptionRewardPayListener::class);
    $refundListener = app()->make(MallConsumptionRewardCustomEventListener::class);

    $attribution->assignFirst($c1, $storeA, st3Mutation('historical_package_activation', 930001, 'stage3-c1-attribution'));
    st3CreateMember($c1, $storeA, 930001);
    st3CreateMember($otherMember, $storeB, 930006);
    $relation = st3CreateReferral($referrals, $c1, $c2, 'primary');

    $noRuleOrder = st3CreateOrder($c2, '88.00', 'no-rule');
    $noRuleBefore = st3OrderSnapshot($noRuleOrder);
    st3Pay($payListener, $noRuleOrder);
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$noRuleOrder)->count() === 0,
        'missing_ratio_rule_generates_no_candidate');
    $assert(st3OrderSnapshot($noRuleOrder) === $noRuleBefore, 'missing_rule_does_not_change_paid_order');

    $rule = $reward->saveRule([
        'package_ratio_first_bps' => 1500,
        'package_ratio_second_bps' => 2500,
        'package_ratio_third_bps' => 6000,
        'mall_consumption_enabled' => 1,
        'mall_consumption_ratio_bps' => 700,
    ], 1);
    $rule = $reward->publishRule((int)$rule['id'], 1);

    $paidOrder = st3CreateOrder($c2, '123.45', 'paid-main');
    $paidBefore = st3OrderSnapshot($paidOrder);
    st3Pay($payListener, $paidOrder);
    $candidate = (array)Db::name('yfth_direct_referral_reward_candidate')
        ->where('source_business_id', (string)$paidOrder)->where('candidate_type', 'mall_consumption')->find();
    $assert((int)($candidate['id'] ?? 0) > 0, 'paid_headquarters_mall_order_creates_candidate');
    $assert((int)$candidate['referrer_uid'] === $c1 && (int)$candidate['referred_uid'] === $c2
        && (int)$candidate['store_id'] === $storeA, 'candidate_snapshots_c1_c2_and_authoritative_b1');
    $assert((int)$candidate['actual_paid_amount_cent'] === 12345 && (int)$candidate['ratio_bps'] === 700
        && (int)$candidate['reward_amount_cent'] === 864 && (int)$candidate['rule_version_id'] === (int)$rule['id'],
        'candidate_snapshots_paid_amount_ratio_rule_and_integer_reward');
    $assert((string)$candidate['status'] === 'pending' && (string)$candidate['responsibility_type'] === 'store_mall_revenue',
        'candidate_is_pending_b1_suballocation_only');
    $assert(st3OrderSnapshot($paidOrder) === $paidBefore, 'pay_listener_does_not_change_crmeb_order');
    st3Pay($payListener, $paidOrder);
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$paidOrder)->count() === 1,
        'duplicate_payment_event_is_idempotent');

    $unboundOrder = st3CreateOrder(930007, '50.00', 'unbound');
    st3Pay($payListener, $unboundOrder);
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$unboundOrder)->count() === 0,
        'order_without_active_referral_generates_no_candidate');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', 930007)->count() === 0,
        'order_without_referral_does_not_create_attribution_placeholder');

    $invalidOrders = [];
    $invalidOrders['child'] = st3CreateOrder($c2, '30.00', 'child', ['pid' => $paidOrder]);
    $invalidOrders['deleted'] = st3CreateOrder($c2, '30.00', 'deleted', ['is_del' => 1]);
    $invalidOrders['system_deleted'] = st3CreateOrder($c2, '30.00', 'system-deleted', ['is_system_del' => 1]);
    $invalidOrders['cancelled'] = st3CreateOrder($c2, '30.00', 'cancelled', ['is_cancel' => 1]);
    $invalidOrders['unpaid'] = st3CreateOrder($c2, '30.00', 'unpaid', ['paid' => 0, 'pay_time' => 0]);
    $invalidOrders['refunded'] = st3CreateOrder($c2, '30.00', 'refunded', ['refund_status' => 2, 'refund_price' => '30.00', 'status' => -2]);
    $invalidOrders['invalid_status'] = st3CreateOrder($c2, '30.00', 'invalid-status', ['status' => -1]);
    $invalidOrders['package'] = st3CreateOrder($c2, '30.00', 'package');
    st3MarkPackageOrder($invalidOrders['package'], $c2, $storeA);
    foreach ($invalidOrders as $label => $orderId) {
        st3Pay($payListener, $orderId);
        $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$orderId)->count() === 0,
            'invalid_order_generates_no_candidate:' . $label);
    }

    $partialOrder = st3CreateOrder($c2, '80.00', 'partial-refund');
    st3Pay($payListener, $partialOrder);
    Db::name('store_order')->where('id', $partialOrder)->update(['refund_status' => 2, 'refund_price' => '20.00']);
    st3Refund($refundListener, $partialOrder);
    $assert((string)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$partialOrder)->value('status') === 'pending',
        'partial_refund_does_not_cancel_candidate');
    $assert((int)Db::name('yfth_reward_adjustment_ledger')->where('candidate_id', (int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$partialOrder)->value('id'))->where('action_type', 'partial_refund')->count() === 1,
        'partial_refund_appends_adjustment_ledger');

    Db::name('store_order')->where('id', $paidOrder)->update(['refund_status' => 2, 'refund_price' => '123.45', 'status' => -2]);
    $fullRefundOrderBefore = st3OrderSnapshot($paidOrder);
    st3Refund($refundListener, $paidOrder);
    $assert((string)Db::name('yfth_direct_referral_reward_candidate')->where('id', (int)$candidate['id'])->value('status') === 'cancelled',
        'full_refund_cancels_pending_candidate');
    $assert(st3OrderSnapshot($paidOrder) === $fullRefundOrderBefore, 'refund_listener_does_not_change_crmeb_order');
    st3Refund($refundListener, $paidOrder);
    $assert((int)Db::name('yfth_reward_adjustment_ledger')->where('candidate_id', (int)$candidate['id'])->where('action_type', 'reversal')->count() === 1,
        'duplicate_refund_event_is_idempotent_with_one_reversal');

    $relationRow = (array)Db::name('yfth_hq_active_referral_current')->where('id', (int)$relation['id'])->find();
    app()->make(HqActiveReferralServices::class)->close(
        (int)$relationRow['id'],
        (int)$relationRow['relation_version'],
        'stage3_test_membership_close',
        st3Mutation('package_membership_activation', 930002, 'stage3-close-primary-referral')
    );
    $closedOrder = st3CreateOrder($c2, '60.00', 'closed-referral');
    st3Pay($payListener, $closedOrder);
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$closedOrder)->count() === 0,
        'closed_referral_generates_no_later_consumption_candidate');

    $mismatchUid = 930003;
    st3CreateReferral($referrals, $c1, $mismatchUid, 'mismatch');
    $mismatchCurrent = (array)Db::name('yfth_hq_customer_attribution_current')->where('uid', $mismatchUid)->find();
    Db::name('yfth_hq_customer_attribution_current')->where('id', (int)$mismatchCurrent['id'])->update(['store_id' => $storeB]);
    Db::name('yfth_hq_customer_attribution_event')->where('attribution_current_id', (int)$mismatchCurrent['id'])
        ->where('authority_version', (int)$mismatchCurrent['authority_version'])->update(['after_store_id' => $storeB]);
    $mismatchOrder = st3CreateOrder($mismatchUid, '70.00', 'b1-mismatch');
    st3Pay($payListener, $mismatchOrder);
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('source_business_id', (string)$mismatchOrder)->count() === 0,
        'c1_c2_different_b1_fails_closed');

    $userCandidates = $reward->userCandidates($c1);
    $otherUserCandidates = $reward->userCandidates($otherMember);
    $storeACandidates = $reward->storeCandidates($storeA, ['candidate_type' => 'mall_consumption']);
    $storeBCandidates = $reward->storeCandidates($storeB, ['candidate_type' => 'mall_consumption']);
    $hqCandidates = $reward->candidateList(['candidate_type' => 'mall_consumption', 'status' => 'cancelled']);
    $assert(count($userCandidates['list'] ?? []) >= 2, 'c1_can_read_own_mall_candidates');
    $assert(count($otherUserCandidates['list'] ?? []) === 0, 'other_user_cannot_read_c1_candidates');
    $assert(count($storeACandidates['list'] ?? []) >= 2 && count($storeBCandidates['list'] ?? []) === 0,
        'store_candidate_read_is_b1_isolated');
    $assert(count($hqCandidates['list'] ?? []) === 1, 'headquarters_can_filter_cancelled_mall_candidate');
    st3AssertUserDto($assert, (array)($userCandidates['list'][0] ?? []));

    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('candidate_type', 'package_activation')->count() === 0,
        'stage3_flow_does_not_create_or_modify_package_candidates');
} catch (Throwable $e) {
    $failures[] = 'exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
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
echo "[OK] YFTH Stage 3 mall consumption reward real flow verified.\n";

function st3Cleanup(): void
{
    foreach ([
        'yfth_direct_referral_reward_candidate', 'yfth_direct_referral_rule_version', 'yfth_direct_referral_invite',
        'yfth_permanent_membership_event', 'yfth_permanent_membership', 'yfth_hq_active_referral_event',
        'yfth_hq_active_referral_current', 'yfth_hq_customer_attribution_event', 'yfth_hq_customer_attribution_current',
        'yfth_reward_adjustment_ledger', 'yfth_reward_event', 'yfth_idempotency_record', 'yfth_audit_event',
    ] as $table) {
        Db::name($table)->delete(true);
    }
    Db::name('yfth_package_purchase')->whereBetween('uid', [930001, 930020])->delete();
    Db::name('store_order')->whereBetween('uid', [930001, 930020])->delete();
    Db::name('user')->whereBetween('uid', [930001, 930020])->delete();
    Db::name('system_store')->whereIn('id', [9301, 9302])->delete();
}

function st3CreateFixtures(): void
{
    $now = time();
    foreach (range(930001, 930020) as $uid) {
        Db::name('user')->insert([
            'uid' => $uid, 'account' => 'st3' . $uid, 'nickname' => 'Stage3 ' . $uid,
            'phone' => '13' . substr((string)$uid . '000000000', 0, 9), 'status' => 1,
            'user_type' => 'wechat', 'uniqid' => 'st3' . $uid, 'add_time' => $now,
        ]);
    }
    foreach ([9301 => 'Stage3 Store A', 9302 => 'Stage3 Store B'] as $id => $name) {
        Db::name('system_store')->insert([
            'id' => $id, 'name' => $name, 'phone' => '13800000000', 'address' => 'isolated validation',
            'detailed_address' => 'isolated validation only', 'valid_time' => '00:00-23:59',
            'day_time' => '1,2,3,4,5,6,7', 'is_show' => 1, 'is_del' => 0, 'add_time' => $now,
        ]);
    }
}

function st3Mutation(string $sourceType, int $sourceId, string $requestId): HqAuthorityMutation
{
    return new HqAuthorityMutation(
        HqAuthoritySource::fromTrusted($sourceType, $sourceId),
        1,
        'system',
        'stage3_isolated_validation',
        $requestId,
        $requestId
    );
}

function st3CreateMember(int $uid, int $storeId, int $sourceId): void
{
    $now = time();
    Db::name('yfth_permanent_membership')->insert([
        'membership_no' => 'ST3M' . $uid, 'uid' => $uid, 'store_id' => $storeId,
        'source_package_instance_id' => $sourceId, 'source_purchase_id' => $sourceId,
        'source_rule_version_id' => $sourceId, 'actual_paid_amount_cent' => 980000,
        'currency' => 'CNY', 'status' => 'active', 'authority_version' => 1,
        'source_type' => 'historical_package_activation', 'activated_at' => $now,
        'request_id' => 'stage3-member-' . $uid, 'add_time' => $now, 'update_time' => $now,
    ]);
}

function st3CreateReferral(PackageMembershipReferralServices $services, int $ownerUid, int $referredUid, string $key): array
{
    $invite = $services->issueInvite($ownerUid, ['request_id' => 'stage3-invite-' . $key]);
    $services->acceptInvite($referredUid, (string)$invite['invite_token'], [
        'idempotency_key' => 'stage3-accept-' . $key,
        'request_id' => 'stage3-accept-' . $key,
    ]);
    $row = Db::name('yfth_hq_active_referral_current')->where('referred_uid', $referredUid)->order('id desc')->find();
    if (!$row) {
        throw new RuntimeException('stage3_referral_missing:' . $key);
    }
    return (array)$row;
}

function st3CreateOrder(int $uid, string $amount, string $suffix, array $overrides = []): int
{
    $now = time();
    $data = [
        'order_id' => 'ST3' . strtoupper(substr(hash('sha256', $suffix . microtime(true)), 0, 20)),
        'uid' => $uid, 'pay_price' => $amount, 'total_price' => $amount, 'paid' => 1,
        'pay_time' => $now, 'pay_type' => 'test', 'add_time' => $now,
        'unique' => substr(hash('md5', $suffix . microtime(true)), 0, 32),
        'store_id' => 0, 'pid' => 0, 'status' => 0, 'refund_status' => 0,
        'refund_price' => '0.00', 'is_del' => 0, 'is_system_del' => 0, 'is_cancel' => 0,
    ];
    return (int)Db::name('store_order')->insertGetId(array_merge($data, $overrides));
}

function st3MarkPackageOrder(int $orderId, int $uid, int $storeId): void
{
    $orderSn = (string)Db::name('store_order')->where('id', $orderId)->value('order_id');
    Db::name('yfth_package_purchase')->insert([
        'purchase_no' => 'ST3P' . $orderId, 'uid' => $uid, 'store_id' => $storeId,
        'order_id' => $orderId, 'order_sn' => $orderSn, 'expected_pay_price' => '30.00',
        'order_pay_price' => '30.00', 'purchase_status' => 'paid', 'activation_status' => 'pending',
        'idempotency_key' => 'stage3-package-' . $orderId, 'add_time' => time(), 'update_time' => time(),
    ]);
}

function st3Pay(MallConsumptionRewardPayListener $listener, int $orderId): void
{
    $listener->handle([(array)Db::name('store_order')->where('id', $orderId)->find()]);
}

function st3Refund(MallConsumptionRewardCustomEventListener $listener, int $orderId): void
{
    $orderSn = (string)Db::name('store_order')->where('id', $orderId)->value('order_id');
    $listener->handle(['admin_order_refund_success', ['order_id' => $orderSn]]);
}

function st3OrderSnapshot(int $orderId): array
{
    return (array)Db::name('store_order')->where('id', $orderId)->find();
}

function st3AssertUserDto(callable $assert, array $row): void
{
    foreach (['referrer_uid', 'referred_uid', 'reward_sequence_no', 'rule_version_id', 'source_business_id', 'source_unique_key'] as $field) {
        $assert(!array_key_exists($field, $row), 'user_candidate_dto_excludes:' . $field);
    }
}
