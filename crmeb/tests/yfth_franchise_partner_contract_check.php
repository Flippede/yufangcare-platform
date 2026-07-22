<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$failures = [];
$passes = [];

$read = function (string $path) use ($root, $projectRoot): string {
    $full = strpos($path, '../') === 0
        ? $projectRoot . DIRECTORY_SEPARATOR . substr($path, 3)
        : $root . DIRECTORY_SEPARATOR . $path;
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

$migration = $read('database/migrations/20260719100000_create_yfth_franchise_partner_hierarchy.php');
$grantMigration = $read('database/migrations/20260719110000_add_yfth_partner_manual_grant_permissions.php');
$revokeMigration = $read('database/migrations/20260722100000_add_yfth_partner_revoke_permission.php');
$unificationMigration = $read('database/migrations/20260720100000_create_yfth_partner_reward_unification_v1.php');
$service = $read('app/services/yfth/FranchisePartnerServices.php');
$opening = $read('app/services/yfth/FranchiseOpeningServices.php');
$fixture = $read('app/services/yfth/HqAcceptanceFixtureServices.php');
$roleService = $read('app/services/yfth/HqUserRoleManagementServices.php');
$adminController = $read('app/adminapi/controller/v1/yfth/FranchisePartner.php');
$userRoleController = $read('app/adminapi/controller/v1/yfth/HqUserRole.php');
$apiController = $read('app/api/controller/v1/yfth/FranchisePartnerController.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminPage = $read('../template/admin/src/pages/yfth/franchisePartner/index.vue');
$userRolePage = $read('../template/admin/src/pages/yfth/userRole/index.vue');
$adminApi = $read('../template/admin/src/api/yfth.js');
$userPage = $read('../template/uni-app/pages/yfth/franchise/partner/index.vue');

foreach ([
    'yfth_partner_rule_version', 'yfth_partner_rank_rule', 'yfth_partner_profile',
    'yfth_partner_relation', 'yfth_partner_rank_event', 'yfth_partner_invite',
    'yfth_franchise_recruit_source', 'yfth_partner_opening_performance',
    'yfth_partner_reward_candidate', 'yfth_partner_reward_settlement', 'yfth_partner_warning',
    'yfth_partner_promotion_application',
] as $table) {
    $assert($contains($migration, $table), 'migration_table:' . $table);
}
foreach ([
    'uniq_yfth_partner_rule_active', 'uniq_yfth_partner_profile_uid',
    'uniq_yfth_partner_relation_active', 'uniq_yfth_partner_invite_active',
    'uniq_yfth_franchise_recruit_source_app', 'uniq_yfth_partner_performance_app',
    'uniq_yfth_partner_candidate_rank', 'uniq_yfth_partner_settlement_candidate',
    'uniq_yfth_partner_promotion_active',
] as $index) {
    $assert($contains($migration, $index), 'migration_unique_guard:' . $index);
}
foreach (['89100.00', '440', '40.00', '17.00', '10.00', '8.00', '5.00'] as $value) {
    $assert($contains($migration, $value), 'default_rule_value:' . $value);
}
foreach (['county_partner', 'prefecture_partner', 'province_partner', 'regional_director', 'platform_director'] as $rank) {
    $assert($contains($service, "'{$rank}'") && $contains($migration, $rank), 'rank_supported:' . $rank);
}
$assert($contains($service, 'REQUIRED_PARENT_RANKS'), 'manual_grant_declares_adjacent_parent_matrix');
$assert($contains($service, 'adminGrantOptions') && $contains($service, 'adminGrantPartner') && $contains($service, 'adminRevokePartner'), 'manual_partner_identity_service_present');
foreach (['partner_parent_required', 'partner_parent_rank_invalid', 'partner_top_rank_parent_forbidden', 'partner_already_active'] as $error) {
    $assert($contains($service, $error), 'manual_grant_guard:' . $error);
}
$assert($contains($service, "'primary_store_id' => 0") && $contains($service, "'source_type' => 'headquarters_grant'"), 'manual_partner_grant_is_store_independent_and_auditable');
$assert($contains($service, "'active_key' => 'partner:' . \$uid"), 'manual_grant_keeps_one_active_parent_relation');
$assert($contains($service, 'partner_active_children_must_be_reassigned')
    && $contains($service, "'action' => 'headquarters_revoke'")
    && $contains($service, "['qualification_status'] = 'terminated'"), 'partner_revoke_closes_leaf_identity_and_protects_children');

foreach ([
    'adminSaveRule', 'adminPublishRule', 'adminChangeRank', 'adminChangeParent',
    'adminCorrectSource', 'createInvite', 'captureRecruitSource', 'freezeRecruitSource',
    'finalizeOpeningInTransaction', 'ensurePerformanceAndRewards', 'adminRewardTransition',
    'adminSettleReward', 'applyPromotion', 'adminReviewPromotion',
    'partner_relation_cycle_detected', 'partner_recruiter_inactive',
] as $needle) {
    $assert($contains($service, $needle), 'partner_service:' . $needle);
}
$assert($contains($service, 'V1 deliberately creates no hierarchy cash candidate'), 'opening_reward_disables_hierarchy_cash_candidates');
$assert($contains($service, "'direct_partner_uid' => (int)(\$source['direct_partner_uid'] ?? 0)"), 'opening_reward_uses_direct_partner_only');
$assert($contains($opening, "'first_purchase', 'name' => 'First purchase proof', 'required' => 0"), 'first_purchase_does_not_block_preopening_acceptance');
$assert($contains($opening, "->where('id', \$profileId)->lock(true)->find()"), 'formal_store_creation_profile_lock');
$assert($contains($opening, 'franchise_store_create_acceptance_not_passed'), 'formal_store_after_acceptance');
$assert($contains($opening, 'franchise_identity_store_not_bound'), 'identity_after_store_binding');
$assert($contains($opening, "['county_partner', 'store_manager', 'all']") && !$contains($opening, "['county_partner', 'franchisee', 'store_manager', 'all']"), 'formal_opening_never_grants_new_franchisee_role');
$assert($contains($unificationMigration, 'yfth_partner_store_binding') && $contains($unificationMigration, 'yfth_partner_opening_quota_award'), 'partner_store_ownership_and_opening_quota_are_explicit');

$assert($contains($migration, "\$this->quote('franchisee')"), 'legacy_franchisee_backfill_present');
$assert($contains($migration, 'legacy_franchisee_migration'), 'legacy_franchisee_mapping_auditable');
$assert($contains($roleService, 'county_partner'), 'user_role_dto_exposes_partner_rank');
$assert(!$contains($roleService, "'franchisee' => 'Franchisee'"), 'product_ui_does_not_use_old_franchisee_label');
$assert($contains($fixture, 'yfth_stg_b1_franchisee'), 'legacy_test_account_reused');
foreach (['yfth_stg_partner_prefecture', 'yfth_stg_partner_province', 'yfth_stg_partner_regional', 'yfth_stg_partner_platform'] as $account) {
    $assert($contains($fixture, $account), 'fixture_partner_account:' . $account);
}
$assert($contains($fixture, "\$data['legacy_franchisee_role_id'] = \$legacyRoleId"), 'fixture_links_legacy_role_to_county_profile');

foreach (['sourceCorrect', 'rewardSettle', 'rulePublish', 'parentChange', 'promotionList', 'promotionReview'] as $method) {
    $assert($contains($adminController, $method), 'admin_controller:' . $method);
}
foreach (['createInvite', 'workbench', 'team', 'rewards', 'promotionApply'] as $method) {
    $assert($contains($apiController, $method), 'api_controller:' . $method);
}
$assert($contains($adminRoute, "Route::group('franchise_partner'"), 'admin_routes_registered');
$assert($contains($adminRoute, "partner/grant_options") && $contains($adminRoute, "user/:uid/partner/grant"), 'manual_grant_routes_registered');
$assert($contains($adminRoute, "user/:uid/partner/revoke"), 'partner_revoke_route_registered');
$assert($contains($userRoleController, 'partnerGrantOptions') && $contains($userRoleController, 'grantPartner') && $contains($userRoleController, 'revokePartner'), 'manual_partner_identity_controller_present');
$assert($contains($grantMigration, 'yfth-user-role-partner-grant-options') && $contains($grantMigration, 'yfth-user-role-partner-grant'), 'manual_grant_permissions_forward_migration');
$assert($contains($revokeMigration, 'yfth-user-role-partner-revoke'), 'partner_revoke_permission_forward_migration');
$assert($contains($userRolePage, '授予五级合伙人') && $contains($userRolePage, '直属上级') && $contains($userRolePage, '撤销{{ row.partner_identity.rank_name }}'), 'manual_partner_identity_admin_surface_present');
$assert($contains($userRolePage, 'partnerGrantReady') && $contains($userRolePage, '平台董事 → 大区总监 → 省级合伙人 → 地级合伙人 → 县级合伙人'), 'manual_partner_grant_hierarchy_and_submit_guard_present');
$assert($contains($adminApi, 'yfthPartnerGrantOptions') && $contains($adminApi, 'yfthUserPartnerGrant') && $contains($adminApi, 'yfthUserPartnerRevoke'), 'manual_partner_identity_admin_api_present');
$assert($contains($apiRoute, "yfth/franchise/partner/workbench") && $contains($apiRoute, "yfth/franchise/partner/invite"), 'user_routes_registered');
$assert($contains($apiRoute, 'yfth/franchise/partner/promotion/apply'), 'promotion_apply_route_registered');
$assert($contains($adminPage, '招商合伙人详情') && $contains($adminPage, '收益与线下结算'), 'admin_surface_present');
$assert($contains($adminPage, '晋级申请') && $contains($userPage, '申请晋升下一职级'), 'promotion_surface_present');
$assert($contains($userPage, '加盟申请二维码') && $contains($userPage, '招商收益候选'), 'partner_workbench_present');

$assert($contains($userPage, ':key="qrRenderKey"')
    && $contains($userPage, 'queueQrRender')
    && $contains($userPage, 'this.$refs.partnerQr._makeCode()'),
    'partner_invite_qr_rerenders_after_async_invite');
$assert($contains($userPage, 'partner-qr-image')
    && $contains($userPage, 'qrError')
    && $contains($userPage, '二维码生成中'),
    'partner_invite_qr_has_visible_result_and_safe_state');

foreach (['user_brokerage', 'user_bill', 'brokerage_price', 'integral', 'now_money', 'spread_uid', 'store_order'] as $forbidden) {
    $assert(!$contains($service, $forbidden), 'partner_service_does_not_write_crmeb_money_or_order:' . $forbidden);
}

if ($failures) {
    fwrite(STDERR, "YFTH franchise partner contract check failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, " - {$failure}\n");
    exit(1);
}
echo '[OK] YFTH franchise partner contract check passed with ' . count($passes) . " assertions.\n";
