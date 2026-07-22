<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void { $condition ? $passes[] = $label : $failures[] = $label; };
$read = function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) throw new RuntimeException('missing_file:' . $path);
    return (string)file_get_contents($file);
};

try {
    $migration = $read('database/migrations/20260715100000_create_yfth_permanent_membership_tables.php');
    $service = $read('app/services/yfth/PermanentMembershipServices.php');
    $membershipService = $read('app/services/yfth/PackageMembershipServices.php');
    $user = $read('app/api/controller/v1/yfth/PermanentMembershipController.php');
    $store = $read('app/api/controller/v1/yfth/PermanentMembershipStoreController.php');
    $admin = $read('app/adminapi/controller/v1/yfth/PermanentMembership.php');
    $routes = $read('app/api/route/yfth_service.php') . $read('app/adminapi/route/yfth.php');
    $canonicalizer = $read('app/services/yfth/HqAuthoritySourceCanonicalizer.php');
    $attribution = $read('app/services/yfth/HqCustomerAttributionServices.php');
    $referral = $read('app/services/yfth/HqActiveReferralServices.php');

    foreach (['yfth_permanent_membership_enrollment','yfth_permanent_membership','yfth_permanent_membership_event','yfth_business_dynamic_code','yfth_membership_reward_candidate'] as $table) {
        $assert(strpos($migration, "'{$table}'") !== false, 'migration_has_' . $table);
    }
    foreach (['uniq_yfth_pm_uid','uniq_yfth_pm_enrollment','uniq_yfth_business_code_hash','uniq_yfth_business_code_active','uniq_yfth_pm_candidate_key'] as $index) {
        $assert(strpos($migration, $index) !== false, 'migration_has_' . $index);
    }
    $assert(strpos($service, 'currentPackageAmountCents()') !== false, 'offline_amount_uses_current_published_package_rule_snapshot');
    $assert(strpos($service, 'public const AMOUNT_CENTS') === false, 'offline_amount_is_not_a_hardcoded_constant');
    $assert(strpos($service, "SCENE_CUSTOMER_IDENTITY = 'customer_identity'") !== false
        && strpos($service, "SCENE_MEMBERSHIP_CONFIRMATION = 'membership_confirmation'") === false,
        'only_customer_identity_code_scene_remains_writable');
    $assert(strpos($service, "hash('sha256', \$token)") !== false && strpos($service, "'token_hash' => \$this->hashToken(\$token)") !== false, 'dynamic_code_plaintext_is_not_persisted');
    $assert(strpos($service, 'membershipLockContext') !== false
        && strpos($service, 'closeForMembershipWithLockedCurrentsInTransaction') !== false,
        'activation_uses_stage1a_transaction_boundaries');
    $assert(strpos($membershipService, "'membership_activated'") !== false
        && strpos($service, 'consumePackageActivation') !== false,
        'activation_closes_referral_and_writes_unique_commission_event');
    $candidateStart = strpos($migration, 'private function createRewardCandidate');
    $candidateEnd = strpos($migration, 'private function preflightTables', $candidateStart);
    $candidate = substr($migration, $candidateStart, $candidateEnd - $candidateStart);
    foreach (["addColumn('amount", "addColumn('rate", "addColumn('sequence", "addColumn('commission", "addColumn('settlement"] as $forbidden) $assert(stripos($candidate, $forbidden) === false, 'candidate_has_no_' . preg_replace('/\W+/', '_', $forbidden));
    foreach (['target_uid', 'phone', 'mobile'] as $forbidden) $assert(strpos($user, "['{$forbidden}'") === false && strpos($store, "['{$forbidden}'") === false, 'client_cannot_submit_' . $forbidden);
    $assert(strpos($service, "['store_manager', 'store_staff']") !== false, 'store_membership_activation_roles_are_manager_and_staff_only');
    $assert(strpos($service, 'public function applyByCustomer') !== false
        && strpos($service, 'public function approveForStore') !== false
        && strpos($service, 'public function activateIdentityForStore') !== false,
        'offline_application_review_and_identity_scan_share_one_service');
    $assert(strpos($service, 'consumePackageActivation') !== false
        && strpos($service, 'closeForMembershipWithLockedCurrentsInTransaction') !== false,
        'offline_activation_freezes_reward_before_closing_referral');
    $assert(strpos($admin, 'assertApiAuthForAdmin') !== false && strpos($service, 'assertHeadquarterScope') !== false, 'admin_requires_api_permission_and_headquarter_scope');
    $assert(strpos($canonicalizer, 'PERMANENT_MEMBERSHIP_SOURCE') !== false && strpos($canonicalizer, 'permanent_membership_confirmation') !== false, 'single_stage2_canonical_source_is_frozen');
    $assert(strpos($attribution, 'assignFirstInTransaction') !== false && strpos($referral, 'closeForMembershipInTransaction') !== false, 'stage1a_exposes_controlled_transaction_methods');
    $assert(strpos($service, '$query = $query->where') !== false, 'stage2_filters_retain_thinkorm_query_objects');
    $assert(strpos($service, 'membershipLockContext') !== false
        && strpos($service, "(array)\$lockContext['locked_currents']") !== false
        && strpos($service, 'closeForMembershipWithLockedCurrentsInTransaction') !== false,
        'activation_prelocks_complete_numeric_uid_set');
    foreach (['confirmByCustomer', 'createForStore', 'createForAdmin', 'bindForStore', 'bindForAdmin', 'confirmPaymentForStore', 'confirmPaymentForAdmin', 'confirmationCodeForStore', 'confirmationCodeForAdmin'] as $legacyMethod) {
        $assert(strpos($service, 'function ' . $legacyMethod) === false, 'legacy_membership_write_method_removed_' . $legacyMethod);
    }
    foreach (['assertTableColumns','assertIndexSignature','assertPermissionSignature','migrationRecordExists','forward_repair_required','down_signature_ambiguous'] as $guard) {
        $assert(strpos($migration, $guard) !== false, 'migration_has_strict_guard_' . $guard);
    }
    foreach (['permanent_membership/identity_code','permanent_membership/apply','store_workbench/permanent_membership/:id/approve','store_workbench/permanent_membership/activate_identity',"Route::group('permanent_membership'"] as $route) $assert(strpos($routes, $route) !== false, 'route_exists_' . preg_replace('/\W+/', '_', $route));
    foreach (['permanent_membership/confirm', "Route::post('enrollment'", "Route::post('enrollment/:id/bind'", "Route::post('enrollment/:id/payment'", "Route::post('enrollment/:id/confirmation_code'"] as $legacyRoute) {
        $assert(strpos($routes, $legacyRoute) === false, 'legacy_write_route_removed_' . preg_replace('/\W+/', '_', $legacyRoute));
    }
    foreach (['template/admin/src/pages/yfth/permanentMembership/index.vue','template/uni-app/pages/yfth/permanent_membership/index.vue','template/uni-app/pages/yfth/workbench/permanent_membership/index.vue'] as $file) $assert(is_file(dirname($root) . '/' . $file), 'frontend_exists_' . basename($file));
} catch (Throwable $e) { $failures[] = 'contract_exception:' . $e->getMessage(); }

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n"); exit(1); }
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH permanent membership Stage 2 contract verified.\n";
