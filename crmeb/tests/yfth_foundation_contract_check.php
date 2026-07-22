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
$constants = $read('app/services/yfth/YfthConstants.php');
$context = $read('app/services/yfth/CurrentBusinessContextServices.php');
$storeRole = $read('app/services/yfth/UserStoreRoleServices.php');
$storeSubject = $read('app/services/yfth/StoreSubjectServices.php');
$capability = $read('app/services/yfth/StoreCapabilityServices.php');
$paymentRoute = $read('app/services/yfth/StorePaymentRouteServices.php');
$idempotency = $read('app/services/yfth/IdempotencyRecordServices.php');
$base = $read('app/services/yfth/YfthFoundationBaseServices.php');
$subject = $read('app/services/yfth/BusinessSubjectServices.php');
$audit = $read('app/services/yfth/AuditEventServices.php');
$controller = $read('app/adminapi/controller/v1/yfth/Foundation.php');

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
    'uniq_yfth_store_subject_active',
    'uniq_yfth_cap_active',
    'uniq_yfth_pay_route_active',
    'uniq_yfth_idem_key',
] as $index) {
    $assertContains($migration, $index, "Missing unique/index contract: {$index}");
}

foreach (["addColumn('secret'", "addColumn('private_key'", "addColumn('api_key'", "addColumn('cert'"] as $forbidden) {
    $assertNotContains($migration, $forbidden, "Payment route migration must not add secret material: {$forbidden}");
}

$assertContains($menuMigration, 'yfth-foundation-index', 'Missing admin menu permission seed');
$assertContains($menuMigration, 'upsertMenu', 'Menu seed must be idempotent');
$assertContains($menuMigration, "'pid' => \$rootId", 'Foundation page must be parented under YFTH root');
$assertContains($menuMigration, "'pid' => \$pid", 'API permissions must be parented under the page menu');
$assertContains($constants, "return ['store_manager', 'store_staff'];", 'only_manager_and_staff_are_store_scoped_roles');
$assertContains($constants, "'payment' =>", 'Store subject roles must include payment');
$assertContains($constants, "'fulfillment' =>", 'Store subject roles must include fulfillment');
$assertContains($constants, "'headquarter_purchase' =>", 'Payment scenes must include HQ procurement');
$assertContains($context, "'store_id' => 0", 'Non-store roles must not trust client store_id');
$assertContains($context, 'assertStoreRole($uid, $storeId, $roleCode)', 'Store context must assert server-side store role');
$assertContains($context, 'assertStoreActive', 'Store context must assert active store state');
$assertContains($storeRole, 'assertStoreActive', 'Store role assertion must verify active store state');
$assertContains($storeSubject, "activeKey([\$data['store_id'], \$data['subject_role']]", 'Store subject active key must be store_id + subject_role only');
$assertContains($storeSubject, 'disableStoreSubject', 'Store subject service must disable instead of physical delete');
$assertContains($writeOff, 'assertWriteOffStoreAccess', 'Writeoff must use store-scoped staff assertion');
$assertNotContains($writeOff, '$orderInfo->store_id = $staffInfo->store_id', 'Writeoff must not rewrite order store_id');
$assertContains($writeOff, 'is_repeat_writeoff', 'Repeated writeoff must return idempotent marker');
$assertContains($writeOff, 'storeProductOrderUserTakeDelivery', 'Normal writeoff fulfilment call must remain present');
$assertContains($writeOff, 'lock(true)', 'Writeoff confirmation must lock the order row');
$assertNotContains($writeOff, "'verify_code' =>", 'Writeoff audit must not store full verify_code');
$assertContains($writeOff, 'verify_code_hash', 'Writeoff audit must store verify_code hash');
$assertContains($writeOff, 'Log::error', 'Writeoff audit failure must be logged');
$assertContains($staff, 'assertWriteOffStoreAccess', 'Store staff service must expose writeoff store assertion');
$assertContains($capability, 'isQualificationActive', 'Capability check must depend on source qualification state');
$assertContains($paymentRoute, 'unset($row[\'secret\']', 'Payment route output must strip secret-like fields');
$assertContains($paymentRoute, "activeKey([\$data['store_id'], \$data['business_scene']]", 'Payment route active key must be store_id + business_scene');
$assertContains($paymentRoute, 'payment_route_conflict', 'resolveRoute must fail when historical duplicates are active');
$assertContains($paymentRoute, 'snapshot_requirement', 'Resolved payment route must advertise order snapshot requirement');
$assertContains($idempotency, '$this->dao->save($data)', 'Idempotency begin must insert first');
$assertContains($idempotency, 'isUniqueConflict', 'Idempotency begin must handle unique conflicts');
$assertContains($idempotency, 'idempotency_key_payload_mismatch', 'Idempotency key must reject changed payloads');
$assertContains($idempotency, 'can_retry', 'Idempotency failed status must expose retry rule');
$assertContains($base, 'verify_code', 'Audit sanitizer must include verify code masking');
$assertContains($base, 'credit_code', 'Audit sanitizer must include credit code masking');
$assertContains($subject, 'credit_code_masked', 'Subject list must expose masked credit code');
$assertContains($subject, "\$row['credit_code'] = ''", 'Subject list must not expose full credit code by default');
$assertContains($audit, 'recordSafely', 'Audit service must expose safe audit recording');
$assertContains($audit, 'Log::error', 'Audit failure must be logged');
$assertContains($controller, 'storeSubjectSave', 'Admin controller must expose store subject save');
$assertContains($controller, 'paymentRouteSave', 'Admin controller must expose payment route save');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

echo "[OK] YFTH foundation contracts verified.\n";
