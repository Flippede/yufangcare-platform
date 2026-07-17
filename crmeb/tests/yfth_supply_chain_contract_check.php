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

$migration = $read('database/migrations/20260708170000_create_yfth_supply_chain_inventory_tables.php');
$service = $read('app/services/yfth/SupplyChainServices.php');
$apiController = $read('app/api/controller/v1/yfth/SupplyChainController.php');
$adminController = $read('app/adminapi/controller/v1/yfth/SupplyChain.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$adminApi = $read('../template/admin/src/api/yfth.js');
$uniApi = $read('../template/uni-app/api/yfth.js');
$adminPage = $read('../template/admin/src/pages/yfth/supplyChain/index.vue');
$purchasePage = $read('../template/uni-app/pages/yfth/workbench/purchase/index.vue');
$inventoryPage = $read('../template/uni-app/pages/yfth/workbench/purchase/inventory.vue');

foreach ([
    'yfth_supply_catalog',
    'yfth_purchase_order',
    'yfth_purchase_order_item',
    'yfth_stock_location',
    'yfth_inventory_balance',
    'yfth_inventory_ledger',
    'yfth_purchase_shipment',
    'yfth_purchase_receipt',
    'yfth_inventory_alert_rule',
] as $table) {
    $assert($contains($migration, $table), 'migration_contains_' . $table);
}

foreach ([
    'uniq_yfth_supply_catalog_product',
    'uniq_yfth_purchase_order_no',
    'uniq_yfth_purchase_item_order_sku',
    'uniq_yfth_inventory_balance_location_sku',
    'idx_yfth_inventory_ledger_business',
    'uniq_yfth_inventory_ledger_business_sku',
    'uniq_yfth_purchase_shipment_order',
    'uniq_yfth_purchase_receipt_order',
    'uniq_yfth_purchase_receipt_shipment',
    'uniq_yfth_inventory_alert_store_sku',
] as $index) {
    $assert($contains($migration, $index), 'migration_contains_index_' . $index);
}

$assert($contains($migration, "'auth_type' => 2"), 'migration_seeds_api_permissions');
$assert($contains($migration, 'yfth-supply-chain-index'), 'migration_seeds_supply_page_menu');
$assert($contains($migration, 'DELETE FROM `') && $contains($migration, 'system_menus'), 'migration_down_removes_seeded_menus');
$assert($contains($migration, "->table('yfth_inventory_alert_rule')->drop()") || $contains($migration, '$this->table($table)->drop()'), 'migration_down_drops_tables');

$assert($contains($service, 'CurrentBusinessContextServices::class'), 'service_uses_current_business_context');
$assert($contains($service, 'AdminStoreContextServices::class'), 'service_uses_admin_store_context');
$assert($contains($service, "STORE_WRITE_ROLES = ['franchisee', 'store_manager']"), 'service_staff_not_in_write_roles');
$assert($contains($service, "STORE_READ_ROLES = ['franchisee', 'store_manager', 'store_staff']"), 'service_staff_read_allowed');
$assert($contains($service, 'supply_purchase_store_field_forbidden'), 'service_rejects_client_store_fields_on_create');
$assert($contains($service, 'supply_receipt_store_field_forbidden'), 'service_rejects_client_store_fields_on_receipt');
$assert($contains($service, '$query->where(\'store_id\', $storeId)') || $contains($service, "['store_id' => (int)\$scope['store_id']"), 'service_filters_store_queries_by_resolved_store');
$assert($contains($service, 'lockPurchaseOrder') && $contains($service, '->lock(true)->find()'), 'service_locks_purchase_order_for_state_transitions');
$assert($contains($service, "return in_array('store_purchase', \$capabilities, true);"), 'service_requires_explicit_store_purchase_capability');
$assert($contains($service, 'supply_receive:') && $contains($service, 'supply_purchase_create:'), 'service_generates_server_side_idempotency_keys');
$assert($contains($service, 'idempotency_key_required') && !$contains($service, 'if ($key === \'\') {' . "\n" . '            return $callback();'), 'service_does_not_bypass_empty_idempotency_key');
$assert($contains($service, 'decimalToCents') && $contains($service, 'centsToDecimal') && !$contains($service, '(float)'), 'service_avoids_float_money_calculation');
$assert($contains($service, 'FIND_IN_SET(:store_type, allow_store_types)') && !$contains($service, "whereOr('allow_store_types', 'like'"), 'service_uses_exact_store_type_matching');
$assert($contains($service, 'normalizeCatalogPayload(array $data, int $adminId, array $before = [])'), 'service_catalog_update_accepts_existing_create_fields');
$assert($contains($service, "'created_uid' => \$before ?") && $contains($service, "'create_time' => \$before ?"), 'service_catalog_update_preserves_created_fields');
$assert($contains($service, 'store_product_attr_value'), 'service_reuses_crmeb_sku_table');
$assert($contains($service, 'store_product'), 'service_reuses_crmeb_product_table');
$assert(!$contains($service, 'decStockIncSales('), 'service_does_not_decrement_crmeb_sales_stock');
$assert(!$contains($service, 'incStockDecSales('), 'service_does_not_increment_crmeb_sales_stock');
$assert(!$contains($service, "Db::name('store_order')->insert"), 'service_does_not_create_crmeb_order');
$assert(!$contains($service, "Db::name('store_product')->update"), 'service_does_not_update_crmeb_product_stock');
$assert($contains($service, 'YfthInventoryLedgerDao::class') && $contains($service, 'purchase_inbound'), 'service_writes_inventory_ledger');
$assert($contains($service, 'IdempotencyRecordServices::class'), 'service_reuses_yfth_idempotency');
$assert($contains($service, 'AuditEventServices::class'), 'service_reuses_yfth_audit');

$assert($contains($apiRoute, 'AuthTokenMiddleware::class'), 'api_route_uses_user_token_middleware');
$assert($contains($apiRoute, "yfth/supply/purchase_order/:id/receive"), 'api_route_has_receipt_endpoint');
$assert($contains($apiRoute, "yfth/supply/inventory"), 'api_route_has_inventory_endpoint');
$assert($contains($adminRoute, "Route::group('supply_chain'"), 'admin_route_has_supply_group');
$assert($contains($adminRoute, 'AdminAuthTokenMiddleware::class'), 'admin_route_uses_admin_token_middleware');
$assert($contains($adminController, 'assertApiAuthForAdmin'), 'admin_controller_asserts_api_auth');
$assert($contains($adminController, "yfth/supply_chain/purchase_order/<id>/ship"), 'admin_controller_asserts_ship_permission');

$assert($contains($apiController, '$request->post()'), 'api_controller_checks_raw_post_fields');
$assert($contains($apiController, 'Idempotency-Key'), 'api_controller_accepts_idempotency_header');
$assert($contains($uniApi, 'createYfthPurchaseOrder'), 'uni_api_has_purchase_create');
$assert($contains($uniApi, 'receiveYfthPurchaseOrder'), 'uni_api_has_receive');
$assert($contains($adminApi, 'yfthSupplyCatalogSave'), 'admin_api_has_catalog_save');
$assert($contains($adminApi, 'yfthPurchaseOrderShip'), 'admin_api_has_ship');
$assert($contains($adminPage, 'yfthSupplyCatalogList') && $contains($adminPage, 'yfthPurchaseOrderAudit'), 'admin_page_uses_real_api');
$assert($contains($purchasePage, 'createYfthPurchaseOrder') && $contains($purchasePage, 'receiveYfthPurchaseOrder'), 'purchase_page_uses_real_api');
$assert($contains($purchasePage, "context.role_code !== 'store_manager'") && $contains($purchasePage, '仅店长可进入采购中心'), 'purchase_page_rejects_non_manager_roles');
$assert($contains($inventoryPage, 'getYfthInventory') && $contains($inventoryPage, 'getYfthInventoryLedger'), 'inventory_page_uses_real_api');

if ($failures) {
    echo "YFTH supply chain contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH supply chain contract check passed with ' . count($passes) . " assertions.\n";
