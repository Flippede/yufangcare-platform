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
$membershipAuthority = $read('app/services/yfth/PackageMembershipServices.php');
$franchiseCustomer = $read('app/services/yfth/FranchiseCustomerServices.php');
$membershipGrantMigration = $read('database/migrations/20260718130000_allow_headquarters_permanent_membership_grant.php');
$membershipPurgeMigration = $read('database/migrations/20260718150000_add_yfth_membership_and_debug_purge_permissions.php');
$purgeService = $read('app/services/yfth/HqUserDebugPurgeServices.php');
$adminPage = (string)file_get_contents(dirname($root) . '/template/admin/src/pages/yfth/userRole/index.vue');
$userPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/user/index.vue');
$codePage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/referral/code.vue');
$acceptPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/referral/accept.vue');
$scanPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/referral/scan.vue');
$roleSwitchPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/workbench/role_switch.vue');
$storeAcquisitionService = $read('app/services/yfth/StoreAcquisitionServices.php');
$storeAcquisitionController = $read('app/api/controller/v1/yfth/StoreAcquisitionController.php');
$storeAcquisitionMigration = $read('database/migrations/20260718140000_create_yfth_store_acquisition_codes.php');
$storeAcquisitionCodePage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/store_acquisition/code.vue');
$storeAcquisitionAcceptPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/store_acquisition/accept.vue');
$workbenchPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/workbench/index.vue');
$pages = (string)file_get_contents(dirname($root) . '/template/uni-app/pages.json');
$nativeUserPage = (string)file_get_contents(dirname($root) . '/template/admin/src/pages/user/list/index.vue');

