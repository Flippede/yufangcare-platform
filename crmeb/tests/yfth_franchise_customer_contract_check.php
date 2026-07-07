<?php

$root = dirname(__DIR__);
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
    'database/migrations/20260707110000_create_yfth_customer_relation_tables.php',
    'app/model/yfth/YfthCustomerRelation.php',
    'app/model/yfth/YfthCustomerFollowRecord.php',
    'app/dao/yfth/YfthCustomerRelationDao.php',
    'app/dao/yfth/YfthCustomerFollowRecordDao.php',
    'app/services/yfth/FranchiseCustomerServices.php',
    'app/api/controller/v1/yfth/FranchiseCustomerController.php',
    'app/api/route/yfth_service.php',
    'tests/yfth_franchise_customer_contract_check.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$migration = $read('database/migrations/20260707110000_create_yfth_customer_relation_tables.php');
foreach ([
    'yfth_customer_relation',
    'yfth_customer_follow_record',
    'uid',
    'store_id',
    'owner_uid',
    'source',
    'customer_status',
    'bind_time',
    'create_time',
    'update_time',
    'active_key',
    'uniq_yfth_customer_relation_active',
    'idx_yfth_customer_relation_store_status',
    'idx_yfth_follow_relation_time',
    'idx_yfth_follow_store_time',
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}

$service = $read('app/services/yfth/FranchiseCustomerServices.php');
foreach ([
    'CurrentBusinessContextServices',
    "['franchisee', 'store_manager', 'store_staff']",
    'franchise_customer_role_forbidden',
    'store_id_required_for_franchise_customer',
    'StoreAccessServices',
    'customer_relation_already_bound',
    'active_key',
    'maskPhone',
    'phone_masked',
    'has_5980_package',
    'has_appointment',
    'latest_follow_time',
    'AuditEventServices',
    'recordSafely',
    'yfth_franchise_customer',
    'customer_follow_record',
    'sanitizeState',
] as $needle) {
    $assert(strpos($service, $needle) !== false, 'service_contains:' . $needle);
}

foreach ([
    'admin_token',
    'AdminAuthTokenMiddleware',
    'adminapi',
    'openid',
    'unionid',
    'id_card',
] as $needle) {
    $assert(strpos($service, $needle) === false, 'service_not_contains:' . $needle);
}

$route = $read('app/api/route/yfth_service.php');
foreach ([
    'yfth/customer/list',
    'yfth/customer/relation',
    'yfth/customer/:id',
    'yfth/customer/:id/follow',
    'FranchiseCustomerController/customerList',
    'FranchiseCustomerController/bind',
    'FranchiseCustomerController/detail',
    'FranchiseCustomerController/follow',
    'AuthTokenMiddleware',
    'yfth_franchise_customer_user',
] as $needle) {
    $assert(strpos($route, $needle) !== false, 'route_contains:' . $needle);
}

$controller = $read('app/api/controller/v1/yfth/FranchiseCustomerController.php');
foreach ([
    'customerList',
    'bindCustomer',
    'customerDetail',
    'addFollow',
    'postMore',
    'getMore',
] as $needle) {
    $assert(strpos($controller, $needle) !== false, 'controller_contains:' . $needle);
}

$api = (string)file_get_contents(dirname($root) . DIRECTORY_SEPARATOR . 'template/uni-app/api/yfth.js');
foreach ([
    'getYfthCustomerList',
    'getYfthCustomerDetail',
    'createYfthCustomerRelation',
    'addYfthCustomerFollow',
    'yfth/customer/list',
    'yfth/customer/relation',
    "yfth/customer/' + id + '/follow",
] as $needle) {
    $assert(strpos($api, $needle) !== false, 'uni_api_contains:' . $needle);
}

foreach ([
    'pages/yfth/workbench/customer/index.vue',
    'pages/yfth/workbench/customer/detail.vue',
    'pages/yfth/workbench/customer/follow.vue',
] as $file) {
    $assert(is_file(dirname($root) . DIRECTORY_SEPARATOR . 'template/uni-app/' . $file), 'uni_page_exists:' . $file);
}

$pagesJson = (string)file_get_contents(dirname($root) . DIRECTORY_SEPARATOR . 'template/uni-app/pages.json');
foreach ([
    '"path": "workbench/customer/index"',
    '"path": "workbench/customer/detail"',
    '"path": "workbench/customer/follow"',
] as $needle) {
    $assert(strpos($pagesJson, $needle) !== false, 'pages_json_contains:' . $needle);
}

$workbench = (string)file_get_contents(dirname($root) . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/workbench/index.vue');
$assert(strpos($workbench, '/pages/yfth/workbench/customer/index') !== false, 'workbench_links_customer_page');
$assert(strpos($workbench, "from '@/api/yfth_admin.js'") === false, 'workbench_not_import_admin_api');

$listPage = (string)file_get_contents(dirname($root) . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/workbench/customer/index.vue');
foreach ([
    'currentContext',
    'role_code',
    'store_id',
    'phone_masked',
    'has_5980_package',
    'has_appointment',
    'createYfthCustomerRelation',
] as $needle) {
    $assert(strpos($listPage, $needle) !== false, 'list_page_contains:' . $needle);
}

if ($failures) {
    echo "YFTH franchise customer contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - " . $failure . "\n";
    }
    exit(1);
}

echo "YFTH franchise customer contract check passed (" . count($passes) . " assertions).\n";
