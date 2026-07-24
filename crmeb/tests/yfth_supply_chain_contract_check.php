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

$legacyMigration = $read('database/migrations/20260708170000_create_yfth_supply_chain_inventory_tables.php');
$nativeMigration = $read('database/migrations/20260724120000_unify_yfth_procurement_with_store_orders.php');
$service = $read('app/services/yfth/SupplyChainServices.php');
$cartService = $read('app/services/order/StoreCartServices.php');
$orderCreateService = $read('app/services/order/StoreOrderCreateServices.php');
$orderService = $read('app/services/order/StoreOrderServices.php');
$sourceService = $read('app/services/yfth/YfthOrderSourceServices.php');
$payListener = $read('app/listener/yfth/MallConsumptionRewardPayListener.php');
$refundListener = $read('app/listener/yfth/MallConsumptionRewardCustomEventListener.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$uniApi = $read('../template/uni-app/api/yfth.js');
$purchasePage = $read('../template/uni-app/pages/yfth/workbench/purchase/index.vue');
$checkoutPage = $read('../template/uni-app/pages/yfth/workbench/purchase/checkout.vue');
$adminPage = $read('../template/admin/src/pages/yfth/supplyChain/index.vue');
$orderTable = $read('../template/admin/src/pages/order/orderList/components/tableList.vue');
$userRouter = $read('../template/admin/src/router/modules/user.js');
$yfthRouter = $read('../template/admin/src/router/modules/yfth.js');

foreach ([
    'yfth_supply_catalog',
    'yfth_purchase_order',
    'yfth_inventory_balance',
    'yfth_inventory_ledger',
] as $legacyTable) {
    $assert($contains($legacyMigration, $legacyTable), 'legacy_history_table_is_preserved:' . $legacyTable);
}

foreach ([
    'yfth_native_procurement_order',
    'yfth_supply_catalog_sku',
    'uniq_yfth_native_procurement_order',
    'uniq_yfth_catalog_sku',
    'uniq_yfth_procurement_snapshot_source',
] as $schemaPart) {
    $assert($contains($nativeMigration, $schemaPart), 'native_migration_contains:' . $schemaPart);
}
$assert(
    $contains($nativeMigration, 'yfth_native_procurement_snapshot_must_be_empty_before_rollback')
    && $contains($nativeMigration, 'yfth_native_procurement_ledger_must_be_empty_before_rollback'),
    'native_migration_blocks_lossy_rollback'
);
$assert(
    $contains($nativeMigration, "'yfth-supply-chain-index'")
    && $contains($nativeMigration, '`is_show`=0')
    && $contains($nativeMigration, "'yfth-procurement-product-index'"),
    'migration_hides_legacy_supply_page_and_exposes_procurement_products'
);
$assert(
    $contains($nativeMigration, "'admin-user'")
    && $contains($nativeMigration, "'yfth-user-role-management-index'")
    && $contains($nativeMigration, "'/user/yfth-user-role'"),
    'migration_places_user_role_under_customer_and_member'
);

$assert($contains($service, 'prepareNativeCheckout'), 'service_exposes_native_checkout');
$assert(
    $contains($service, "throw new ApiException('procurement_legacy_runtime_disabled')"),
    'legacy_procurement_writes_are_disabled'
);
$assert(
    $contains($service, "['channel' => 'procurement'")
    || ($contains($service, "'channel' => 'procurement'") && $contains($service, 'StoreCartServices::class')),
    'native_checkout_creates_procurement_cart'
);
$assert(
    $contains($service, "'order_confirm_url' => '/pages/goods/order_confirm/index"),
    'native_checkout_reuses_crmeb_order_confirmation'
);
$assert($contains($service, "'sku_prices'") || $contains($service, '$skuPrices'), 'catalog_supports_sku_procurement_prices');
$assert($contains($service, "->where('is_virtual', 0)"), 'catalog_rejects_virtual_products');
$assert(!$contains($service, "return in_array('store_purchase', \$capabilities, true);"), 'procurement_has_no_extra_store_capability_gate');
$assert(!$contains($service, "Db::name('store_order')->insert"), 'catalog_service_does_not_bypass_native_order_service');

$assert($contains($cartService, '$allowHiddenProduct'), 'cart_accepts_hidden_procurement_only_products');
$assert($contains($cartService, "'yfth_channel'] = 'procurement'"), 'cart_caches_procurement_channel');
$assert($contains($cartService, "'yfth_procurement_unit_price'"), 'cart_caches_procurement_price');
$assert($contains($cartService, "'price_type'] = 'procurement'"), 'cart_restores_procurement_price');

$assert($contains($orderCreateService, 'resolveProcurementContext'), 'order_create_validates_procurement_cart_context');
$assert($contains($orderCreateService, 'procurement_cart_mixed_channel'), 'order_create_rejects_mixed_cart_channels');
$assert($contains($orderCreateService, "Db::name('yfth_native_procurement_order')->insert"), 'order_create_writes_procurement_sidecar');
$assert($contains($orderCreateService, "->mark((int)\$order['id'], 'procurement')"), 'order_create_marks_procurement_commission_source');
$assert($contains($orderCreateService, 'excludesCrmebBrokerage'), 'order_create_excludes_crmeb_legacy_brokerage');

$assert(
    $contains($sourceService, 'public function sourceType(int $orderId)')
    && $contains($sourceService, 'public function isSource(int $orderId, string $sourceType)'),
    'order_source_service_is_authoritative'
);
$assert($contains($payListener, "isSource(\$orderId, 'procurement')"), 'ordinary_mall_pay_reward_skips_procurement');
$assert($contains($refundListener, "isSource(\$orderId, 'procurement')"), 'ordinary_mall_refund_reward_skips_procurement');
$assert($contains($orderService, "'yfth_order_source'") && $contains($orderService, '门店采购订单'), 'native_order_list_exposes_procurement_tag');

$assert($contains($apiRoute, 'supply/native_checkout'), 'api_exposes_native_checkout');
$assert($contains($uniApi, 'prepareYfthNativeProcurementCheckout'), 'uni_api_uses_native_checkout');
$assert($contains($checkoutPage, 'prepareYfthNativeProcurementCheckout'), 'checkout_page_prepares_native_cart');
$assert($contains($checkoutPage, 'data.order_confirm_url'), 'checkout_page_enters_native_confirmation');
$assert($contains($purchasePage, '/pages/goods/order_list/index'), 'procurement_page_reuses_native_order_list');
$assert($contains($purchasePage, '总部采购价'), 'procurement_page_labels_procurement_price');

$assert($contains($adminPage, '采购商品管理'), 'admin_has_procurement_product_management');
$assert($contains($adminPage, 'sku_prices'), 'admin_can_set_procurement_sku_prices');
$assert(!$contains($adminPage, 'yfthPurchaseOrderAudit'), 'admin_has_no_legacy_purchase_audit_action');
$assert($contains($orderTable, "yfth_order_source === 'procurement'"), 'admin_native_order_table_tags_procurement_orders');
$assert($contains($userRouter, 'yfth-user-role'), 'user_role_route_is_under_user_module');
$assert(!$contains($yfthRouter, 'yfth-user-role'), 'user_role_route_is_not_duplicated_under_yfth_module');

if ($failures) {
    echo "YFTH native procurement contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH native procurement contract check passed with ' . count($passes) . " assertions.\n";
