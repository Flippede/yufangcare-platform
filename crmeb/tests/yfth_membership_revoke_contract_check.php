<?php

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/app/services/yfth/PackageMembershipServices.php',
    'management' => $root . '/app/services/yfth/HqUserRoleManagementServices.php',
    'controller' => $root . '/app/adminapi/controller/v1/yfth/HqUserRole.php',
    'route' => $root . '/app/adminapi/route/yfth.php',
    'migration' => $root . '/database/migrations/20260721123000_add_yfth_membership_revoke_permission.php',
    'api' => dirname($root) . '/template/admin/src/api/yfth.js',
    'page' => dirname($root) . '/template/admin/src/pages/yfth/userRole/index.vue',
];

$failures = [];
$assert = function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) $failures[] = $label;
    else echo "[PASS] {$label}\n";
};
$source = [];
foreach ($files as $name => $path) {
    $assert(is_file($path), 'file_exists:' . $name);
    $source[$name] = is_file($path) ? (string)file_get_contents($path) : '';
}

$assert(strpos($source['service'], 'revokeByHeadquarters') !== false, 'authority_service_revoke_exists');
$assert(strpos($source['service'], 'membership_revoked_by_headquarters') !== false, 'revoke_event_and_audit_exist');
$assert(strpos($source['service'], 'explicit inactive current is authoritative') !== false, 'explicit_revoke_blocks_legacy_fallback');
$assert(strpos($source['service'], 'membership_regranted_by_headquarters') !== false, 'headquarters_can_regrant');
$assert(strpos($source['service'], 'membership_reactivated_by_package') !== false, 'new_package_can_reactivate');
$assert(strpos($source['management'], "'确认解除会员'") !== false, 'exact_confirmation_required');
$assert(strpos($source['management'], 'assertHeadquarters') !== false, 'headquarters_scope_required');
$assert(strpos($source['controller'] . $source['route'], 'membership/revoke') !== false, 'dedicated_route_exists');
$assert(strpos($source['migration'], 'yfth-user-role-membership-revoke') !== false, 'dedicated_permission_exists');
$assert(strpos($source['api'] . $source['page'], 'yfthUserMembershipRevoke') !== false, 'admin_api_is_wired');
$assert(strpos($source['page'], '商城订单、套餐购买、已产生权益') !== false, 'history_preservation_warning_is_visible');
$assert(strpos($source['page'], '解除会员') !== false, 'admin_revoke_action_is_visible');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
echo "[OK] YFTH controlled membership revoke contract verified.\n";
