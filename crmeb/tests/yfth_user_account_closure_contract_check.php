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
$yfthConfig = $read('config/yfth.php');
$userController = $read('app/api/controller/v1/user/UserController.php');
$userRoutes = $read('app/api/route/v1.php');
$adminController = $read('app/adminapi/controller/v1/yfth/HqUserRole.php');
$adminRoutes = $read('app/adminapi/route/yfth.php');
$permissionMigration = $read('database/migrations/20260719120000_formalize_yfth_user_account_closure.php');
$v2Migration = $read('database/migrations/20260719150000_create_yfth_account_closure_v2.php');
$jwt = $read('crmeb/utils/JwtAuth.php');
$adminPage = $read('../template/admin/src/pages/yfth/userRole/index.vue');
$userPage = $read('../template/uni-app/pages/users/user_cancellation/index.vue');

$assert(strpos($service, "private const CONFIRMATION_PHRASE = '确认注销'") !== false, 'exact_confirmation_phrase');
$assert(strpos($yfthConfig, "user_account_closure_enabled', false") !== false, 'closure_is_fail_closed_without_environment_flag');
$assert(strpos($service, 'PERSONAL_DELETE_REFERENCES') !== false && strpos($service, 'RETAINED_HISTORY') !== false, 'explicit_business_domain_matrix');
$assert(strpos($service, 'information_schema.COLUMNS') === false && strpos($service, 'discoverReferences') === false, 'schema_wide_uid_scanner_removed');
$assert(strpos($service, "'store_order' => ['domain' => 'mall_order'") !== false, 'orders_are_anonymized_not_deleted');
$assert(strpos($service, "'yfth_direct_referral_reward_settlement_ledger' => ['domain' => 'reward_settlement'") !== false, 'settlement_history_is_anonymized');
$assert(strpos($service, "'yfth_customer_relation' => ['uid']") !== false, 'store_customer_projection_deleted');
$assert(strpos($service, "'yfth_permanent_membership' => ['uid']") !== false, 'membership_projection_deleted');
$assert(strpos($service, "'yfth_user_store_role' => ['uid']") !== false, 'revocable_store_roles_deleted');
$assert(strpos($service, 'businessBlockers') !== false && strpos($service, 'unfinishedOrderCount') !== false, 'explicit_business_gates_exist');
$assert(strpos($service, "whereIn('status', [0, 1, 4])") !== false, 'unfinished_orders_follow_order_lifecycle_status');
$assert(strpos($service, "\$sub->where('paid', 0)") === false, 'completed_unpaid_orders_do_not_require_user_deletion');
$assert(strpos($service, 'verifySecurity') !== false && strpos($service, "empty(\$data['agreement'])") !== false, 'self_closure_requires_security_and_agreement');
$assert(strpos($service, 'Db::transaction') !== false && strpos($service, 'lock(true)') !== false, 'closure_is_locked_transaction');
$assert(strpos($service, '$this->audit->record(') !== false && strpos($service, 'recordSafely') === false, 'audit_is_strict_inside_transaction');
$assert(strpos($service, 'former_uid_digest') !== false && strpos($service, 'newAnonymousSubjectUid') !== false, 'anonymous_subject_is_random_and_one_way_linked');
$assert(strpos($service, 'revokeSessions') !== false && strpos($jwt, 'yfth:user_tokens:') !== false, 'all_indexed_sessions_are_actively_revoked');

foreach (['yfth_account_closure_subject', 'yfth_account_closure_history_link', 'former_uid_digest', 'subject_uid'] as $needle) {
    $assert(strpos($v2Migration, $needle) !== false, 'v2_migration_contains:' . $needle);
}
$assert(strpos($v2Migration, 'phone') === false && strpos($v2Migration, 'openid') === false && strpos($v2Migration, 'unionid') === false, 'closure_tables_store_no_direct_identity');

$assert(strpos($userRoutes, "Route::get('user_cancel/preflight'") !== false && strpos($userRoutes, "Route::post('user_cancel'") !== false, 'self_routes_are_read_then_post');
foreach (['agreement', 'verification_type', 'password', 'sms_phone', 'sms_code'] as $field) {
    $assert(strpos($userController, "['{$field}'") !== false, 'self_controller_accepts:' . $field);
}
$assert(strpos($userPage, '账号注销协议') !== false && strpos($userPage, '安全验证与最后确认') !== false, 'self_ui_explains_and_verifies');
$assert(strpos($userPage, '重新注册是全新账号') !== false && strpos($userPage, '不继承') !== false, 'self_ui_explains_no_inheritance');
$assert(strpos($userPage, 'blocking_references') === false && strpos($userPage, 'table_name') === false, 'self_ui_hides_internal_storage');

foreach (['closurePreflight', 'closeForHeadquarters', 'yfth/user_role/user/<uid>/closure'] as $needle) {
    $assert(strpos($adminController . $adminRoutes, $needle) !== false, 'headquarters_closure_wired:' . $needle);
}
foreach (['yfth-user-account-closure-preflight', 'yfth-user-account-closure-execute'] as $permission) {
    $assert(strpos($permissionMigration, $permission) !== false, 'dedicated_permission_exists:' . $permission);
}
$assert(strpos($adminPage, '匿名保留') !== false && strpos($adminPage, 'closureForm.reason') !== false, 'headquarters_ui_shows_policy_and_reason');
$assert(strpos($adminPage, 'blocking_references') === false && strpos($adminPage, 'closurePreflight.blockers') !== false, 'headquarters_ui_uses_business_blockers');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH account closure V2 contract verified.\n";
