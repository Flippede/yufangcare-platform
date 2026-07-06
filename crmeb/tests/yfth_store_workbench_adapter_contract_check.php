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
    'app/api/controller/v1/yfth/StoreWorkbenchController.php',
    'app/services/yfth/StoreWorkbenchBusinessAdapterServices.php',
    'app/api/route/yfth_service.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$route = $read('app/api/route/yfth_service.php');
foreach ([
    'yfth/store_workbench/overview',
    'yfth/store_workbench/appointments',
    'yfth/store_workbench/writeoff/precheck',
    'yfth/store_workbench/writeoff/token',
    'yfth/store_workbench/writeoff/digital',
    'yfth/store_workbench/orders',
    'AuthTokenMiddleware::class',
    'yfth_store_workbench_user',
] as $needle) {
    $assert(strpos($route, $needle) !== false, 'route_contains:' . $needle);
}
$assert(strpos($route, 'AdminAuthTokenMiddleware::class') === false, 'store_workbench_routes_do_not_use_admin_token_middleware');

$controller = $read('app/api/controller/v1/yfth/StoreWorkbenchController.php');
foreach ([
    'StoreWorkbenchBusinessAdapterServices',
    'appointmentConfirm',
    'appointmentReject',
    'appointmentCancel',
    'writeoffPrecheck',
    'writeoffToken',
    'writeoffDigital',
    'orderList',
    'orderDetail',
] as $needle) {
    $assert(strpos($controller, $needle) !== false, 'controller_contains:' . $needle);
}

$service = $read('app/services/yfth/StoreWorkbenchBusinessAdapterServices.php');
foreach ([
    'CurrentBusinessContextServices',
    'ServiceAppointmentBookingServices',
    'ServiceAppointmentWriteoffServices',
    'StoreOrderDao',
    'StoreOrderCartInfoDao',
    'store_workbench_role_forbidden',
    'store_staff_can_read_appointment_only',
    'yfth_user_token_store_workbench',
    'headquarter_exception_writeoff',
    'maskPhone',
    'maskAddress',
] as $needle) {
    $assert(strpos($service, $needle) !== false, 'service_contains:' . $needle);
}
foreach ([
    'OrderPayServices',
    'StoreOrderRefundServices',
    'StoreOrderWriteOffServices',
    'PackageActivationServices',
    'exceptionWriteoff(',
    'AdminAuthTokenMiddleware',
    'admin_token',
] as $needle) {
    $assert(strpos($service, $needle) === false, 'service_must_not_contain:' . $needle);
}

$uniApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/api/yfth.js');
foreach ([
    'getYfthStoreWorkbenchOverview',
    'getYfthStoreWorkbenchAppointments',
    'confirmYfthStoreWorkbenchAppointment',
    'precheckYfthStoreWorkbenchWriteoff',
    'writeoffYfthStoreWorkbenchByToken',
    'writeoffYfthStoreWorkbenchByDigital',
    'getYfthStoreWorkbenchOrders',
] as $needle) {
    $assert(strpos($uniApi, $needle) !== false, 'uni_api_contains:' . $needle);
}
$assert(strpos($uniApi, 'admin_token') === false, 'uni_yfth_api_must_not_reference_admin_token');

$workbench = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/workbench/index.vue');
foreach ([
    'getYfthStoreWorkbenchOverview',
    'precheckYfthStoreWorkbenchWriteoff',
    'writeoffYfthStoreWorkbenchByDigital',
    'getYfthStoreWorkbenchOrders',
    'store_staff',
    'can_confirm',
    'can_writeoff',
    'uni.scanCode',
    ':key="item.item_key"',
] as $needle) {
    $assert(strpos($workbench, $needle) !== false, 'workbench_contains:' . $needle);
}
$assert(strpos($workbench, 'item.product_name + item.sku') === false, 'workbench_order_item_key_must_not_use_mp_expression');
foreach ([
    '@/api/yfth_admin.js',
    '/pages/admin/yfth_writeoff/index',
    '/pages/admin/orderList/index',
    'admin_token',
] as $needle) {
    $assert(strpos($workbench, $needle) === false, 'workbench_must_not_contain:' . $needle);
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
echo "[OK] YFTH store workbench adapter contract checks passed.\n";
