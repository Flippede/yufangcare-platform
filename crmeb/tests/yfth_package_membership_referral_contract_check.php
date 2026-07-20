<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

$files = [
    'database/migrations/20260716100000_create_yfth_package_membership_referral_v2.php',
    'app/services/yfth/PackageMembershipServices.php',
    'app/services/yfth/PackageMembershipGrantPolicy.php',
    'app/services/yfth/PackageMembershipReferralMigrationHealthServices.php',
    'app/services/yfth/PackageMembershipReferralServices.php',
    'app/services/yfth/PackageMembershipActivationCoordinator.php',
    'app/services/yfth/PackageMembershipReferralQualificationPolicy.php',
    'app/services/yfth/DirectReferralRewardServices.php',
    'app/api/controller/v1/yfth/PackageMembershipReferralController.php',
    'app/api/controller/v1/yfth/PackageMembershipReferralStoreController.php',
    'app/adminapi/controller/v1/yfth/PackageMembershipReferral.php',
    'tests/yfth_package_membership_referral_migration_health_check.php',
    'tests/yfth_package_membership_referral_http_flow_check.php',
];
$source = '';
foreach ($files as $file) {
    $path = $root . '/' . $file;
    $assert(is_file($path), 'file_exists:' . $file);
    if (is_file($path)) {
        $source .= (string)file_get_contents($path) . "\n";
    }
}

