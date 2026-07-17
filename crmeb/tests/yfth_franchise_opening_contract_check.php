<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$failures = [];
$passes = [];

$read = function (string $path) use ($root, $projectRoot): string {
    $full = strpos($path, '../') === 0
        ? $projectRoot . DIRECTORY_SEPARATOR . substr($path, 3)
        : $root . DIRECTORY_SEPARATOR . $path;
    $full = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $full);
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

$contains = function (string $text, string $needle): bool {
    return strpos($text, $needle) !== false;
};

$files = [
    'database/migrations/20260709100000_create_yfth_franchise_opening_tables.php',
    'app/services/yfth/FranchiseOpeningServices.php',
    'app/api/controller/v1/yfth/FranchiseOpeningController.php',
    'app/adminapi/controller/v1/yfth/FranchiseOpening.php',
    'app/api/route/yfth_service.php',
    'app/adminapi/route/yfth.php',
    '../template/admin/src/pages/yfth/franchiseOpening/index.vue',
    '../template/uni-app/pages/yfth/franchise/opening/index.vue',
    '../template/uni-app/pages/yfth/franchise/opening/contract.vue',
    '../template/uni-app/pages/yfth/franchise/opening/payment.vue',
    '../template/uni-app/pages/yfth/franchise/opening/tasks.vue',
    '../template/uni-app/pages/yfth/franchise/opening/acceptance.vue',
];
foreach ($files as $file) {
    $assert(is_file(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, strpos($file, '../') === 0 ? $projectRoot . DIRECTORY_SEPARATOR . substr($file, 3) : $root . DIRECTORY_SEPARATOR . $file)), 'file_exists:' . $file);
}

$migration = $read('database/migrations/20260709100000_create_yfth_franchise_opening_tables.php');
foreach ([
    'yfth_franchise_contract',
    'yfth_franchise_payment_proof',
    'yfth_franchise_store_profile',
    'yfth_franchise_preparation_task',
    'yfth_franchise_preparation_task_record',
    'yfth_store_opening_acceptance',
    'yfth_store_opening_acceptance_item',
    'yfth_franchise_identity_grant',
    'uniq_yfth_franchise_contract_no',
    'uniq_yfth_franchise_contract_app',
    'uniq_yfth_preparation_app_task',
    'uniq_yfth_acceptance_app',
    'uniq_yfth_acceptance_item_code',
    'uniq_yfth_identity_grant_active',
    'yfth-franchise-opening-index',
    "'auth_type' => 2",
    'DELETE FROM `',
] as $needle) {
    $assert($contains($migration, $needle), 'migration_contains:' . $needle);
}

$service = $read('app/services/yfth/FranchiseOpeningServices.php');
foreach ([
    'private const DOMAIN = \'yfth_franchise_opening\'',
    'pending_contract',
    'userConfirmContract',
    'adminConfirmContract',
    'adminConfirmPayment',
    'adminCreateAndBindStore',
    'ensurePreparationTasks',
    'userSubmitTask',
    'validateFirstPurchaseTask',
    'userAcceptance',
    'userSubmitAcceptance',
    'adminReviewAcceptance',
    'adminGrantIdentity',
    'pendingAcceptanceDto',
    'ensureAcceptanceForSubmit',
    'assertAcceptanceSubmitReady',
    'assertAcceptancePassReady',
    'expectedRequiredTaskCodes',
    'requiredTasksGeneratedForApplication',
    'allRequiredTasksApprovedStrict',
    'activateStoreRoleGrant',
    'UserStoreRoleServices::class',
    'YfthStoreCapabilityDao::class',
    'source_authorization\' => \'franchise_opening\'',
    'StoreAccessServices::class',
    'AdminStoreContextServices::class',
    'assertHeadquarterScope',
    'AuditEventServices::class',
    'recordSafely',
    'yfth_purchase_order',
    'stocked',
    'franchise_opening_user_field_forbidden',
    'opening_status_change',
    'identity_grant',
    'store_purchase',
    'retail_sale',
    'package_sale',
    'reservation_service',
    'order_writeoff',
] as $needle) {
    $assert($contains($service, $needle), 'service_contains:' . $needle);
}

foreach ([
    'store_order',
    'decStockIncSales',
    'incStockDecSales',
    'user_brokerage',
    'user_bill',
    'spread',
    'createSystemStore',
    'global franchisee',
] as $needle) {
    $assert(!$contains($service, $needle), 'service_not_contains:' . $needle);
}

