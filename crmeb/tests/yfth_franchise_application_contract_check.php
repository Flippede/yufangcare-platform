<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$failures = [];
$passes = [];

$assert = function ($condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $message;
        return;
    }
    $failures[] = $message;
};

$read = function (string $path) use ($root): string {
    return (string)file_get_contents($root . DIRECTORY_SEPARATOR . $path);
};

foreach ([
    'database/migrations/20260708110000_create_yfth_franchise_application_tables.php',
    'app/model/yfth/YfthFranchiseApplication.php',
    'app/model/yfth/YfthFranchiseFollowRecord.php',
    'app/dao/yfth/YfthFranchiseApplicationDao.php',
    'app/dao/yfth/YfthFranchiseFollowRecordDao.php',
    'app/services/yfth/FranchiseApplicationServices.php',
    'app/api/controller/v1/yfth/FranchiseApplicationController.php',
    'app/adminapi/controller/v1/yfth/FranchiseApplication.php',
    'app/api/route/yfth_service.php',
    'app/adminapi/route/yfth.php',
    'tests/yfth_franchise_application_contract_check.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$migration = $read('database/migrations/20260708110000_create_yfth_franchise_application_tables.php');
foreach ([
    'yfth_franchise_application',
    'yfth_franchise_follow_record',
    'application_no',
    'applicant_uid',
    'assigned_uid',
    'intention_area',
    'pending_contract',
    'uniq_yfth_franchise_app_no',
    'idx_yfth_franchise_app_user_status',
    'idx_yfth_franchise_app_owner_status',
    'idx_yfth_franchise_follow_app_time',
    'yfth-franchise-application-index',
    'yfth/franchise_application/application/<id>/status',
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}

$service = $read('app/services/yfth/FranchiseApplicationServices.php');
foreach ([
    'FranchiseApplicationServices',
    'YfthFranchiseApplicationDao',
    'YfthFranchiseFollowRecordDao',
    'private const DOMAIN = \'yfth_franchise_application\'',
    'private const USER_SOURCE = \'miniapp_cooperation_center\'',
    'IMPLEMENTED_STATUSES',
    'RESERVED_STATUSES',
    'STATUS_TRANSITIONS',
    '\'submitted\' => [\'contacting\']',
    '\'contacting\' => [\'communicating\']',
    '\'communicating\' => [\'inspecting\']',
    '\'inspecting\' => [\'pending_contract\']',
    'franchise_application_user_field_forbidden',
    'franchise_application_status_reserved_for_later',
    'franchise_application_status_transition_forbidden',
    'request->uid()',
    'maskPhone',
    'phone_masked',
    'assigned_name',
    'submit_time',
    'AuditEventServices',
    'recordSafely',
    'SystemAdminDao',
    'AdminStoreContextServices',
    'assertHeadquarterScope',
] as $needle) {
    $assert(strpos($service, $needle) !== false, 'service_contains:' . $needle);
}

foreach ([
    'createStore',
    'grantFranchisee',
    'franchisee_identity',
    'store_manager',
    'store_staff',
    'service_mentor',
    'admin_token',
    'openid',
    'unionid',
    'AppSecret',
] as $needle) {
    $assert(strpos($service, $needle) === false, 'service_not_contains:' . $needle);
}

$apiController = $read('app/api/controller/v1/yfth/FranchiseApplicationController.php');
foreach ([
    'submit',
    'myList',
    'detail',
    'postMore',
    'applicant_uid',
    'assigned_uid',
    'status',
    '_forbidden_user_fields_submitted',
] as $needle) {
    $assert(strpos($apiController, $needle) !== false, 'api_controller_contains:' . $needle);
}
foreach ([
    "[['uid', 'd'], 0]",
    "[['applicant_uid', 'd'], 0]",
    "['status', 'submitted']",
] as $needle) {
    $assert(strpos($apiController, $needle) === false, 'api_controller_not_accepts:' . $needle);
}

$apiRoute = $read('app/api/route/yfth_service.php');
foreach ([
    'yfth/franchise/application',
    'yfth/franchise/application/my',
    'yfth/franchise/application/:id',
    'FranchiseApplicationController/submit',
    'FranchiseApplicationController/myList',
    'FranchiseApplicationController/detail',
    'AuthTokenMiddleware',
    'yfth_franchise_application_user',
] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'api_route_contains:' . $needle);
}

$adminController = $read('app/adminapi/controller/v1/yfth/FranchiseApplication.php');
foreach ([
    'extends AuthController',
    "assertAdminApiAuth('yfth/franchise_application/application', 'GET')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>', 'GET')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>/assign', 'POST')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>/status', 'POST')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>/follow', 'POST')",
    'SystemRoleServices',
    'assignOwner',
    'changeStatus',
    'addFollow',
] as $needle) {
    $assert(strpos($adminController, $needle) !== false, 'admin_controller_contains:' . $needle);
}

$adminRoute = $read('app/adminapi/route/yfth.php');
foreach ([
    'franchise_application',
    'FranchiseApplication/applicationList',
    'FranchiseApplication/applicationDetail',
    'FranchiseApplication/assign',
    'FranchiseApplication/status',
    'FranchiseApplication/follow',
    'AdminAuthTokenMiddleware',
    'AdminCheckRoleMiddleware',
] as $needle) {
    $assert(strpos($adminRoute, $needle) !== false, 'admin_route_contains:' . $needle);
}

$adminApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/api/yfth.js');
foreach ([
    'yfthFranchiseApplicationList',
    'yfthFranchiseApplicationDetail',
    'yfthFranchiseApplicationAssign',
    'yfthFranchiseApplicationStatus',
    'yfthFranchiseApplicationFollow',
    'yfth/franchise_application/application',
] as $needle) {
    $assert(strpos($adminApi, $needle) !== false, 'admin_api_contains:' . $needle);
}

$adminRouteJs = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/router/modules/yfth.js');
$assert(strpos($adminRouteJs, 'franchise-application') !== false, 'admin_router_contains_franchise_application');
$assert(strpos($adminRouteJs, 'yfth-franchise-application-index') !== false, 'admin_router_contains_auth');

$uniApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/api/yfth.js');
foreach ([
    'getYfthFranchiseApplications',
    'getYfthFranchiseApplicationDetail',
    'submitYfthFranchiseApplication',
    'yfth/franchise/application/my',
    'yfth/franchise/application/',
] as $needle) {
    $assert(strpos($uniApi, $needle) !== false, 'uni_api_contains:' . $needle);
}

foreach ([
    'pages/yfth/franchise/index.vue',
    'pages/yfth/franchise/apply.vue',
    'pages/yfth/franchise/detail.vue',
] as $file) {
    $assert(is_file($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/' . $file), 'uni_page_exists:' . $file);
}

$pagesJson = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages.json');
foreach ([
    '"path": "franchise/index"',
    '"path": "franchise/apply"',
    '"path": "franchise/detail"',
] as $needle) {
    $assert(strpos($pagesJson, $needle) !== false, 'pages_json_contains:' . $needle);
}

$userIndex = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/user/index.vue');
foreach ([
    'goYfthFranchiseApplications',
    '/pages/yfth/franchise/index',
    '御方通和合作中心',
] as $needle) {
    $assert(strpos($userIndex, $needle) !== false, 'user_center_contains:' . $needle);
}

$userApply = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/franchise/apply.vue');
foreach ([
    'submitYfthFranchiseApplication',
    'form.name',
    'form.phone',
    'form.city',
    'form.intention_area',
] as $needle) {
    $assert(strpos($userApply, $needle) !== false, 'user_apply_contains:' . $needle);
}
foreach ([
    'applicant_uid',
    'assigned_uid',
    'admin_token',
    'status:',
] as $needle) {
    $assert(strpos($userApply, $needle) === false, 'user_apply_not_contains:' . $needle);
}

$userDetail = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/franchise/detail.vue');
foreach ([
    'assigned_uid',
    'operator_uid',
    'audit_events',
    'phone }}',
    'create_time',
    'update_time',
] as $needle) {
    $assert(strpos($userDetail, $needle) === false, 'user_detail_not_contains:' . $needle);
}

if ($failures) {
    echo "YFTH franchise application contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - " . $failure . "\n";
    }
    exit(1);
}

echo "YFTH franchise application contract check passed (" . count($passes) . " assertions).\n";
