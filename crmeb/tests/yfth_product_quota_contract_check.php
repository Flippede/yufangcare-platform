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

$migration = $read('database/migrations/20260711100000_create_yfth_product_quota_tables.php');
$service = $read('app/services/yfth/ProductQuotaServices.php');
$apiController = $read('app/api/controller/v1/yfth/ProductQuotaController.php');
$adminController = $read('app/adminapi/controller/v1/yfth/ProductQuota.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$adminApi = $read('../template/admin/src/api/yfth.js');
$adminPage = $read('../template/admin/src/pages/yfth/productQuota/index.vue');
$adminRouter = $read('../template/admin/src/router/modules/yfth.js');
$uniApi = $read('../template/uni-app/api/yfth.js');
$pagesJson = $read('../template/uni-app/pages.json');
$quotaIndex = $read('../template/uni-app/pages/yfth/product_quota/index.vue');
$quotaLedger = $read('../template/uni-app/pages/yfth/product_quota/ledger.vue');
$quotaDetail = $read('../template/uni-app/pages/yfth/product_quota/detail.vue');
$workbench = $read('../template/uni-app/pages/yfth/workbench/index.vue');

foreach ([
    'yfth_product_quota_account',
    'yfth_product_quota_ledger',
    'yfth_product_quota_grant_order',
    'yfth_product_quota_adjustment',
    'yfth_product_quota_source_snapshot',
] as $table) {
    $assert($contains($migration, $table), 'migration_contains_' . $table);
}

foreach ([
    'uniq_yfth_product_quota_account_no',
    'uniq_yfth_product_quota_account_active',
    'uniq_yfth_product_quota_ledger_no',
    'uniq_yfth_product_quota_ledger_idempotency',
    'uniq_yfth_product_quota_grant_no',
    'uniq_yfth_product_quota_grant_idempotency',
    'uniq_yfth_product_quota_adjustment_dedupe',
] as $index) {
    $assert($contains($migration, $index), 'migration_contains_index_' . $index);
}

foreach ([
    'total_granted_cent',
    'total_adjusted_cent',
    'total_reversed_cent',
    'reserved_cent',
    'consumed_cent',
    'available_cent',
    'frozen_cent',
    'amount_cent',
    'balance_before_cent',
    'balance_after_cent',
] as $field) {
    $assert($contains($migration, $field), 'migration_contains_cent_field_' . $field);
}

$assert($contains($migration, 'biginteger') || $contains($migration, 'integer'), 'migration_uses_integer_amount_columns');
$assert(!$contains($service, '(float)'), 'quota_service_does_not_use_float_amount_calculation');
$assert($contains($migration, "'auth_type' => 2"), 'migration_seeds_api_permissions');
$assert($contains($migration, 'yfth-product-quota-index'), 'migration_seeds_product_quota_page_menu');
$assert($contains($migration, 'DELETE FROM `') && $contains($migration, 'system_menus'), 'migration_down_removes_seeded_menus');

$assert($contains($service, "private const DOMAIN = 'yfth_product_quota'"), 'service_uses_product_quota_domain');
$assert($contains($service, 'AdminStoreContextServices::class') && $contains($service, 'assertHeadquarterScope'), 'service_requires_headquarter_scope_for_writes');
$assert($contains($service, 'CurrentBusinessContextServices::class'), 'service_uses_current_business_context_for_user_reads');
$assert($contains($service, "['franchisee', 'store_manager', 'county_partner', 'prefecture_partner', 'province_partner', 'regional_director', 'platform_director']"), 'service_user_read_roles_include_bound_partner_ranks');
$assert($contains($service, 'product_quota_store_required'), 'service_requires_store_context');
$assert($contains($service, 'assertUserReadonlyPayload'), 'service_rejects_user_forbidden_query_fields');
$assert($contains($apiController, 'source_id') && $contains($service, 'product_quota_user_field_forbidden'), 'user_api_rejects_sensitive_fields');
$assert($contains($service, 'lockAccount') && $contains($service, "->lock(true)->find()"), 'service_locks_account_for_amount_mutations');
$assert($contains($service, 'lockGrant') && $contains($service, 'yfth_product_quota_grant_order') && $contains($service, '->lock(true)->find()'), 'service_locks_grant_for_transitions');
$assert($contains($service, 'assertAccountAmountWritable'), 'service_blocks_amount_changes_for_frozen_or_closed_accounts');
$assert($contains($service, "available_cent'] <"), 'service_blocks_negative_available_balance');
$assert($contains($service, 'headquarters_manual_grant'), 'service_supports_manual_headquarter_grant');
$assert($contains($service, 'franchise_opening_initial_quota'), 'service_supports_manual_opening_initial_source_guard');
$assert($contains($service, 'product_quota_source_reserved_not_open') && $contains($service, 'referral_reward_converted') && $contains($service, 'purchase_after_sale_return'), 'service_rejects_reserved_future_sources');
$assert($contains($service, "\$account['status'] !== 'active'") || $contains($service, "\$account['status'] === 'closed'"), 'service_checks_account_status');
$assert($contains($service, 'AuditEventServices::class'), 'service_reuses_yfth_audit');
$assert($contains($service, 'writeSnapshot'), 'service_writes_source_snapshot');
$assert($contains($service, 'sanitizeState($before)') && $contains($service, 'sanitizeState($after)'), 'service_sanitizes_audit_payload');
$assert($contains($service, 'idempotency_key'), 'service_uses_idempotency_keys');
$assert($contains($service, 'normalizeOperationKey'), 'service_normalizes_client_operation_keys_server_side');
$assert($contains($service, 'product_quota_idempotency_key_required'), 'grant_create_requires_non_empty_idempotency_key');
$assert($contains($service, 'product_quota_dedupe_key_required'), 'adjustment_requires_non_empty_dedupe_key');
$assert($contains($service, 'product_quota_idempotency_payload_mismatch'), 'service_rejects_reused_key_payload_mismatch');
$assert($contains($service, 'findGrantByIdempotencyKey') && $contains($service, 'assertGrantIdempotentPayload'), 'grant_create_returns_existing_by_idempotency_key');
$assert($contains($service, 'findAdjustmentByDedupeKey') && $contains($service, 'assertAdjustmentDedupePayload'), 'manual_adjustment_returns_existing_by_dedupe_key');
$assert($contains($service, 'formatExistingAdjustmentResult'), 'duplicate_adjustment_returns_existing_balance_result');
$assert($contains($service, 'product_quota_grant_create') && $contains($service, 'product_quota_adjustment_post'), 'idempotency_keys_are_scoped_by_write_scene');
$assert($contains($service, 'grant_confirm') && $contains($service, 'grant_reverse') && $contains($service, 'manual_increase') && $contains($service, 'manual_decrease'), 'service_has_expected_actions');

foreach ([
    'store_order',
    'store_product_attr_value',
    "store_product')->update",
    'decStockIncSales',
    'incStockDecSales',
    'now_money',
    'brokerage',
    'user_spread',
    'user_bill',
    'withdraw',
    'settlement',
] as $forbidden) {
    $assert(!$contains($service, $forbidden), 'service_does_not_touch_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $forbidden));
}

$assert($contains($adminRoute, "Route::group('product_quota'"), 'admin_route_has_product_quota_group');
$assert($contains($adminRoute, 'AdminAuthTokenMiddleware::class') && $contains($adminRoute, 'AdminCheckRoleMiddleware::class'), 'admin_route_uses_admin_middlewares');
$assert($contains($adminController, 'assertApiAuthForAdmin'), 'admin_controller_explicit_permission_assertion');
$assert($contains($adminController, "yfth/product_quota/grant/<id>/confirm"), 'admin_controller_asserts_grant_confirm_permission');
$assert($contains($adminController, "yfth/product_quota/account/<id>/freeze"), 'admin_controller_asserts_account_freeze_permission');
$assert($contains($adminController, 'client_operation_key'), 'admin_controller_accepts_client_operation_key_for_write_idempotency');

$assert(!$contains($migration, "'idempotency_key', 'string', ['limit' => 160, 'null' => true"), 'grant_idempotency_key_is_not_nullable_optional_column');
$assert(!$contains($migration, "'dedupe_key', 'string', ['limit' => 160, 'null' => true"), 'adjustment_dedupe_key_is_not_nullable_optional_column');
$assert($contains($migration, "mandatory request idempotency key"), 'grant_idempotency_column_is_mandatory');
$assert($contains($migration, "mandatory dedupe key"), 'adjustment_dedupe_column_is_mandatory');

$assert($contains($apiRoute, 'yfth/product_quota/summary') && $contains($apiRoute, 'AuthTokenMiddleware::class'), 'user_routes_use_user_token');
$assert($contains($apiController, 'get()') && !$contains($apiController, 'post('), 'user_controller_is_read_only');

$assert($contains($adminApi, 'yfthProductQuotaAccountList') && $contains($adminApi, 'yfthProductQuotaGrantConfirm'), 'admin_api_wrappers_exist');
$assert($contains($adminRouter, 'product-quota') && $contains($adminRouter, 'yfth-product-quota-index'), 'admin_router_registers_product_quota_page');
$assert($contains($adminPage, 'yfthProductQuotaAccountList') && $contains($adminPage, 'yfthProductQuotaGrantCreate'), 'admin_page_uses_real_api');
$assert($contains($adminPage, '不代表系统付款') && $contains($adminPage, '不自动抵扣采购单'), 'admin_page_displays_required_boundary_text');
$assert($contains($adminPage, 'makeOperationKey') && $contains($adminPage, 'idempotency_key'), 'admin_page_sends_grant_idempotency_key');
$assert($contains($adminPage, 'makeOperationKey') && $contains($adminPage, 'dedupe_key'), 'admin_page_sends_adjustment_dedupe_key');
$assert($contains($adminPage, 'grantSubmitting') && $contains($adminPage, 'adjustSubmitting'), 'admin_page_guards_duplicate_submit');

$quotaFrontend = $adminPage . $quotaIndex . $quotaLedger . $quotaDetail;
foreach (['余额到账', '可用现金', '提现', '打款'] as $forbiddenText) {
    $assert(!$contains($quotaFrontend, $forbiddenText), 'quota_frontend_avoids_' . $forbiddenText);
}

$assert($contains($uniApi, 'getYfthProductQuotaSummary') && $contains($uniApi, 'getYfthProductQuotaLedger'), 'uni_api_wrappers_exist');
foreach ([
    'product_quota/index',
    'product_quota/ledger',
    'product_quota/detail',
] as $page) {
    $assert($contains($pagesJson, $page), 'pages_json_registers_' . str_replace('/', '_', $page));
}
$assert($contains($quotaIndex, 'getYfthProductQuotaSummary') && $contains($quotaIndex, 'getYfthProductQuotaAccounts'), 'quota_index_uses_real_api');
$assert($contains($quotaLedger, 'getYfthProductQuotaLedger'), 'quota_ledger_uses_real_api');
$assert($contains($quotaDetail, 'getYfthProductQuotaAccountDetail'), 'quota_detail_uses_real_api');
$assert($contains($workbench, 'goProductQuota') && $contains($workbench, 'canReadProductQuota'), 'workbench_links_franchisee_manager_quota_page');

$userSensitiveFields = [
    'operator_uid',
    'operator_type',
    'idempotency_key',
    'source_id',
    'reason',
    'snapshot_json',
    'balance_before_cent',
];
foreach ($userSensitiveFields as $field) {
    $assert(!$contains($quotaIndex, $field) && !$contains($quotaLedger, $field) && !$contains($quotaDetail, $field), 'uni_pages_do_not_render_' . $field);
}

if ($failures) {
    echo "YFTH product quota contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH product quota contract check passed with ' . count($passes) . " assertions.\n";
