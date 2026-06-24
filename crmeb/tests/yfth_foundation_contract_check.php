<?php

$root = dirname(__DIR__);
$failures = [];

$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    return is_file($full) ? file_get_contents($full) : '';
};

$assertContains = function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) === false) {
        $failures[] = $message;
    }
};

$assertNotContains = function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) !== false) {
        $failures[] = $message;
    }
};

$migration = $read('database/migrations/20260624090000_create_yfth_foundation_tables.php');
$menuMigration = $read('database/migrations/20260624090010_seed_yfth_foundation_menus.php');
$writeOff = $read('app/services/order/StoreOrderWriteOffServices.php');
$staff = $read('app/services/system/store/SystemStoreStaffServices.php');
$capability = $read('app/services/yfth/StoreCapabilityServices.php');
$paymentRoute = $read('app/services/yfth/StorePaymentRouteServices.php');

foreach ([
    'yfth_user_identity',
    'yfth_user_store_role',
    'yfth_business_subject',
    'yfth_store_subject',
    'yfth_store_qualification',
    'yfth_store_capability',
    'yfth_store_payment_route',
    'yfth_audit_event',
    'yfth_idempotency_record',
] as $table) {
    $assertContains($migration, $table, "Missing migration table: {$table}");
}

foreach ([
    'uniq_yfth_user_identity_active',
    'uniq_yfth_store_role_active',
    'uniq_yfth_cap_active',
    'uniq_yfth_idem_key',
] as $index) {
    $assertContains($migration, $index, "Missing unique/index contract: {$index}");
}

foreach (["addColumn('secret'", "addColumn('private_key'", "addColumn('api_key'", "addColumn('cert'"] as $forbidden) {
    $assertNotContains($migration, $forbidden, "Payment route migration must not add secret material: {$forbidden}");
}

$assertContains($menuMigration, 'yfth-foundation-index', 'Missing admin menu permission seed');
$assertContains($writeOff, 'assertWriteOffStoreAccess', 'Writeoff must use store-scoped staff assertion');
$assertNotContains($writeOff, '$orderInfo->store_id = $staffInfo->store_id', 'Writeoff must not rewrite order store_id');
$assertContains($writeOff, 'is_repeat_writeoff', 'Repeated writeoff must return idempotent marker');
$assertContains($writeOff, 'storeProductOrderUserTakeDelivery', 'Normal writeoff fulfilment call must remain present');
$assertContains($staff, 'assertWriteOffStoreAccess', 'Store staff service must expose writeoff store assertion');
$assertContains($capability, 'isQualificationActive', 'Capability check must depend on source qualification state');
$assertContains($paymentRoute, 'unset($row[\'secret\']', 'Payment route output must strip secret-like fields');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

echo "[OK] YFTH foundation contracts verified.\n";
