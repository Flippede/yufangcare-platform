<?php

$root = dirname(__DIR__);
$repo = dirname($root);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};
$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

try {
    $service = $read('app/services/yfth/RelationshipManagementServices.php');
    $controller = $read('app/adminapi/controller/v1/yfth/RelationshipManagement.php');
    $routes = $read('app/adminapi/route/yfth.php');
    $attribution = $read('app/services/yfth/HqCustomerAttributionServices.php');
    $referral = $read('app/services/yfth/HqActiveReferralServices.php');
    $membership = $read('app/services/yfth/PackageMembershipServices.php');
    $canonicalizer = $read('app/services/yfth/HqAuthoritySourceCanonicalizer.php');
    $membershipReferral = $read('app/services/yfth/PackageMembershipReferralServices.php');
    $userRoutes = $read('app/api/route/yfth_service.php');
    $migration = $read('database/migrations/20260718100000_add_yfth_relationship_management_permissions.php');
    $adminPage = (string)file_get_contents($repo . '/template/admin/src/pages/yfth/hqAuthority/index.vue');
    $adminApi = (string)file_get_contents($repo . '/template/admin/src/api/yfth.js');
    $membershipPage = (string)file_get_contents($repo . '/template/uni-app/pages/yfth/package_membership/index.vue');
    $storeBindingPage = (string)file_get_contents($repo . '/template/uni-app/pages/yfth/store_binding/index.vue');
    $uniApi = (string)file_get_contents($repo . '/template/uni-app/api/yfth.js');

    foreach (['userHierarchy', 'storeHierarchy', 'revokeParent'] as $method) {
        $assert(strpos($controller, 'function ' . $method . '(') !== false, 'controller_method:' . $method);
    }
    $assert(strpos($controller, 'assertApiAuthForAdmin') !== false, 'controller_checks_explicit_permissions');
    foreach ([
        "Route::get('user_hierarchy'",
        "Route::get('store_hierarchy'",
        "Route::post('user/:id/revoke_parent'",
    ] as $route) {
        $assert(strpos($routes, $route) !== false, 'route_exists:' . $route);
    }

    foreach (['yfth_hq_customer_attribution_current', 'yfth_hq_active_referral_current', 'yfth_permanent_membership'] as $table) {
        $assert(strpos($service, $table) !== false, 'hierarchy_uses_authority:' . $table);
    }
    $assert(strpos($service, "where('role_code', 'store_manager')") !== false, 'store_hierarchy_uses_active_manager_grants');
    $assert(strpos($service, 'relationship_revoke_has_active_children') !== false, 'parent_revoke_is_bottom_up');
    $assert(strpos($service, 'invalidateWithLockedCurrentsInTransaction') !== false
        && strpos($service, 'unassignForRebindingWithLockedCurrentInTransaction') !== false,
        'revoke_composes_one_atomic_transaction');
    $assert(strpos($service, '$this->referral->invalidate(') === false
        && strpos($service, '$this->attribution->unassignForRebinding(') === false,
        'revoke_does_not_nest_idempotency_runners');
    $assert(strpos($service, "Db::name('system_store')->where('is_del', 0)") !== false, 'store_list_includes_all_non_deleted_stores');
    $assert(strpos($service, 'revokeStore') === false && strpos($controller, 'revokeStore') === false, 'store_revoke_not_implemented');

    foreach (['headquarters_parent_revoked', 'attribution_unassigned_for_rebinding', 'attribution_rebound'] as $needle) {
        $assert(strpos($attribution, $needle) !== false, 'rebind_state_machine:' . $needle);
    }
    $assert(strpos($referral, 'invalidateWithLockedCurrentsInTransaction') !== false, 'referral_can_join_outer_transaction');
    $assert(strpos($membership, 'applyCurrentBindingStore') !== false
        && strpos($membership, "'binding_status' =>") !== false,
        'membership_projects_current_binding_without_rewriting_membership_origin');
    $assert(strpos($canonicalizer, "'store_qr_binding'") !== false, 'store_qr_rebinding_source_is_trusted');
    $assert(strpos($membershipReferral, 'function bindStoreFromQr(') !== false
        && strpos($membershipReferral, "HqAuthoritySource::fromTrusted('store_qr_binding'") !== false,
        'store_qr_binding_calls_authoritative_assignment');
    $assert(strpos($userRoutes, "Route::post('yfth/package_membership/store_qr_bind'") !== false
        && strpos($uniApi, 'function bindYfthStoreFromQr(') !== false
        && strpos($storeBindingPage, 'bindYfthStoreFromQr') !== false,
        'store_qr_binding_has_user_route_and_scan_landing_page');

    foreach ([
        'yfth-hq-relationship-user-hierarchy',
        'yfth-hq-relationship-store-hierarchy',
        'yfth-hq-relationship-parent-revoke',
    ] as $auth) {
        $assert(strpos($migration, $auth) !== false, 'permission_exists:' . $auth);
    }
    $assert(strpos($migration, "'POST', self::AUTHS[2]") !== false, 'only_parent_revoke_permission_is_post');

    foreach (['yfthRelationshipUserHierarchy', 'yfthRelationshipStoreHierarchy', 'yfthRelationshipRevokeParent'] as $function) {
        $assert(strpos($adminApi, 'function ' . $function . '(') !== false, 'admin_api:' . $function);
        $assert(strpos($adminPage, $function) !== false, 'admin_page_uses:' . $function);
    }
    foreach (['C2 → C1 → 门店', '用户关系层级', '门店与合伙人', '撤销上级'] as $label) {
        $assert(strpos($adminPage, $label) !== false, 'admin_hierarchy_ui:' . $label);
    }
    $assert(strpos($adminPage, '>撤销门店<') === false
        && strpos($adminApi, 'RevokeStore') === false,
        'admin_page_has_no_store_revoke_action');
    $assert(strpos($membershipPage, '当前未绑定门店，可重新扫门店码绑定') !== false
        && strpos($membershipPage, 'isMember && isBound') !== false,
        'unbound_member_ui_requires_rebind_before_inviting');
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
echo '[OK] YFTH headquarters relationship management contract verified.' . PHP_EOL;
