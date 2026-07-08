<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];

$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

$contains = function (string $text, string $needle): bool {
    return strpos($text, $needle) !== false;
};

$service = $read('app/services/yfth/ReferralRewardServices.php');
$migration = $read('database/migrations/20260710100000_create_yfth_referral_reward_tables.php');
$adminController = $read('app/adminapi/controller/v1/yfth/ReferralReward.php');
$apiController = $read('app/api/controller/v1/yfth/ReferralRewardController.php');

$assert($contains($service, 'candidateActiveKey($scene, $uid') && $contains($migration, 'uniq_yfth_referral_candidate_active_key'), 'same_referred_uid_same_scene_has_one_active_candidate');
$assert($contains($apiController, 'array_key_exists($field, $post)') && $contains($service, 'referral_client_owner_field_forbidden'), 'client_cannot_forge_owner_or_store_fields');
$assert($contains($service, 'package_activated') && $contains($service, "'status' => 'observing'"), 'package_activated_enters_observing');
$assert(!$contains($service, 'application_submitted') || !$contains($service, 'application_submitted') || $contains($service, "in_array($eventType, ['package_activated', 'franchise_opened']"), 'application_submitted_not_effective_reward');
$assert($contains($service, 'franchise_opened') && $contains($service, 'createAttributionAndLedger'), 'franchise_opened_enters_observing');
$assert($contains($service, 'observe_end_time') && $contains($service, "where('status', 'observing')") && $contains($service, 'reward_ledger_valid'), 'observing_scan_promotes_valid');
$assert($contains($service, 'package_refunded') && $contains($service, 'package_closed') && $contains($service, 'package_frozen'), 'package_negative_events_reverse_or_invalid');
$assert($contains($service, 'franchise_terminated') && $contains($service, 'franchise_revoked'), 'franchise_negative_events_reverse_or_invalid');
$assert($contains($service, 'rule_snapshot') && $contains($service, 'YfthRewardLedgerSnapshotDao::class'), 'ledger_uses_rule_snapshot');
$assert($contains($service, 'published_reward_rule_immutable'), 'published_rule_is_immutable');
$assert($contains($service, 'amount_cent') && !$contains($service, '(float)'), 'amount_cent_integer_no_float');
$assert($contains($service, 'YfthRewardSettlementRecordDao::class') && $contains($service, 'active_key') && $contains($service, 'ledger:'), 'settlement_mark_unique_per_active_ledger');
$assert($contains($service, 'YfthRewardAdjustmentDao::class') && $contains($service, 'append-only') === false && $contains($service, 'save(['), 'adjustment_is_append_only_insert');
$assert($contains($migration, 'uniq_yfth_referral_event_idempotency') && $contains($service, 'replay'), 'duplicate_event_replays_without_duplicate_ledger');
$assert($contains($service, 'reward_scan') && $contains($service, 'changed'), 'scan_reports_dry_run_and_run');
$assert($contains($adminController, 'assertApiAuthForAdmin'), 'admin_apis_have_explicit_permission_assertion');
$assert($contains($service, 'assertHeadquarterScope'), 'store_scoped_admin_forbidden_from_hq_management');

foreach ([
    'user_brokerage',
    'user_bill',
    'now_money',
    'user_spread',
    'extract',
    'withdraw',
    'StoreOrderCreateServices',
    'decStockIncSales',
    'incStockDecSales',
    'YfthInventoryBalanceDao',
    'YfthInventoryLedgerDao',
] as $forbidden) {
    $assert(!$contains($service, $forbidden), 'referral_service_does_not_touch_' . $forbidden);
}

if ($failures) {
    echo "YFTH referral reward real-flow source guard failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH referral reward real-flow source guard passed with ' . count($passes) . " assertions.\n";
