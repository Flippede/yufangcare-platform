<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$failures = [];
$read = function (string $path) use ($root, $projectRoot): string {
    $local = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($local)) return (string)file_get_contents($local);
    $project = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    return is_file($project) ? (string)file_get_contents($project) : '';
};
$contains = function (string $source, string $needle, string $label) use (&$failures): void {
    if (strpos($source, $needle) === false) $failures[] = $label;
};
$notContains = function (string $source, string $needle, string $label) use (&$failures): void {
    if (strpos($source, $needle) !== false) $failures[] = $label;
};

$migration = $read('database/migrations/20260721100000_promote_yfth_member_package_9800.php');
$virtualRepairMigration = $read('database/migrations/20260722110000_repair_yfth_member_package_virtual_checkout.php');
$templateService = $read('app/services/yfth/PackageTemplateServices.php');
$routes = $read('app/api/route/v1.php');
$detail = $read('template/uni-app/pages/yfth/package/detail.vue');
$membership = $read('template/uni-app/pages/yfth/package_membership/index.vue');
$payment = $read('template/uni-app/pages/yfth/package/payment_confirm.vue');
$finance = $read('app/services/yfth/CommissionFinanceServices.php');

foreach (['YFTH-MEMBER-PACKAGE-V1', "PRICE = '9800.00'", 'YFTHPKG9800',
          'real_payment_required', 'package_reward_ratios_bps', 'ensureBinding'] as $needle) {
    $contains($migration, $needle, 'formal_package_migration_missing_' . $needle);
}
foreach (['syncManagedMemberPackageBinding', 'MANAGED_MEMBER_PRODUCT_BARCODE',
          'sku_price_snapshot', 'product_snapshot', "'virtual_type' => 1",
          'package_product_must_be_virtual'] as $needle) {
    $contains($templateService, $needle, 'admin_price_sync_missing_' . $needle);
}
foreach (['YFTHPKG9800', '`virtual_type` = 1', '`is_virtual` = 1'] as $needle) {
    $contains($virtualRepairMigration, $needle, 'virtual_checkout_repair_missing_' . $needle);
}
$contains($detail, '已是会员也可以再次购买', 'detail_repeat_purchase_message_missing');
$contains($membership, '再次购买康养套餐', 'membership_repeat_purchase_entry_missing');
$contains($payment, 'createYfthPackageIntent', 'real_purchase_intent_missing');
$contains($payment, 'createYfthPackageOrder', 'real_crmeb_order_missing');
$contains($payment, '确认并支付', 'real_payment_action_missing');
$contains($finance, 'recent_package_rewards', 'c1_package_reward_summary_missing');
$contains($finance, 'ratio_percent', 'c1_reward_ratio_missing');
$notContains($routes, 'yfth/package/simulate', 'simulation_route_must_be_closed');
$notContains($payment, 'simulateYfthPackagePurchase', 'simulation_purchase_must_not_be_used');
$notContains($membership, 'v-if="!isMember" class="panel purchase-panel"', 'member_purchase_must_not_be_hidden');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
echo "[OK] YFTH formal repeatable member package contracts verified.\n";
