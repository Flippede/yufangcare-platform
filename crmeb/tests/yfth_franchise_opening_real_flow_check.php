<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];

$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

$service = $read('app/services/yfth/FranchiseOpeningServices.php');
$migration = $read('database/migrations/20260709100000_create_yfth_franchise_opening_tables.php');

$guards = [
    'pending_contract_before_contract' => 'franchise_contract_application_status_invalid',
    'contract_signed_before_payment' => 'franchise_payment_contract_not_signed',
    'payment_confirmed_before_preparing' => 'advanceApplication((int)$before[\'application_id\'], \'preparing\')',
    'tasks_approved_before_acceptance' => 'franchise_required_tasks_not_approved',
    'acceptance_passed_before_grant' => 'franchise_identity_acceptance_not_passed',
    'store_bound_before_grant' => 'franchise_identity_store_not_bound',
    'store_role_written' => 'UserStoreRoleServices::class',
    'specific_store_role' => "'store_id' => \$storeId",
    'capabilities_enabled_after_grant' => 'enableOpeningCapabilities',
    'no_global_franchisee' => 'global franchisee',
    'no_crmeb_order_write' => "Db::name('store_order')",
    'no_inventory_mutation' => "YfthInventoryBalanceDao",
    'audit_exists' => 'AuditEventServices::class',
];

foreach ($guards as $label => $needle) {
    if (strpos($label, 'no_') === 0) {
        $assert(strpos($service, $needle) === false, $label);
    } else {
        $assert(strpos($service, $needle) !== false, $label);
    }
}

foreach ([
    'yfth_franchise_contract',
    'yfth_franchise_payment_proof',
    'yfth_franchise_store_profile',
    'yfth_franchise_preparation_task',
    'yfth_store_opening_acceptance',
    'yfth_franchise_identity_grant',
] as $table) {
    $assert(strpos($migration, $table) !== false, 'migration_has_' . $table);
}

$transitionOrder = [
    'pending_contract' => strpos($service, "'pending_contract' => ['signed']"),
    'signed' => strpos($service, "'signed' => ['preparing']"),
    'preparing' => strpos($service, "'preparing' => ['opened']"),
];
$assert($transitionOrder['pending_contract'] !== false && $transitionOrder['signed'] !== false && $transitionOrder['preparing'] !== false, 'application_opening_transition_chain_present');

if ($failures) {
    echo "YFTH franchise opening real-flow source guard failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH franchise opening real-flow source guard passed with ' . count($passes) . " assertions.\n";
