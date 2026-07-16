<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};
$read = function (string $path) use ($root, $assert): string {
    $full = $root . '/' . $path;
    $assert(is_file($full), 'file_exists:' . $path);
    return is_file($full) ? (string)file_get_contents($full) : '';
};

$service = $read('app/services/yfth/HqUserRoleManagementServices.php');
$controller = $read('app/adminapi/controller/v1/yfth/HqUserRole.php');
$route = $read('app/adminapi/route/yfth.php');
$migration = $read('database/migrations/20260718100000_add_yfth_user_role_management_permissions.php');
$fixtureMigration = $read('database/migrations/20260718110000_create_yfth_acceptance_fixture.php');
$passwordMigration = $read('database/migrations/20260718120000_add_yfth_acceptance_password_reset_permission.php');
$fixtureService = $read('app/services/yfth/HqAcceptanceFixtureServices.php');
$membership = $read('app/services/yfth/PackageMembershipReferralServices.php');
$adminPage = (string)file_get_contents(dirname($root) . '/template/admin/src/pages/yfth/userRole/index.vue');
$userPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/user/index.vue');
$codePage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/referral/code.vue');
$acceptPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/referral/accept.vue');
$scanPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/referral/scan.vue');
$roleSwitchPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/workbench/role_switch.vue');
$pages = (string)file_get_contents(dirname($root) . '/template/uni-app/pages.json');
$nativeUserPage = (string)file_get_contents(dirname($root) . '/template/admin/src/pages/user/list/index.vue');

foreach (['assertHeadquarters', 'assertHeadquarterScope', 'UserStoreRoleServices', 'YfthUserStoreRoleDao', "'grant'", "'revoke'", 'user_store_role_reason_required', 'AuditEventServices'] as $needle) {
    $assert(strpos($service, $needle) !== false, 'role_service_contains:' . $needle);
}
$assert(strpos($service, 'YfthUserIdentityDao') === false, 'role_write_does_not_replace_global_identity');
$assert(strpos($service, 'YfthPermanentMembershipDao') === false, 'role_write_does_not_replace_membership');
foreach (['franchisee', 'store_manager', 'store_staff'] as $roleCode) {
    $assert(strpos($service, "'{$roleCode}'") !== false, 'supported_store_role:' . $roleCode);
}
foreach (['assertApiAuthForAdmin', 'yfth/user_role/user', 'yfth/user_role/user/<uid>/grant', 'yfth/user_role/role/<id>/revoke'] as $needle) {
    $assert(strpos($controller . $route, $needle) !== false, 'admin_boundary_contains:' . $needle);
}
foreach (['yfth-user-role-management-index', 'yfth-user-role-management-list', 'yfth-user-role-management-detail', 'yfth-user-role-management-grant', 'yfth-user-role-management-revoke'] as $auth) {
    $assert(strpos($migration, $auth) !== false, 'permission_exists:' . $auth);
}
foreach (['yfth-user-role-management-fixture-read', 'yfth-user-role-management-fixture-generate', 'yfth-user-role-management-fixture-reset'] as $auth) {
    $assert(strpos($fixtureMigration, $auth) !== false, 'fixture_permission_exists:' . $auth);
}
$assert(strpos($passwordMigration, 'yfth-user-role-management-fixture-password-reset') !== false, 'fixture_password_reset_permission_exists');
foreach (['acceptance_fixture_enabled', 'assertHeadquarterScope', 'YFTH-ACCEPTANCE-TEST-V1', '0600', 'acceptance_fixture_user_marker_invalid'] as $needle) {
    $assert(strpos($fixtureService, $needle) !== false, 'controlled_fixture_contains:' . $needle);
}
$assert(strpos($fixtureService, "'password_exposed' => false") !== false, 'fixture_api_never_exposes_password');
$assert(strpos($fixtureService, "where('mark', self::MARKER)") !== false, 'fixture_reset_targets_test_marker');
$assert(strpos($fixtureService, 'Db::name(\'user\')->delete') === false, 'fixture_reset_does_not_delete_users');
$assert(strpos($fixtureService, 'Db::name(\'system_store\')->delete') === false, 'fixture_reset_does_not_delete_store');
$assert(strpos($controller . $route, 'yfth/user_role/fixture/generate') !== false, 'fixture_generate_route_protected');
$assert(strpos($controller . $route, 'yfth/user_role/fixture/reset') !== false, 'fixture_reset_route_protected');
$assert(strpos($controller . $route, 'yfth/user_role/fixture/password/reset') !== false, 'fixture_password_reset_route_protected');
$assert(strpos($fixtureService, 'temporary_passwords_once') !== false, 'fixture_password_reset_is_one_time_response');
$assert(strpos($fixtureService, 'yfth_stg_b1_franchisee') !== false && strpos($fixtureService, 'yfth_stg_c2_customer') !== false, 'fixture_uses_stable_staging_accounts');
$assert(strpos((string)file_get_contents($root . '/app/adminapi/controller/v1/user/User.php'), 'HqUserRoleManagementServices') !== false, 'native_user_list_uses_yfth_summary');
$assert(strpos($nativeUserPage, '御方通和套餐会员') !== false, 'native_user_list_shows_yfth_membership');
$assert(strpos($nativeUserPage, '永久归属门店') !== false, 'native_user_list_shows_attribution');
$assert(strpos($nativeUserPage, '管理经营身份') !== false, 'native_user_list_links_role_management');
$assert(strpos($nativeUserPage, "name: 'yfth_user_role'") !== false, 'native_user_list_uses_admin_named_route');
$assert(strpos($nativeUserPage, "path: '/yfth/user-role'") === false, 'native_user_list_does_not_escape_admin_route_base');
$assert(strpos($adminPage, '生成或补齐完整测试门店与账号') !== false, 'admin_page_exposes_fixture_action');
$assert(strpos($adminPage, 'yfthUserRoleGrant') !== false && strpos($adminPage, 'yfthUserRoleRevoke') !== false, 'admin_page_uses_real_role_api');
$assert(strpos($adminPage, '操作原因') !== false, 'admin_page_requires_reason');