$migration = (string)file_get_contents($root . '/database/migrations/20260716100000_create_yfth_package_membership_referral_v2.php');
foreach ([
    'yfth_permanent_membership',
    'yfth_permanent_membership_event',
    'yfth_direct_referral_invite',
    'yfth_direct_referral_rule_version',
    'yfth_direct_referral_reward_candidate',
    'grants_permanent_membership',
    'uniq_yfth_pm_uid',
    'uniq_yfth_pm_source_instance',
    'uniq_yfth_pm_event_source',
    'uniq_yfth_direct_invite_hash',
    'uniq_yfth_direct_invite_active',
    'uniq_yfth_direct_rule_version',
    'uniq_yfth_direct_rule_active',
    'uniq_yfth_direct_candidate_source',
    'uniq_yfth_direct_candidate_sequence',
    'yfth-package-membership-referral-legacy-backfill',
    'forward_repair_required',
    'down_signature_ambiguous',
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}
$assert(strpos($migration, 'yfth_package_instance') === false, 'migration_has_no_historical_business_scan');
$assert(substr_count($migration, "'null' => true, 'default' => null") >= 2, 'legacy_grant_columns_use_auditable_null_semantics');
$assert(!preg_match('/UPDATE\s+.*grants_permanent_membership/is', $migration), 'migration_does_not_bulk_rewrite_historical_grants');

$membership = (string)file_get_contents($root . '/app/services/yfth/PackageMembershipServices.php');
foreach ([
    'effectiveMembership',
    'effectiveMembershipAuthority',
    'assertEffectiveActive',
    'assertPersistedActive',
    'grantFromPackageInTransaction',
    'legacyBackfill',
    "'historical_package_activation'",
    "'historical_package_pending_controlled_backfill'",
    "where('p.activation_status', 'succeeded')",
    "where('o.paid', 1)",
    's.grants_permanent_membership IS NULL',
] as $needle) {
    $assert(strpos($membership, $needle) !== false, 'membership_service_contains:' . $needle);
}
$assert(strpos($membership, "where('i.status', 'active')") === false, 'historical_membership_does_not_depend_on_current_instance_status');
$assert(strpos($membership, "where('p.purchase_status', 'activated')") === false, 'historical_membership_does_not_depend_on_mutable_purchase_status');

$referral = (string)file_get_contents($root . '/app/services/yfth/PackageMembershipReferralServices.php');
foreach ([
    'assertEffectiveActive($ownerUid, $storeId, true)',
    'direct_referral_referred_user_must_be_non_member',
    'assignFirstWithLockedCurrentsInTransaction',
    'createWithLockedCurrentsInTransaction',
    'package_purchase_cross_store_forbidden',
    'package_must_grant_permanent_membership',
    "['franchisee', 'store_manager']",
] as $needle) {
    $assert(strpos($referral, $needle) !== false, 'referral_service_contains:' . $needle);
}
$acceptReferredLock = strpos($referral, '$lockedCurrents = $this->attribution->lockCurrents([$uid]);');
$acceptOwnerLock = strpos($referral, '$lockedCurrents += $this->attribution->lockCurrents([$ownerUid]);');
$acceptMember = strpos($referral, '$this->membership->assertEffectiveActive($ownerUid, $storeId, true);');
$assert($acceptReferredLock !== false && $acceptOwnerLock !== false && $acceptMember !== false
    && $acceptReferredLock < $acceptOwnerLock && $acceptOwnerLock < $acceptMember,
    'invite_accept_locks_referred_then_referrer_before_membership');
$assert(strpos($referral, "'referrer_uid' => (int)\$referral['referrer_uid']") === false, 'user_me_does_not_expose_referrer_uid');

$activation = (string)file_get_contents($root . '/app/services/yfth/PackageMembershipActivationCoordinator.php');
foreach ([
    'membershipLockContext',
    "['locked_currents']",
    'closeForMembershipWithLockedCurrentsInTransaction',
    'UnifiedRewardOrchestratorServices',
    "'package_activated'",
    'grantFromPackageInTransaction',
    "'package_membership_activation'",
] as $needle) {
    $assert(strpos($activation, $needle) !== false, 'activation_coordinator_contains:' . $needle);
}

$reward = (string)file_get_contents($root . '/app/services/yfth/DirectReferralRewardServices.php');
foreach ([
    '[1500, 2500, 6000]',
    'intdiv($amountCent * $ratio, 10000)',
    "'status' => 'pending'",
    "'responsibility_type' => 'store_mall_revenue'",
    'direct_referral_rule_unavailable',
    "(int)\$order['paid'] !== 1",
    "(int)(\$order['pid'] ?? 0) !== 0",
    "(int)(\$order['refund_status'] ?? 0) !== 0",
    "(int)(\$order['is_del'] ?? 0) !== 0",
    'package_order_not_mall_consumption',
    'userCandidateDto',
    'storeCandidateDto',
    'adminCandidateDto',
] as $needle) {
    $assert(strpos($reward, $needle) !== false, 'reward_service_contains:' . $needle);
}

$packageTemplate = (string)file_get_contents($root . '/app/services/yfth/PackageTemplateServices.php');
$packagePurchase = (string)file_get_contents($root . '/app/services/yfth/PackagePurchaseServices.php');
$packageActivation = (string)file_get_contents($root . '/app/services/yfth/PackageActivationServices.php');
$assert(strpos($packageTemplate, 'grants_permanent_membership') !== false, 'package_rule_versions_membership_grant');
$assert(strpos($packagePurchase, 'resolveAuthoritativeStoreForPurchase') !== false, 'purchase_resolves_authoritative_store');
$assert(strpos($packagePurchase, 'assertMembershipGrantRule') !== false, 'every_package_purchase_requires_membership_grant');
$assert(strpos($packageTemplate, 'published_package_rule_must_grant_permanent_membership') !== false, 'published_package_rule_requires_membership_grant');
$assert(strpos($packageTemplate, 'supersedeCurrentPublishedRule') !== false, 'package_price_rules_support_immutable_version_rollover');
$assert(strpos($packagePurchase, "'grants_permanent_membership'") !== false, 'purchase_snapshot_captures_membership_grant');
$assert(strpos($packageActivation, 'PackageMembershipActivationCoordinator') !== false, 'activation_calls_membership_coordinator');
$assert(strpos($activation, 'PackageMembershipGrantPolicy') !== false, 'legacy_reward_uses_classified_membership_semantics');

$grantPolicy = (string)file_get_contents($root . '/app/services/yfth/PackageMembershipGrantPolicy.php');
$assert(strpos($grantPolicy, 'legacy_package_semantics') !== false, 'legacy_package_grant_semantics_is_explicit');
$assert(strpos($grantPolicy, "['grants_permanent_membership'] === null") !== false, 'legacy_null_grant_is_classified');

$health = (string)file_get_contents($root . '/app/services/yfth/PackageMembershipReferralMigrationHealthServices.php');
foreach (['migration_record_missing', 'column_signature:', 'index_signature:', 'permission_signature:', 'forward_repair_required'] as $needle) {
    $assert(strpos($health, $needle) !== false, 'migration_health_contains:' . $needle);
}

$userRewardStart = strpos($reward, 'private function userCandidateDto');
$userRewardEnd = strpos($reward, 'private function storeCandidateDto', $userRewardStart);
$userRewardDto = substr($reward, $userRewardStart, $userRewardEnd - $userRewardStart);
foreach (['referrer_uid', 'referred_uid', 'reward_sequence_no', 'rule_version_id'] as $field) {
    $assert(strpos($userRewardDto, $field) === false, 'user_candidate_dto_excludes:' . $field);
}

$userController = (string)file_get_contents($root . '/app/api/controller/v1/yfth/PackageMembershipReferralController.php');
$assert(strpos($userController, '(int)$request->uid()') !== false, 'user_uid_is_authenticated_uid');
foreach (['uid', 'owner_uid', 'referrer_uid', 'store_id', 'source_unique_key'] as $field) {
    $assert(strpos($userController, "'{$field}'") !== false, 'client_authority_field_rejected:' . $field);
}

$adminController = (string)file_get_contents($root . '/app/adminapi/controller/v1/yfth/PackageMembershipReferral.php');
$assert(strpos($adminController, 'assertApiAuthForAdmin') !== false, 'admin_api_permission_is_explicit');
$assert(strpos($adminController, 'assertHeadquarterScope') !== false, 'admin_scope_is_headquarters_only');

$apiRoute = (string)file_get_contents($root . '/app/api/route/yfth_service.php');
$adminRoute = (string)file_get_contents($root . '/app/adminapi/route/yfth.php');
foreach ([
    'yfth/package_membership/me',
    'yfth/package_membership/invite',
    'yfth/package_membership/invite/accept',
    'yfth/package_membership/candidate',
    'yfth/store_workbench/package_membership/member',
    'yfth/store_workbench/package_membership/candidate',
] as $route) {
    $assert(strpos($apiRoute, $route) !== false, 'user_route_exists:' . $route);
}
foreach (['member', 'candidate', 'rule', 'legacy_backfill'] as $route) {
    $assert(strpos($adminRoute, $route) !== false, 'admin_route_exists:' . $route);
}

$adminPage = (string)file_get_contents(dirname($root) . '/template/admin/src/pages/yfth/packageMembershipReferral/index.vue');
$uniPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/package_membership/index.vue');
$acceptPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/referral/accept.vue');
$referralNavigation = (string)file_get_contents(dirname($root) . '/template/uni-app/libs/yfthReferralNavigation.js');
$assert(strpos($adminPage, 'runBackfill') !== false, 'admin_page_has_controlled_backfill');
$assert(strpos($adminPage, 'payout') === false, 'admin_page_has_no_automatic_payout');
$assert(strpos($uniPage, 'forwardToReferralAccept') !== false, 'legacy_membership_invite_forwards_to_canonical_accept_page');
$assert(strpos($uniPage, 'acceptYfthDirectReferralInvite') === false, 'membership_page_does_not_accept_invite_directly');
$assert(strpos($acceptPage, 'acceptYfthDirectReferralInvite') !== false, 'canonical_accept_page_uses_real_invite_api');
$assert(strpos($acceptPage, '进入商城首页') !== false, 'accept_success_exposes_headquarters_home_action');
$assert(strpos($referralNavigation, "'/pages/index/index'") !== false, 'referral_success_uses_headquarters_home_route');
$assert(strpos($referral, "'customer_status' => 'non_member'") !== false, 'accept_dto_keeps_referred_user_non_member');
$assert(strpos($referral, "'is_permanent_member' => false") !== false, 'accept_dto_does_not_grant_membership');
$assert(strpos($referral, "'target_page' => self::HEADQUARTERS_HOME_ROUTE") !== false, 'accept_dto_targets_headquarters_home');

foreach (['5980', '9800'] as $price) {
    $assert(strpos($source, $price) === false, 'new_v2_production_has_no_hardcoded_price:' . $price);
}
foreach (['now_money', 'brokerage_price', 'spread_uid', 'UserBrokerageServices', 'UserBillServices', 'DirectReferralRewardSettlementServices', 'payout'] as $forbidden) {
    $assert(stripos($source, $forbidden) === false, 'new_v2_production_excludes:' . $forbidden);
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
echo "[OK] YFTH package membership and direct referral V2 contract verified.\n";
