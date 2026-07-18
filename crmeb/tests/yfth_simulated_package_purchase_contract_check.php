<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$failures = [];
$read = function (string $path) use ($root, $projectRoot): string {
    $local = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($local)) {
        return file_get_contents($local);
    }
    $project = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    return is_file($project) ? file_get_contents($project) : '';
};
$contains = function (string $source, string $needle, string $label) use (&$failures): void {
    if (strpos($source, $needle) === false) {
        $failures[] = $label;
    }
};
$notContains = function (string $source, string $needle, string $label) use (&$failures): void {
    if (strpos($source, $needle) !== false) {
        $failures[] = $label;
    }
};

$service = $read('app/services/yfth/SimulatedPackagePurchaseServices.php');
$migration = $read('database/migrations/20260719160000_enable_yfth_simulated_package_purchase.php');
$routes = $read('app/api/route/v1.php');
$controller = $read('app/api/controller/v1/yfth/PackageBenefitController.php');
$mobileApi = $read('template/uni-app/api/yfth.js');
$detail = $read('template/uni-app/pages/yfth/package/detail.vue');
$payment = $read('template/uni-app/pages/yfth/package/payment_confirm.vue');

foreach ([
    'simulated_package_purchase_enabled',
    "YFTH-TEST-PACKAGE-V1",
    "[YFTH-ACCEPTANCE-TEST-V1]",
    "SIMULATION_PRICE = '0.10'",
    'resolveAuthoritativeStoreForPurchase',
    'PackageMembershipActivationCoordinator::class',
    "'order_id' => 0",
    "'order_unique_key' => null",
    "'source' => self::SOURCE",
    "'payment_scene' => 'simulated_acceptance'",
    "'real_payment_created' => false",
    "'activation_status' => 'succeeded'",
] as $needle) {
    $contains($service, $needle, 'service_missing_' . preg_replace('/[^a-z0-9]+/i', '_', $needle));
}

foreach ([
    'uniq_yfth_pkg_instance_order_key',
    'idx_yfth_pkg_instance_order',
    "WHERE `order_id` > 0",
    "YFTH-TEST-PACKAGE-RULE-SIM-010-V1",
    "'0.10'",
    'grants_permanent_membership',
] as $needle) {
    $contains($migration, $needle, 'migration_missing_' . preg_replace('/[^a-z0-9]+/i', '_', $needle));
}

$contains($routes, 'yfth/package/simulation_context/:id', 'simulation_context_route_missing');
$contains($routes, 'yfth/package/simulate', 'simulation_purchase_route_missing');
$contains($controller, 'SimulatedPackagePurchaseServices', 'simulation_controller_service_missing');
$contains($mobileApi, 'getYfthPackageSimulationContext', 'mobile_simulation_context_api_missing');
$contains($mobileApi, 'simulateYfthPackagePurchase', 'mobile_simulation_purchase_api_missing');
$contains($detail, '上级商家', 'detail_authoritative_store_display_missing');
$contains($detail, 'context.store.store_id', 'detail_must_use_server_store_missing');
$contains($payment, '确认0.1元模拟购买', 'payment_simulation_action_missing');
$contains($payment, 'v-if="!simulation"', 'real_payment_component_must_be_hidden_for_simulation');
$notContains($service, 'StoreOrderCreateServices', 'simulation_must_not_create_crmeb_order');
$notContains($service, "Db::name('store_order')->insert", 'simulation_must_not_insert_crmeb_order');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

echo "[OK] YFTH simulated package purchase contracts verified.\n";
