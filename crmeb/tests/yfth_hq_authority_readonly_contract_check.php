<?php

$root = dirname(__DIR__);
$repo = dirname($root);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};
$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

try {
    $files = [
        'app/services/yfth/HqAuthorityDtoServices.php',
        'app/services/yfth/HqAuthorityReadServices.php',
        'app/services/yfth/HqAuthorityUserReadServices.php',
        'app/services/yfth/HqAuthorityStoreReadServices.php',
        'app/services/yfth/HqAuthorityAdminReadServices.php',
        'app/services/yfth/HqAuthorityAuditReadServices.php',
        'app/api/controller/v1/yfth/HqAuthorityReadController.php',
        'app/api/controller/v1/yfth/HqAuthorityStoreReadController.php',
        'app/adminapi/controller/v1/yfth/HqAuthorityRead.php',
        'database/migrations/20260714100000_add_yfth_hq_authority_readonly_permissions.php',
    ];
    foreach ($files as $file) {
        $assert(is_file($root . '/' . $file), 'file_exists:' . $file);
    }

    $apiRoute = $read('app/api/route/yfth_service.php');
    $adminRoute = $read('app/adminapi/route/yfth.php');
    foreach ([
        'yfth/hq_authority/me',
        'yfth/store_workbench/customer_attribution',
        'yfth/store_workbench/customer_attribution/:id',
    ] as $route) {
        $assert(strpos($apiRoute, "Route::get('{$route}'") !== false, 'user_route_is_get:' . $route);
    }
    foreach ([
        'attribution', 'attribution/:id', 'attribution/:id/events',
        'referral', 'referral/:id', 'referral/:id/events',
    ] as $route) {
        $assert(strpos($adminRoute, "Route::get('{$route}'") !== false, 'admin_route_is_get:' . $route);
    }

    $userController = $read('app/api/controller/v1/yfth/HqAuthorityReadController.php');
    $storeController = $read('app/api/controller/v1/yfth/HqAuthorityStoreReadController.php');
    $adminController = $read('app/adminapi/controller/v1/yfth/HqAuthorityRead.php');
    $assert(strpos($userController, 'request->uid()') !== false, 'user_uid_comes_from_authenticated_request');
    foreach (['getMore', 'param(', 'uid', 'user_id', 'target_uid'] as $needle) {
        if ($needle === 'uid') {
            continue;
        }
        $assert(strpos($userController, $needle) === false, 'user_controller_does_not_accept:' . $needle);
    }
    $assert(strpos($storeController, "['status', '']") !== false && strpos($storeController, "['keyword', '']") !== false, 'store_controller_filter_whitelist');
    $assert(strpos($storeController, "['store_id'") === false && strpos($storeController, "['uid'") === false, 'store_controller_does_not_accept_scope_or_uid_filter');
    foreach (['attributionList', 'attributionDetail', 'referralList', 'referralDetail', 'attributionEvents', 'referralEvents'] as $method) {
        $assert(strpos($adminController, 'function ' . $method . '(') !== false, 'admin_get_method:' . $method);
    }
    foreach (['postMore', 'public function create', 'public function bind', 'public function pause', 'public function close'] as $forbidden) {
        $assert(strpos($userController . $storeController . $adminController, $forbidden) === false, 'controllers_have_no_write_entry:' . $forbidden);
    }

    $readService = $read('app/services/yfth/HqAuthorityReadServices.php');
    $storeService = $read('app/services/yfth/HqAuthorityStoreReadServices.php');
    $adminService = $read('app/services/yfth/HqAuthorityAdminReadServices.php');
    $auditService = $read('app/services/yfth/HqAuthorityAuditReadServices.php');
    $dto = $read('app/services/yfth/HqAuthorityDtoServices.php');
    foreach (['YfthHqCustomerAttributionCurrentDao', 'YfthHqCustomerAttributionEventDao', 'YfthHqActiveReferralCurrentDao', 'YfthHqActiveReferralEventDao'] as $dao) {
        $assert(strpos($readService, $dao) !== false, 'readonly_core_uses_dao:' . $dao);
    }
    $assert(strpos($storeService, 'CurrentBusinessContextServices') !== false, 'store_scope_uses_current_business_context');
    $assert(strpos($storeService, "['franchisee', 'store_manager']") !== false, 'store_staff_is_not_allowed_role');
    $assert(strpos($adminService . $auditService, 'assertHeadquarterScope') !== false, 'admin_services_assert_headquarters_scope');
    $assert(strpos($adminController, 'assertApiAuthForAdmin') !== false, 'admin_controller_asserts_api_permission');
    $assert(strpos($dto, 'phone_masked') !== false && strpos($dto, 'maskPhone') !== false, 'dto_reuses_phone_masking');
    $assert(strpos($dto, "?? '系统来源'") !== false, 'unknown_source_uses_safe_label');
    $assert(strpos($dto, 'has_active_referral') !== false, 'user_and_store_only_expose_referral_boolean');

    $production = $readService . $storeService . $adminService . $auditService . $dto . $userController . $storeController . $adminController;
    foreach (['source_unique_key', 'idempotency_key', 'HqCustomerAttributionServices', 'HqActiveReferralServices', 'ensurePlaceholder'] as $forbidden) {
        $assert(strpos($production, $forbidden) === false, 'readonly_production_excludes:' . $forbidden);
    }

    $migration = $read('database/migrations/20260714100000_add_yfth_hq_authority_readonly_permissions.php');
    foreach ([
        'yfth-hq-authority-readonly-index',
        'yfth-hq-authority-attribution-list', 'yfth-hq-authority-attribution-detail',
        'yfth-hq-authority-referral-list', 'yfth-hq-authority-referral-detail',
        'yfth-hq-authority-attribution-audit', 'yfth-hq-authority-referral-audit',
    ] as $auth) {
        $assert(strpos($migration, $auth) !== false, 'permission_exists:' . $auth);
    }
    $assert(substr_count($migration, "'methods' => 'GET'") >= 2, 'permission_methods_are_get');
    foreach (['yfth_hq_customer_attribution_current', 'yfth_hq_active_referral_current', 'createTable', 'addColumn'] as $forbidden) {
        $assert(strpos($migration, $forbidden) === false, 'permission_migration_does_not_change_business_schema:' . $forbidden);
    }

    $adminPage = (string)file_get_contents($repo . '/template/admin/src/pages/yfth/hqAuthority/index.vue');
    $uniUser = (string)file_get_contents($repo . '/template/uni-app/pages/yfth/authority/index.vue');
    $uniStore = (string)file_get_contents($repo . '/template/uni-app/pages/yfth/workbench/customer_attribution/index.vue');
    foreach (['yfthHqAuthorityAttributionList', 'yfthHqAuthorityReferralList', 'canAuditAttribution', 'canAuditReferral'] as $needle) {
        $assert(strpos($adminPage, $needle) !== false, 'admin_page_contains:' . $needle);
    }
    foreach (['绑定', '换店', '暂停归属', '恢复归属', '接管客户'] as $forbidden) {
        $assert(strpos($adminPage, '>' . $forbidden . '<') === false, 'admin_page_has_no_write_button:' . $forbidden);
    }
    $assert(strpos($uniUser, 'getYfthMyHqAuthority') !== false, 'uni_user_page_uses_real_api');
    $assert(strpos($uniStore, "['franchisee', 'store_manager']") !== false, 'uni_store_page_role_gate');
    $assert(strpos($uniStore, 'getYfthStoreCustomerAttributions') !== false, 'uni_store_page_uses_real_api');

    $diffExit = 0;
    exec('git -C ' . escapeshellarg($repo) . ' diff --quiet main -- crmeb/database/migrations/20260713100000_create_yfth_hq_authority_foundation_tables.php', $unused, $diffExit);
    $assert($diffExit === 0, 'stage1a_authority_migration_unchanged');
} catch (Throwable $e) {
    $failures[] = 'contract_exception:' . $e->getMessage();
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
echo '[OK] YFTH headquarters authority Stage 1B read-only contract verified.' . PHP_EOL;
