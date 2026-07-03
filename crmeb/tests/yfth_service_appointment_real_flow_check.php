<?php

use app\Request;
use app\adminapi\middleware\AdminAuthTokenMiddleware;
use app\services\system\admin\SystemAdminServices;
use app\services\yfth\AdminStoreContextServices;
use app\services\yfth\BenefitTemplateServices;
use app\services\yfth\ServiceAppointmentBookingServices;
use app\services\yfth\ServiceAppointmentQueryServices;
use app\services\yfth\ServiceAppointmentWriteoffServices;
use app\services\yfth\ServiceProjectServices;
use app\services\yfth\StoreServiceAppointmentServices;
use app\services\yfth\StoreServiceScheduleServices;
use app\services\yfth\YfthConstants;
use think\App;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = new class() extends App {
        public function loadEnv(string $envName = ''): void
        {
            parent::loadEnv($envName);
            foreach ([
                'YFTH_REAL_FLOW_DB_HOSTNAME' => 'database.hostname',
                'YFTH_REAL_FLOW_DB_HOSTPORT' => 'database.hostport',
                'YFTH_REAL_FLOW_DB_USERNAME' => 'database.username',
                'YFTH_REAL_FLOW_DB_PASSWORD' => 'database.password',
                'YFTH_REAL_FLOW_DB_DATABASE' => 'database.database',
                'YFTH_REAL_FLOW_DB_PREFIX' => 'database.prefix',
                'YFTH_REAL_FLOW_DB_CHARSET' => 'database.charset',
            ] as $envKey => $configKey) {
                $value = getenv($envKey);
                if ($value !== false) {
                    $this->env->set($configKey, $value);
                }
            }
            $this->env->set('cache.driver', 'file');
        }
    };
    $app->initialize();
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] application_bootstrap_failed:' . $e->getMessage() . "\n");
    exit(1);
}

$failures = [];
$passes = [];
$notes = [];

$assert = function ($condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $message;
        return;
    }
    $failures[] = $message;
};

$query = function (string $sql, array $bind = []) use (&$failures) {
    try {
        return Db::query($sql, $bind);
    } catch (Throwable $e) {
        $failures[] = 'mysql_query_failed:' . $e->getMessage();
        return [];
    }
};

$versionRow = $query('SELECT VERSION() AS version');
$mysqlVersion = (string)($versionRow[0]['version'] ?? '');
$assert($mysqlVersion !== '', 'mysql_version_available');
$assert(stripos($mysqlVersion, 'mariadb') === false, 'mysql_vendor_is_not_mariadb');
$assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);

$connection = Config::get('database.default');
$database = (string)Config::get('database.connections.' . $connection . '.database');
$prefix = (string)Config::get('database.connections.' . $connection . '.prefix');

foreach ([
    'yfth_service_project',
    'yfth_store_service',
    'yfth_store_service_schedule_rule',
    'yfth_store_service_special_day',
    'yfth_admin_store_scope',
    'yfth_service_dynamic_code',
    'yfth_service_writeoff_record',
] as $table) {
    $fullTable = $prefix . $table;
    $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $fullTable]);
    $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'real_table_exists:' . $fullTable);
}

foreach ([
    [$prefix . 'yfth_service_project', 'uniq_yfth_svc_project_code'],
    [$prefix . 'yfth_store_service', 'uniq_yfth_store_svc_active'],
    [$prefix . 'yfth_store_service_schedule_rule', 'uniq_yfth_svc_rule_active'],
    [$prefix . 'yfth_store_service_special_day', 'uniq_yfth_svc_day_active'],
    [$prefix . 'yfth_store_service_special_day', 'idx_yfth_svc_day_binding_date'],
    [$prefix . 'yfth_admin_store_scope', 'uniq_yfth_admin_scope_active'],
    [$prefix . 'yfth_admin_store_scope', 'idx_yfth_admin_scope_admin_role'],
    [$prefix . 'yfth_service_dynamic_code', 'uniq_yfth_svc_code_active'],
    [$prefix . 'yfth_service_dynamic_code', 'uniq_yfth_svc_code_store_digital_active'],
    [$prefix . 'yfth_service_dynamic_code', 'idx_yfth_svc_code_store_digital'],
    [$prefix . 'yfth_service_writeoff_record', 'uniq_yfth_svc_writeoff_active'],
    [$prefix . 'yfth_service_writeoff_record', 'idx_yfth_svc_writeoff_store_time'],
] as $index) {
    $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$database, $index[0], $index[1]]);
    $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
}

foreach ([
    ServiceProjectServices::class,
    StoreServiceAppointmentServices::class,
    StoreServiceScheduleServices::class,
    ServiceAppointmentQueryServices::class,
    ServiceAppointmentWriteoffServices::class,
    AdminStoreContextServices::class,
] as $class) {
    try {
        app()->make($class);
        $passes[] = 'service_resolvable:' . $class;
    } catch (Throwable $e) {
        $failures[] = 'service_not_resolvable:' . $class . ':' . $e->getMessage();
    }
}

$executeFlow = (string)getenv('YFTH_SERVICE_APPOINTMENT_REAL_FLOW_EXECUTE') === '1';
if ($executeFlow) {
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
    $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);
    if (!$failures) {
        try {
            vfRunServiceAppointmentFlow($assert, $notes);
        } catch (Throwable $e) {
            $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
        }
    }
} else {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_SERVICE_APPOINTMENT_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
}

