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
    $condition ? $passes[] = $label : $failures[] = $label;
};

$contains = static function (string $text, string $needle): bool {
    return strpos($text, $needle) !== false;
};

$baseMigration = $read('database/migrations/20260723150000_create_yfth_procurement_partner_profit_v1.php');
$nativeMigration = $read('database/migrations/20260724120000_unify_yfth_procurement_with_store_orders.php');
$service = $read('app/services/yfth/ProcurementPartnerProfitServices.php');
$sourceService = $read('app/services/yfth/YfthOrderSourceServices.php');
$payListener = $read('app/listener/yfth/ProcurementPartnerProfitPayListener.php');
$customListener = $read('app/listener/yfth/ProcurementPartnerProfitCustomEventListener.php');
$ordinaryPayListener = $read('app/listener/yfth/MallConsumptionRewardPayListener.php');
$ordinaryCustomListener = $read('app/listener/yfth/MallConsumptionRewardCustomEventListener.php');
$events = $read('app/event.php');
$partnerService = $read('app/services/yfth/FranchisePartnerServices.php');
$adminController = $read('app/adminapi/controller/v1/yfth/FranchisePartner.php');
$adminRoutes = $read('app/adminapi/route/yfth.php');

foreach ([
    'yfth_procurement_profit_snapshot',
    'yfth_procurement_profit_ledger',
    'yfth_partner_opening_reward_ledger',
    'yfth_platform_dividend_batch',
    'yfth_platform_dividend_item',
] as $table) {
    $assert($contains($baseMigration, $table), 'base_migration_contains:' . $table);
}
$assert($contains($nativeMigration, "'source_type'") && $contains($nativeMigration, "'store_order_id'"), 'native_migration_extends_profit_sources');

$assert($contains($service, 'freezeForStoreOrder'), 'profit_freezes_native_store_order');
$assert($contains($service, 'recognizeForStoreOrder'), 'profit_recognizes_native_store_order');
$assert($contains($service, 'reverseForStoreOrder'), 'profit_reverses_native_store_order');
$assert($contains($service, 'synchronizeStoreOrderRefund'), 'profit_synchronizes_cumulative_native_refunds');
$assert($contains($service, "'source_type' => 'store_order'"), 'profit_records_native_source_type');
$assert($contains($service, 'resolveDirectCountyPartner'), 'profit_resolves_direct_county_partner');
$assert($contains($service, 'nearestCountyPartner'), 'profit_keeps_nearest_county_fallback');
$assert($contains($service, 'chain_snapshot') && $contains($service, 'rate_snapshot'), 'profit_freezes_chain_and_rule_rates');
$assert($contains($service, "source_unique_key' => \$sourceKey"), 'profit_ledger_has_idempotency_key');
$assert($contains($service, 'recordOpeningReward'), 'county_opening_reward_is_preserved');
$assert($contains($service, 'generateDividend'), 'platform_weighted_dividend_is_preserved');

$assert($contains($payListener, 'freezeForStoreOrder'), 'native_payment_freezes_partner_profit');
$assert($contains($customListener, "['order_take', 'admin_order_refund_success']"), 'native_completion_and_refund_events_are_consumed');
$assert($contains($customListener, 'recognizeForStoreOrder'), 'native_receipt_recognizes_partner_profit');
$assert($contains($customListener, 'synchronizeStoreOrderRefund'), 'native_refund_reverses_only_new_cumulative_amount');
$assert($contains($events, 'ProcurementPartnerProfitPayListener::class'), 'native_profit_pay_listener_is_registered');
$assert($contains($events, 'ProcurementPartnerProfitCustomEventListener::class'), 'native_profit_custom_listener_is_registered');

$assert(
    $contains($sourceService, "'legacy_brokerage_excluded' => 1")
    && $contains($sourceService, 'public function excludesCrmebBrokerage'),
    'procurement_source_excludes_crmeb_brokerage'
);
$assert($contains($ordinaryPayListener, "isSource(\$orderId, 'procurement')"), 'procurement_payment_skips_c1_b1_mall_commission');
$assert($contains($ordinaryCustomListener, "isSource(\$orderId, 'procurement')"), 'procurement_completion_and_refund_skip_c1_b1_mall_commission');

$assert($contains($partnerService, "'procurement_rate_bps'"), 'partner_rules_keep_procurement_rates');
$assert($contains($partnerService, "'opening_reward_amount_cent'"), 'partner_rules_keep_county_opening_reward');
$assert($contains($partnerService, "'profit_summary'"), 'partner_workbench_keeps_profit_summary');
$assert($contains($adminController, 'procurementProfitList'), 'headquarters_can_query_procurement_profit');
$assert($contains($adminRoutes, 'procurement_profit'), 'headquarters_profit_route_is_registered');

if ($failures) {
    echo "YFTH native procurement partner profit contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH native procurement partner profit contract check passed with ' . count($passes) . " assertions.\n";
