<?php

use app\services\yfth\BenefitTemplateServices;
use app\services\yfth\ServiceAppointmentQueryServices;
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
] as $index) {
    $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$database, $index[0], $index[1]]);
    $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
}

foreach ([
    ServiceProjectServices::class,
    StoreServiceAppointmentServices::class,
    StoreServiceScheduleServices::class,
    ServiceAppointmentQueryServices::class,
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
    $admin = ['level' => 0, 'roles' => []];
    $operator = 91001;
    $storeId = vfCreateStore($runId);
    vfGrantCapability($storeId, 'reservation_service', $runId);
    $store2Id = vfCreateStore($runId . 'B');
    vfGrantCapability($store2Id, 'reservation_service', $runId . 'B');

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
    ], $operator, $admin);
    $projectId = (int)$project->id;
    $notes[] = 'real_flow_project_id:' . $projectId;

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
    ], $operator, $admin);
    $bindingId = (int)$binding->id;
    vfExpectException(function () use ($storeServiceServices, $storeId, $projectId, $operator, $admin) {
        $storeServiceServices->saveStoreService([
            'store_id' => $storeId,
            'service_project_id' => $projectId,
            'duration_minutes' => 60,
            'default_capacity' => 1,
            'status' => 'active',
        ], $operator, $admin);
    }, 'duplicate_active_binding_rejected', $assert);

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
    ], $operator, $admin);
    $scheduleId = (int)$schedule->id;
    $assert($scheduleId > 0, 'schedule_rule_created');
    vfExpectException(function () use ($scheduleServices, $bindingId, $weekday, $operator, $admin) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => $bindingId,
            'weekday' => $weekday,
            'start_minute' => 600,
            'end_minute' => 660,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, $admin);
    }, 'overlapping_schedule_rejected', $assert);
    vfExpectException(function () use ($scheduleServices, $bindingId, $weekday, $operator, $admin) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => $bindingId,
            'weekday' => $weekday,
            'start_minute' => 1380,
            'end_minute' => 1500,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, $admin);
    }, 'cross_day_schedule_rejected', $assert);
    vfExpectException(function () use ($scheduleServices, $bindingId, $weekday, $operator, $storeId) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => $bindingId,
            'weekday' => $weekday,
            'start_minute' => 780,
            'end_minute' => 840,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, ['level' => 1, 'store_id' => $storeId, 'yfth_store_role_code' => 'store_staff']);
    }, 'store_staff_schedule_rejected', $assert);

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
    ], $operator, $admin);
    $closedSlots = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => vfDateText($serviceDate)]);
    $assert($closedSlots['status'] === 'empty' && $closedSlots['reason'] === 'special_day_closed', 'special_day_closed_filters_slots');
    $scheduleServices->disableSpecialDay((int)$closed->id, 'continue_real_flow', $operator, $admin);

    vfExpectException(function () use ($scheduleServices, $bindingId, $serviceDate, $operator, $admin) {
        $scheduleServices->saveSpecialDay([
            'store_service_id' => $bindingId,
            'service_date' => vfDateText($serviceDate),
            'date_type' => 'extra',
            'start_minute' => 600,
            'end_minute' => 660,
            'slot_capacity' => 2,
            'status' => 'active',
        ], $operator, $admin);
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
    ], $operator, $admin);
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
    ], $operator, $admin);
    $overlaySlots = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => vfDateText($serviceDate)]);
    $assert($overlaySlots['status'] === 'ok' && count($overlaySlots['slots']) === 4, 'special_extra_adds_slot');
    $assert((int)$overlaySlots['slots'][0]['remaining_capacity'] === 5, 'capacity_override_applied');

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
    ], $operator, $admin);
    vfExpectException(function () use ($scheduleServices, $binding2, $weekday, $operator, $storeId) {
        $scheduleServices->saveScheduleRule([
            'store_service_id' => (int)$binding2->id,
            'weekday' => $weekday,
            'start_minute' => 540,
            'end_minute' => 600,
            'slot_interval_minutes' => 60,
            'slot_capacity' => 1,
            'status' => 'active',
        ], $operator, ['level' => 1, 'store_id' => $storeId, 'yfth_store_role_code' => 'store_manager']);
    }, 'cross_store_manager_rejected', $assert);

    $storeServiceServices->disableStoreService($bindingId, 'real_flow_disable_binding', $operator, $admin);
    $disabledBindingSlots = $queryServices->daySlots($projectId, ['store_id' => $storeId, 'date' => vfDateText($serviceDate)]);
    $assert($disabledBindingSlots['status'] === 'unavailable' && $disabledBindingSlots['reason'] === 'store_service_not_available', 'disabled_store_service_filters_public_slots');

    $projectServices->disableProject($projectId, 'real_flow_disable_project', $operator, $admin);
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
