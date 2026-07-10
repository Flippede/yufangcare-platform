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

$migration = $read('database/migrations/20260712100000_create_yfth_monthly_benefit_fulfillment_tables.php');
$service = $read('app/services/yfth/MonthlyBenefitFulfillmentServices.php');
$userController = $read('app/api/controller/v1/yfth/MonthlyBenefitFulfillmentController.php');
$storeController = $read('app/api/controller/v1/yfth/StoreWorkbenchController.php');
$adminController = $read('app/adminapi/controller/v1/yfth/MonthlyBenefitFulfillment.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$adminApi = $read('../template/admin/src/api/yfth.js');
$adminPage = $read('../template/admin/src/pages/yfth/monthlyBenefitFulfillment/index.vue');
$adminRouter = $read('../template/admin/src/router/modules/yfth.js');
$uniApi = $read('../template/uni-app/api/yfth.js');
$pagesJson = $read('../template/uni-app/pages.json');
$userIndex = $read('../template/uni-app/pages/yfth/monthly_benefit/index.vue');
$userHistory = $read('../template/uni-app/pages/yfth/monthly_benefit/history.vue');
$userDetail = $read('../template/uni-app/pages/yfth/monthly_benefit/detail.vue');
$pickupPage = $read('../template/uni-app/pages/yfth/workbench/monthly_benefit_pickup.vue');
$workbench = $read('../template/uni-app/pages/yfth/workbench/index.vue');

foreach (['yfth_benefit_fulfillment', 'yfth_benefit_fulfillment_event'] as $table) {
    $assert($contains($migration, $table), 'migration_contains_' . $table);
}

foreach ([
    'uniq_yfth_benefit_fulfillment_no',
    'uniq_yfth_benefit_fulfillment_idem',
    'uniq_yfth_benefit_fulfillment_active',
    'idx_yfth_benefit_fulfillment_uid',
    'idx_yfth_benefit_fulfillment_store',
    'idx_yfth_benefit_fulfillment_pickup',
] as $index) {
    $assert($contains($migration, $index), 'migration_contains_index_' . $index);
}

foreach ([
    'package_instance_id',
    'benefit_plan_id',
    'benefit_period_id',
    'benefit_item_id',
    'fulfillment_method',
    'pickup_store_id',
    'delivery_no_masked',
    'active_key',
] as $field) {
    $assert($contains($migration, $field), 'migration_contains_field_' . $field);
}

$assert($contains($migration, "'auth_type' => 2"), 'migration_seeds_api_permissions');
$assert($contains($migration, 'yfth-monthly-benefit-fulfillment-index'), 'migration_seeds_admin_page_permission');
$assert($contains($migration, 'DELETE FROM `') && $contains($migration, 'system_menus'), 'migration_down_removes_permissions');

foreach ([
    "private const FULFILLMENT_DOMAIN = 'yfth_monthly_benefit_fulfillment'",
    'IdempotencyRecordServices::class',
    'AuditEventServices::class',
    'CurrentBusinessContextServices::class',
    'StoreAccessServices::class',
    'AdminStoreContextServices::class',
    'lockBenefitRows',
    'assertProductBenefitClaimable',
    'consumeProductBenefit',
    'activeFulfillmentKey',
    'appendEvent',
    'recordPackageAudit',
    'monthly_benefit_idempotency_key_required',
    'monthly_benefit_claim_field_forbidden',
    'benefit_item_already_claimed',
] as $needle) {
    $assert($contains($service, $needle), 'service_contains_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $needle));
}

$assert($contains($service, "benefit_type'] !== 'product'"), 'service_claims_product_benefit_only');
$assert($contains($service, "status' => 'used'") && $contains($service, "fulfillment_status' => 'product_fulfilled'"), 'service_finally_consumes_product_benefit');
$assert($contains($service, "quantity_available' => '0.00'"), 'service_zeroes_available_quantity_on_complete');
$assert($contains($service, "fulfilled_item_count' =>") && $contains($service, "fulfilled_count' =>"), 'service_updates_package_fulfilled_counters');
$assert($contains($service, 'where(\'id\', $benefitItemId)->lock(true)->find()'), 'service_locks_benefit_item_row');
$assert($contains($service, "where('id', (int)\$item['package_instance_id'])->lock(true)->find()"), 'service_locks_package_instance_row');
$assert($contains($service, "where('id', (int)\$item['plan_id'])->lock(true)->find()"), 'service_locks_plan_row');
$assert($contains($service, "where('id', (int)\$item['period_id'])->lock(true)->find()"), 'service_locks_period_row');
$assert($contains($service, 'begin(self::FULFILLMENT_DOMAIN, $eventType') && $contains($service, "'fulfillment:' . \$id"), 'service_transition_uses_yfth_idempotency_record');
$assert($contains($service, "'user_cancel:' . \$id") && $contains($service, "'store_pickup:' . \$id"), 'service_user_and_store_writes_have_scoped_idempotency_keys');
$assert($contains($service, "'admin_' . \$action . ':' . \$fulfillmentId . ':' . \$adminId"), 'service_admin_default_idempotency_key_includes_fulfillment_id');
$assert($contains($service, "adminComplete(int \$id") && $contains($service, '[self::STATUS_SHIPPED, self::STATUS_PICKED_UP]') && !$contains($service, '[self::STATUS_CONFIRMED, self::STATUS_PREPARING, self::STATUS_SHIPPED, self::STATUS_PICKED_UP]'), 'admin_complete_rejects_confirmed_and_preparing_sources');
$assert($contains($service, 'assertCompletionPath') && $contains($service, 'monthly_benefit_complete_requires_shipped') && $contains($service, 'monthly_benefit_pickup_complete_requires_pickup_confirm'), 'service_enforces_method_specific_complete_path');
$assert($contains($service, 'allow_pickup_direct_complete') && $contains($service, "'event_type' => 'pickup_confirm'"), 'store_pickup_confirm_path_is_explicit');
$assert($contains($service, 'monthly_benefit_delivery_company_required') && $contains($service, 'monthly_benefit_delivery_no_required'), 'service_requires_delivery_company_and_no_for_express_ship');
$assert($contains($service, "'active_key'"), 'service_claim_payload_rejects_active_key');

foreach ([
    "Db::name('store_order')",
    'StoreOrderCreate',
    'store_product_attr_value',
    'decStockIncSales',
    'incStockDecSales',
    'yfth_inventory_balance',
    'yfth_product_quota_account',
    'now_money',
    'brokerage',
    'user_bill',
    'settlement',
] as $forbidden) {
    $assert(!$contains($service, $forbidden), 'service_does_not_touch_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $forbidden));
}

$assert($contains($userController, 'withForbiddenParams') && $contains($userController, 'uid') && $contains($userController, 'store_id'), 'user_controller_rejects_sensitive_payload_fields');
$assert($contains($apiRoute, 'yfth/monthly_benefit/current') && $contains($apiRoute, 'yfth/monthly_benefit/claim') && $contains($apiRoute, 'AuthTokenMiddleware::class'), 'user_routes_registered_with_user_token');
$assert($contains($apiRoute, 'yfth/store_workbench/monthly_benefit/pickup') && $contains($storeController, 'monthlyBenefitPickupConfirm'), 'store_pickup_routes_registered');

$assert($contains($adminRoute, "Route::group('monthly_benefit'"), 'admin_route_has_monthly_benefit_group');
$assert($contains($adminController, 'assertApiAuthForAdmin'), 'admin_controller_has_explicit_permission_assertions');
foreach (['confirm', 'reject', 'prepare', 'ship', 'complete', 'exception', 'cancel'] as $action) {
    $assert($contains($adminController, '/<id>/' . $action) || $contains($adminController, 'admin' . ucfirst($action)), 'admin_controller_supports_' . $action);
}

$assert($contains($adminApi, 'yfthMonthlyBenefitFulfillmentList') && $contains($adminApi, 'yfthMonthlyBenefitFulfillmentShip'), 'admin_api_wrappers_exist');
$assert($contains($adminRouter, 'monthly-benefit-fulfillment') && $contains($adminRouter, 'yfth-monthly-benefit-fulfillment-index'), 'admin_router_registered');
$assert($contains($adminPage, 'yfthMonthlyBenefitFulfillmentList') && $contains($adminPage, 'client_operation_key'), 'admin_page_uses_real_api_and_operation_keys');
$assert($contains($adminPage, '不创建 CRMEB 订单') && $contains($adminPage, '不修改商品/SKU库存'), 'admin_page_displays_boundary_text');
$assert($contains($adminPage, "row.status === 'preparing'") && $contains($adminPage, "row.status === 'shipped'") && $contains($adminPage, '请填写承运方'), 'admin_page_matches_hardened_state_machine_and_shipping_required_fields');

$assert($contains($uniApi, 'getYfthMonthlyBenefitCurrent') && $contains($uniApi, 'confirmYfthStoreWorkbenchMonthlyBenefitPickup'), 'uni_api_wrappers_exist');
foreach (['monthly_benefit/index', 'monthly_benefit/history', 'monthly_benefit/detail', 'workbench/monthly_benefit_pickup'] as $page) {
    $assert($contains($pagesJson, $page), 'pages_json_registers_' . str_replace('/', '_', $page));
}
$assert($contains($userIndex, 'claimYfthMonthlyBenefit') && $contains($userIndex, 'addressId') && $contains($userIndex, 'pickupStoreId'), 'user_index_uses_real_claim_api_and_real_selectors');
$assert($contains($userHistory, 'getYfthMonthlyBenefitHistory'), 'user_history_uses_real_api');
$assert($contains($userDetail, 'getYfthMonthlyBenefitFulfillment') && $contains($userDetail, 'cancelYfthMonthlyBenefitFulfillment'), 'user_detail_uses_real_api');
$assert($contains($pickupPage, 'getYfthStoreWorkbenchMonthlyBenefitPickup') && $contains($pickupPage, 'confirmYfthStoreWorkbenchMonthlyBenefitPickup'), 'pickup_page_uses_real_store_api');
$assert($contains($workbench, 'goMonthlyBenefitPickup') && $contains($workbench, 'monthly_benefit_pickup'), 'workbench_links_pickup_page');

foreach (['完整手机号', 'openid', 'unionid', 'delivery_no:'] as $label) {
    $assert(!$contains($userIndex . $userHistory . $userDetail . $pickupPage, $label), 'mobile_pages_do_not_render_sensitive_label_' . $label);
}
$assert($contains($userDetail, 'delivery_no_masked'), 'mobile_detail_uses_masked_delivery_no');

if ($failures) {
    echo "YFTH monthly benefit fulfillment contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH monthly benefit fulfillment contract check passed with ' . count($passes) . " assertions.\n";