foreach ($notes as $note) {
    echo "[NOTE] {$note}\n";
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
echo "[OK] YFTH service appointment real MySQL checks verified on MySQL {$mysqlVersion}.\n";

function vfRunServiceAppointmentFlow(callable $assert, array &$notes): void
{
    $runId = 'SA' . date('His') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $admin = ['id' => 1, 'level' => 0, 'roles' => []];
    $operator = 91001;
    $storeId = vfCreateStore($runId);
    vfGrantCapability($storeId, 'reservation_service', $runId);
    $store2Id = vfCreateStore($runId . 'B');
    vfGrantCapability($store2Id, 'reservation_service', $runId . 'B');
    $hqAdmin = vfCreateAdmin($runId, 'hq', 1);
    vfGrantAdminScope($hqAdmin['id'], 0, AdminStoreContextServices::ROLE_HEADQUARTER, $runId);
    $managerAdmin = vfCreateAdmin($runId, 'manager', 1);
    vfGrantAdminScope($managerAdmin['id'], $storeId, 'store_manager', $runId);
    $staffAdmin = vfCreateAdmin($runId, 'staff', 1);
    vfGrantAdminScope($staffAdmin['id'], $storeId, 'store_staff', $runId);
    $store2StaffAdmin = vfCreateAdmin($runId, 'staffb', 1);
    vfGrantAdminScope($store2StaffAdmin['id'], $store2Id, 'store_staff', $runId);
    $rateLimitAdmin = vfCreateAdmin($runId, 'limit', 1);
    vfGrantAdminScope($rateLimitAdmin['id'], $store2Id, 'store_staff', $runId);
    $noScopeAdmin = vfCreateAdmin($runId, 'noscope', 1);

    $hqInfo = vfAdminInfoFromToken($hqAdmin['id'], $hqAdmin['pwd']);
    $managerInfo = vfAdminInfoFromToken($managerAdmin['id'], $managerAdmin['pwd']);
    $staffInfo = vfAdminInfoFromToken($staffAdmin['id'], $staffAdmin['pwd']);
    $store2StaffInfo = vfAdminInfoFromToken($store2StaffAdmin['id'], $store2StaffAdmin['pwd']);
    $rateLimitInfo = vfAdminInfoFromToken($rateLimitAdmin['id'], $rateLimitAdmin['pwd']);
    $noScopeInfo = vfAdminInfoFromToken($noScopeAdmin['id'], $noScopeAdmin['pwd']);
    $assert(!empty($hqInfo['yfth_admin_context']['is_headquarter_admin']), 'backend_token_resolves_headquarter_context');
    $assert(in_array($storeId, $managerInfo['yfth_store_ids'] ?? [], true), 'backend_token_resolves_manager_store_scope');
    $assert(($staffInfo['yfth_store_role_code'] ?? '') === 'store_staff', 'backend_token_resolves_staff_role');
    $assert(empty($noScopeInfo['yfth_store_ids'] ?? []), 'backend_token_with_no_scope_has_no_store_context');

    /** @var BenefitTemplateServices $benefitServices */
    $benefitServices = app()->make(BenefitTemplateServices::class);
    $serviceBenefit = $benefitServices->saveBenefitTemplate([
        'benefit_code' => 'SVCBEN' . $runId,
        'benefit_name' => 'Service Benefit ' . $runId,
        'benefit_type' => 'service',
        'fulfillment_type' => 'manual',
        'unit' => 'item',
        'description' => 'real flow service benefit',
        'status' => 'active',
        'sort' => 1,
    ], $operator);
    $productBenefit = $benefitServices->saveBenefitTemplate([
        'benefit_code' => 'PRODBEN' . $runId,
        'benefit_name' => 'Product Benefit ' . $runId,
        'benefit_type' => 'product',
        'fulfillment_type' => 'manual',
        'unit' => 'item',
        'description' => 'real flow product benefit',
        'status' => 'active',
        'sort' => 1,
    ], $operator);

    /** @var ServiceProjectServices $projectServices */
    $projectServices = app()->make(ServiceProjectServices::class);
    vfExpectException(function () use ($projectServices, $productBenefit, $operator, $admin, $runId) {
        $projectServices->saveProject([
            'service_code' => 'BAD' . $runId,
            'service_name' => 'Bad Benefit',
            'required_benefit_template_ids' => (string)$productBenefit->id,
        ], $operator, $admin);
    }, 'product_benefit_rejected', $assert);

    $project = $projectServices->saveProject([
        'service_code' => 'SVC' . $runId,
        'service_name' => 'Therapy ' . $runId,
        'service_type' => 'health_service',
        'service_desc' => 'real flow service',
        'suggested_duration_minutes' => 60,
        'allow_benefit' => 1,
        'required_benefit_type' => 'service',
        'required_benefit_template_ids' => (string)$serviceBenefit->id,
        'allow_paid' => 0,
        'status' => 'active',
        'sort' => 10,
    ], $operator, $hqInfo);
    $projectId = (int)$project->id;
    $notes[] = 'real_flow_project_id:' . $projectId;
    vfExpectException(function () use ($projectServices, $operator, $managerInfo, $runId) {
        $projectServices->saveProject([
            'service_code' => 'MGR' . $runId,
            'service_name' => 'Manager Forbidden',
        ], $operator, $managerInfo);
    }, 'store_manager_cannot_save_global_project', $assert);

    /** @var StoreServiceAppointmentServices $storeServiceServices */
    $storeServiceServices = app()->make(StoreServiceAppointmentServices::class);
    $binding = $storeServiceServices->saveStoreService([
        'store_id' => $storeId,
        'service_project_id' => $projectId,
        'service_alias' => 'Therapy Room',
        'duration_minutes' => 60,
        'requires_confirmation' => 1,
        'appointment_enabled' => 1,
        'advance_min_minutes' => 0,
        'advance_max_days' => 30,
        'cancel_deadline_minutes' => 1440,
        'default_capacity' => 3,
        'timezone' => 'Asia/Shanghai',
        'status' => 'active',
    ], $operator, $hqInfo);
    $bindingId = (int)$binding->id;
    vfExpectException(function () use ($storeServiceServices, $storeId, $projectId, $operator, $hqInfo) {
        $storeServiceServices->saveStoreService([
            'store_id' => $storeId,
            'service_project_id' => $projectId,
            'duration_minutes' => 60,
            'default_capacity' => 1,
            'status' => 'active',
        ], $operator, $hqInfo);
    }, 'duplicate_active_binding_rejected', $assert);
    vfExpectException(function () use ($storeServiceServices, $bindingId, $store2Id, $projectId, $operator, $hqInfo) {
        $storeServiceServices->saveStoreService([
            'id' => $bindingId,
            'store_id' => $store2Id,
            'service_project_id' => $projectId,
            'duration_minutes' => 60,
            'default_capacity' => 3,
            'status' => 'active',
        ], $operator, $hqInfo);
    }, 'store_service_store_id_immutable', $assert);

    /** @var StoreServiceScheduleServices $scheduleServices */
    $scheduleServices = app()->make(StoreServiceScheduleServices::class);
    $serviceDate = vfFutureDateInt(3);
    $weekday = vfWeekday($serviceDate);
    $schedule = $scheduleServices->saveScheduleRule([
        'store_service_id' => $bindingId,
        'weekday' => $weekday,
        'start_minute' => 540,
        'end_minute' => 720,
        'slot_interval_minutes' => 60,
        'slot_capacity' => 3,
        'status' => 'active',
    ], $operator, $managerInfo);
    $scheduleId = (int)$schedule->id;
    $assert($scheduleId > 0, 'schedule_rule_created');
    vfExpectException(function () use ($scheduleServices, $bindingId, $weekday, $operator, $managerInfo) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => $bindingId,
            'weekday' => $weekday,
            'start_minute' => 600,
            'end_minute' => 660,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, $managerInfo);
    }, 'overlapping_schedule_rejected', $assert);
    vfExpectException(function () use ($scheduleServices, $bindingId, $weekday, $operator, $managerInfo) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => $bindingId,
            'weekday' => $weekday,
            'start_minute' => 1380,
            'end_minute' => 1500,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, $managerInfo);
    }, 'cross_day_schedule_rejected', $assert);
    vfExpectException(function () use ($scheduleServices, $bindingId, $weekday, $operator, $staffInfo) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => $bindingId,
            'weekday' => $weekday,
            'start_minute' => 780,
            'end_minute' => 840,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, $staffInfo);
    }, 'store_staff_schedule_rejected', $assert);
    vfExpectException(function () use ($scheduleServices, $bindingId, $weekday, $operator, $noScopeInfo, $storeId) {
        $unsafeInfo = $noScopeInfo;
        $unsafeInfo['store_id'] = $storeId;
        $unsafeInfo['yfth_store_role_code'] = 'store_manager';
        $scheduleServices->saveScheduleRule([
            'store_service_id' => $bindingId,
            'weekday' => $weekday,
            'start_minute' => 780,
            'end_minute' => 840,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, $unsafeInfo);
    }, 'client_injected_store_scope_rejected', $assert);

    /** @var ServiceAppointmentQueryServices $queryServices */
    $queryServices = app()->make(ServiceAppointmentQueryServices::class);
    $slots = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => vfDateText($serviceDate)]);
    $assert($slots['status'] === 'ok' && count($slots['slots']) === 3, 'regular_schedule_slots_available');
    $assert((int)$slots['total_capacity'] === 9 && (int)$slots['occupied_count'] === 0, 'remaining_capacity_uses_config_without_fake_occupancy');

    $closed = $scheduleServices->saveSpecialDay([
        'store_service_id' => $bindingId,
        'service_date' => vfDateText($serviceDate),
        'date_type' => 'closed',
        'reason' => 'real_flow_closed',
        'status' => 'active',
    ], $operator, $managerInfo);
    $closedSlots = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => vfDateText($serviceDate)]);
    $assert($closedSlots['status'] === 'empty' && $closedSlots['reason'] === 'special_day_closed', 'special_day_closed_filters_slots');
    $scheduleServices->disableSpecialDay((int)$closed->id, 'continue_real_flow', $operator, $managerInfo);

    vfExpectException(function () use ($scheduleServices, $bindingId, $serviceDate, $operator, $managerInfo) {
        $scheduleServices->saveSpecialDay([
            'store_service_id' => $bindingId,
            'service_date' => vfDateText($serviceDate),
            'date_type' => 'extra',
            'start_minute' => 600,
            'end_minute' => 660,
            'slot_capacity' => 2,
            'status' => 'active',
        ], $operator, $managerInfo);
    }, 'extra_overlapping_regular_schedule_rejected', $assert);

    $extra = $scheduleServices->saveSpecialDay([
        'store_service_id' => $bindingId,
        'service_date' => vfDateText($serviceDate),
        'date_type' => 'extra',
        'start_minute' => 780,
        'end_minute' => 840,
        'slot_capacity' => 2,
        'reason' => 'real_flow_extra',
        'status' => 'active',
    ], $operator, $managerInfo);
    $assert((int)$extra->id > 0, 'special_extra_created');
    $scheduleServices->saveSpecialDay([
        'store_service_id' => $bindingId,
        'service_date' => vfDateText($serviceDate),
        'date_type' => 'capacity_override',
        'start_minute' => 540,
        'end_minute' => 600,
        'slot_capacity' => 5,
        'reason' => 'real_flow_capacity',
        'status' => 'active',
    ], $operator, $managerInfo);
    $overlaySlots = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => vfDateText($serviceDate)]);
    $assert($overlaySlots['status'] === 'ok' && count($overlaySlots['slots']) === 4, 'special_extra_adds_slot');
    $assert((int)$overlaySlots['slots'][0]['remaining_capacity'] === 5, 'capacity_override_applied');

    $uid = vfCreateUser($runId);
    $benefitA = vfCreateServiceBenefitFixture($uid, $storeId, (int)$serviceBenefit->id, $runId, 1);
    /** @var ServiceAppointmentBookingServices $bookingServices */
    $bookingServices = app()->make(ServiceAppointmentBookingServices::class);
    $availableBenefits = $bookingServices->availableBenefits($uid, ['service_project_id' => $projectId]);
    $assert((int)$availableBenefits['count'] >= 1, 'available_service_benefits_listed');

    $created = $bookingServices->createAppointment($uid, [
        'store_id' => $storeId,
        'service_project_id' => $projectId,
        'benefit_item_id' => $benefitA['item_id'],
        'date' => vfDateText($serviceDate),
        'start_minute' => 540,
        'user_note' => 'real flow appointment',
        'idempotency_key' => 'create-a-' . $runId,
    ]);
    $appointmentA = $created['appointment'];
    $assert($appointmentA['status'] === ServiceAppointmentBookingServices::STATUS_PENDING, 'manual_create_pending_confirm');
    $slotAfterCreate = vfSlotRow((int)$appointmentA['slot_id']);
    $assert((int)$slotAfterCreate['locked_count'] === 1 && (int)$slotAfterCreate['occupied_count'] === 0, 'pending_create_locks_capacity');
    $assert(vfActiveBenefitLockCount($benefitA['item_id']) === 1, 'pending_create_locks_benefit_item');
    vfExpectException(function () use ($bookingServices, $uid, $storeId, $projectId, $benefitA, $serviceDate, $runId) {
        $bookingServices->createAppointment($uid, [
            'store_id' => $storeId,
            'service_project_id' => $projectId,
            'benefit_item_id' => $benefitA['item_id'],
            'date' => vfDateText($serviceDate),
            'start_minute' => 600,
            'idempotency_key' => 'create-duplicate-benefit-' . $runId,
        ]);
    }, 'duplicate_active_benefit_lock_rejected', $assert);

    $confirmed = $bookingServices->confirmByAdmin((int)$appointmentA['id'], 'real_flow_confirm', $operator, $managerInfo, ['idempotency_key' => 'confirm-a-' . $runId]);
    $appointmentA = $confirmed['appointment'];
    $slotAfterConfirm = vfSlotRow((int)$appointmentA['slot_id']);
    $assert($appointmentA['status'] === ServiceAppointmentBookingServices::STATUS_CONFIRMED, 'manual_confirm_transitions_confirmed');
    $assert((int)$slotAfterConfirm['locked_count'] === 0 && (int)$slotAfterConfirm['occupied_count'] === 1, 'confirm_moves_locked_to_occupied');

    $rescheduled = $bookingServices->rescheduleByUser($uid, (int)$appointmentA['id'], [
        'date' => vfDateText($serviceDate),
        'start_minute' => 600,
        'reason' => 'real_flow_reschedule',
        'idempotency_key' => 'reschedule-a-' . $runId,
    ]);
    $appointmentA = $rescheduled['appointment'];
    $assert((int)$appointmentA['start_minute'] === 600 && (int)$appointmentA['reschedule_count'] === 1, 'user_reschedule_updates_slot');
    $cancelled = $bookingServices->cancelByUser($uid, (int)$appointmentA['id'], 'real_flow_user_cancel', ['idempotency_key' => 'cancel-a-' . $runId]);
    $appointmentA = $cancelled['appointment'];
    $assert($appointmentA['status'] === ServiceAppointmentBookingServices::STATUS_CANCELLED, 'user_cancel_transitions_cancelled');
    $assert(vfActiveBenefitLockCount($benefitA['item_id']) === 0, 'cancel_releases_benefit_lock');

    Db::name('yfth_store_service')->where('id', $bindingId)->update(['requires_confirmation' => 0, 'update_time' => time()]);
    $benefitB = vfCreateServiceBenefitFixture($uid, $storeId, (int)$serviceBenefit->id, $runId, 2);
    $auto = $bookingServices->createAppointment($uid, [
        'store_id' => $storeId,
        'service_project_id' => $projectId,
        'benefit_item_id' => $benefitB['item_id'],
        'date' => vfDateText($serviceDate),
        'start_minute' => 660,
        'idempotency_key' => 'create-b-' . $runId,
    ]);
    $assert($auto['appointment']['status'] === ServiceAppointmentBookingServices::STATUS_CONFIRMED, 'auto_confirm_create_confirmed');
    $slotAfterAuto = vfSlotRow((int)$auto['appointment']['slot_id']);
    $assert((int)$slotAfterAuto['occupied_count'] >= 1, 'auto_confirm_occupies_capacity');

    /** @var ServiceAppointmentWriteoffServices $writeoffServices */
    $writeoffServices = app()->make(ServiceAppointmentWriteoffServices::class);
    $appointmentBId = (int)$auto['appointment']['id'];
    $now = time();
    Db::name('yfth_service_appointment')->where('id', $appointmentBId)->update([
        'service_date' => (int)date('Ymd', $now),
        'start_time' => $now - 600,
        'end_time' => $now + 1800,
        'start_minute' => max(0, (int)date('H', $now) * 60 + (int)date('i', $now) - 10),
        'end_minute' => min(1440, (int)date('H', $now) * 60 + (int)date('i', $now) + 30),
        'update_time' => $now,
    ]);
    $publicAppointment = $bookingServices->userDetail($uid, $appointmentBId);
    $forbiddenUserKeys = ['events', 'benefit_lock', 'request_id', 'idempotency_key', 'confirm_operator_id', 'store_snapshot', 'service_snapshot', 'benefit_snapshot'];
    $leakedUserKeys = array_intersect($forbiddenUserKeys, array_keys($publicAppointment));
    $assert(!$leakedUserKeys && isset($publicAppointment['timeline']), 'user_appointment_detail_is_whitelisted');
    $assert(($publicAppointment['dynamic_code']['can_generate'] ?? false) === true, 'public_detail_dynamic_code_available_in_window');

    $firstCode = $writeoffServices->generateUserCode($uid, $appointmentBId, ['idempotency_key' => 'code-first-' . $runId]);
    $secondCode = $writeoffServices->generateUserCode($uid, $appointmentBId, ['idempotency_key' => 'code-second-' . $runId]);
    $assert(!empty($secondCode['code']['qr_token']) && !empty($secondCode['code']['digital_code']), 'dynamic_code_returns_plaintext_once');
    $storedPlaintextCount = Db::name('yfth_service_dynamic_code')
        ->where('appointment_id', $appointmentBId)
        ->where(function ($query) use ($secondCode) {
            $query->where('token_hash', (string)$secondCode['code']['qr_token'])->whereOr('digital_code_hash', (string)$secondCode['code']['digital_code']);
        })
        ->count();
    $assert((int)$storedPlaintextCount === 0, 'dynamic_code_plaintext_not_persisted');
    $invalidatedCount = Db::name('yfth_service_dynamic_code')
        ->where('appointment_id', $appointmentBId)
        ->where('status', 'invalidated')
        ->count();
    $assert((int)$invalidatedCount >= 1, 'dynamic_code_refresh_invalidates_old_code');
    vfExpectException(function () use ($writeoffServices, $firstCode, $staffInfo) {
        $writeoffServices->precheckByToken((string)$firstCode['code']['qr_token'], $staffInfo);
    }, 'old_dynamic_code_rejected_after_refresh', $assert);
    vfExpectException(function () use ($writeoffServices, $secondCode, $noScopeInfo) {
        $writeoffServices->precheckByToken((string)$secondCode['code']['qr_token'], $noScopeInfo);
    }, 'noscope_staff_writeoff_rejected', $assert);

    $precheck = $writeoffServices->precheckByToken((string)$secondCode['code']['qr_token'], $staffInfo);
    $assert($precheck['status'] === 'ok' && (int)$precheck['appointment']['id'] === $appointmentBId, 'store_staff_precheck_allowed_same_store');
    $writeoff = $writeoffServices->writeoffByToken((string)$secondCode['code']['qr_token'], $staffInfo, ['idempotency_key' => 'writeoff-qr-' . $runId]);
    $assert($writeoff['status'] === 'ok', 'store_staff_qr_writeoff_succeeds');
    $writtenAppointment = Db::name('yfth_service_appointment')->where('id', $appointmentBId)->find();
    $writtenLock = Db::name('yfth_service_benefit_lock')->where('appointment_id', $appointmentBId)->find();
    $writtenItem = Db::name('yfth_benefit_item')->where('id', $benefitB['item_id'])->find();
    $assert($writtenAppointment['status'] === ServiceAppointmentBookingServices::STATUS_COMPLETED && (int)$writtenAppointment['writeoff_id'] > 0, 'writeoff_marks_appointment_completed');
    $assert($writtenLock['status'] === 'consumed' && $writtenLock['consume_status'] === 'consumed', 'writeoff_consumes_benefit_lock');
    $assert($writtenItem['status'] === 'used' && $writtenItem['fulfillment_status'] === 'service_writeoff', 'writeoff_marks_benefit_item_used');
    $repeat = $writeoffServices->writeoffByToken((string)$secondCode['code']['qr_token'], $staffInfo, ['idempotency_key' => 'writeoff-qr-repeat-' . $runId]);
    $assert($repeat['status'] === 'already_written_off' || $repeat['status'] === 'ok', 'repeat_writeoff_is_idempotent_or_already_done');
    $recordCount = Db::name('yfth_service_writeoff_record')->where('appointment_id', $appointmentBId)->where('status', 'succeeded')->count();
    $assert((int)$recordCount === 1, 'repeat_writeoff_does_not_duplicate_record');
    $writeoffEventCount = Db::name('yfth_service_appointment_event')->where('appointment_id', $appointmentBId)->whereIn('event_type', ['checked_in', 'benefit_written_off', 'completed'])->count();
    $assert((int)$writeoffEventCount === 3, 'writeoff_records_checkin_benefit_completed_events');

    $benefitC = vfCreateServiceBenefitFixture($uid, $storeId, (int)$serviceBenefit->id, $runId, 3);
    $digital = $bookingServices->createAppointment($uid, [
        'store_id' => $storeId,
        'service_project_id' => $projectId,
        'benefit_item_id' => $benefitC['item_id'],
        'date' => vfDateText($serviceDate),
        'start_minute' => 780,
        'idempotency_key' => 'create-c-' . $runId,
    ]);
    $appointmentCId = (int)$digital['appointment']['id'];
    Db::name('yfth_service_appointment')->where('id', $appointmentCId)->update([
        'service_date' => (int)date('Ymd', $now),
        'start_time' => $now - 600,
        'end_time' => $now + 1800,
        'update_time' => $now,
    ]);
    $digitalCode = $writeoffServices->generateUserCode($uid, $appointmentCId, ['idempotency_key' => 'code-digital-' . $runId]);
    $digitalRowBeforePrecheck = Db::name('yfth_service_dynamic_code')->where('appointment_id', $appointmentCId)->where('status', 'issued')->find();
    $assert(!empty($digitalRowBeforePrecheck['digital_active_key']), 'digital_code_has_store_scoped_active_key');
    for ($i = 0; $i < 3; $i++) {
        $precheckDigital = $writeoffServices->precheckByDigital((string)$digitalCode['code']['digital_code'], $managerInfo);
        $assert($precheckDigital['status'] === 'ok', 'digital_precheck_readonly_ok_' . $i);
    }
    $digitalRowAfterPrecheck = Db::name('yfth_service_dynamic_code')->where('id', (int)$digitalRowBeforePrecheck['id'])->find();
    $assert((int)$digitalRowAfterPrecheck['attempt_count'] === (int)$digitalRowBeforePrecheck['attempt_count'], 'digital_precheck_does_not_increment_attempts');
    $assert($digitalRowAfterPrecheck['status'] === $digitalRowBeforePrecheck['status'] && $digitalRowAfterPrecheck['active_key'] === $digitalRowBeforePrecheck['active_key'], 'digital_precheck_does_not_change_code_state');
    $duplicateRejected = false;
    try {
        Db::name('yfth_service_dynamic_code')->insert([
            'appointment_id' => 999000,
            'uid' => $uid,
            'store_id' => $storeId,
            'token_hash' => hash('sha256', 'duplicate-token-' . $runId),
            'digital_code_hash' => $digitalRowBeforePrecheck['digital_code_hash'],
            'status' => 'issued',
            'issued_time' => time(),
            'expire_time' => time() + 300,
            'used_time' => 0,
            'invalidated_time' => 0,
            'attempt_count' => 0,
            'max_attempts' => 5,
            'used_admin_id' => 0,
            'used_role_code' => '',
            'used_writeoff_id' => 0,
            'request_id' => 'duplicate-test',
            'active_key' => 'duplicate-test-' . $runId,
            'digital_active_key' => $digitalRowBeforePrecheck['digital_active_key'],
            'add_time' => time(),
            'update_time' => time(),
        ]);
    } catch (Throwable $e) {
        $duplicateRejected = true;
    }
    $assert($duplicateRejected, 'same_store_active_digital_unique_constraint_rejects_duplicate');
    Db::name('yfth_service_dynamic_code')->insert([
        'appointment_id' => 999001,
        'uid' => $uid,
        'store_id' => $store2Id,
        'token_hash' => hash('sha256', 'duplicate-token-store2-' . $runId),
        'digital_code_hash' => $digitalRowBeforePrecheck['digital_code_hash'],
        'status' => 'issued',
        'issued_time' => time(),
        'expire_time' => time() + 300,
        'used_time' => 0,
        'invalidated_time' => 0,
        'attempt_count' => 0,
        'max_attempts' => 5,
        'used_admin_id' => 0,
        'used_role_code' => '',
        'used_writeoff_id' => 0,
        'request_id' => 'duplicate-store2-test',
        'active_key' => 'duplicate-store2-test-' . $runId,
        'digital_active_key' => $store2Id . ':' . $digitalRowBeforePrecheck['digital_code_hash'],
        'add_time' => time(),
        'update_time' => time(),
    ]);
    $assert(true, 'different_store_same_digital_hash_allowed_by_unique_constraint');
    $randomDigitalError = vfCaptureExceptionMessage(function () use ($writeoffServices, $store2StaffInfo) {
        $writeoffServices->precheckByDigital('123456', $store2StaffInfo);
    });
    $crossStorePrecheckError = vfCaptureExceptionMessage(function () use ($writeoffServices, $digitalCode, $store2StaffInfo) {
        $writeoffServices->precheckByDigital((string)$digitalCode['code']['digital_code'], $store2StaffInfo);
    });
    $crossStoreWriteoffError = vfCaptureExceptionMessage(function () use ($writeoffServices, $digitalCode, $store2StaffInfo, $runId) {
        $writeoffServices->writeoffByDigital((string)$digitalCode['code']['digital_code'], $store2StaffInfo, ['idempotency_key' => 'cross-store-digital-' . $runId]);
    });
    $assert($randomDigitalError !== '' && $randomDigitalError === $crossStorePrecheckError && $crossStorePrecheckError === $crossStoreWriteoffError, 'random_and_cross_store_digital_errors_are_indistinguishable');
    $digitalRowAfterCrossStore = Db::name('yfth_service_dynamic_code')->where('id', (int)$digitalRowBeforePrecheck['id'])->find();
    $assert((int)$digitalRowAfterCrossStore['attempt_count'] === (int)$digitalRowBeforePrecheck['attempt_count'] && $digitalRowAfterCrossStore['status'] === 'issued', 'cross_store_real_code_does_not_consume_attempts_or_state');
    for ($i = 1; $i <= 5; $i++) {
        $message = vfCaptureExceptionMessage(function () use ($writeoffServices, $rateLimitInfo, $i) {
            $writeoffServices->precheckByDigital(str_pad((string)(220000 + $i), 6, '0', STR_PAD_LEFT), $rateLimitInfo);
        });
        $assert($message === $randomDigitalError, 'digital_rate_limit_failure_allowed_' . $i);
    }
    $limitedMessage = vfCaptureExceptionMessage(function () use ($writeoffServices, $rateLimitInfo) {
        $writeoffServices->precheckByDigital('229999', $rateLimitInfo);
    });
    $assert($limitedMessage === $randomDigitalError, 'digital_rate_limit_blocks_after_max_without_off_by_one');
    $digitalWriteoff = $writeoffServices->writeoffByDigital((string)$digitalCode['code']['digital_code'], $managerInfo, ['idempotency_key' => 'writeoff-digital-' . $runId]);
    $assert($digitalWriteoff['status'] === 'ok', 'store_manager_digital_writeoff_succeeds');
    $digitalRowAfterWriteoff = Db::name('yfth_service_dynamic_code')->where('id', (int)$digitalRowBeforePrecheck['id'])->find();
    $assert($digitalRowAfterWriteoff['status'] === 'used' && $digitalRowAfterWriteoff['digital_active_key'] === null, 'digital_writeoff_clears_active_key');

    $benefitD = vfCreateServiceBenefitFixture($uid, $storeId, (int)$serviceBenefit->id, $runId, 4);
    $exceptionAppointment = $bookingServices->createAppointment($uid, [
        'store_id' => $storeId,
        'service_project_id' => $projectId,
        'benefit_item_id' => $benefitD['item_id'],
        'date' => vfDateText($serviceDate),
        'start_minute' => 600,
        'idempotency_key' => 'create-d-' . $runId,
    ]);
    $appointmentDId = (int)$exceptionAppointment['appointment']['id'];
    Db::name('yfth_service_appointment')->where('id', $appointmentDId)->update([
        'service_date' => (int)date('Ymd', $now),
        'start_time' => $now - 600,
        'end_time' => $now + 1800,
        'update_time' => $now,
    ]);
    vfExpectException(function () use ($writeoffServices, $appointmentDId, $hqInfo) {
        $writeoffServices->exceptionWriteoff($appointmentDId, $hqInfo, ' ');
    }, 'headquarter_exception_reason_required', $assert);
    $exceptionWriteoff = $writeoffServices->exceptionWriteoff($appointmentDId, $hqInfo, 'manual exception verification', ['idempotency_key' => 'exception-writeoff-' . $runId]);
    $assert($exceptionWriteoff['status'] === 'ok', 'headquarter_exception_writeoff_with_reason_succeeds');
    $exceptionRecord = Db::name('yfth_service_writeoff_record')->where('appointment_id', $appointmentDId)->find();
    $exceptionEvent = Db::name('yfth_service_appointment_event')->where('appointment_id', $appointmentDId)->where('event_type', 'completed')->find();
    $assert((string)$exceptionRecord['reason'] === 'manual exception verification' && (string)$exceptionEvent['reason'] === 'manual exception verification', 'headquarter_exception_reason_persisted_to_record_and_event');

    $eventCount = Db::name('yfth_service_appointment_event')->where('appointment_id', (int)$appointmentA['id'])->count();
    $assert((int)$eventCount >= 4, 'appointment_events_record_create_confirm_reschedule_cancel');

    $binding2 = $storeServiceServices->saveStoreService([
        'store_id' => $store2Id,
        'service_project_id' => $projectId,
        'duration_minutes' => 60,
        'appointment_enabled' => 1,
        'advance_min_minutes' => 0,
        'advance_max_days' => 30,
        'default_capacity' => 1,
        'timezone' => 'Asia/Shanghai',
        'status' => 'active',
    ], $operator, $hqInfo);
    vfExpectException(function () use ($scheduleServices, $binding2, $weekday, $operator, $managerInfo) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => (int)$binding2->id,
            'weekday' => $weekday,
            'start_minute' => 540,
            'end_minute' => 600,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, $managerInfo);
    }, 'cross_store_manager_rejected', $assert);

    foreach (['20260231', '2026-02-31', '20261301', '20260000', '20260229'] as $invalidDate) {
        vfExpectException(function () use ($queryServices, $projectId, $storeId, $invalidDate) {
            $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => $invalidDate]);
        }, 'invalid_service_date_rejected:' . $invalidDate, $assert);
    }
    $leapDay = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => '20280229']);
    $assert(in_array($leapDay['status'], ['ok', 'empty'], true), 'valid_leap_day_accepted');

    $publicDetail = $queryServices->projectDetail($projectId);
    $forbiddenPublicKeys = ['created_uid', 'updated_uid', 'disabled_uid', 'close_reason', 'add_time', 'update_time', 'required_benefit_template_ids'];
    $leakedKeys = array_intersect($forbiddenPublicKeys, array_keys($publicDetail['project'] ?? []));
    $assert($publicDetail['status'] === 'ok' && !$leakedKeys, 'public_project_detail_has_no_backend_fields');

    Db::name('system_store')->where('id', $storeId)->update(['is_show' => 0]);
    vfExpectException(function () use ($scheduleServices, $bindingId, $managerInfo) {
        $scheduleServices->previewSlots(['store_service_id' => $bindingId, 'date' => '2028-02-29'], $managerInfo);
    }, 'stopped_store_cannot_configure_schedule', $assert);
    Db::name('system_store')->where('id', $storeId)->update(['is_show' => 1]);

    $storeServiceServices->disableStoreService($bindingId, 'real_flow_disable_binding', $operator, $hqInfo);
    $disabledBindingSlots = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => vfDateText($serviceDate)]);
    $assert($disabledBindingSlots['status'] === 'unavailable' && $disabledBindingSlots['reason'] === 'store_service_not_available', 'disabled_store_service_filters_public_slots');

    $projectServices->disableProject($projectId, 'real_flow_disable_project', $operator, $hqInfo);
    $disabledProject = $queryServices->projectDetail($projectId);
    $assert($disabledProject['status'] === 'unavailable' && $disabledProject['reason'] === 'service_project_not_active', 'disabled_project_filters_public_detail');

    $auditCount = Db::name('yfth_audit_event')->where('business_domain', 'yfth_service_appointment')->whereLike('object_id', '%')->count();
    $assert((int)$auditCount > 0, 'service_appointment_audit_written');
    $notes[] = 'real_flow_run_id:' . $runId;
}

