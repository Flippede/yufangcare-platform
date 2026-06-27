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
    'database/migrations/20260626130000_create_yfth_service_appointment_tables.php',
    'database/migrations/20260626130010_seed_yfth_service_appointment_menus.php',
    'database/migrations/20260627100000_create_yfth_admin_store_scope.php',
    'app/model/yfth/YfthAdminStoreScope.php',
    'app/dao/yfth/YfthAdminStoreScopeDao.php',
    'app/services/yfth/AdminStoreContextServices.php',
    'app/services/yfth/ServiceProjectServices.php',
    'app/services/yfth/StoreServiceAppointmentServices.php',
    'app/services/yfth/StoreServiceScheduleServices.php',
    'app/services/yfth/ServiceAppointmentQueryServices.php',
    'app/adminapi/controller/v1/yfth/ServiceAppointment.php',
    'app/api/controller/v1/yfth/ServiceAppointmentController.php',
    'app/api/route/yfth_service.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$adminScopeMigration = $read('database/migrations/20260627100000_create_yfth_admin_store_scope.php');
foreach ([
    'yfth_admin_store_scope',
    'admin_id',
    'store_id',
    'role_code',
    'permission_scope',
    'uniq_yfth_admin_scope_active',
    'idx_yfth_admin_scope_admin_role',
    'idx_yfth_admin_scope_store_role',
] as $needle) {
    $assert(strpos($adminScopeMigration, $needle) !== false, 'admin_scope_migration_contains:' . $needle);
}

$migration = $read('database/migrations/20260626130000_create_yfth_service_appointment_tables.php');
foreach ([
    'yfth_service_project',
    'yfth_store_service',
    'yfth_store_service_schedule_rule',
    'yfth_store_service_special_day',
    'uniq_yfth_svc_project_code',
    'uniq_yfth_store_svc_active',
    'uniq_yfth_svc_rule_active',
    'uniq_yfth_svc_day_active',
    'idx_yfth_svc_day_binding_date',
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}

$menu = $read('database/migrations/20260626130010_seed_yfth_service_appointment_menus.php');
foreach ([
    'yfth-service-appointment-index',
    'yfth-service-project-save',
    'yfth-store-service-save',
    'yfth-service-schedule-save',
    'yfth-service-special-day-save',
    'yfth-service-slot-preview',
] as $needle) {
    $assert(strpos($menu, $needle) !== false, 'menu_permission_exists:' . $needle);
}

$adminController = $read('app/adminapi/controller/v1/yfth/ServiceAppointment.php');
foreach ([
    "assertAdminApiAuth('yfth/service_appointment/project/save', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/store_service/save', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/schedule_rule/save', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/special_day/save', 'POST')",
] as $needle) {
    $assert(strpos($adminController, $needle) !== false, 'controller_forces_api_auth:' . $needle);
}

$middleware = $read('app/adminapi/middleware/AdminAuthTokenMiddleware.php');
foreach ([
    'AdminStoreContextServices',
    'enrichAdminInfo($adminInfo)',
] as $needle) {
    $assert(strpos($middleware, $needle) !== false, 'admin_token_context_contains:' . $needle);
}

$adminContext = $read('app/services/yfth/AdminStoreContextServices.php');
foreach ([
    'YfthAdminStoreScopeDao',
    'assertHeadquarterScope',
    'assertStoreWritable',
    'applyStoreFilter',
    'store_staff_cannot_configure_service_appointment',
    'store_scope_forbidden',
] as $needle) {
    $assert(strpos($adminContext, $needle) !== false, 'admin_context_guard_contains:' . $needle);
}

$query = $read('app/services/yfth/ServiceAppointmentQueryServices.php');
foreach ([
    'slot_generation_mode',
    'rule_realtime_with_special_day_overlay',
    'occupied_count',
    'locked_count',
    'remaining_capacity',
    'store_capability_unavailable',
    'special_day_closed',
    'publicProjectRow',
] as $needle) {
    $assert(strpos($query, $needle) !== false, 'query_contract_contains:' . $needle);
}

$schedule = $read('app/services/yfth/StoreServiceScheduleServices.php') . $read('app/services/yfth/ServiceAppointmentBaseServices.php');
foreach ([
    'schedule_rule_overlap',
    'special_day_closed_conflict',
    'extra_special_day_overlaps_weekly_schedule',
    'invalid_service_date',
] as $needle) {
    $assert(strpos($schedule, $needle) !== false, 'schedule_guard_contains:' . $needle);
}

$storeService = $read('app/services/yfth/StoreServiceAppointmentServices.php');
$assert(strpos($storeService, 'store_service_identity_immutable') !== false, 'store_service_identity_immutable_guard_exists');

$apiRoute = $read('app/api/route/yfth_service.php');
foreach ([
    'yfth/service/project',
    'yfth/service/project/:id/stores',
    'yfth/service/project/:id/dates',
    'yfth/service/project/:id/slots',
    'AuthTokenMiddleware::class, false',
] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'public_route_contains:' . $needle);
}

$adminApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/api/yfth.js');
$adminPage = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/pages/yfth/serviceAppointment/index.vue');
$uniApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/api/yfth.js');
foreach ([
    'yfthServiceProjectList',
    'yfthStoreServiceSave',
    'yfthServiceScheduleRuleSave',
    'yfthServiceSpecialDaySave',
    'yfthServiceSlotPreview',
] as $needle) {
    $assert(strpos($adminApi, $needle) !== false, 'admin_api_contains:' . $needle);
}
$assert(strpos($adminPage, 'Slot Preview') !== false, 'admin_page_has_slot_preview');
$assert(strpos($uniApi, 'getYfthServiceDaySlots') !== false, 'uni_api_has_readonly_slots');
$assert(strpos($uniApi, 'createYfthService') === false, 'uni_api_has_no_appointment_create');

$handoff = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'docs/PROJECT_HANDOFF.md');
$assert(strpos($handoff, 'Service Appointment Domain V1') !== false || strpos($handoff, '服务项目与门店预约时段基础域 V1') !== false, 'handoff_mentions_service_appointment');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

foreach ($passes as $pass) {
    echo "[PASS] {$pass}\n";
}
echo "[OK] YFTH service appointment contract checks passed.\n";
