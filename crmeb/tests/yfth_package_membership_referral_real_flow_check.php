<?php

use app\services\yfth\DirectReferralRewardServices;
use app\services\yfth\CurrentBusinessContextServices;
use app\services\yfth\HqAuthorityMutation;
use app\services\yfth\HqAuthoritySource;
use app\services\yfth\HqCustomerAttributionServices;
use app\services\yfth\PackageMembershipActivationCoordinator;
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
    $coordinator = app()->make(PackageMembershipActivationCoordinator::class);
    $attribution = app()->make(HqCustomerAttributionServices::class);
    $templates = app()->make(PackageTemplateServices::class);
    $storeA = 9201;
    $storeB = 9202;
    $c1 = 920001;

    pmrAssertVersionedPackagePrices($assert, $templates);

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
    $relationCount = (int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->count();
    $eventCount = (int)Db::name('yfth_hq_active_referral_event')->where('referred_uid', 920002)->count();
    $replayed = $referral->acceptInvite(920002, (string)$currentInvite['invite_token'], [
        'idempotency_key' => 'pmr-accept-flow-1',
        'request_id' => 'pmr-accept-flow-1',
    ]);
    $assert(!empty($replayed['idempotent_replay']), 'invite_accept_same_request_is_idempotent_replay');
    $assert((int)($replayed['relation']['id'] ?? 0) === (int)($accepted['relation']['id'] ?? 0), 'invite_accept_replay_reuses_relation');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->count() === $relationCount, 'invite_accept_replay_creates_no_relation');
    $assert((int)Db::name('yfth_hq_active_referral_event')->where('referred_uid', 920002)->count() === $eventCount, 'invite_accept_replay_creates_no_event');
    $first = (array)$accepted['relation'];
    $assert((int)$first['store_id'] === $storeA, 'c2_inherits_c1_store');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->value('referrer_uid') === $c1, 'one_level_relation_created');
    $expect(function () use ($referral, $storeB) {
        $referral->resolveAuthoritativeStoreForPurchase(920002, $storeB);
    }, 'package_purchase_cross_store_forbidden', 'cross_store_package_purchase_rejected');
    $assert($referral->resolveAuthoritativeStoreForPurchase(920002, $storeA) === $storeA, 'same_store_package_purchase_allowed');

    $result1 = pmrActivate($coordinator, 920002, $storeA, 991002, 992002, '123.45');
    $assert(!empty($result1['membership_created']), 'c2_activation_creates_membership');
    $assert(!empty($result1['relation_closed']), 'c2_activation_closes_relation');
    $assert((string)Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920002)->value('close_reason') === 'membership_activated', 'relation_close_reason_is_membership_activated');
    pmrAssertCandidate($assert, 920002, 1, 1500, 1851, 'first_package_candidate');
    $postMembershipOrder = pmrCreatePaidOrder(920002, $storeA, '66.00', 'closed-referral-consumption');
    $postMembership = $reward->recordMallOrderPaid($postMembershipOrder);
    $assert(($postMembership['reason'] ?? '') === 'active_referral_not_found', 'closed_referral_produces_no_new_consumption_candidate');
    $c2Referral = pmrCreateReferral($referral, 920002, 920011, 'c2-independent-referral');
    $assert((int)$c2Referral['referrer_uid'] === 920002 && (int)$c2Referral['store_id'] === $storeA, 'qualified_c2_can_refer_independently');

    pmrCreateReferral($referral, 920001, 920003, 'flow-2');
    pmrActivate($coordinator, 920003, $storeA, 991003, 992003, '200.00');
    pmrAssertCandidate($assert, 920003, 2, 2500, 5000, 'second_package_candidate');

    pmrCreateReferral($referral, 920001, 920004, 'flow-3');
    pmrActivate($coordinator, 920004, $storeA, 991004, 992004, '300.00');
    pmrAssertCandidate($assert, 920004, 3, 6000, 18000, 'third_package_candidate');

    pmrCreateReferral($referral, 920001, 920005, 'flow-4');
    pmrCreateReferral($referral, 920001, 920006, 'flow-5');
    $workers = pmrRunWorkers([
        ['coordinator', '920005', (string)$storeA, '991005', '992005', '400.00'],
        ['coordinator', '920006', (string)$storeA, '991006', '992006', '500.00'],
    ]);
    foreach ($workers as $index => $worker) {
        $assert($worker['exit_code'] === 0, 'concurrent_activation_worker_' . ($index + 1) . ':' . $worker['stderr']);
    }
    $sequences = Db::name('yfth_direct_referral_reward_candidate')->where('referrer_uid', $c1)
        ->whereNotNull('reward_sequence_no')->order('reward_sequence_no asc')->column('reward_sequence_no');
    $assert(array_map('intval', $sequences) === [1, 2, 3, 4, 5], 'concurrent_sequence_is_unique_and_contiguous');
    $concurrentCandidates = Db::name('yfth_direct_referral_reward_candidate')
        ->whereIn('referred_uid', [920005, 920006])
        ->where('candidate_type', 'package_activation')
        ->order('reward_sequence_no asc')
        ->select()
        ->toArray();
    $assert(array_map('intval', array_column($concurrentCandidates, 'reward_sequence_no')) === [4, 5], 'concurrent_candidates_receive_next_two_sequences');
    foreach ($concurrentCandidates as $candidate) {
        $sequence = (int)$candidate['reward_sequence_no'];
        $expectedRatio = $sequence === 4 ? 1500 : 2500;
        $paidCent = (int)$candidate['referred_uid'] === 920005 ? 40000 : 50000;
        $assert((int)$candidate['ratio_bps'] === $expectedRatio, 'concurrent_candidate_ratio_for_sequence_' . $sequence);
        $assert((int)$candidate['reward_amount_cent'] === intdiv($paidCent * $expectedRatio, 10000), 'concurrent_candidate_integer_amount_for_sequence_' . $sequence);
    }

    pmrCreateReferral($referral, 920001, 920007, 'flow-rollback');
    Db::name('yfth_direct_referral_rule_version')->where('id', (int)$rule['id'])->update([
        'status' => 'superseded',
        'active_key' => null,
        'update_time' => time(),
    ]);
    $expect(function () use ($coordinator, $storeA) {
        pmrActivate($coordinator, 920007, $storeA, 991007, 992007, '600.00');
    }, 'direct_referral_rule_unavailable', 'missing_rule_fails_closed');
    $rollbackRelation = Db::name('yfth_hq_active_referral_current')->where('referred_uid', 920007)->find();
    $assert((string)$rollbackRelation['status'] === 'active' && (int)$rollbackRelation['active_referred_uid'] === 920007, 'failed_activation_rolls_back_relation_close');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', 920007)->count() === 0, 'failed_activation_rolls_back_membership');
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('referred_uid', 920007)->count() === 0, 'failed_activation_rolls_back_candidate');

    $mallOrderWithoutRule = pmrCreatePaidOrder(920007, $storeA, '88.00', 'mall-no-rule');
    $noRule = $reward->recordMallOrderPaid($mallOrderWithoutRule);
    $assert(($noRule['reason'] ?? '') === 'mall_consumption_rule_unavailable', 'mall_extension_fails_closed_without_rule');
    Db::name('yfth_direct_referral_rule_version')->where('id', (int)$rule['id'])->update([
        'status' => 'published',
        'active_key' => 'published',
        'update_time' => time(),
    ]);
    $mallOrder = pmrCreatePaidOrder(920007, $storeA, '88.00', 'mall-active-rule');
    $mallCandidate = $reward->recordMallOrderPaid($mallOrder);
    $assert(!empty($mallCandidate['created']), 'mall_extension_creates_candidate_with_active_rule');
    $assert((int)$mallCandidate['candidate']['ratio_bps'] === 500 && (int)$mallCandidate['candidate']['reward_amount_cent'] === 440, 'mall_candidate_uses_versioned_integer_ratio');
    $crossStoreOrder = pmrCreatePaidOrder(920007, $storeB, '88.00', 'mall-cross-store');
    $expect(function () use ($reward, $crossStoreOrder) {
        $reward->recordMallOrderPaid($crossStoreOrder);
    }, 'mall_consumption_referral_store_mismatch', 'mall_cross_store_rejected');

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
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->where('referred_uid', $fullUid)->where('candidate_type', 'package_activation')->count() === 1, 'duplicate_activation_creates_one_candidate');
    $assert((int)Db::name('yfth_package_purchase')->where('id', $full['purchase_id'])->value('instance_id') > 0, 'real_package_activation_updates_purchase');

    $legacyUid = 920009;
    $legacy = pmrCreateHistoricalPackage($legacyUid, $storeA, '5981.23');
    $effective = $membership->effectiveMembership($legacyUid);
    $assert($effective['is_member'] === true && $effective['persisted'] === false, 'historical_active_package_has_read_through_membership');
    $countBeforeDryRun = (int)Db::name('yfth_permanent_membership')->count();
    $dryRun = $membership->legacyBackfill(false, 200, 1, '', 'pmr-backfill-dry');
    $assert((int)$dryRun['eligible'] >= 1 && (int)Db::name('yfth_permanent_membership')->count() === $countBeforeDryRun, 'historical_backfill_dry_run_is_read_only');
    $execute = $membership->legacyBackfill(true, 200, 1, 'approved historical package recognition', 'pmr-backfill-execute');
    $assert((int)$execute['created'] >= 1 && $membership->effectiveMembership($legacyUid)['persisted'] === true, 'historical_backfill_execute_persists_membership');
    Db::name('yfth_package_instance')->where('id', $legacy['instance_id'])->update(['refund_status' => 'refunded']);
    $assert($membership->effectiveMembership($legacyUid)['is_member'] === true, 'refund_does_not_auto_revoke_permanent_membership');

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
        'yfth_direct_referral_reward_candidate', 'yfth_direct_referral_rule_version', 'yfth_direct_referral_invite',
        'yfth_permanent_membership_event', 'yfth_permanent_membership', 'yfth_hq_active_referral_event',
        'yfth_hq_active_referral_current', 'yfth_hq_customer_attribution_event', 'yfth_hq_customer_attribution_current',
        'yfth_idempotency_record', 'yfth_audit_event', 'yfth_benefit_item', 'yfth_benefit_period',
        'yfth_benefit_plan', 'yfth_package_instance', 'yfth_package_purchase_benefit_snapshot',
        'yfth_package_purchase_snapshot', 'yfth_package_purchase', 'yfth_package_product_binding',
        'yfth_monthly_benefit_rule', 'yfth_package_rule_version', 'yfth_package_template', 'yfth_user_identity',
    ] as $table) {
        Db::name($table)->delete(true);
    }
    Db::name('store_order')->whereBetween('uid', [920001, 920012])->delete();
    Db::name('yfth_user_store_role')->whereBetween('uid', [920001, 920012])->delete();
    Db::name('user')->whereBetween('uid', [920001, 920012])->delete();
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
    foreach (range(920001, 920012) as $uid) {
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
    return (array)$accepted['relation'];
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
    $candidate = Db::name('yfth_direct_referral_reward_candidate')->where('referred_uid', $referredUid)
        ->where('candidate_type', 'package_activation')->find();
    $assert((int)($candidate['reward_sequence_no'] ?? 0) === $sequence, $label . ':sequence');
    $assert((int)($candidate['ratio_bps'] ?? 0) === $ratio, $label . ':ratio');
    $assert((int)($candidate['reward_amount_cent'] ?? 0) === $amount, $label . ':integer_amount');
    $assert((string)($candidate['status'] ?? '') === 'pending', $label . ':pending_only');
}

function pmrCreatePaidOrder(int $uid, int $storeId, string $amount, string $suffix): int
{
    $now = time();
    return (int)Db::name('store_order')->insertGetId([
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
    Db::name('yfth_package_purchase')->where('id', $purchaseId)->update(['instance_id' => $instanceId]);
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

function pmrFundingSnapshot(): array
{
    return [
        'users' => Db::name('user')->whereBetween('uid', [920001, 920012])->order('uid asc')->column('now_money,brokerage_price', 'uid'),
        'legacy_reward_ledger_count' => (int)Db::name('yfth_reward_ledger')->count(),
        'legacy_settlement_count' => (int)Db::name('yfth_reward_settlement_record')->count(),
    ];
}
