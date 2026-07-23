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

$migration = $read('database/migrations/20260723150000_create_yfth_procurement_partner_profit_v1.php');
$service = $read('app/services/yfth/ProcurementPartnerProfitServices.php');
$supply = $read('app/services/yfth/SupplyChainServices.php');
$partner = $read('app/services/yfth/FranchisePartnerServices.php');
$controller = $read('app/adminapi/controller/v1/yfth/FranchisePartner.php');
$routes = $read('app/adminapi/route/yfth.php');
$adminApi = $read('../template/admin/src/api/yfth.js');
$adminPage = $read('../template/admin/src/pages/yfth/franchisePartner/index.vue');
$partnerPage = $read('../template/uni-app/pages/yfth/franchise/partner/index.vue');
$purchasePage = $read('../template/uni-app/pages/yfth/workbench/purchase/index.vue');

foreach ([
    'yfth_procurement_profit_snapshot',
    'yfth_procurement_profit_ledger',
    'yfth_partner_opening_reward_ledger',
    'yfth_platform_dividend_batch',
    'yfth_platform_dividend_item',
    'yfth_partner_service_area',
] as $table) {
    $assert($contains($migration, $table), 'migration_contains_' . $table);
}

foreach ([
    'uniq_yfth_procurement_snapshot_order',
    'uniq_yfth_procurement_profit_source',
    'uniq_yfth_opening_reward_source',
    'uniq_yfth_platform_dividend_batch',
    'uniq_yfth_platform_dividend_item',
    'uniq_yfth_partner_service_area',
] as $index) {
    $assert($contains($migration, $index), 'migration_contains_' . $index);
}

$assert($contains($migration, "'county_partner' => [2000, 1760000]"), 'migration_seeds_county_defaults');
$assert($contains($migration, "'prefecture_partner' => [1000, 0]"), 'migration_seeds_prefecture_default');
$assert($contains($migration, "'province_partner' => [500, 0]"), 'migration_seeds_province_default');
$assert($contains($migration, "'regional_director' => [300, 0]"), 'migration_seeds_regional_default');
$assert($contains($migration, "'platform_director' => [100, 0]"), 'migration_seeds_platform_default');
$assert($contains($migration, "if (!\$rankRule->hasColumn('opening_reward_amount_cent'))"), 'migration_repairs_partial_columns');

$assert($contains($supply, "STORE_WRITE_ROLES = ['store_manager']"), 'only_store_manager_can_purchase');
$assert($contains($supply, 'freezeForPurchaseOrder'), 'purchase_creation_freezes_profit_snapshot');
$assert($contains($supply, 'recognizeForReceipt'), 'receipt_recognizes_partner_profit');
$assert(!$contains($supply, "Db::name('store_order')->insert"), 'procurement_does_not_create_crmeb_order');
$assert(!$contains($supply, "Db::name('store_product')->update"), 'procurement_does_not_mutate_crmeb_sales_stock');

$assert($contains($service, 'resolveDirectCountyPartner'), 'service_resolves_county_partner');
$assert($contains($service, 'nearestCountyPartner'), 'service_has_nearest_fallback');
$assert($contains($service, "['match_score', 'priority', 'workload', 'start_time', 'uid']"), 'nearest_assignment_is_stable');
$assert($contains($service, 'chain_snapshot') && $contains($service, 'rate_snapshot'), 'order_freezes_chain_and_rates');
$assert($contains($service, "source_unique_key' => \$sourceKey"), 'profit_ledger_is_idempotent');
$assert($contains($service, "(string)\$profile['rank_code'] !== 'county_partner'"), 'opening_reward_is_county_only');
$assert($contains($service, 'generateDividend'), 'service_generates_platform_dividend');
$assert($contains($service, 'reverseForPurchaseOrder'), 'service_reserves_refund_reversal');

$assert($contains($partner, "'procurement_rate_bps'"), 'partner_rule_supports_procurement_rate');
$assert($contains($partner, "'opening_reward_amount_cent'"), 'partner_rule_supports_opening_reward');
$assert($contains($partner, 'recordOpeningReward'), 'opening_completion_records_reward');
$assert($contains($partner, "'profit_summary'"), 'partner_workbench_returns_profit_summary');

$assert($contains($controller, 'procurementProfitList') && $contains($controller, 'dividendGenerate'), 'admin_controller_exposes_profit_management');
$assert($contains($routes, 'procurement_profit') && $contains($routes, 'dividend/generate'), 'admin_routes_expose_profit_management');
$assert($contains($adminApi, 'yfthPartnerProcurementProfits') && $contains($adminApi, 'yfthPartnerDividendGenerate'), 'admin_api_exposes_profit_management');
$assert($contains($adminPage, '采购分润') && $contains($adminPage, '开店服务奖励') && $contains($adminPage, '平台加权分红'), 'admin_page_shows_profit_surfaces');
$assert($contains($partnerPage, '采购分润') && $contains($partnerPage, '开店服务奖励'), 'partner_workbench_shows_profit_surfaces');
$assert($contains($purchasePage, '仅店长可进入采购中心'), 'purchase_page_excludes_non_managers');

if ($failures) {
    echo "YFTH procurement partner profit contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH procurement partner profit contract check passed with ' . count($passes) . " assertions.\n";