function vfCreateStore(string $runId): int
{
    return (int)Db::name('system_store')->insertGetId([
        'name' => 'YFTH Store ' . $runId,
        'introduction' => 'real flow store',
        'phone' => '139' . substr(preg_replace('/\D/', '', md5($runId)), 0, 8),
        'address' => 'Shanghai',
        'detailed_address' => 'Validation Road',
        'image' => '',
        'oblong_image' => '',
        'latitude' => '31.2304',
        'longitude' => '121.4737',
        'valid_time' => '',
        'day_time' => '',
        'add_time' => time(),
        'is_show' => 1,
        'is_del' => 0,
    ]);
}

function vfCreateUser(string $runId): int
{
    $now = time();
    $phone = '131' . str_pad((string)(abs(crc32('svc-user-' . $runId)) % 100000000), 8, '0', STR_PAD_LEFT);
    return (int)Db::name('user')->insertGetId([
        'account' => 'sa' . substr(strtolower($runId), 0, 20),
        'pwd' => md5($runId),
        'real_name' => 'YFTH Appointment Flow',
        'nickname' => 'YFTH Appointment ' . $runId,
        'avatar' => '',
        'phone' => $phone,
        'add_time' => $now,
        'add_ip' => '127.0.0.1',
        'last_time' => $now,
        'last_ip' => '127.0.0.1',
        'user_type' => 'h5',
        'login_type' => 'h5',
        'status' => 1,
        'uniqid' => md5('yfth-service-appointment-' . $runId),
    ]);
}