foreach (['assertHeadquarters', 'assertHeadquarterScope', 'UserStoreRoleServices', 'YfthUserStoreRoleDao', "'grant'", "'revoke'", 'user_store_role_reason_required', 'AuditEventServices'] as $needle) {
    $assert(strpos($service, $needle) !== false, 'role_service_contains:' . $needle);
}
$assert(strpos($service, 'YfthUserIdentityDao') === false, 'role_write_does_not_replace_global_identity');
$assert(strpos($service, 'YfthPermanentMembershipDao') === false, 'role_service_does_not_write_membership_dao_directly');
$assert(strpos($service, 'grantByHeadquarters') !== false, 'headquarters_can_grant_real_permanent_membership');
$assert(strpos($membershipAuthority, 'membership_granted_by_headquarters') !== false, 'membership_grant_is_evented_and_audited');
$assert(strpos($membershipGrantMigration, 'source_package_instance_id` INT UNSIGNED NULL') !== false, 'manual_membership_has_explicit_nullable_package_source');
$assert(strpos($controller . $route, 'yfth/user_role/user/<uid>/membership/grant') !== false, 'dedicated_membership_grant_route_exists');
$assert(strpos($adminPage, 'yfthUserMembershipGrant') !== false, 'admin_membership_button_uses_dedicated_api');
foreach (['yfth-user-role-membership-grant', 'yfth-user-debug-purge-preflight', 'yfth-user-debug-purge-execute'] as $auth) {
    $assert(strpos($membershipPurgeMigration, $auth) !== false, 'membership_purge_permission_exists:' . $auth);
}
foreach (['user_debug_purge_enabled', 'discoverReferences', 'confirmation_phrase', 'blocking_references', 'debug_user_purge_residual_reference_detected'] as $needle) {
    $assert(strpos($purgeService, $needle) !== false, 'debug_purge_guard_contains:' . $needle);
}
$assert(strpos($purgeService, "'store_order' =>") === false, 'debug_purge_never_allowlists_store_orders');
$assert(strpos($purgeService, "'yfth_permanent_membership' =>") === false, 'debug_purge_never_allowlists_membership');
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
$assert(strpos($adminPage, '授权会员') !== false && strpos($adminPage, '加盟商身份独立存在') !== false, 'admin_page_separates_membership_and_franchisee_identity');
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
foreach (['uni.scanCode', 'BarcodeDetector', 'jsQR', 'chooseQrImage', 'invite_token', '/pages/yfth/referral/accept'] as $needle) {
    $assert(strpos($scanPage, $needle) !== false, 'referral_scan_contains:' . $needle);
}
$assert(strpos($scanPage, 'onlyFromCamera: false') !== false, 'native_scanner_allows_album_qr');
$assert(strpos($scanPage, 'onReady()') !== false && strpos($scanPage, 'this.scan()') !== false, 'referral_scanner_opens_immediately');
$assert(strpos($scanPage, 'class="camera-view"') !== false && strpos($scanPage, 'class="scanner-footer"') !== false, 'h5_scanner_uses_fullscreen_camera_surface');
$assert(strpos($scanPage, ':controls="false"') !== false && strpos($scanPage, ':show-center-play-btn="false"') !== false, 'h5_scanner_hides_media_playback_controls');
$assert(strpos($scanPage, "document.createElement('input')") !== false && strpos($scanPage, "picker.type = 'file'") !== false, 'h5_album_uses_native_file_picker');
$assert(strpos($scanPage, "picker.accept = 'image/png,image/jpeg,image/webp,image/gif'") !== false, 'h5_album_picker_accepts_qr_images');
$assert(strpos($scanPage, 'window.URL.createObjectURL(file)') !== false && strpos($scanPage, 'window.URL.revokeObjectURL(objectUrl)') !== false, 'h5_album_file_lifecycle_is_bounded');
$assert(strpos($userPage, 'iconfont icon-saoma') !== false, 'user_center_exposes_top_scan_icon');
$assert(strpos($userPage, 'class="referral-scan-entry"') === false, 'user_center_removes_large_referral_scan_card');
$assert(strpos($codePage, 'saveQr') !== false && strpos($codePage, '_saveCode') !== false, 'promotion_qr_can_be_saved_to_device');
$assert(strpos($membership, 'syncAuthorityCustomerInTransaction') !== false, 'invite_accept_projects_authority_to_store_customer_view');
$assert(strpos($franchiseCustomer, 'backfillAuthorityCustomers') !== false, 'existing_authority_has_controlled_customer_projection_repair');
$assert(strpos($userPage, 'goYfthReferralScan') !== false, 'user_center_exposes_referral_scan');
$assert(strpos($userPage, '当前身份') !== false && strpos($userPage, '进入工作台') !== false, 'user_center_exposes_current_role');
$assert(strpos($roleSwitchPage, 'resolveDominantYfthContext') !== false, 'role_switch_uses_server_resolved_dominant_context');
$assert(strpos($roleSwitchPage, "uni.reLaunch") !== false && strpos($roleSwitchPage, "this.switching") !== false, 'business_role_switch_relaunches_with_busy_guard');
$assert(strpos($workbenchPage, '我的门店获客码') !== false && strpos($workbenchPage, 'canIssueAcquisitionCode') !== false, 'manager_and_staff_acquisition_code_entry_visible');
$assert(strpos($storeAcquisitionService, "private const ROLES = ['store_manager', 'store_staff']") !== false, 'acquisition_code_role_boundary');
$assert(strpos($storeAcquisitionService, 'HqCustomerAttributionServices') !== false && strpos($storeAcquisitionService, 'syncAuthorityCustomerInTransaction') !== false, 'acquisition_uses_authoritative_store_attribution');
$assert(strpos($storeAcquisitionService, "'store_acquisition'") !== false, 'acquisition_projects_store_customer_source');
$assert(strpos($storeAcquisitionService, 'HqActiveReferralServices') === false && strpos($storeAcquisitionService, 'user_spread') === false, 'acquisition_does_not_create_referral_or_legacy_spread');
foreach (['store_acquisition_self_bind_forbidden', 'store_acquisition_customer_already_bound', 'store_acquisition_customer_already_attributed', 'store_acquisition_issuer_role_inactive'] as $guard) {
    $assert(strpos($storeAcquisitionService, $guard) !== false, 'acquisition_guard:' . $guard);
}
foreach (['yfth_store_acquisition_code', 'yfth_store_acquisition_acceptance', 'uniq_yfth_acquisition_customer', 'uniq_yfth_acquisition_active'] as $needle) {
    $assert(strpos($storeAcquisitionMigration, $needle) !== false, 'acquisition_migration_contains:' . $needle);
}
$assert(strpos($storeAcquisitionController, 'acquisition_token') !== false, 'acquisition_controller_accepts_opaque_token_only');
$assert(strpos($storeAcquisitionCodePage, 'saveQr') !== false && strpos($storeAcquisitionCodePage, '_saveCode') !== false, 'employee_acquisition_qr_can_be_saved');
$assert(strpos($storeAcquisitionService, 'MiniProgramService::getUrlLink') !== false && strpos($storeAcquisitionService, 'h5_launch_url') !== false, 'employee_qr_exposes_dedicated_h5_fallback');
$assert(strpos($storeAcquisitionCodePage, 'this.code.h5_launch_url') !== false, 'employee_qr_uses_current_site_acquisition_route');
$assert(strpos($storeAcquisitionCodePage, 'resolveYfthContext') !== false, 'employee_acquisition_page_revalidates_role_and_store');
$assert(strpos($workbenchPage, 'store_acquisition/code?role_code=') !== false, 'workbench_passes_verified_context_to_acquisition_page');
$assert(strpos($storeAcquisitionCodePage, 'getYfthStoreAcquisitionCode') !== false && strpos($storeAcquisitionCodePage, 'active.code_no === cached.code_no') !== false, 'saved_employee_qr_is_reused_until_rotated_or_expired');
$assert(strpos($storeAcquisitionAcceptPage, 'yfth_pending_store_acquisition') !== false && strpos($storeAcquisitionAcceptPage, 'toLogin') !== false, 'acquisition_login_continuation_exists');
$assert(strpos($storeAcquisitionAcceptPage, 'this.$nextTick(() => this.accept())') !== false, 'acquisition_accepts_automatically_after_login');
$assert(strpos($storeAcquisitionAcceptPage, "uni.reLaunch({ url: '/pages/index/index' })") !== false, 'acquisition_success_returns_to_mall_home');
$assert(strpos($scanPage, 'acquisition_token') !== false && strpos($scanPage, '/pages/yfth/store_acquisition/accept') !== false, 'scanner_recognizes_store_acquisition_qr');
$assert(substr_count($pages, '"path": "store_acquisition/code"') === 1, 'store_acquisition_code_route_unique');
$assert(substr_count($pages, '"path": "store_acquisition/accept"') === 1, 'store_acquisition_accept_route_unique');
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