$assert($contains($service, "'pending_contract' => ['signed']"), 'application_transition_pending_contract_to_signed');
$assert($contains($service, "'signed' => ['preparing']"), 'application_transition_signed_to_preparing');
$assert($contains($service, "'preparing' => ['opened']"), 'application_transition_preparing_to_opened');
$assert($contains($service, "if ((string)\$contract['status'] !== 'signed')"), 'payment_requires_signed_contract');
$assert($contains($service, "\$acceptance = \$this->latestAcceptance((int)\$application['id']);"), 'user_acceptance_reads_existing_only');
$assert($contains($service, "return ['acceptance' => \$this->pendingAcceptanceDto((int)\$application['id'])];"), 'user_acceptance_missing_returns_safe_dto');
$assert(!$contains($service, "userAcceptance(Request \$request): array\n    {\n        \$uid = \$this->requestUid(\$request);\n        \$application = \$this->requireLatestOpeningApplication(\$uid);\n        return ['acceptance' => \$this->formatAcceptance(\$this->ensureAcceptance"), 'user_acceptance_does_not_ensure_acceptance');
$assert(!$contains($service, "\$this->ensureAcceptance((int)\$before['application_id']);"), 'payment_confirm_does_not_create_acceptance');
$assert($contains($service, "\$gate = \$this->assertAcceptanceSubmitReady((int)\$application['id']);"), 'acceptance_submit_uses_strict_gate');
$assert($contains($service, "\$this->assertAcceptancePassReady((int)\$before['application_id']);"), 'acceptance_pass_uses_strict_gate');
$assert($contains($service, "if (count(\$rows) !== count(\$expectedCodes))"), 'required_task_missing_count_fails');
$assert($contains($service, "isset(\$seen[\$code])"), 'required_task_duplicate_fails');
$assert($contains($service, "(string)(\$row['status'] ?? '') !== 'approved'"), 'required_task_unapproved_fails');
$assert($contains($service, "\$this->validateFirstPurchaseTask(\$row, []);"), 'first_purchase_approved_readonly_revalidated');
$assert($contains($service, "'franchise_acceptance_application_not_preparing'"), 'acceptance_requires_preparing_application');
$assert($contains($service, "'franchise_acceptance_payment_not_confirmed'"), 'acceptance_requires_finance_payment');
$assert($contains($service, "'franchise_acceptance_store_profile_not_verified'"), 'acceptance_pass_requires_verified_profile');
$assert($contains($service, "'franchise_store_create_acceptance_not_passed'"), 'formal_store_creation_requires_passed_acceptance');
$assert($contains($service, "Db::name('yfth_franchise_store_profile')->where('id', \$profileId)->lock(true)->find()"), 'formal_store_creation_locks_profile');
$assert($contains($service, "StoreAccessServices::class)->assertStoreActive((int)\$profile['system_store_id'])"), 'formal_store_creation_is_idempotent_for_active_store');
$assert($contains($service, "if (!\$this->allRequiredTasksApprovedStrict"), 'acceptance_and_grant_require_approved_tasks');
$assert($contains($service, "(string)\$acceptance['status'] !== 'passed'"), 'grant_requires_passed_acceptance');
$assert($contains($service, "\$storeId <= 0"), 'grant_requires_concrete_store_id');

$apiController = $read('app/api/controller/v1/yfth/FranchiseOpeningController.php');
foreach (['uid', 'applicant_uid', 'status', 'store_id', 'system_store_id', 'finance_uid', 'verified_uid', 'reviewer_uid', 'grant_uid'] as $field) {
    $assert($contains($apiController, "'" . $field . "'"), 'api_controller_checks_forbidden:' . $field);
}

$adminController = $read('app/adminapi/controller/v1/yfth/FranchiseOpening.php');
foreach ([
    'assertApiAuthForAdmin',
    'yfth/franchise_opening/contract',
    'yfth/franchise_opening/payment/<id>/confirm',
    'yfth/franchise_opening/profile/<id>/bind_store',
    'yfth/franchise_opening/profile/<id>/create_store',
    'yfth/franchise_opening/task/<id>/review',
    'yfth/franchise_opening/acceptance/<id>/review',
    'yfth/franchise_opening/identity_grant',
] as $needle) {
    $assert($contains($adminController, $needle), 'admin_controller_contains:' . $needle);
}

$apiRoute = $read('app/api/route/yfth_service.php');
foreach ([
    'yfth/franchise/opening/my',
    'yfth/franchise/opening/contract/:id/confirm',
    'yfth/franchise/opening/payment/:id/proof',
    'yfth/franchise/opening/tasks/:id/submit',
    'yfth/franchise/opening/acceptance/submit',
    'AuthTokenMiddleware::class',
] as $needle) {
    $assert($contains($apiRoute, $needle), 'api_route_contains:' . $needle);
}

$adminRoute = $read('app/adminapi/route/yfth.php');
foreach ([
    "Route::group('franchise_opening'",
    'AdminAuthTokenMiddleware::class',
    'AdminCheckRoleMiddleware::class',
    'FranchiseOpening/identityGrant',
    'FranchiseOpening/profileCreateStore',
] as $needle) {
    $assert($contains($adminRoute, $needle), 'admin_route_contains:' . $needle);
}

$adminApi = $read('../template/admin/src/api/yfth.js');
$uniApi = $read('../template/uni-app/api/yfth.js');
foreach ([
    'yfthFranchiseOpeningContractList',
    'yfthFranchiseOpeningPaymentConfirm',
    'yfthFranchiseOpeningIdentityGrant',
] as $needle) {
    $assert($contains($adminApi, $needle), 'admin_api_contains:' . $needle);
}
foreach ([
    'getYfthFranchiseOpening',
    'confirmYfthFranchiseOpeningContract',
    'uploadYfthFranchisePaymentProof',
    'submitYfthFranchiseOpeningTask',
    'submitYfthFranchiseOpeningAcceptance',
] as $needle) {
    $assert($contains($uniApi, $needle), 'uni_api_contains:' . $needle);
}

if ($failures) {
    echo "YFTH franchise opening contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH franchise opening contract check passed with ' . count($passes) . " assertions.\n";
