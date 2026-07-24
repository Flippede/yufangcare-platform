<?php

use app\services\yfth\DirectReferralRewardServices;
use app\services\yfth\AutomaticCommissionServices;
use app\services\yfth\YfthOrderSourceServices;
use app\services\yfth\CurrentBusinessContextServices;
use app\services\yfth\HqAuthorityMutation;
use app\services\yfth\HqAuthoritySource;
use app\services\yfth\HqCustomerAttributionServices;
use app\services\yfth\PackageMembershipActivationCoordinator;
use app\services\yfth\PackageMembershipGrantPolicy;
use app\services\yfth\PackageMembershipReferralMigrationHealthServices;
use app\services\yfth\PackageMembershipReferralServices;
use app\services\yfth\PackageMembershipServices;
use app\services\yfth\PackageTemplateServices;
use app\services\yfth\UserStoreRoleServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$notes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};
$expect = function (callable $operation, string $message, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ':no_exception');
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $message) !== false, $label . ':' . $e->getMessage());
    }
};

if ((string)getenv('YFTH_PACKAGE_MEMBERSHIP_REFERRAL_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_PACKAGE_MEMBERSHIP_REFERRAL_REAL_FLOW_EXECUTE=1\n";
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
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    pmrCleanup();
    pmrCreateFixtures();

    $membership = app()->make(PackageMembershipServices::class);
    $referral = app()->make(PackageMembershipReferralServices::class);
    $reward = app()->make(DirectReferralRewardServices::class);
    $automatic = app()->make(AutomaticCommissionServices::class);
    $coordinator = app()->make(PackageMembershipActivationCoordinator::class);
    $attribution = app()->make(HqCustomerAttributionServices::class);
    $templates = app()->make(PackageTemplateServices::class);
    $grantPolicy = app()->make(PackageMembershipGrantPolicy::class);
    $storeA = 9201;
    $storeB = 9202;
    $c1 = 920001;

    pmrAssertVersionedPackagePrices($assert, $templates);
    $legacyRuleDecision = $grantPolicy->forRule(['grants_permanent_membership' => null]);
    $legacySnapshotDecision = $grantPolicy->forSnapshot(['grants_permanent_membership' => null]);
    $explicitNoGrant = $grantPolicy->forSnapshot(['grants_permanent_membership' => 0]);
    $assert($legacyRuleDecision['grants_permanent_membership'] === true
        && $legacyRuleDecision['semantics'] === PackageMembershipGrantPolicy::SEMANTICS_LEGACY_PACKAGE,
        'legacy_rule_null_is_classified_as_package_membership_grant');
    $assert($legacySnapshotDecision['grants_permanent_membership'] === true
        && $legacySnapshotDecision['semantics'] === PackageMembershipGrantPolicy::SEMANTICS_LEGACY_PACKAGE,
        'legacy_snapshot_null_is_classified_without_rewrite');
    $assert($explicitNoGrant['grants_permanent_membership'] === false,
        'explicit_non_grant_snapshot_is_not_reclassified');
    $migrationHealth = app()->make(PackageMembershipReferralMigrationHealthServices::class)->inspect();
    $assert(!empty($migrationHealth['healthy']) && empty($migrationHealth['issues']),
        'recorded_migration_schema_index_and_permission_health_is_complete');

    $rule = $reward->saveRule([
        'package_ratio_first_bps' => 1500,
        'package_ratio_second_bps' => 2500,
        'package_ratio_third_bps' => 6000,
        'mall_consumption_enabled' => 1,
        'mall_consumption_ratio_bps' => 500,
    ], 1);
    $rule = $reward->publishRule((int)$rule['id'], 1);
    $assert((string)$rule['status'] === 'published', 'versioned_reward_rule_published');
    $expect(function () use ($reward) {
        $reward->saveRule([
            'package_ratio_first_bps' => 1400,
            'package_ratio_second_bps' => 2500,
            'package_ratio_third_bps' => 6000,
        ], 1);
    }, 'package_three_cycle_ratio_must_be_15_25_60', 'package_cycle_ratio_is_frozen');

    $mutation = new HqAuthorityMutation(
        HqAuthoritySource::fromTrusted('historical_package_activation', 990001),
        1,
        'admin',
        'test_seed_c1',
        'pmr-c1-attribution',
        'pmr-c1-attribution'
    );
    $attribution->assignFirst($c1, $storeA, $mutation);
    Db::transaction(function () use ($membership, $c1, $storeA) {
        $membership->grantFromPackageInTransaction([
            'id' => 990001,
            'uid' => $c1,
            'store_id' => $storeA,
            'rule_version_id' => 990001,
        ], [
            'order_pay_price' => '7312.45',
            'currency' => 'CNY',
            'paid_time' => time(),
        ], 990001, 'historical_package_activation', 'pmr-c1-membership');
    });
    $assert($membership->effectiveMembership($c1)['is_member'] === true, 'c1_is_permanent_member');

    $selfInvite = $referral->issueInvite($c1, ['request_id' => 'pmr-self-scan']);
    $expect(function () use ($referral, $c1, $selfInvite) {
        $referral->acceptInvite($c1, (string)$selfInvite['invite_token'], [
            'idempotency_key' => 'pmr-self-scan', 'request_id' => 'pmr-self-scan',
        ]);
    }, 'direct_referral_invite_invalid', 'self_scan_is_rejected');

    $expiredInvite = $referral->issueInvite($c1, ['request_id' => 'pmr-expired-scan']);
    Db::name('yfth_direct_referral_invite')->where('invite_no', $expiredInvite['invite_no'])->update(['expires_at' => time() - 1]);
    $expect(function () use ($referral, $expiredInvite) {
        $referral->acceptInvite(920019, (string)$expiredInvite['invite_token'], [
            'idempotency_key' => 'pmr-expired-scan', 'request_id' => 'pmr-expired-scan',
        ]);
    }, 'direct_referral_invite_unavailable', 'expired_promotion_code_is_rejected');

    $otherStoreMutation = new HqAuthorityMutation(
        HqAuthoritySource::fromTrusted('historical_package_activation', 990018),
        1,
        'admin',
        'test_seed_other_store_attribution',
        'pmr-other-store-attribution',
        'pmr-other-store-attribution'
    );
    $attribution->assignFirst(920018, $storeB, $otherStoreMutation);
    $crossStoreInvite = $referral->issueInvite($c1, ['request_id' => 'pmr-cross-store-scan']);
    $crossStoreRejected = false;
    try {
        $referral->acceptInvite(920018, (string)$crossStoreInvite['invite_token'], [
            'idempotency_key' => 'pmr-cross-store-scan', 'request_id' => 'pmr-cross-store-scan',
        ]);
    } catch (Throwable $e) {
        $crossStoreRejected = true;
    }
    $assert($crossStoreRejected, 'other_store_permanent_attribution_is_rejected');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', 920018)->value('store_id') === $storeB, 'cross_store_scan_does_not_change_attribution');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920018)->count() === 0, 'cross_store_scan_creates_no_referral');

    $expect(function () use ($referral) {
        $referral->issueInvite(920010, ['request_id' => 'non-member-invite']);
    }, 'permanent_membership_required', 'non_member_cannot_invite');

    $balancesBefore = pmrFundingSnapshot();
    $rotatedInvite = $referral->issueInvite(920001, ['request_id' => 'pmr-invite-flow-1-old']);
    $currentInvite = $referral->issueInvite(920001, ['request_id' => 'pmr-invite-flow-1']);
    $assert((int)Db::name('yfth_direct_referral_invite')->where('owner_uid', 920001)->where('status', 'active')->count() === 1, 'invite_rotation_keeps_one_active_token');
    $expect(function () use ($referral, $rotatedInvite) {
        $referral->acceptInvite(920002, (string)$rotatedInvite['invite_token'], [
            'idempotency_key' => 'pmr-accept-flow-1-old',
            'request_id' => 'pmr-accept-flow-1-old',
        ]);
    }, 'direct_referral_invite_unavailable', 'rotated_invite_cannot_be_accepted');
    $accepted = $referral->acceptInvite(920002, (string)$currentInvite['invite_token'], [
        'idempotency_key' => 'pmr-accept-flow-1',
        'request_id' => 'pmr-accept-flow-1',
    ]);
    $assert(($accepted['customer_status'] ?? '') === 'non_member'
        && ($accepted['is_permanent_member'] ?? true) === false,
        'invite_accept_keeps_c2_as_non_member');
    $assert(($accepted['target_page'] ?? '') === '/pages/index/index'
        && ($accepted['redirect_url'] ?? '') === '/pages/index/index',
        'invite_accept_targets_headquarters_home');
    pmrAssertRecursiveKeysAbsent($assert, $accepted, [
        'referrer_uid', 'referred_uid', 'owner_uid', 'reward_sequence_no', 'rule_version_id',
    ], 'invite_accept_user_dto');
    $relationCount = (int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->count();
    $eventCount = (int)Db::name('yfth_hq_active_referral_event')->where('referred_uid', 920002)->count();
    $relationId = (int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->value('id');
    $replayed = $referral->acceptInvite(920002, (string)$currentInvite['invite_token'], [
        'idempotency_key' => 'pmr-accept-flow-1',
        'request_id' => 'pmr-accept-flow-1',
    ]);
    $assert(!empty($replayed['idempotent_replay']), 'invite_accept_same_request_is_idempotent_replay');
    $assert(($replayed['target_page'] ?? '') === '/pages/index/index', 'invite_accept_replay_targets_headquarters_home');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->value('id') === $relationId, 'invite_accept_replay_reuses_relation');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->count() === $relationCount, 'invite_accept_replay_creates_no_relation');
    $assert((int)Db::name('yfth_hq_active_referral_event')->where('referred_uid', 920002)->count() === $eventCount, 'invite_accept_replay_creates_no_event');
    $first = (array)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->find();
    $assert((int)$first['store_id'] === $storeA, 'c2_inherits_c1_store');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->value('referrer_uid') === $c1, 'one_level_relation_created');
    $assert((int)Db::name('yfth_customer_relation')->where('uid', 920002)->where('store_id', $storeA)->where('status', 'active')->count() === 1, 'invite_accept_projects_customer_into_store_crm');
    Db::name('yfth_customer_relation')->where('uid', 920002)->delete();
    $repair = app()->make(\app\services\yfth\FranchiseCustomerServices::class)->backfillAuthorityCustomers(
        $storeA, 100, $c1, 'isolated projection repair', 'pmr-projection-repair'
    );
    $assert($repair['failed'] === 0 && $repair['created'] >= 1, 'authority_customer_projection_repair_succeeds');
    $assert((int)Db::name('yfth_customer_relation')->where('uid', 920002)->where('store_id', $storeA)->where('status', 'active')->count() === 1, 'projection_repair_restores_store_customer_visibility');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', 920002)->count() === 0,
        'invite_accept_does_not_grant_permanent_membership');

    if ((string)getenv('YFTH_REFERRAL_REDIRECT_REAL_FLOW_ONLY') === '1') {
        pmrCleanup();
        if ($failures) {
            foreach ($failures as $failure) {
                fwrite(STDERR, "[FAIL] {$failure}\n");
            }
            exit(1);
        }
        foreach ($passes as $pass) {
            echo "[PASS] {$pass}\n";
        }
        echo "[OK] YFTH referral acceptance headquarters redirect real flow verified.\n";
        exit(0);
    }

    $duplicateInvite = $referral->issueInvite($c1, ['request_id' => 'pmr-existing-referral']);
    $duplicateRejected = false;
    try {
        $referral->acceptInvite(920002, (string)$duplicateInvite['invite_token'], [
            'idempotency_key' => 'pmr-existing-referral', 'request_id' => 'pmr-existing-referral',
        ]);
    } catch (Throwable $e) {
        $duplicateRejected = true;
    }
    $assert($duplicateRejected, 'existing_active_referral_is_rejected');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->count() === 1, 'existing_referral_rejection_creates_no_duplicate');
    $expect(function () use ($referral, $storeB) {
        $referral->requireAuthoritativeStoreForPurchase(920002, $storeB);
    }, 'package_purchase_cross_store_forbidden', 'cross_store_package_purchase_rejected');
    $assert($referral->requireAuthoritativeStoreForPurchase(920002, $storeA) === $storeA, 'same_store_package_purchase_allowed');
    $expect(function () use ($referral) {
        $referral->requireAuthoritativeStoreForPurchase(920099);
    }, 'package_purchase_authoritative_store_required', 'unbound_package_purchase_requires_store_qr_binding');

    $result1 = pmrActivate($coordinator, 920002, $storeA, 991002, 992002, '123.45');
    $assert(!empty($result1['membership_created']), 'c2_activation_creates_membership');
    $assert(!empty($result1['relation_closed']), 'c2_activation_closes_relation');

    $memberInvite = $referral->issueInvite($c1, ['request_id' => 'pmr-member-scan']);
    $expect(function () use ($referral, $memberInvite) {
        $referral->acceptInvite(920002, (string)$memberInvite['invite_token'], [
            'idempotency_key' => 'pmr-member-scan', 'request_id' => 'pmr-member-scan',
        ]);
    }, 'direct_referral_referred_user_must_be_non_member', 'permanent_member_scan_is_rejected');
    $assert((string)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->value('close_reason') === 'membership_activated', 'relation_close_reason_is_membership_activated');
    pmrAssertCandidate($assert, 920002, 1, 1500, 1851, 'first_package_accrual');
    $postMembershipOrder = pmrCreatePaidOrder(920002, $storeA, '66.00', 'closed-referral-consumption');
    $postMembership = $automatic->snapshotMallOrderPaid($postMembershipOrder);
    $assert(empty($postMembership['snapshot']['referrer_uid']), 'closed_referral_produces_no_new_c1_mall_commission');
    $c2Referral = pmrCreateReferral($referral, 920002, 920011, 'c2-independent-referral');
    $assert((int)$c2Referral['referrer_uid'] === 920002 && (int)$c2Referral['store_id'] === $storeA, 'qualified_c2_can_refer_independently');
    $userSummary = $referral->me(920002);
    pmrAssertRecursiveKeysAbsent($assert, $userSummary, [
        'referrer_uid', 'referred_uid', 'owner_uid', 'reward_sequence_no', 'rule_version_id',
    ], 'membership_summary_user_dto');
    $userCandidates = $reward->userCandidates($c1);
    pmrAssertRecursiveKeysAbsent($assert, $userCandidates, [
        'referrer_uid', 'referred_uid', 'owner_uid', 'reward_sequence_no', 'rule_version_id',
    ], 'candidate_list_user_dto');

    pmrCreateReferral($referral, 920001, 920003, 'flow-2');
    pmrActivate($coordinator, 920003, $storeA, 991003, 992003, '200.00');
    pmrAssertCandidate($assert, 920003, 2, 2500, 5000, 'second_package_accrual');
    $directReferrals = $referral->directReferrals($c1, 1, 20);
    pmrAssertRecursiveKeysAbsent($assert, $directReferrals, [
        'referrer_uid', 'referred_uid', 'owner_uid', 'reward_sequence_no', 'rule_version_id',
    ], 'direct_referral_summary_user_dto');
    $directC3 = null;
    foreach ($directReferrals['list'] as $directReferral) {
        if ((string)$directReferral['display_name'] === 'PMR 920003') {
            $directC3 = $directReferral;
            break;
        }
    }
    $assert($directC3 !== null, 'direct_referral_summary_contains_referred_user_name');
    $assert($directC3 !== null && (int)$directC3['reward_amount_cent'] === 5000, 'direct_referral_summary_aggregates_reward_amount');
    $assert($directC3 !== null && (int)$directC3['pending_amount_cent'] === 5000, 'direct_referral_summary_marks_pending_amount');
    $assert($directC3 !== null && (int)$directC3['settled_amount_cent'] === 0, 'direct_referral_summary_keeps_settled_amount_separate');
    $assert($directC3 !== null && (string)$directC3['relation_status'] === 'closed', 'direct_referral_summary_keeps_closed_membership_relation_visible');

    pmrCreateReferral($referral, 920001, 920004, 'flow-3');
    pmrActivate($coordinator, 920004, $storeA, 991004, 992004, '300.00');
    pmrAssertCandidate($assert, 920004, 3, 6000, 18000, 'third_package_accrual');

    pmrCreateReferral($referral, 920001, 920005, 'flow-4');
    pmrCreateReferral($referral, 920001, 920006, 'flow-5');
    $concurrentPackageA = pmrCreateActivatablePackage(920005, $storeA, '400.00');
    $concurrentPackageB = pmrCreateActivatablePackage(920006, $storeA, '500.00');
    $workers = pmrRunWorkers([
        ['activate_order', (string)$concurrentPackageA['order_id']],
        ['activate_order', (string)$concurrentPackageB['order_id']],
    ]);
    foreach ($workers as $index => $worker) {
        $assert($worker['exit_code'] === 0, 'concurrent_activation_worker_' . ($index + 1) . ':' . $worker['stderr']);
    }
    $sequences = Db::name('yfth_commission_accrual')->where('c1_uid', $c1)
        ->where('source_type', 'package_activation')->whereNotNull('package_sequence_no')
        ->order('package_sequence_no asc')->column('package_sequence_no');
    $assert(array_map('intval', $sequences) === [1, 2, 3, 4, 5], 'concurrent_sequence_is_unique_and_contiguous');
    $concurrentCandidates = Db::name('yfth_commission_accrual')
        ->whereIn('buyer_uid', [920005, 920006])
        ->where('source_type', 'package_activation')
        ->order('package_sequence_no asc')
        ->select()
        ->toArray();
    $assert(array_map('intval', array_column($concurrentCandidates, 'package_sequence_no')) === [4, 5], 'concurrent_accruals_receive_next_two_sequences');
    foreach ($concurrentCandidates as $candidate) {
        $sequence = (int)$candidate['package_sequence_no'];
        $expectedRatio = $sequence === 4 ? 1500 : 2500;
        $paidCent = (int)$candidate['buyer_uid'] === 920005 ? 40000 : 50000;
        $assert((int)$candidate['c1_ratio_bps'] === $expectedRatio, 'concurrent_accrual_ratio_for_sequence_' . $sequence);
        $assert((int)$candidate['c1_amount_cent'] === intdiv($paidCent * $expectedRatio, 10000), 'concurrent_accrual_integer_amount_for_sequence_' . $sequence);
    }

    pmrCreateReferral($referral, 920001, 920007, 'flow-rollback');
    Db::name('yfth_direct_referral_rule_version')->where('id', (int)$rule['id'])->update([
        'status' => 'superseded',
        'active_key' => null,
        'update_time' => time(),
    ]);
    $expect(function () use ($coordinator, $storeA) {
        pmrActivate($coordinator, 920007, $storeA, 991007, 992007, '600.00');
    }, 'package_commission_rule_not_found', 'missing_rule_fails_closed');
    $rollbackRelation = Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920007)->find();
    $assert((string)$rollbackRelation['status'] === 'active' && (int)$rollbackRelation['active_referred_uid'] === 920007, 'failed_activation_rolls_back_relation_close');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', 920007)->count() === 0, 'failed_activation_rolls_back_membership');
    $assert((int)Db::name('yfth_commission_accrual')->where('buyer_uid', 920007)->count() === 0, 'failed_activation_rolls_back_accrual');

    $mallOrderWithoutRule = pmrCreatePaidOrder(920007, $storeA, '88.00', 'mall-no-rule');
    $noRule = $automatic->snapshotMallOrderPaid($mallOrderWithoutRule);
    $assert(($noRule['reason'] ?? '') === 'mall_order_has_no_commission_rule', 'mall_extension_fails_closed_without_automatic_rule');
    Db::name('yfth_direct_referral_rule_version')->where('id', (int)$rule['id'])->update([
        'status' => 'published',
        'active_key' => 'published',
        'update_time' => time(),
    ]);
    $refundedOrder = pmrCreatePaidOrder(920007, $storeA, '88.00', 'mall-refunded');
    Db::name('store_order')->where('id', $refundedOrder)->update(['refund_status' => 2]);
    $refundedResult = $automatic->snapshotMallOrderPaid($refundedOrder);
    $assert(($refundedResult['reason'] ?? '') === 'mall_order_not_eligible', 'mall_refunded_order_rejected');
    $childOrder = pmrCreatePaidOrder(920007, $storeA, '88.00', 'mall-child');
    Db::name('store_order')->where('id', $childOrder)->update(['pid' => $refundedOrder]);
    $childResult = $automatic->snapshotMallOrderPaid($childOrder);
    $assert(($childResult['reason'] ?? '') === 'mall_order_not_eligible', 'mall_child_order_rejected');
    $deletedOrder = pmrCreatePaidOrder(920007, $storeA, '88.00', 'mall-deleted');
    Db::name('store_order')->where('id', $deletedOrder)->update(['is_del' => 1]);
    $deletedResult = $automatic->snapshotMallOrderPaid($deletedOrder);
    $assert(($deletedResult['reason'] ?? '') === 'mall_order_not_eligible', 'mall_deleted_order_rejected');
    $assert((int)Db::name('yfth_mall_commission_order_snapshot')
        ->whereIn('order_id', [$refundedOrder, $childOrder, $deletedOrder])
        ->count() === 0, 'invalid_mall_orders_create_no_snapshot');
    $mallOrder = pmrCreatePaidOrder(920007, $storeA, '88.00', 'mall-active-rule');
    $mallSnapshot = $automatic->snapshotMallOrderPaid($mallOrder);
    $assert(($mallSnapshot['reason'] ?? '') === 'mall_order_has_no_commission_rule', 'mall_extension_never_creates_legacy_candidate');
    $storeMetadataOrder = pmrCreatePaidOrder(920007, $storeB, '88.00', 'mall-store-metadata');
    $storeMetadataSnapshot = $automatic->snapshotMallOrderPaid($storeMetadataOrder);
    $assert(($storeMetadataSnapshot['reason'] ?? '') === 'mall_order_has_no_commission_rule', 'mall_snapshot_does_not_trust_order_store_metadata');

    $fullUid = 920008;
    pmrCreateReferral($referral, $c1, $fullUid, 'full-package');
    $full = pmrCreateActivatablePackage($fullUid, $storeA, '456.78');
    $activationWorkers = pmrRunWorkers([
        ['activate_order', (string)$full['order_id']],
        ['activate_order', (string)$full['order_id']],
    ]);
    foreach ($activationWorkers as $index => $worker) {
        $assert($worker['exit_code'] === 0, 'duplicate_paid_activation_worker_' . ($index + 1) . ':' . $worker['stderr']);
    }
    $assert((int)Db::name('yfth_package_instance')->where('purchase_id', $full['purchase_id'])->count() === 1, 'duplicate_activation_creates_one_package_instance');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $fullUid)->count() === 1, 'duplicate_activation_creates_one_membership');
    $assert((int)Db::name('yfth_commission_accrual')->where('buyer_uid', $fullUid)->where('source_type', 'package_activation')->count() === 1, 'duplicate_activation_creates_one_accrual');
    $assert((int)Db::name('yfth_package_purchase')->where('id', $full['purchase_id'])->value('instance_id') > 0, 'real_package_activation_updates_purchase');

    $legacyUid = 920009;
    $legacy = pmrCreateHistoricalPackage($legacyUid, $storeA, '5981.23');
    $effective = $membership->effectiveMembership($legacyUid);
    $assert($effective['is_member'] === true && $effective['persisted'] === false, 'historical_active_package_has_read_through_membership');
    $assert(Db::name('yfth_package_purchase_snapshot')->where('purchase_id', $legacy['purchase_id'])
        ->value('grants_permanent_membership') === null, 'historical_snapshot_grant_remains_null_and_immutable');
    Db::name('yfth_package_instance')->where('id', $legacy['instance_id'])->update([
        'status' => 'refunded',
        'refund_status' => 'refunded',
        'close_reason' => 'isolated_validation_refund',
    ]);
    Db::name('yfth_package_purchase')->where('id', $legacy['purchase_id'])->update([
        'purchase_status' => 'refunded',
    ]);
    $refundedHistorical = $membership->effectiveMembership($legacyUid);
    $assert($refundedHistorical['is_member'] === true && $refundedHistorical['persisted'] === false,
        'unbackfilled_historical_refund_keeps_permanent_membership');
    $legacyInvite = $referral->issueInvite($legacyUid, ['request_id' => 'pmr-legacy-refunded-invite']);
    $assert((string)($legacyInvite['invite_token'] ?? '') !== ''
        && (int)Db::name('yfth_permanent_membership')->where('uid', $legacyUid)->count() === 0,
        'unbackfilled_refunded_historical_member_can_invite');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $legacyUid)->value('store_id') === $storeA,
        'historical_invite_bootstraps_trusted_store_attribution_without_membership_backfill');
    $countBeforeDryRun = (int)Db::name('yfth_permanent_membership')->count();
    $dryRun = $membership->legacyBackfill(false, 200, 1, '', 'pmr-backfill-dry');
    $assert((int)$dryRun['eligible'] >= 1 && (int)Db::name('yfth_permanent_membership')->count() === $countBeforeDryRun, 'historical_backfill_dry_run_is_read_only');
    $execute = $membership->legacyBackfill(true, 200, 1, 'approved historical package recognition', 'pmr-backfill-execute');
    $assert((int)$execute['created'] >= 1 && $membership->effectiveMembership($legacyUid)['persisted'] === true, 'historical_backfill_execute_persists_membership');
    $assert($membership->effectiveMembership($legacyUid)['is_member'] === true, 'refund_does_not_auto_revoke_permanent_membership');

    $raceUid = 920015;
    $raceInvite = $referral->issueInvite($c1, ['request_id' => 'pmr-race-invite']);
    $racePackage = pmrCreateActivatablePackage($raceUid, $storeA, '678.90');
    $raceWorkers = pmrRunWorkers([
        ['accept_invite', (string)$raceUid, (string)$raceInvite['invite_token'], 'pmr-race-accept'],
        ['activate_order', (string)$racePackage['order_id']],
    ]);
    foreach ($raceWorkers as $worker) {
        $combined = strtolower((string)$worker['stdout'] . ' ' . (string)$worker['stderr']);
        $assert(strpos($combined, 'deadlock') === false && strpos($combined, 'lock wait timeout') === false,
            'invite_activation_concurrency_has_no_deadlock_or_lock_wait');
    }
    $assert($raceWorkers[1]['exit_code'] === 0, 'concurrent_package_activation_succeeds:' . $raceWorkers[1]['stderr']);
    $acceptRaceExpected = $raceWorkers[0]['exit_code'] === 0
        || strpos($raceWorkers[0]['stderr'], 'direct_referral_referred_user_must_be_non_member') !== false;
    $assert($acceptRaceExpected, 'concurrent_invite_accept_has_only_serializable_outcome:' . $raceWorkers[0]['stderr']);
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $raceUid)->count() === 1,
        'concurrent_invite_activation_creates_one_membership');
    $raceRelation = Db::name('yfth_hq_active_referral_current')->where('referred_uid', $raceUid)->find();
    if ($raceRelation) {
        $assert((string)$raceRelation['status'] === 'closed'
            && (string)$raceRelation['close_reason'] === 'membership_activated',
            'accepted_concurrent_relation_is_atomically_closed');
        $assert((int)Db::name('yfth_commission_accrual')->where('buyer_uid', $raceUid)->where('source_type', 'package_activation')->count() === 1,
            'accepted_concurrent_relation_creates_one_accrual');
    } else {
        $assert((int)Db::name('yfth_commission_accrual')->where('buyer_uid', $raceUid)->count() === 0,
            'activation_first_concurrent_outcome_creates_no_partial_accrual');
    }
    $assert((int)Db::name('yfth_idempotency_record')
        ->where('idempotency_key', 'pmr-race-accept')->where('process_status', 'processing')->count() === 0,
        'concurrent_invite_activation_leaves_no_processing_idempotency');

    $membershipCount = (int)Db::name('yfth_permanent_membership')->count();
    $eventCount = (int)Db::name('yfth_permanent_membership_event')->count();
    $assert($membershipCount === $eventCount, 'one_activation_event_per_membership');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_package_membership_referral')->count() > 0, 'audit_events_written');
    $assert((int)Db::name('yfth_idempotency_record')->where('business_domain', 'yfth_hq_authority')->count() > 0, 'authority_idempotency_written');
    $assert(pmrFundingSnapshot() === $balancesBefore, 'legacy_wallet_and_reward_funding_unchanged');

    $stoppedInvite = $referral->issueInvite($c1, ['request_id' => 'pmr-stopped-store-invite']);
    Db::name('system_store')->where('id', $storeA)->update(['is_show' => 0]);
    $expect(function () use ($referral, $stoppedInvite) {
        $referral->acceptInvite(920012, (string)$stoppedInvite['invite_token'], [
            'idempotency_key' => 'pmr-stopped-store-accept',
            'request_id' => 'pmr-stopped-store-accept',
        ]);
    }, 'store_not_active', 'stopped_store_fails_closed');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920012)->count() === 0, 'stopped_store_creates_no_relation');
    Db::name('system_store')->where('id', $storeA)->update(['is_show' => 1]);

    $storeRoles = app()->make(UserStoreRoleServices::class);
    $role = $storeRoles->saveRole([
        'uid' => 920012,
        'store_id' => $storeA,
        'role_code' => 'store_manager',
        'permission_scope' => [],
        'status' => 'active',
        'creator_uid' => 1,
    ]);
    $roleId = (int)$role->id;
    $context = app()->make(CurrentBusinessContextServices::class)->resolve(920012, 'store_manager', $storeA);
    $assert((int)$context['store_id'] === $storeA && (string)$context['role_code'] === 'store_manager', 'active_store_manager_context_resolves');
    Db::name('yfth_user_store_role')->where('id', $roleId)->update(['status' => 'disabled', 'active_key' => null, 'update_time' => time()]);
    $expect(function () use ($storeA) {
        app()->make(CurrentBusinessContextServices::class)->resolve(920012, 'store_manager', $storeA);
    }, 'store_role_not_granted', 'revoked_store_role_fails_closed');

    $notes[] = 'membership_count:' . $membershipCount;
    $notes[] = 'candidate_count:' . (int)Db::name('yfth_direct_referral_reward_candidate')->count();
} catch (Throwable $e) {
    $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
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
echo "[OK] YFTH package membership and direct referral V2 real flow verified.\n";

function pmrCleanup(): void
{
    foreach ([
        'yfth_customer_relation', 'yfth_direct_referral_reward_candidate', 'yfth_direct_referral_rule_version', 'yfth_direct_referral_invite',
        'yfth_store_settlement_callback', 'yfth_store_settlement_return', 'yfth_store_settlement_batch_item',
        'yfth_store_settlement_batch', 'yfth_store_settlement_receiver', 'yfth_commission_refund_reversal',
        'yfth_commission_ledger', 'yfth_store_commission_account', 'yfth_user_commission_account',
        'yfth_commission_accrual', 'yfth_mall_commission_order_snapshot', 'yfth_commission_order_source',
        'yfth_commission_sequence_counter', 'yfth_commission_rule_version',
        'yfth_permanent_membership_event', 'yfth_permanent_membership', 'yfth_hq_active_referral_event',
        'yfth_hq_active_referral_current', 'yfth_hq_customer_attribution_event', 'yfth_hq_customer_attribution_current',
        'yfth_idempotency_record', 'yfth_audit_event', 'yfth_benefit_item', 'yfth_benefit_period',
        'yfth_benefit_plan', 'yfth_package_instance', 'yfth_package_purchase_benefit_snapshot',
        'yfth_package_purchase_snapshot', 'yfth_package_purchase', 'yfth_package_product_binding',
        'yfth_monthly_benefit_rule', 'yfth_package_rule_version', 'yfth_package_template', 'yfth_user_identity',
    ] as $table) {
        Db::name($table)->delete(true);
    }
    Db::name('store_order')->whereBetween('uid', [920001, 920020])->delete();
    Db::name('yfth_user_store_role')->whereBetween('uid', [920001, 920020])->delete();
    Db::name('user')->whereBetween('uid', [920001, 920020])->delete();
    Db::name('system_store')->whereIn('id', [9201, 9202])->delete();
}

function pmrAssertVersionedPackagePrices(callable $assert, PackageTemplateServices $services): void
{
    $template = $services->saveTemplate([
        'package_code' => 'PMR-CONFIGURED-PACKAGE',
        'package_name' => 'PMR configurable package',
        'package_title' => 'PMR configurable package',
        'package_type' => 'health_package',
        'base_price' => '7312.45',
        'currency' => 'CNY',
        'benefit_months' => 1,
        'agreement_title' => 'PMR agreement',
        'agreement_content' => 'PMR agreement content',
        'status' => 'draft',
    ], 1);
    $templateId = (int)$template->id;
    $first = $services->saveRuleVersion([
        'template_id' => $templateId,
        'status' => 'published',
        'package_price' => '7312.45',
        'month_count' => 1,
        'grants_permanent_membership' => 1,
        'agreement_title' => 'PMR agreement V1',
        'agreement_content' => 'PMR agreement V1 content',
    ], 1);
    $firstId = (int)$first->id;
    Db::name('yfth_package_purchase_snapshot')->insert([
        'purchase_id' => 999999,
        'template_id' => $templateId,
        'rule_version_id' => $firstId,
        'rule_version_no' => 1,
        'package_code' => 'PMR-CONFIGURED-PACKAGE',
        'package_name' => 'PMR configurable package',
        'package_title' => 'PMR configurable package',
        'package_type' => 'health_package',
        'package_price' => '7312.45',
        'currency' => 'CNY',
        'month_count' => 1,
        'grants_permanent_membership' => 1,
        'order_pay_price' => '7199.99',
        'add_time' => time(),
        'update_time' => time(),
    ]);
    $second = $services->saveRuleVersion([
        'template_id' => $templateId,
        'status' => 'published',
        'package_price' => '8123.67',
        'month_count' => 1,
        'grants_permanent_membership' => 1,
        'agreement_title' => 'PMR agreement V2',
        'agreement_content' => 'PMR agreement V2 content',
    ], 1);
    $secondId = (int)$second->id;
    $firstAfter = Db::name('yfth_package_rule_version')->where('id', $firstId)->find();
    $snapshotAfter = Db::name('yfth_package_purchase_snapshot')->where('purchase_id', 999999)->find();
    $current = $services->currentRule($templateId);
    $assert((string)$firstAfter['status'] === 'superseded' && (string)$firstAfter['package_price'] === '7312.45', 'old_package_price_version_is_immutable');
    $assert((string)$snapshotAfter['package_price'] === '7312.45' && (string)$snapshotAfter['order_pay_price'] === '7199.99', 'historical_transaction_snapshot_is_immutable');
    $assert((int)$current['id'] === $secondId && (string)$current['package_price'] === '8123.67', 'new_package_price_version_becomes_effective');
    Db::name('yfth_package_purchase_snapshot')->where('purchase_id', 999999)->delete();
}

function pmrCreateFixtures(): void
{
    $now = time();
    foreach (range(920001, 920020) as $uid) {
        Db::name('user')->insert([
            'uid' => $uid,
            'account' => 'pmr' . $uid,
            'nickname' => 'PMR ' . $uid,
            'phone' => '13' . substr((string)$uid . '000000000', 0, 9),
            'status' => 1,
            'user_type' => 'wechat',
            'uniqid' => 'pmr' . $uid,
            'add_time' => $now,
        ]);
    }
    foreach ([9201 => 'PMR Store A', 9202 => 'PMR Store B'] as $id => $name) {
        Db::name('system_store')->insert([
            'id' => $id,
            'name' => $name,
            'phone' => '13800000000',
            'address' => 'isolated validation',
            'detailed_address' => 'isolated validation only',
            'valid_time' => '00:00-23:59',
            'day_time' => '1,2,3,4,5,6,7',
            'is_show' => 1,
            'is_del' => 0,
            'add_time' => $now,
        ]);
    }
}

function pmrCreateReferral(PackageMembershipReferralServices $services, int $ownerUid, int $referredUid, string $key): array
{
    $invite = $services->issueInvite($ownerUid, ['request_id' => 'pmr-invite-' . $key]);
    $accepted = $services->acceptInvite($referredUid, (string)$invite['invite_token'], [
        'idempotency_key' => 'pmr-accept-' . $key,
        'request_id' => 'pmr-accept-' . $key,
    ]);
    if (empty($accepted['direct_referral'])) {
        throw new RuntimeException('pmr_referral_accept_result_missing');
    }
    $relation = Db::name('yfth_hq_active_referral_current')->where('referred_uid', $referredUid)->order('id desc')->find();
    if (!$relation) {
        throw new RuntimeException('pmr_referral_row_missing');
    }
    return $relation;
}

function pmrActivate(PackageMembershipActivationCoordinator $coordinator, int $uid, int $storeId, int $purchaseId, int $instanceId, string $amount): array
{
    return Db::transaction(function () use ($coordinator, $uid, $storeId, $purchaseId, $instanceId, $amount) {
        return $coordinator->activateInTransaction([
            'id' => $purchaseId,
            'uid' => $uid,
            'store_id' => $storeId,
            'rule_version_id' => 900001,
        ], [
            'grants_permanent_membership' => 1,
            'order_pay_price' => $amount,
            'currency' => 'CNY',
            'paid_time' => time(),
        ], $instanceId);
    });
}

function pmrAssertCandidate(callable $assert, int $referredUid, int $sequence, int $ratio, int $amount, string $label): void
{
    $accrual = Db::name('yfth_commission_accrual')->where('buyer_uid', $referredUid)
        ->where('source_type', 'package_activation')->find();
    $assert((int)($accrual['package_sequence_no'] ?? 0) === $sequence, $label . ':sequence');
    $assert((int)($accrual['c1_ratio_bps'] ?? 0) === $ratio, $label . ':ratio');
    $assert((int)($accrual['c1_amount_cent'] ?? 0) === $amount, $label . ':integer_amount');
    $assert(in_array((string)($accrual['status'] ?? ''), ['observing', 'credited'], true), $label . ':automatic_status');
}

function pmrCreatePaidOrder(int $uid, int $storeId, string $amount, string $suffix): int
{
    $now = time();
    $orderId = (int)Db::name('store_order')->insertGetId([
        'order_id' => 'PMR' . strtoupper(substr(hash('sha256', $suffix . microtime(true)), 0, 20)),
        'uid' => $uid,
        'pay_price' => $amount,
        'total_price' => $amount,
        'paid' => 1,
        'pay_time' => $now,
        'pay_type' => 'test',
        'add_time' => $now,
        'unique' => substr(hash('md5', $suffix . microtime(true)), 0, 32),
        'store_id' => $storeId,
    ]);
    app()->make(YfthOrderSourceServices::class)->mark($orderId, 'normal_mall');
    return $orderId;
}

function pmrCreateActivatablePackage(int $uid, int $storeId, string $amount): array
{
    $now = time();
    $orderId = pmrCreatePaidOrder($uid, $storeId, $amount, 'package-' . $uid);
    $orderSn = (string)Db::name('store_order')->where('id', $orderId)->value('order_id');
    $purchaseId = (int)Db::name('yfth_package_purchase')->insertGetId([
        'purchase_no' => 'PMRP' . $uid,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 900001,
        'rule_version_id' => 900001,
        'product_id' => 900001,
        'product_attr_unique' => 'pmr-sku',
        'order_id' => $orderId,
        'order_sn' => $orderSn,
        'expected_pay_price' => $amount,
        'order_pay_price' => $amount,
        'payment_scene' => 'package_configured',
        'purchase_status' => 'paid',
        'activation_status' => 'pending',
        'idempotency_key' => 'pmr-package-' . $uid,
        'order_unique_key' => (string)$orderId,
        'order_sn_unique_key' => $orderSn,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $snapshotId = (int)Db::name('yfth_package_purchase_snapshot')->insertGetId([
        'purchase_id' => $purchaseId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 900001,
        'rule_version_id' => 900001,
        'rule_version_no' => 1,
        'package_code' => 'CONFIGURED-PACKAGE',
        'package_name' => 'Configured package',
        'package_title' => 'Configured package validation',
        'package_type' => 'membership_package',
        'package_price' => $amount,
        'currency' => 'CNY',
        'month_count' => 1,
        'grants_permanent_membership' => 1,
        'product_id' => 900001,
        'product_attr_unique' => 'pmr-sku',
        'product_name' => 'Configured package product',
        'sku_name' => 'Configured package SKU',
        'sku_price' => $amount,
        'payment_scene' => 'package_configured',
        'available_store_ids' => json_encode([$storeId]),
        'order_id' => $orderId,
        'order_sn' => $orderSn,
        'order_pay_price' => $amount,
        'paid_time' => $now,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_package_purchase')->where('id', $purchaseId)->update(['snapshot_id' => $snapshotId]);
    Db::name('yfth_package_purchase_benefit_snapshot')->insert([
        'purchase_id' => $purchaseId,
        'snapshot_id' => $snapshotId,
        'rule_version_id' => 900001,
        'month_no' => 1,
        'source_rule_id' => 900001,
        'benefit_template_id' => 900001,
        'benefit_code' => 'PMR-SERVICE',
        'benefit_name' => 'PMR service benefit',
        'benefit_type' => 'service',
        'fulfillment_type' => 'service',
        'unit' => 'times',
        'quantity' => '1.00',
        'per_limit' => '1.00',
        'available_store_ids' => json_encode([$storeId]),
        'add_time' => $now,
        'update_time' => $now,
    ]);
    return ['order_id' => $orderId, 'purchase_id' => $purchaseId];
}

function pmrCreateHistoricalPackage(int $uid, int $storeId, string $amount): array
{
    $now = time();
    $orderId = pmrCreatePaidOrder($uid, $storeId, $amount, 'historical-' . $uid);
    $orderSn = (string)Db::name('store_order')->where('id', $orderId)->value('order_id');
    $purchaseId = (int)Db::name('yfth_package_purchase')->insertGetId([
        'purchase_no' => 'PMRH' . $uid,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 800001,
        'rule_version_id' => 800001,
        'product_id' => 800001,
        'product_attr_unique' => 'historical-sku',
        'order_id' => $orderId,
        'order_sn' => $orderSn,
        'expected_pay_price' => $amount,
        'order_pay_price' => $amount,
        'purchase_status' => 'activated',
        'activation_status' => 'succeeded',
        'instance_id' => 0,
        'idempotency_key' => 'pmr-historical-' . $uid,
        'order_unique_key' => (string)$orderId,
        'order_sn_unique_key' => $orderSn,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $snapshotId = (int)Db::name('yfth_package_purchase_snapshot')->insertGetId([
        'purchase_id' => $purchaseId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 800001,
        'rule_version_id' => 800001,
        'rule_version_no' => 1,
        'package_code' => 'HISTORICAL-PACKAGE',
        'package_name' => 'Historical package',
        'package_title' => 'Historical package validation',
        'package_type' => 'health_package',
        'package_price' => $amount,
        'currency' => 'CNY',
        'month_count' => 10,
        'grants_permanent_membership' => null,
        'product_id' => 800001,
        'product_attr_unique' => 'historical-sku',
        'order_id' => $orderId,
        'order_sn' => $orderSn,
        'order_pay_price' => $amount,
        'paid_time' => $now,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $instanceId = (int)Db::name('yfth_package_instance')->insertGetId([
        'instance_no' => 'PMRHI' . $uid,
        'purchase_id' => $purchaseId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 800001,
        'rule_version_id' => 800001,
        'order_id' => $orderId,
        'order_sn' => $orderSn,
        'status' => 'active',
        'refund_status' => 'none',
        'start_time' => $now - 86400,
        'end_time' => $now + 86400,
        'activated_time' => $now - 3600,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_package_purchase')->where('id', $purchaseId)->update([
        'instance_id' => $instanceId,
        'snapshot_id' => $snapshotId,
    ]);
    return ['order_id' => $orderId, 'purchase_id' => $purchaseId, 'instance_id' => $instanceId];
}

function pmrRunWorkers(array $arguments): array
{
    $php = PHP_BINARY;
    $ini = php_ini_loaded_file();
    $worker = __DIR__ . '/yfth_package_membership_referral_worker.php';
    $processes = [];
    foreach ($arguments as $args) {
        $command = [$php];
        if ($ini) {
            $command[] = '-c';
            $command[] = $ini;
        }
        $command[] = $worker;
        foreach ($args as $arg) {
            $command[] = (string)$arg;
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('worker_process_start_failed');
        }
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $results[] = ['exit_code' => proc_close($process), 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
    }
    return $results;
}

function pmrAssertRecursiveKeysAbsent(callable $assert, array $payload, array $forbidden, string $label): void
{
    $walk = function (array $value) use (&$walk, $assert, $forbidden, $label): void {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $assert(!in_array($key, $forbidden, true), $label . ':excludes_' . $key);
            }
            if (is_array($item)) {
                $walk($item);
            }
        }
    };
    $walk($payload);
}

function pmrFundingSnapshot(): array
{
    return [
        'users' => Db::name('user')->whereBetween('uid', [920001, 920020])->order('uid asc')->column('now_money,brokerage_price', 'uid'),
        'legacy_reward_ledger_count' => (int)Db::name('yfth_reward_ledger')->count(),
        'legacy_settlement_count' => (int)Db::name('yfth_reward_settlement_record')->count(),
    ];
}
