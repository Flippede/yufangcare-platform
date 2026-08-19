<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$source = function (string $path) use ($root, $assert): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $assert(is_file($full), 'file_exists:' . $path);
    return is_file($full) ? (string)file_get_contents($full) : '';
};

$automatic = $source('app/services/yfth/AutomaticCommissionServices.php');
$points = $source('app/services/yfth/MemberPointsServices.php');
$finance = $source('app/services/yfth/CommissionFinanceServices.php');
$computed = $source('app/services/order/StoreOrderComputedServices.php');
$order = $source('app/services/order/StoreOrderServices.php');
$customerService = $source('app/services/yfth/CustomerServiceServices.php');
$customerServiceController = $source('app/api/controller/v1/yfth/CustomerServiceController.php');
$customerServiceRoute = $source('app/api/route/yfth_service.php');
$context = $source('app/services/yfth/CurrentBusinessContextServices.php');
$api = $source('../template/uni-app/api/yfth.js');
$userCenter = $source('../template/uni-app/pages/user/index.vue');
$storeCommission = $source('../template/uni-app/pages/yfth/workbench/commission/index.vue');
$migration = $source('database/migrations/20260804100000_create_yfth_member_points_and_customer_service_v1.php');

foreach (['creditAccrual($accrual, $c1)', 'reverseAccrual($accrual, $c1Cent'] as $needle) {
    $assert(strpos($automatic, $needle) !== false, 'automatic_commission_uses_points:' . $needle);
}
$assert(strpos($automatic, '$this->postUser(') === false, 'automatic_commission_no_c1_cash_post_call');
$assert(strpos($automatic, 'commission_c1_responsibility_credit') === false, 'automatic_commission_no_store_c1_cash_responsibility');
foreach (['c1_cash_settlement_retired_use_points', 'c1_cash_adjustment_retired_use_points'] as $needle) {
    $assert(strpos($finance, $needle) !== false, 'c1_cash_service_fail_closed:' . $needle);
}
foreach (['creditAccrual', 'reverseAccrual', 'legacy_c1_balance_conversion', 'integral_debt', 'source_unique_key'] as $needle) {
    $assert(strpos($points, $needle) !== false, 'points_service_contains:' . $needle);
}
foreach (['max_deduction_bps', 'min_cash_cent', "yfth_channel'] ?? '') === 'procurement'", 'yfth_package_product_binding'] as $needle) {
    $assert(strpos($points, $needle) !== false, 'points_policy_contains:' . $needle);
}
$assert(strpos($computed, 'deductionPolicy($cartInfo, $payPrice)') !== false, 'checkout_computation_uses_points_policy');
$assert(strpos($order, "['integral_open'] = !empty(\$memberPointsPolicy['eligible'])") !== false, 'order_confirm_exposes_points_policy');

foreach (['customer_service_uid', 'active_store_key', 'store_customer_service_already_assigned', "'role_code' => 'customer_service'"] as $needle) {
    $assert(strpos($customerService, $needle) !== false, 'customer_service_contains:' . $needle);
}
$assert(strpos($customerService, 'function storeContact(Request $request)') !== false, 'customer_service_has_store_contact_reader');
$assert(strpos($customerService, "['store_manager', 'store_staff']") !== false, 'customer_service_contact_is_store_operator_scoped');
$assert(strpos($customerServiceController, 'storeContact(Request $request') !== false, 'customer_service_contact_controller_exists');
$assert(strpos($customerServiceRoute, 'yfth/store_workbench/customer_service/contact') !== false, 'customer_service_contact_route_exists');
$assert(strpos($customerService, 'yfth_hq_customer_attribution_current') === false, 'customer_service_does_not_read_c_end_customers');
$assert(strpos($customerService, 'commission') === false && strpos($customerService, 'withdrawal') === false, 'customer_service_has_no_finance_access');
$assert(strpos($context, "if (\$roleCode === 'customer_service')") !== false, 'customer_service_has_server_context');

foreach (['requestYfthCommissionSettlement', 'getYfthStoreC1Settlements', 'completeYfthStoreC1Settlement'] as $needle) {
    $assert(strpos($api, $needle) === false, 'retired_c1_write_wrapper_removed:' . $needle);
}
$assert(strpos($storeCommission, 'C1线下结算') === false, 'store_workbench_has_no_c1_cash_settlement');
$assert(strpos($userCenter, '我的积分') !== false, 'user_center_exposes_points');
$assert(strpos($api, 'getYfthStoreCustomerServiceContact') !== false, 'user_api_exposes_store_customer_service_contact');
$assert(strpos($userCenter, '专属客服') !== false, 'store_user_center_exposes_dedicated_customer_service');
$assert(strpos($userCenter, '暂无客服') !== false, 'store_user_center_has_no_customer_service_state');

foreach ([
    'yfth_member_points_account', 'yfth_member_points_ledger', 'yfth_member_points_config',
    'yfth_member_points_product_rule', 'yfth_customer_service_store_binding',
    'uniq_yfth_member_points_source', 'uniq_yfth_customer_service_active_store',
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH customer service and member points contract verified.\n";