function vfCreateServiceBenefitFixture(int $uid, int $storeId, int $benefitTemplateId, string $runId, int $index): array
{
    $now = time();
    $baseId = 700000000 + (abs(crc32($runId . ':' . $index)) % 100000000);
    $instanceId = (int)Db::name('yfth_package_instance')->insertGetId([
        'instance_no' => 'SAINST' . $runId . $index,
        'purchase_id' => $baseId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 1,
        'rule_version_id' => 1,
        'order_id' => $baseId + 1,
        'order_sn' => 'SAORDER' . $runId . $index,
        'plan_id' => 0,
        'status' => 'active',
        'refund_status' => 'none',
        'fulfilled_count' => 0,
        'start_time' => $now - 86400,
        'end_time' => $now + 86400 * 60,
        'activated_time' => $now,
        'close_reason' => '',
        'rule_snapshot' => '',
        'store_snapshot' => '',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $planId = (int)Db::name('yfth_benefit_plan')->insertGetId([
        'plan_no' => 'SAPLAN' . $runId . $index,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 1,
        'rule_version_id' => 1,
        'month_count' => 10,
        'status' => 'active',
        'start_time' => $now - 86400,
        'end_time' => $now + 86400 * 60,
        'opened_month_no' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_package_instance')->where('id', $instanceId)->update(['plan_id' => $planId]);
    $periodId = (int)Db::name('yfth_benefit_period')->insertGetId([
        'plan_id' => $planId,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'month_no' => 1,
        'period_code' => 'SAPERIOD' . $runId . $index,
        'period_start_time' => $now - 86400,
        'period_end_time' => $now + 86400 * 30,
        'open_at' => $now - 86400,
        'expire_at' => $now + 86400 * 30,
        'status' => 'available',
        'total_item_count' => 1,
        'fulfilled_item_count' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $itemId = (int)Db::name('yfth_benefit_item')->insertGetId([
        'plan_id' => $planId,
        'period_id' => $periodId,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'month_no' => 1,
        'benefit_template_id' => $benefitTemplateId,
        'benefit_code' => 'SVCBEN' . $runId,
        'benefit_name' => 'Service Benefit ' . $runId,
        'benefit_type' => 'service',
        'quantity_total' => '1.00',
        'quantity_available' => '1.00',
        'quantity_used' => '0.00',
        'available_time' => $now - 3600,
        'expire_time' => $now + 86400 * 30,
        'status' => 'available',
        'fulfillment_status' => 'none',
        'source_rule_id' => 900000 + $index,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    return ['instance_id' => $instanceId, 'plan_id' => $planId, 'period_id' => $periodId, 'item_id' => $itemId];
}

function vfSlotRow(int $slotId): array
{
    return Db::name('yfth_service_appointment_slot')->where('id', $slotId)->find() ?: [];
}

function vfActiveBenefitLockCount(int $benefitItemId): int
{
    return (int)Db::name('yfth_service_benefit_lock')->where('benefit_item_id', $benefitItemId)->where('status', 'locked')->count();
}

function vfGrantCapability(int $storeId, string $capability, string $runId): void
{
    Db::name('yfth_store_capability')->insert([
        'store_id' => $storeId,
        'capability_code' => $capability,
        'source_qualification_id' => 0,
        'source_authorization' => 'real_flow_' . $runId,
        'status' => YfthConstants::STATUS_ACTIVE,
        'effective_time' => 0,
        'expire_time' => 0,
        'close_reason' => '',
        'active_key' => $storeId . ':' . $capability,
        'add_time' => time(),
        'update_time' => time(),
    ]);
}

function vfCreateAdmin(string $runId, string $label, int $level): array
{
    $pwd = password_hash('yfth-' . $runId . '-' . $label, PASSWORD_BCRYPT);
    $id = (int)Db::name('system_admin')->insertGetId([
        'account' => substr('yfth_' . strtolower($label) . '_' . strtolower($runId), 0, 32),
        'head_pic' => '',
        'pwd' => $pwd,
        'real_name' => substr('YFTH ' . $label, 0, 16),
        'roles' => '',
        'last_ip' => '127.0.0.1',
        'last_time' => 0,
        'add_time' => time(),
        'login_count' => 0,
        'level' => $level,
        'status' => 1,
        'division_id' => 0,
        'is_del' => 0,
    ]);
    return ['id' => $id, 'pwd' => $pwd];
}

function vfGrantAdminScope(int $adminId, int $storeId, string $roleCode, string $runId): void
{
    Db::name('yfth_admin_store_scope')->insert([
        'admin_id' => $adminId,
        'store_id' => $storeId,
        'role_code' => $roleCode,
        'permission_scope' => json_encode(['run_id' => $runId], JSON_UNESCAPED_UNICODE),
        'status' => YfthConstants::STATUS_ACTIVE,
        'start_time' => 0,
        'end_time' => 0,
        'created_uid' => $adminId,
        'updated_uid' => $adminId,
        'disabled_uid' => 0,
        'disabled_time' => 0,
        'close_reason' => '',
        'active_key' => $adminId . ':' . $storeId . ':' . $roleCode,
        'add_time' => time(),
        'update_time' => time(),
    ]);
}

function vfAdminInfoFromToken(int $adminId, string $pwdHash): array
{
    /** @var SystemAdminServices $adminServices */
    $adminServices = app()->make(SystemAdminServices::class);
    $tokenInfo = $adminServices->createToken($adminId, 'admin', $pwdHash);
    $token = (string)($tokenInfo['token'] ?? '');
    $request = (new Request())->withGet(['token' => $token]);
    $adminInfo = [];
    app()->make(AdminAuthTokenMiddleware::class)->handle($request, function ($request) use (&$adminInfo) {
        $adminInfo = $request->adminInfo();
        return true;
    });
    return $adminInfo;
}

function vfFutureDateInt(int $days): int
{
    return (int)(new DateTimeImmutable('+' . $days . ' days', new DateTimeZone('Asia/Shanghai')))->format('Ymd');
}

function vfDateText(int $date): string
{
    $text = (string)$date;
    return substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2);
}

function vfWeekday(int $date): int
{
    return (int)(new DateTimeImmutable(vfDateText($date) . ' 00:00:00', new DateTimeZone('Asia/Shanghai')))->format('N');
}

function vfExpectException(callable $callback, string $message, callable $assert): void
{
    try {
        $callback();
        $assert(false, $message);
    } catch (Throwable $e) {
        $assert(true, $message . ':' . $e->getMessage());
    }
}

function vfCaptureExceptionMessage(callable $callback): string
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}
