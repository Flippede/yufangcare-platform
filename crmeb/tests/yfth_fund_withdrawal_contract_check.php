<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$read = function (string $path) use ($root): string {
    return (string)file_get_contents($root . DIRECTORY_SEPARATOR . $path);
};

foreach ([
    'app/services/yfth/FundWithdrawalServices.php',
    'app/api/controller/v1/yfth/FundWithdrawalController.php',
    'app/adminapi/controller/v1/yfth/FundFinance.php',
    'database/migrations/20260727100000_create_yfth_fund_withdrawal_finance_v1.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$service = $read('app/services/yfth/FundWithdrawalServices.php');
foreach ([
    "'county_partner'",
    "'prefecture_partner'",
    "'province_partner'",
    "'regional_director'",
    "'platform_director'",
    'partner_observation_days',
    'pending_review',
    'approved',
    'rejected',
    'paid',
    'assertRequestCovered',
    'assertFrozenPartnerSources',
    'fund_withdrawal_pay_status_invalid',
    'store_withdrawal_manager_required',
    'store_withdrawal_scope_forbidden',
    'store_manual_withdrawal_paid',
    'receiver_name_enc',
    'receiver_account_enc',
] as $needle) {
    $assert(strpos($service, $needle) !== false, 'service_contains:' . $needle);
}
foreach ([
    'now_money',
    'brokerage_price',
    'user_extract',
    'merchantPay',
    'automatic_bank_transfer',
] as $needle) {
    $assert(strpos($service, $needle) === false, 'service_does_not_touch_crmeb_cash:' . $needle);
}

$commission = $read('app/services/yfth/CommissionFinanceServices.php');
$assert(strpos($commission, 'store_settlement_batch_write_disabled_use_withdrawal') !== false, 'legacy_b1_batch_writes_disabled');
$partner = $read('app/services/yfth/FranchisePartnerServices.php');
$assert(strpos($partner, 'partner_reward_manual_settlement_disabled_use_withdrawal') !== false, 'legacy_partner_manual_settlement_disabled');

$apiRoute = $read('app/api/route/yfth_service.php');
foreach ([
    'yfth/franchise/partner/withdrawal/summary',
    'yfth/franchise/partner/withdrawal',
    'yfth/store_workbench/withdrawal/summary',
    'yfth/store_workbench/withdrawal',
] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'api_route_contains:' . $needle);
}
$adminRoute = $read('app/adminapi/route/yfth.php');
foreach ([
    "Route::group('fund_finance'",
    "Route::get('withdrawal'",
    'FundFinance/index',
    'FundFinance/detail',
    'FundFinance/review',
    'FundFinance/paid',
] as $needle) {
    $assert(strpos($adminRoute, $needle) !== false, 'admin_route_contains:' . $needle);
}

$migration = $read('database/migrations/20260727100000_create_yfth_fund_withdrawal_finance_v1.php');
foreach ([
    'yfth_fund_withdrawal_setting',
    'yfth_fund_withdrawal_request',
    'yfth_fund_withdrawal_allocation',
    'uniq_yfth_fund_withdrawal_no',
    'uniq_yfth_fund_withdrawal_request',
    'uniq_yfth_fund_withdrawal_allocation',
    'yfth-finance',
    'yfth-finance-withdrawal-review',
    'yfth-finance-withdrawal-pay',
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}

$adminRouter = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/router/modules/yfthFinance.js');
$adminPage = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/pages/yfth/financeWithdrawal/index.vue');
$partnerPage = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/franchise/partner/index.vue');
$storePage = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/workbench/commission/index.vue');
foreach (['yfth-finance', 'withdrawal', 'yfth-finance-withdrawal-index'] as $needle) {
    $assert(strpos($adminRouter, $needle) !== false, 'admin_router_contains:' . $needle);
}
$assert(strpos($adminRouter, "header: 'finance'") !== false, 'admin_router_uses_existing_finance_header');
$assert(strpos($migration, "menu('admin-finance')") !== false, 'migration_reuses_existing_finance_root');
$assert(strpos($migration, "'menu_name' => '御方通和资金'") !== false, 'migration_creates_yfth_finance_group');
$menuRepairMigration = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'crmeb/database/migrations/20260727113000_attach_yfth_withdrawal_to_finance_menu.php');
$assert(strpos($menuRepairMigration, "'menu_name' => '御方通和资金'") !== false, 'menu_repair_uses_yfth_finance_group_name');
$assert(strpos($menuRepairMigration, "'menu_name' => '提现审核'") !== false, 'menu_repair_uses_withdrawal_review_name');
foreach (['审核通过', '驳回', '确认线下打款', 'pay_reference'] as $needle) {
    $assert(strpos($adminPage, $needle) !== false, 'admin_page_contains:' . $needle);
}
foreach (['申请提现', '可提现', '观察期中', '已打款'] as $needle) {
    $assert(strpos($partnerPage, $needle) !== false, 'partner_page_contains:' . $needle);
}
foreach (['门店提现', 'store_manager', '店员可查看'] as $needle) {
    $assert(strpos($storePage, $needle) !== false, 'store_page_contains:' . $needle);
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
echo "[OK] YFTH fund withdrawal contract check passed (" . count($passes) . " assertions).\n";
