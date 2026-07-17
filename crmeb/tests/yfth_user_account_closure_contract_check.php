<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$read = function (string $path) use ($root, $assert): string {
    $full = $root . '/' . $path;
    $assert(is_file($full), 'file_exists:' . $path);
    return is_file($full) ? (string)file_get_contents($full) : '';
};

$service = $read('app/services/yfth/UserAccountClosureServices.php');
$userController = $read('app/api/controller/v1/user/UserController.php');
$userRoutes = $read('app/api/route/v1.php');
$adminController = $read('app/adminapi/controller/v1/yfth/HqUserRole.php');
$adminRoutes = $read('app/adminapi/route/yfth.php');
$permissionMigration = $read('database/migrations/20260719120000_formalize_yfth_user_account_closure.php');
$adminPage = $read('../template/admin/src/pages/yfth/userRole/index.vue');
$adminApi = $read('../template/admin/src/api/yfth.js');
$userPage = $read('../template/uni-app/pages/users/user_cancellation/index.vue');
$userApi = $read('../template/uni-app/api/user.js');

$assert(!is_file($root . '/app/services/yfth/HqUserDebugPurgeServices.php'), 'legacy_debug_purge_service_removed');
$assert(strpos($service, "private const CONFIRMATION_PHRASE = '确认注销'") !== false, 'exact_confirmation_phrase');
$assert(strpos($service, 'information_schema.COLUMNS') !== false, 'database_references_are_discovered_from_real_schema');
$assert(strpos($service, "COLUMN_NAME='uid' OR COLUMN_NAME='user_id' OR COLUMN_NAME LIKE") !== false, 'all_uid_shaped_columns_are_scanned');
$assert(strpos($service, "return 'block';") !== false, 'unknown_reference_fails_closed');
$assert(strpos($service, "if (\$result['user.uid'] !== 1 || \$this->discoverReferences(\$uid))") !== false, 'post_delete_residual_scan_rolls_back');
$assert(strpos($service, "'yfth_customer_relation' => ['uid']") !== false, 'store_customer_relation_deleted');
$assert(strpos($service, "'yfth_customer_follow_record' => ['uid']") !== false, 'store_customer_follow_records_deleted');
$assert(strpos($service, "'yfth_permanent_membership' => ['uid']") !== false, 'membership_authority_deleted_for_eligible_account');
$assert(strpos($service, "'yfth_user_store_role' => ['uid']") !== false, 'store_roles_deleted_for_eligible_account');
$assert(strpos($service, "'store_order' =>") === false, 'orders_are_never_hard_delete_allowlisted');
$assert(strpos($service, "'yfth_reward_settlement_ledger' =>") === false, 'settlement_ledgers_are_never_hard_delete_allowlisted');
$assert(strpos($service, "'blocking_references' => \$preflight['blocking_references']") === false, 'self_preflight_hides_internal_table_details');
$assert(strpos($service, 'assertHeadquarterScope') !== false, 'headquarters_operation_has_explicit_scope_guard');
$assert(strpos($service, 'Db::transaction') !== false && strpos($service, 'lock(true)') !== false, 'closure_is_transactional_and_rechecks_locked_user');
$assert(strpos($service, "'yfth_user_account_closure'") !== false, 'formal_closure_is_audited');
$assert(strpos($service, "'closed_account'") !== false && strpos($service, "['uid' => \$uid]") === false, 'closure_audit_does_not_retain_deleted_uid');

$assert(strpos($userRoutes, "Route::get('user_cancel/preflight'") !== false, 'self_preflight_route_exists');
$assert(strpos($userRoutes, "Route::post('user_cancel'") !== false, 'self_closure_requires_post');
$assert(strpos($userRoutes, "Route::get('user_cancel',") === false, 'legacy_get_side_effect_route_removed');
$assert(strpos($userController . $userApi, 'preflightForUser') !== false && strpos($userApi, 'user_cancel/preflight') !== false, 'self_closure_api_is_wired');
$assert(strpos($userPage, '账号正式销户') !== false && strpos($userPage, '确认注销') !== false, 'self_closure_ui_is_formalized');
$assert(strpos($userPage, 'blocking_references') === false, 'self_ui_does_not_render_internal_table_names');

foreach (['closurePreflight', 'closeForHeadquarters', 'yfth/user_role/user/<uid>/closure'] as $needle) {
    $assert(strpos($adminController . $adminRoutes, $needle) !== false, 'headquarters_closure_wired:' . $needle);
}
foreach (['yfth-user-account-closure-preflight', 'yfth-user-account-closure-execute'] as $permission) {
    $assert(strpos($permissionMigration, $permission) !== false, 'formal_permission_exists:' . $permission);
}
$assert(strpos($adminApi, 'yfthUserAccountClosurePreflight') !== false && strpos($adminApi, 'yfthUserAccountClosure') !== false, 'admin_api_uses_formal_closure_routes');
$assert(strpos($adminPage, '账号销户') !== false && strpos($adminPage, '调试删除') === false, 'admin_ui_replaces_debug_delete');
$assert(strpos($adminPage, 'el-icon-warning') !== false && strpos($adminPage, 'closureForm.reason') !== false, 'admin_ui_shows_warning_and_requires_reason');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH formal user account closure contract verified.\n";
