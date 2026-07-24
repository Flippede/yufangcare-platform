<?php

use app\services\yfth\ProductQuotaPurchaseServices;
use app\services\yfth\UnifiedRewardOrchestratorServices;
use app\services\yfth\CurrentBusinessContextServices;
use app\services\yfth\UserIdentityServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $ok, string $label) use (&$failures, &$passes): void { $ok ? $passes[] = $label : $failures[] = $label; };

if ((string)getenv('YFTH_PARTNER_REWARD_V1_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_PARTNER_REWARD_V1_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_guard');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test|local|dev|v1)/i', $database), 'isolated_database_name:' . $database);
    if ($failures) throw new RuntimeException('isolation_guard_failed');

    $rankCodes = ['platform_director', 'regional_director', 'province_partner', 'prefecture_partner', 'county_partner'];
    $partners = Db::name('yfth_partner_profile')
        ->where(['status' => 'active', 'qualification_status' => 'effective'])
        ->whereIn('rank_code', $rankCodes)
        ->select()->toArray();
    $partnerByRank = [];
    foreach ($partners as $partnerRow) {
        $partnerByRank[(string)$partnerRow['rank_code']] = $partnerRow;
    }
    $assert(count(array_intersect($rankCodes, array_keys($partnerByRank))) === 5, 'five_partner_ranks_are_effective');

    $identityServices = app()->make(UserIdentityServices::class);
    $contextServices = app()->make(CurrentBusinessContextServices::class);
    foreach ($rankCodes as $rankCode) {
        if (empty($partnerByRank[$rankCode])) {
            continue;
        }
        $profile = $partnerByRank[$rankCode];
        $partnerUid = (int)$profile['uid'];
        $identities = $identityServices->listUserIdentities($partnerUid);
        $partnerIdentity = array_values(array_filter($identities, function (array $identity) use ($rankCode): bool {
            return (string)$identity['role_code'] === $rankCode && (int)$identity['store_id'] === 0
                && (string)$identity['source_type'] === 'partner_profile';
        }));
        $assert(count($partnerIdentity) === 1, $rankCode . '_profile_identity_is_unique');
        $context = $contextServices->resolve($partnerUid, $rankCode, (int)$profile['primary_store_id']);
        $assert(
            (int)$context['store_id'] === 0
            && (string)$context['business_context_source'] === 'server_partner_profile'
            && empty($context['capabilities']),
            $rankCode . '_context_isolated_from_store_operation'
        );
    }

    $partner = $partnerByRank['county_partner'] ?? [];
    if (!$partner) throw new RuntimeException('effective_county_partner_fixture_missing');
    $uid = (int)$partner['uid'];
    $storeId = (int)$partner['primary_store_id'];
    $assert($storeId > 0, 'county_partner_manages_test_store');
    $assert((int)Db::name('yfth_user_store_role')->where('uid', $uid)->whereIn('role_code', ['store_manager', 'store_staff'])->where('status', 'active')->count() === 0, 'county_partner_has_no_store_operating_role');
    $countyContext = $contextServices->resolve($uid, 'county_partner', $storeId);
    $assert((int)($countyContext['permission_scope']['managed_store_count'] ?? 0) >= 1, 'county_partner_exposes_managed_store_count_only');
    // Fixed IDs make this isolated flow replayable: the durable event and award
    // uniqueness guards must return the original first-three result on every run.
    $nonce = 991000;
    $purchaseNonce = random_int(700000000, 900000000);
    $orchestrator = app()->make(UnifiedRewardOrchestratorServices::class);
    $fee = 8910000;
    $eventIds = [];
    for ($sequence = 1; $sequence <= 4; $sequence++) {
        $applicationId = $nonce + $sequence;
        $queued = $orchestrator->enqueue('partner_store_opened', 'franchise_application', (string)$applicationId, [
            'application_id' => $applicationId, 'performance_id' => $applicationId,
            'store_id' => $storeId + $sequence, 'applicant_uid' => $uid + $sequence,
            'direct_partner_uid' => $uid, 'fee_amount_cent' => $fee,
        ]);
        $eventIds[] = (int)$queued['event']['id'];
        $orchestrator->process((int)$queued['event']['id'], 'real-flow');
        $orchestrator->process((int)$queued['event']['id'], 'real-flow-replay');
    }
    $awards = Db::name('yfth_partner_opening_quota_award')->whereIn('application_id', [$nonce + 1, $nonce + 2, $nonce + 3, $nonce + 4])->order('sequence_no asc')->select()->toArray();
    $assert(count($awards) === 4, 'four_openings_recorded_once');
    $assert(array_column($awards, 'ratio_bps') === [2000, 3000, 5000, 0], 'opening_ratios_20_30_50_zero');
    $assert(array_column($awards, 'quota_amount_cent') === [1782000, 2673000, 4455000, 0], 'opening_quota_amounts_exact');
    $awardStatuses = array_column($awards, 'status');
    $assert($awardStatuses === ['pending', 'pending', 'pending', 'ineligible'] || $awardStatuses === ['granted', 'granted', 'granted', 'ineligible'], 'opening_quota_waits_for_or_replays_headquarters_confirmation');
    $assert((string)$awards[3]['status'] === 'ineligible', 'fourth_opening_no_reward');
    $assert((int)Db::name('yfth_reward_event')->whereIn('id', $eventIds)->where('status', 'succeeded')->count() === 4, 'durable_events_succeeded');
    foreach (array_slice($awards, 0, 3) as $award) {
        $orchestrator->confirmOpeningQuota((int)$award['id'], 900001);
        $orchestrator->confirmOpeningQuota((int)$award['id'], 900001);
    }
    $assert((int)Db::name('yfth_partner_opening_quota_award')->whereIn('id', array_column(array_slice($awards, 0, 3), 'id'))->where('status', 'granted')->count() === 3, 'headquarters_confirmation_is_idempotent');
    $assert((int)$orchestrator->consistencyIssues(100)['count'] === 0, 'reward_event_result_consistency');

    $account = Db::name('yfth_product_quota_account')->where('active_key', 'store:' . $storeId . ':return_goods')->find();
    $before = (int)$account['available_cent'];
    $quota = app()->make(ProductQuotaPurchaseServices::class);
    $firstOrder = $purchaseNonce + 101;
    $reserved = $quota->reserve($firstOrder, $storeId, 150000, 100000, 'real-flow-reserve:' . $firstOrder);
    $assert((int)$reserved['online_amount_cent'] === 50000, 'mixed_payment_online_remainder');
    $quota->release($firstOrder, 'real_flow_reject');
    $afterRelease = Db::name('yfth_product_quota_account')->where('id', (int)$account['id'])->find();
    $assert((int)$afterRelease['available_cent'] === $before, 'reservation_release_restores_available');

    $secondOrder = $purchaseNonce + 102;
    $quota->reserve($secondOrder, $storeId, 120000, 80000, 'real-flow-reserve:' . $secondOrder);
    $quota->useForStockIn($secondOrder);
    $quota->refundUsed($secondOrder, 30000, 'real_flow_partial_refund');
    $quota->reverseRefund($secondOrder, 10000, 'real_flow_refund_reversal');
    $reservation = Db::name('yfth_product_quota_reservation')->where('purchase_order_id', $secondOrder)->find();
    $assert((int)$reservation['used_cent'] === 80000, 'quota_use_posted');
    $assert((int)$reservation['refunded_cent'] === 30000 && (int)$reservation['reversed_cent'] === 10000, 'quota_refund_and_reversal_posted');
    $assert((int)Db::name('yfth_product_quota_ledger')->where('source_type', 'purchase_order')->whereIn('source_id', [$firstOrder, $secondOrder])->count() === 6, 'quota_append_only_purchase_ledger');
} catch (Throwable $e) {
    $failures[] = 'exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
}

if ($failures) {
    fwrite(STDERR, "YFTH partner reward V1 real flow failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo '[OK] YFTH partner reward V1 real flow passed with ' . count($passes) . " assertions.\n";
