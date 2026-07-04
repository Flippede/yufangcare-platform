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
    'database/migrations/20260703100000_create_yfth_service_appointment_booking_tables.php',
    'database/migrations/20260703100010_seed_yfth_service_appointment_booking_menus.php',
    'database/migrations/20260703120000_create_yfth_service_writeoff_tables.php',
    'database/migrations/20260703120010_seed_yfth_service_writeoff_menus.php',
    'database/migrations/20260703130000_harden_yfth_service_dynamic_codes.php',
    'database/migrations/20260627100000_create_yfth_admin_store_scope.php',
    'app/model/yfth/YfthAdminStoreScope.php',
    'app/dao/yfth/YfthAdminStoreScopeDao.php',
    'app/services/yfth/AdminStoreContextServices.php',
    'app/services/yfth/ServiceProjectServices.php',
    'app/services/yfth/StoreServiceAppointmentServices.php',
    'app/services/yfth/StoreServiceScheduleServices.php',
    'app/services/yfth/ServiceAppointmentQueryServices.php',
    'app/services/yfth/ServiceAppointmentBookingServices.php',
    'app/services/yfth/ServiceAppointmentWriteoffServices.php',
    'app/services/yfth/ServiceBenefitConsumptionServices.php',
    'app/model/yfth/YfthServiceAppointment.php',
    'app/model/yfth/YfthServiceAppointmentSlot.php',
    'app/model/yfth/YfthServiceBenefitLock.php',
    'app/model/yfth/YfthServiceAppointmentEvent.php',
    'app/model/yfth/YfthServiceDynamicCode.php',
    'app/model/yfth/YfthServiceWriteoffRecord.php',
    'app/dao/yfth/YfthServiceAppointmentDao.php',
    'app/dao/yfth/YfthServiceAppointmentSlotDao.php',
    'app/dao/yfth/YfthServiceBenefitLockDao.php',
    'app/dao/yfth/YfthServiceAppointmentEventDao.php',
    'app/dao/yfth/YfthServiceDynamicCodeDao.php',
    'app/dao/yfth/YfthServiceWriteoffRecordDao.php',
    'app/adminapi/controller/v1/yfth/ServiceAppointment.php',
    'app/api/controller/v1/yfth/ServiceAppointmentController.php',
    'app/api/route/yfth_service.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$bookingMigration = $read('database/migrations/20260703100000_create_yfth_service_appointment_booking_tables.php');
