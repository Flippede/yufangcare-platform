<?php

use app\Request;
use app\adminapi\middleware\AdminAuthTokenMiddleware;
use app\services\system\admin\SystemAdminServices;
use app\services\yfth\AdminStoreContextServices;
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
] as $index) {
    $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$database, $index[0], $index[1]]);
    $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
}

foreach ([
    ServiceProjectServices::class,
    StoreServiceAppointmentServices::class,
    StoreServiceScheduleServices::class,
    ServiceAppointmentQueryServices::class,
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
    $noScopeAdmin = vfCreateAdmin($runId, 'noscope', 1);

    $hqInfo = vfAdminInfoFromToken($hqAdmin['id'], $hqAdmin['pwd']);
    $managerInfo = vfAdminInfoFromToken($managerAdmin['id'], $managerAdmin['pwd']);
    $staffInfo = vfAdminInfoFromToken($staffAdmin['id'], $staffAdmin['pwd']);
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