foreach (['now_money', 'integral', 'couponCount', '商城资产与御方通和推荐奖励独立核算'] as $needle) {
    $assert(strpos($userPage, $needle) !== false, 'user_assets_contains:' . $needle);
}
$assert(strpos($userPage, 'goYfthReferralCode') !== false, 'permanent_member_referral_entry_exists');
$assert(strpos($userPage, 'isYfthPermanentMember') !== false, 'referral_entry_is_membership_gated');
foreach (['issueYfthDirectReferralInvite', 'getYfthPackageMembershipMe', 'zb-code', 'invited_count', 'store_name', '/pages/yfth/referral/accept'] as $needle) {
    $assert(strpos($codePage . $membership, $needle) !== false, 'promotion_flow_contains:' . $needle);
}
foreach (['yfth_pending_referral_invite', 'toLogin', 'acceptYfthDirectReferralInvite', 'idempotency_key'] as $needle) {
    $assert(strpos($acceptPage, $needle) !== false, 'scan_login_continuation_contains:' . $needle);
}
foreach (['uni.scanCode', 'BarcodeDetector', 'chooseQrImage', 'invite_token', '/pages/yfth/referral/accept'] as $needle) {
    $assert(strpos($scanPage, $needle) !== false, 'referral_scan_contains:' . $needle);
}
$assert(strpos($userPage, 'goYfthReferralScan') !== false, 'user_center_exposes_referral_scan');
$assert(strpos($userPage, '当前身份') !== false && strpos($userPage, '进入工作台') !== false, 'user_center_exposes_current_role');
$assert(strpos($roleSwitchPage, "switchYfthRole('customer', 0)") !== false, 'customer_switch_uses_server_context');
$assert(substr_count($pages, '"path": "referral/code"') === 1, 'referral_code_route_unique');
$assert(substr_count($pages, '"path": "referral/accept"') === 1, 'referral_accept_route_unique');
$assert(substr_count($pages, '"path": "referral/scan"') === 1, 'referral_scan_route_unique');
foreach (['createYfthReferralCode', 'bindYfthReferralCode', 'user_spread', 'spread_uid'] as $forbidden) {
    $assert(strpos($codePage . $acceptPage, $forbidden) === false, 'promotion_does_not_use_legacy_referral:' . $forbidden);
}
foreach (['now_money', 'integral', 'brokerage_price', 'spread_uid'] as $fundingField) {
    $assert(strpos($service . $membership, "update({$fundingField}") === false, 'new_backend_does_not_write_crmeb_funding:' . $fundingField);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH user role, assets and referral QR contract verified.\n";