foreach ([
    'yfth_service_appointment',
    'yfth_service_appointment_slot',
    'yfth_service_benefit_lock',
    'yfth_service_appointment_event',
    'uniq_yfth_svc_appt_no',
    'uniq_yfth_svc_appt_slot_key',
    'uniq_yfth_svc_benefit_active',
    'idx_yfth_svc_appt_store_status_date',
    'idx_yfth_svc_slot_binding_date',
] as $needle) {
    $assert(strpos($bookingMigration, $needle) !== false, 'booking_migration_contains:' . $needle);
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

$bookingMenu = $read('database/migrations/20260703100010_seed_yfth_service_appointment_booking_menus.php');
foreach ([
    'yfth-service-appointment-booking-list',
    'yfth-service-appointment-booking-detail',
    'yfth-service-appointment-booking-confirm',
    'yfth-service-appointment-booking-reject',
    'yfth-service-appointment-booking-cancel',
] as $needle) {
    $assert(strpos($bookingMenu, $needle) !== false, 'booking_menu_permission_exists:' . $needle);
}

$writeoffMigration = $read('database/migrations/20260703120000_create_yfth_service_writeoff_tables.php');
foreach ([
    'yfth_service_dynamic_code',
    'yfth_service_writeoff_record',
    'token_hash',
    'digital_code_hash',
    'uniq_yfth_svc_code_active',
    'uniq_yfth_svc_writeoff_active',
    'check_in_at',
    'writeoff_at',
    'completed_at',
    'consumed_time',
] as $needle) {
    $assert(strpos($writeoffMigration, $needle) !== false, 'writeoff_migration_contains:' . $needle);
}

$writeoffHardeningMigration = $read('database/migrations/20260703130000_harden_yfth_service_dynamic_codes.php');
foreach ([
    'digital_active_key',
    'uniq_yfth_svc_code_store_digital_active',
    'idx_yfth_svc_code_store_digital',
    'writeoff reason',
] as $needle) {
    $assert(strpos($writeoffHardeningMigration, $needle) !== false, 'writeoff_hardening_migration_contains:' . $needle);
}

$writeoffMenu = $read('database/migrations/20260703120010_seed_yfth_service_writeoff_menus.php');
foreach ([
    'yfth-service-writeoff-list',
    'yfth-service-writeoff-detail',
    'yfth-service-writeoff-precheck',
    'yfth-service-writeoff-token',
    'yfth-service-writeoff-digital',
    'yfth-service-writeoff-result',
    'yfth-service-writeoff-exception',
] as $needle) {
    $assert(strpos($writeoffMenu, $needle) !== false, 'writeoff_menu_permission_exists:' . $needle);
}

$adminController = $read('app/adminapi/controller/v1/yfth/ServiceAppointment.php');
foreach ([
    "assertAdminApiAuth('yfth/service_appointment/project/save', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/store_service/save', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/schedule_rule/save', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/special_day/save', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/appointment', 'GET')",
    "assertAdminApiAuth('yfth/service_appointment/appointment/<id>/confirm', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/appointment/<id>/reject', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/appointment/<id>/cancel', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/writeoff/token', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/writeoff/digital', 'POST')",
    "assertAdminApiAuth('yfth/service_appointment/appointment/<id>/exception_writeoff', 'POST')",
] as $needle) {
    $assert(strpos($adminController, $needle) !== false, 'controller_forces_api_auth:' . $needle);
}

$bookingService = $read('app/services/yfth/ServiceAppointmentBookingServices.php');
foreach ([
    'IdempotencyRecordServices',
    'self::DOMAIN',
    'pending_confirm',
    'confirmed',
    'rejected',
    'cancelled',
    'lockBenefitItem',
    'getOrCreateSlotLocked',
    'ensureSlotExists',
    'lockSlotPairById',
    'runWithDeadlockRetry',
    'formatPublicAppointment',
    'publicDetailPayload',
    'activeBenefitLockExists',
    'recordServiceAudit',
] as $needle) {
    $assert(strpos($bookingService, $needle) !== false, 'booking_service_contains:' . $needle);
}

$writeoffService = $read('app/services/yfth/ServiceAppointmentWriteoffServices.php');
foreach ([
    'CODE_TTL_SECONDS',
    'WINDOW_BEFORE_SECONDS',
    'WINDOW_AFTER_SECONDS',
    'hashSecret',
    'invalidateActiveCodes',
    'assertAdminCanWriteoff',
    'store_staff',
    'headquarter_exception',
    'checked_in',
    'benefit_written_off',
    'completed',
    'ServiceBenefitConsumptionServices',
    'already_written_off',
    'resolveDigitalWriteoffScope',
    'requireScopedDigitalCode',
    'digital_active_key',
    'DIGITAL_SAFE_ERROR',
    'DIGITAL_RATE_LIMIT_TTL',
    'normalizeExceptionReason',
] as $needle) {
    $assert(strpos($writeoffService, $needle) !== false, 'writeoff_service_contains:' . $needle);
}
$assert(strpos($writeoffService, 'function requireActiveCodeByDigital') === false, 'writeoff_service_removed_global_digital_lookup');
$assert(strpos($writeoffService, "'attempt_count' => (int)$" . "row['attempt_count'] + 1") === false, 'writeoff_service_precheck_does_not_increment_attempt_count');

$consumptionService = $read('app/services/yfth/ServiceBenefitConsumptionServices.php');
foreach ([
    "assertTransition('item'",
    "'status' => 'used'",
    "'fulfillment_status' => 'service_writeoff'",
    'fulfilled_item_count',
    'fulfilled_count',
] as $needle) {
    $assert(strpos($consumptionService, $needle) !== false, 'consumption_service_contains:' . $needle);
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
    'applyPersistedCapacity',
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
    'yfth/service/appointment/benefits',
    'yfth/service/appointment/:id/cancel',
    'yfth/service/appointment/:id/reschedule',
    'yfth/service/appointment/:id/code_status',
    'yfth/service/appointment/:id/code',
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
    'yfthServiceAppointmentList',
    'yfthServiceAppointmentConfirm',
    'yfthServiceAppointmentReject',
    'yfthServiceAppointmentCancel',
    'yfthServiceWriteoffList',
    'yfthServiceWriteoffDetail',
    'yfthServiceAppointmentExceptionWriteoff',
] as $needle) {
    $assert(strpos($adminApi, $needle) !== false, 'admin_api_contains:' . $needle);
}
$assert(strpos($adminPage, '时段预览') !== false, 'admin_page_has_slot_preview');
$assert(strpos($adminPage, '预约管理') !== false, 'admin_page_has_appointment_tab');
$assert(strpos($adminPage, '核销记录') !== false, 'admin_page_has_writeoff_tab');
$assert(strpos($uniApi, 'getYfthServiceDaySlots') !== false, 'uni_api_has_readonly_slots');
$assert(strpos($uniApi, 'createYfthServiceAppointment') !== false, 'uni_api_has_real_appointment_create');
$assert(strpos($uniApi, 'generateYfthAppointmentCode') !== false, 'uni_api_has_dynamic_code_generate');
$assert(is_file($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/appointment/create.vue'), 'uni_page_create_exists');
$assert(is_file($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/appointment/detail.vue'), 'uni_page_detail_exists');
$assert(is_file($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/admin/yfth_writeoff/index.vue'), 'uni_page_store_writeoff_exists');
$uniAdminApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/api/yfth_admin.js');
$uniWriteoffPage = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/admin/yfth_writeoff/index.vue');
foreach ([
    '/adminapi/',
    'precheckYfthServiceWriteoff',
    'writeoffYfthServiceByToken',
    'writeoffYfthServiceByDigital',
] as $needle) {
    $assert(strpos($uniAdminApi, $needle) !== false, 'uni_admin_api_contains:' . $needle);
}
$assert(strpos($uniWriteoffPage, 'uni.scanCode') !== false, 'uni_writeoff_page_uses_scan_code');
$assert(strpos($uniWriteoffPage, 'Confirm Writeoff') !== false, 'uni_writeoff_page_has_confirm');

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
