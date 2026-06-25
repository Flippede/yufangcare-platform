<?php

use app\listener\yfth\PackagePaySuccessListener;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\yfth\BenefitPeriodServices;
use app\services\yfth\BenefitTemplateServices;
use app\services\yfth\BusinessSubjectServices;
use app\services\yfth\IdempotencyRecordServices;
use app\services\yfth\PackageActivationRecoveryServices;
use app\services\yfth\PackageActivationServices;
use app\services\yfth\PackageLifecycleServices;
use app\services\yfth\PackagePurchaseServices;
use app\services\yfth\PackageRefundServices;
use app\services\yfth\PackageTemplateServices;
use app\services\yfth\StorePaymentRouteServices;
use app\services\yfth\StoreSubjectServices;
use crmeb\services\CacheService;
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
$notes = [];
$passes = [];

$assert = function ($condition, string $message) use (&$failures, &$passes): void {
    if (!$condition) {
        $failures[] = $message;
    } else {
        $passes[] = $message;
    }
};

$query = function (string $sql, array $bind = []) use (&$failures) {
    try {
        return Db::query($sql, $bind);
    } catch (Throwable $e) {
        $failures[] = 'mysql_query_failed:' . $e->getMessage();
        return [];
    }
};

if ((string)getenv('YFTH_REAL_FLOW_WORKER') === 'bind_purchase') {
    vfRunBindPurchaseWorker();
}

$versionRow = $query('SELECT VERSION() AS version');
$mysqlVersion = (string)($versionRow[0]['version'] ?? '');
$assert($mysqlVersion !== '', 'mysql_version_available');
$assert(stripos($mysqlVersion, 'mariadb') === false, 'mysql_vendor_is_not_mariadb');
$assert((bool)preg_match('/^(5\.7|8\.0)\./', $mysqlVersion), 'mysql_version_is_5_7_or_8_0:' . $mysqlVersion);

$connection = Config::get('database.default');
$database = (string)Config::get('database.connections.' . $connection . '.database');
$prefix = (string)Config::get('database.connections.' . $connection . '.prefix');

if ($mysqlVersion !== '') {
    foreach ([
        'yfth_package_purchase_intent',
        'yfth_package_purchase',
        'yfth_package_purchase_snapshot',
        'yfth_package_purchase_benefit_snapshot',
        'yfth_package_instance',
        'yfth_benefit_plan',
        'yfth_benefit_period',
        'yfth_benefit_item',
        'yfth_idempotency_record',
    ] as $table) {
        $fullTable = $prefix . $table;
        $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $fullTable]);
        $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'real_table_exists:' . $fullTable);
    }

    foreach ([
        [$prefix . 'yfth_package_purchase', 'uniq_yfth_pkg_purchase_order_key'],
        [$prefix . 'yfth_package_purchase', 'uniq_yfth_pkg_purchase_order_sn_key'],
        [$prefix . 'yfth_package_purchase_snapshot', 'uniq_yfth_pkg_snapshot_purchase'],
        [$prefix . 'yfth_package_purchase_benefit_snapshot', 'uniq_yfth_pkg_benefit_snapshot_rule'],
        [$prefix . 'yfth_benefit_period', 'idx_yfth_benefit_period_open_guard'],
        [$prefix . 'yfth_benefit_period', 'idx_yfth_benefit_period_expire_guard'],
    ] as $index) {
        $rows = $query('SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$database, $index[0], $index[1]]);
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

foreach ([
    PackagePurchaseServices::class,
    PackageActivationServices::class,
    PackageActivationRecoveryServices::class,
    PackageLifecycleServices::class,
    PackageRefundServices::class,
    BenefitPeriodServices::class,
    IdempotencyRecordServices::class,
    PackagePaySuccessListener::class,
] as $class) {
    try {
        app()->make($class);
        $passes[] = 'service_resolvable:' . $class;
    } catch (Throwable $e) {
        $failures[] = 'service_or_listener_not_resolvable:' . $class . ':' . $e->getMessage();
    }
}

$executeFlow = (string)getenv('YFTH_REAL_FLOW_EXECUTE') === '1';
if ($executeFlow) {
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
    $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);

    if (!$failures) {
        try {
            $runId = vfRunId();
            $fixture = vfSeedFixture($runId);
            $notes[] = 'real_flow_seeded_run:' . $runId;

            $main = vfRunIntentOrderActivationFlow($fixture, $assert, $notes);
            vfRunActivationFailureRetryFlow($fixture, $assert);
            vfRunActivationRecoveryFlow($fixture, $assert);
            vfRunConcurrencyFlow($fixture, $assert, $notes);
            vfRunLifecycleAndRefundFlow($fixture, $main, $assert);
            vfRunRuleImmutabilityFlow($fixture, $assert);
        } catch (Throwable $e) {
            $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
        }
    }
} else {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
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
echo "[OK] YFTH package benefit real application checks verified on MySQL {$mysqlVersion}.\n";

function vfRunId(): string
{
    $provided = trim((string)getenv('YFTH_REAL_FLOW_RUN_ID'));
    if ($provided !== '') {
        return preg_replace('/[^A-Za-z0-9]/', '', $provided);
    }
    return 'RF' . date('His') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function vfRunBindPurchaseWorker(): void
{
    try {
        $uid = (int)getenv('YFTH_WORKER_UID');
        $data = [
            'template_id' => (int)getenv('YFTH_WORKER_TEMPLATE_ID'),
            'store_id' => (int)getenv('YFTH_WORKER_STORE_ID'),
            'product_id' => (int)getenv('YFTH_WORKER_PRODUCT_ID'),
            'product_attr_unique' => (string)getenv('YFTH_WORKER_SKU'),
            'order_sn' => (string)getenv('YFTH_WORKER_ORDER_SN'),
            'agreement_accepted' => 1,
            'client_price' => '5980.00',
            'client_month_count' => 10,
            'source' => 'real_flow_concurrent_worker',
        ];
        $result = app()->make(PackagePurchaseServices::class)->createPurchase($uid, $data);
        echo json_encode(['purchase_id' => (int)$result['id'], 'order_sn' => (string)$result['order_sn']]) . "\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, '[WORKER_FAIL] ' . $e->getMessage() . "\n");
        exit(1);
    }
}

function vfSeedFixture(string $runId): array
{
    $now = time();
    vfSeedConfig();

    $phone = '130' . str_pad((string)(abs(crc32($runId)) % 100000000), 8, '0', STR_PAD_LEFT);
    $uid = (int)Db::name('user')->insertGetId([
        'account' => 'yf' . substr(strtolower($runId), 0, 20),
        'pwd' => md5($runId),
        'real_name' => 'YFTH Real Flow',
        'nickname' => 'YFTH Real Flow ' . $runId,
        'avatar' => '',
        'phone' => $phone,
        'add_time' => $now,
        'add_ip' => '127.0.0.1',
        'last_time' => $now,
        'last_ip' => '127.0.0.1',
        'user_type' => 'h5',
        'login_type' => 'h5',
        'status' => 1,
        'uniqid' => md5('yfth-real-flow-' . $runId),
    ]);

    $storeId = (int)Db::name('system_store')->insertGetId([
        'name' => 'YFTH Real Flow Store ' . $runId,
        'introduction' => 'Isolated runtime validation store',
        'phone' => $phone,
        'address' => 'Validation Province,Validation City,Validation District',
        'detailed_address' => 'No. 5980 Validation Road',
        'image' => '',
        'oblong_image' => '',
        'latitude' => '31.2304',
        'longitude' => '121.4737',
        'valid_time' => '00:00 - 23:59',
        'day_time' => '00:00 - 23:59',
        'add_time' => $now,
        'is_show' => 1,
        'is_del' => 0,
    ]);

    $skuUnique = 'Y' . strtoupper(substr(hash('crc32b', $runId . random_int(1, 999999)), 0, 7));
    $productId = (int)Db::name('store_product')->insertGetId([
        'image' => '',
        'recommend_image' => '',
        'slider_image' => '[]',
        'store_name' => 'YFTH 5980 Package ' . $runId,
        'store_info' => 'Isolated runtime validation package',
        'keyword' => 'yfth,5980,validation',
        'bar_code' => '',
        'cate_id' => '',
        'price' => '5980.00',
        'vip_price' => '5980.00',
        'ot_price' => '5980.00',
        'postage' => '0.00',
        'unit_name' => 'set',
        'stock' => 1000,
        'is_show' => 1,
        'is_virtual' => 1,
        'virtual_type' => 1,
        'add_time' => $now,
        'is_del' => 0,
        'cost' => '0.00',
        'temp_id' => 1,
        'spec_type' => 0,
        'activity' => '',
        'logistics' => '1,2',
        'freight' => 2,
        'custom_form' => '[]',
        'min_qty' => 1,
        'default_sku' => $skuUnique,
        'params_list' => '[]',
    ]);
    Db::name('store_product_attr_value')->insert([
        'product_id' => $productId,
        'suk' => 'default',
        'stock' => 1000,
        'price' => '5980.00',
        'image' => '',
        'unique' => $skuUnique,
        'cost' => '0.00',
        'ot_price' => '5980.00',
        'vip_price' => '5980.00',
        'type' => 0,
        'is_virtual' => 1,
        'is_show' => 1,
        'is_default_select' => 1,
    ]);
    Db::name('store_product_attr_result')->insert([
        'product_id' => $productId,
        'result' => json_encode([
            'attr' => [],
            'value' => [[
                'suk' => 'default',
                'price' => '5980.00',
                'stock' => 1000,
                'unique' => $skuUnique,
            ]],
        ], JSON_UNESCAPED_UNICODE),
        'change_time' => $now,
        'type' => 0,
    ]);

    $subjectId = vfSeedSubjectAndStoreScope($runId, $storeId, $phone);
    $package = vfSeedPackage($runId, $productId, $skuUnique);

    return array_merge($package, [
        'run_id' => $runId,
        'uid' => $uid,
        'phone' => $phone,
        'store_id' => $storeId,
        'subject_id' => $subjectId,
        'product_id' => $productId,
        'product_attr_unique' => $skuUnique,
    ]);
}

function vfSeedConfig(): void
{
    foreach ([
        'store_self_mention' => 1,
        'member_func_status' => 0,
        'store_free_postage' => 0,
        'offline_postage' => 0,
        'integral_ratio' => 0,
        'integral_max_num' => 0,
        'balance_func_status' => 0,
        'yue_pay_status' => 0,
        'pay_weixin_open' => 0,
        'ali_pay_status' => 0,
        'friend_pay_status' => 0,
        'offline_pay_status' => 2,
        'order_shipping_open' => 0,
    ] as $name => $value) {
        vfUpsertConfig($name, $value);
    }
    try {
        CacheService::clear();
    } catch (Throwable $e) {
        // File cache can be absent in a fresh validation runtime.
    }
}

function vfUpsertConfig(string $name, $value): void
{
    $row = [
        'menu_name' => $name,
        'type' => 'text',
        'input_type' => 'input',
        'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
        'info' => 'YFTH validation config',
        'desc' => 'YFTH validation config',
        'status' => 1,
    ];
    $exists = Db::name('system_config')->where('menu_name', $name)->find();
    if ($exists) {
        Db::name('system_config')->where('id', (int)$exists['id'])->update($row);
    } else {
        Db::name('system_config')->insert($row);
    }
}

function vfSeedSubjectAndStoreScope(string $runId, int $storeId, string $phone): int
{
    /** @var BusinessSubjectServices $subjectServices */
    $subjectServices = app()->make(BusinessSubjectServices::class);
    $subject = $subjectServices->saveSubject([
        'subject_type' => 'store_company',
        'subject_name' => 'YFTH Runtime Subject ' . $runId,
        'credit_code' => 'YFTH' . strtoupper(substr(hash('sha256', $runId), 0, 14)),
        'legal_person' => 'Runtime Validator',
        'contact_name' => 'Runtime Validator',
        'contact_phone' => $phone,
        'registered_address' => 'Validation address',
        'status' => 'active',
    ]);
    $subjectId = (int)($subject->id ?? 0);
    if ($subjectId <= 0) {
        $subjectId = (int)Db::name('yfth_business_subject')->where('subject_name', 'YFTH Runtime Subject ' . $runId)->value('id');
    }

    /** @var StoreSubjectServices $storeSubjectServices */
    $storeSubjectServices = app()->make(StoreSubjectServices::class);
    foreach ([
        'sales' => ['is_sales_subject' => 1],
        'payment' => ['is_payment_subject' => 1],
        'fulfillment' => ['is_fulfillment_subject' => 1],
        'refund' => ['is_refund_subject' => 1],
    ] as $role => $flags) {
        $storeSubjectServices->saveStoreSubject(array_merge([
            'store_id' => $storeId,
            'subject_id' => $subjectId,
            'store_type' => 'direct',
            'subject_role' => $role,
            'status' => 'active',
        ], $flags));
    }

    $now = time();
    foreach (['package_sale', 'online_payment'] as $capability) {
        Db::name('yfth_store_capability')->insert([
            'add_time' => $now,
            'update_time' => $now,
            'store_id' => $storeId,
            'capability_code' => $capability,
            'source_qualification_id' => 0,
            'source_authorization' => 'runtime_validation',
            'status' => 'active',
            'effective_time' => 0,
            'expire_time' => 0,
            'close_reason' => '',
            'active_key' => $storeId . ':' . $capability,
        ]);
    }

    /** @var StorePaymentRouteServices $routeServices */
    $routeServices = app()->make(StorePaymentRouteServices::class);
    $routeServices->saveRoute([
        'store_id' => $storeId,
        'subject_id' => $subjectId,
        'business_scene' => 'package_5980',
        'route_type' => 'mock_validation',
        'merchant_ref' => 'yfth_merchant_' . strtolower($runId),
        'sub_merchant_ref' => 'yfth_sub_' . strtolower($runId),
        'receiver_subject_id' => $subjectId,
        'invoice_subject_id' => $subjectId,
        'refund_subject_id' => $subjectId,
        'status' => 'active',
        'config_status' => 'metadata_only',
        'priority' => 100,
    ]);

    return $subjectId;
}

function vfSeedPackage(string $runId, int $productId, string $skuUnique): array
{
    /** @var PackageTemplateServices $templateServices */
    $templateServices = app()->make(PackageTemplateServices::class);
    /** @var BenefitTemplateServices $benefitServices */
    $benefitServices = app()->make(BenefitTemplateServices::class);

    $packageCode = 'YFTH5980' . strtoupper(substr($runId, -8));
    $template = $templateServices->saveTemplate([
        'package_code' => $packageCode,
        'package_name' => 'YFTH Runtime 5980 ' . $runId,
        'package_title' => 'YFTH Runtime 5980',
        'package_type' => 'health_package',
        'base_price' => '5980.00',
        'currency' => 'CNY',
        'benefit_months' => 10,
        'service_summary' => 'Runtime validation package',
        'agreement_title' => 'Runtime Validation Agreement',
        'agreement_content' => 'Runtime validation agreement content ' . $runId,
        'status' => 'draft',
        'sort' => 1,
    ]);
    $templateId = (int)Db::name('yfth_package_template')->where('package_code', $packageCode)->order('id desc')->value('id');

    $rule = $templateServices->saveRuleVersion([
        'template_id' => $templateId,
        'version_no' => 1,
        'rule_code' => 'RULE-' . $templateId . '-1',
        'status' => 'draft',
        'package_price' => '5980.00',
        'month_count' => 10,
        'agreement_title' => 'Runtime Validation Agreement',
        'agreement_content' => 'Runtime validation agreement content ' . $runId,
        'benefit_rule_snapshot' => [],
        'effective_time' => 0,
        'expire_time' => 0,
    ]);
    $ruleCode = 'RULE-' . $templateId . '-1';
    $ruleId = (int)Db::name('yfth_package_rule_version')->where('template_id', $templateId)->where('rule_code', $ruleCode)->order('id desc')->value('id');

    $benefitCode = 'YFTHBEN' . strtoupper(substr($runId, -8));
    $benefit = $benefitServices->saveBenefitTemplate([
        'benefit_code' => $benefitCode,
        'benefit_name' => 'Runtime monthly benefit',
        'benefit_type' => 'service',
        'fulfillment_type' => 'manual',
        'unit' => 'item',
        'description' => 'Runtime validation benefit',
        'status' => 'active',
        'sort' => 1,
    ]);
    $benefitId = (int)Db::name('yfth_benefit_template')->where('benefit_code', $benefitCode)->order('id desc')->value('id');

    for ($month = 1; $month <= 10; $month++) {
        $benefitServices->saveMonthlyRule([
            'template_id' => $templateId,
            'rule_version_id' => $ruleId,
            'month_no' => $month,
            'benefit_template_id' => $benefitId,
            'quantity' => '1.00',
            'per_limit' => '1.00',
            'available_offset_days' => 0,
            'expire_offset_days' => 0,
            'service_capability' => 'manual_service',
            'status' => 'active',
        ]);
    }

    $templateServices->saveRuleVersion([
        'id' => $ruleId,
        'template_id' => $templateId,
        'version_no' => 1,
        'rule_code' => 'RULE-' . $templateId . '-1',
        'status' => 'published',
        'package_price' => '5980.00',
        'month_count' => 10,
        'agreement_title' => 'Runtime Validation Agreement',
        'agreement_content' => 'Runtime validation agreement content ' . $runId,
        'benefit_rule_snapshot' => [],
        'effective_time' => 0,
        'expire_time' => 0,
    ]);

    $binding = $templateServices->saveProductBinding([
        'template_id' => $templateId,
        'rule_version_id' => $ruleId,
        'product_id' => $productId,
        'product_attr_unique' => $skuUnique,
        'sku_price_snapshot' => '5980.00',
        'binding_status' => 'active',
    ]);

    return [
        'template_id' => $templateId,
        'rule_version_id' => $ruleId,
        'benefit_template_id' => $benefitId,
        'binding_id' => (int)($binding->id ?? 0),
    ];
}

function vfRunIntentOrderActivationFlow(array $fixture, callable $assert, array &$notes): array
{
    [$intent, $payload] = vfCreatePackageOrder($fixture, 'real_flow_main');
    $purchaseId = (int)$payload['purchase']['id'];
    $orderId = (int)$payload['order']['store_order_id'];
    $order = vfMarkPaid($orderId);

    app()->make(PackagePaySuccessListener::class)->handle([$order]);
    $purchase = Db::name('yfth_package_purchase')->where('id', $purchaseId)->find();
    $assert((string)($purchase['activation_status'] ?? '') === 'succeeded', 'listener_activation_succeeded');
    $instanceId = (int)($purchase['instance_id'] ?? 0);
    $assert($instanceId > 0, 'listener_activation_created_instance');

    vfAssertActivationShape($purchaseId, $instanceId, $assert, 'main');
    $instanceCount = vfCount('yfth_package_instance', ['purchase_id' => $purchaseId]);
    app()->make(PackagePaySuccessListener::class)->handle([$order]);
    $assert(vfCount('yfth_package_instance', ['purchase_id' => $purchaseId]) === $instanceCount, 'duplicate_payment_event_does_not_duplicate_instance');
    $assert(vfCount('yfth_idempotency_record', ['idempotency_key' => 'package_activate:' . $orderId, 'process_status' => 'succeeded']) === 1, 'activation_idempotency_record_succeeded');

    $notes[] = 'main_purchase:' . $payload['purchase']['purchase_no'];
    return [
        'intent' => $intent,
        'payload' => $payload,
        'purchase_id' => $purchaseId,
        'order_id' => $orderId,
        'order_sn' => (string)$payload['order']['order_id'],
        'instance_id' => $instanceId,
    ];
}

function vfRunActivationFailureRetryFlow(array $fixture, callable $assert): void
{
    [, $payload] = vfCreatePackageOrder($fixture, 'real_flow_retry');
    $purchaseId = (int)$payload['purchase']['id'];
    $orderId = (int)$payload['order']['store_order_id'];
    $order = vfMarkPaid($orderId);

    Db::name('yfth_package_purchase_snapshot')->where('purchase_id', $purchaseId)->update(['order_pay_price' => '1.00']);
    try {
        app()->make(PackageActivationServices::class)->activateByPaidOrder($order);
        $assert(false, 'activation_failure_detects_snapshot_price_mismatch');
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), 'package_purchase_snapshot_price_mismatch') !== false, 'activation_failure_detects_snapshot_price_mismatch');
    }
    $failed = Db::name('yfth_package_purchase')->where('id', $purchaseId)->find();
    $assert((string)$failed['activation_status'] === 'failed' && (int)$failed['instance_id'] === 0, 'failed_activation_has_no_instance');

    Db::name('yfth_package_purchase_snapshot')->where('purchase_id', $purchaseId)->update(['order_pay_price' => '5980.00']);
    $result = app()->make(PackageActivationServices::class)->activateByPaidOrder($order);
    $instanceId = (int)($result['instance_id'] ?? 0);
    $assert($instanceId > 0, 'failed_activation_retry_succeeds_after_snapshot_repair');
    vfAssertActivationShape($purchaseId, $instanceId, $assert, 'retry');
    $idem = Db::name('yfth_idempotency_record')->where('idempotency_key', 'package_activate:' . $orderId)->find();
    $assert((int)($idem['attempt_count'] ?? 0) >= 2 && (string)($idem['process_status'] ?? '') === 'succeeded', 'failed_activation_idempotency_reacquired_and_succeeded');
}

function vfRunActivationRecoveryFlow(array $fixture, callable $assert): void
{
    [, $payload] = vfCreatePackageOrder($fixture, 'real_flow_recovery');
    $purchaseId = (int)$payload['purchase']['id'];
    $orderId = (int)$payload['order']['store_order_id'];
    vfMarkPaid($orderId);

    $first = app()->make(PackageActivationRecoveryServices::class)->recoverPaidUnactivated(50, 0, 'real_flow_check');
    $purchase = Db::name('yfth_package_purchase')->where('id', $purchaseId)->find();
    $instanceId = (int)($purchase['instance_id'] ?? 0);
    $assert((int)$first['activated'] >= 1 && $instanceId > 0, 'recovery_activates_paid_unactivated_purchase');
    vfAssertActivationShape($purchaseId, $instanceId, $assert, 'recovery');

    $instanceCount = vfCount('yfth_package_instance', ['purchase_id' => $purchaseId]);
    app()->make(PackageActivationRecoveryServices::class)->recoverPaidUnactivated(50, 0, 'real_flow_check_repeat');
    $assert(vfCount('yfth_package_instance', ['purchase_id' => $purchaseId]) === $instanceCount, 'recovery_repeat_does_not_duplicate_instance');
}

function vfRunConcurrencyFlow(array $fixture, callable $assert, array &$notes): void
{
    $order = vfCreateStandaloneCrmebOrder($fixture, 'real_flow_concurrency');
    $orderId = (int)$order['id'];
    $orderSn = (string)$order['order_id'];

    $workers = [];
    $cmd = vfBuildPhpCommand();
    for ($i = 0; $i < 10; $i++) {
        $env = vfWorkerEnv([
            'YFTH_REAL_FLOW_WORKER' => 'bind_purchase',
            'YFTH_WORKER_UID' => (string)$fixture['uid'],
            'YFTH_WORKER_TEMPLATE_ID' => (string)$fixture['template_id'],
            'YFTH_WORKER_STORE_ID' => (string)$fixture['store_id'],
            'YFTH_WORKER_PRODUCT_ID' => (string)$fixture['product_id'],
            'YFTH_WORKER_SKU' => (string)$fixture['product_attr_unique'],
            'YFTH_WORKER_ORDER_SN' => $orderSn,
        ]);
        $pipes = [];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
        if (!is_resource($process)) {
            $assert(false, 'concurrent_worker_started');
            return;
        }
        $workers[] = [$process, $pipes];
    }

    $workerOk = 0;
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code === 0) {
            $workerOk++;
        } else {
            $notes[] = 'concurrent_worker_failed:' . substr(trim($stderr ?: $stdout), 0, 160);
        }
    }
    $assert($workerOk === 10, 'all_concurrent_workers_return_existing_or_created_purchase');

    $purchaseCount = (int)Db::name('yfth_package_purchase')
        ->where(function ($query) use ($orderId, $orderSn) {
            $query->where('order_unique_key', (string)$orderId)
                ->whereOr('order_id', $orderId)
                ->whereOr('order_sn_unique_key', $orderSn)
                ->whereOr('order_sn', $orderSn);
        })
        ->count();
    $purchase = Db::name('yfth_package_purchase')->where('order_id', $orderId)->find();
    $assert($purchaseCount === 1, 'mysql_unique_order_binding_allows_only_one_purchase_under_concurrency');
    $assert($purchase && vfCount('yfth_package_purchase_snapshot', ['purchase_id' => (int)$purchase['id']]) === 1, 'concurrent_purchase_has_single_snapshot');
}

function vfRunLifecycleAndRefundFlow(array $fixture, array $main, callable $assert): void
{
    $periodId = vfMakeSecondPeriodDue((int)$main['instance_id']);
    app()->make(PackageLifecycleServices::class)->changeInstanceState((int)$main['instance_id'], 'frozen', 'real_flow_freeze', 0);
    $open = app()->make(BenefitPeriodServices::class)->openDuePeriods(time(), 20);
    $period = Db::name('yfth_benefit_period')->where('id', $periodId)->find();
    $assert((string)$period['status'] === 'unopened' && (int)$open['opened'] === 0, 'frozen_instance_blocks_due_period_opening');
    app()->make(PackageLifecycleServices::class)->changeInstanceState((int)$main['instance_id'], 'active', 'real_flow_unfreeze', 0);

    $partial = vfCreateAndActivatePackage($fixture, 'real_flow_refund_partial');
    $partialPeriodId = vfMakeSecondPeriodDue((int)$partial['instance_id']);
    app()->make(PackageRefundServices::class)->onRefundApplied([
        'store_order_id' => (int)$partial['order_id'],
        'store_order_sn' => (string)$partial['order_sn'],
        'refund_reason_wap' => 'real_flow_refund_applied',
    ]);
    app()->make(BenefitPeriodServices::class)->openDuePeriods(time(), 20);
    $partialPeriod = Db::name('yfth_benefit_period')->where('id', $partialPeriodId)->find();
    $assert((string)$partialPeriod['status'] === 'unopened', 'refunding_instance_blocks_due_period_opening');

    app()->make(PackageRefundServices::class)->onRefundCanceled([
        'store_order_id' => (int)$partial['order_id'],
        'store_order_sn' => (string)$partial['order_sn'],
    ]);
    $restoredPurchase = Db::name('yfth_package_purchase')->where('id', (int)$partial['purchase_id'])->find();
    $restoredInstance = Db::name('yfth_package_instance')->where('id', (int)$partial['instance_id'])->find();
    $assert((string)$restoredPurchase['purchase_status'] === 'activated' && (string)$restoredInstance['status'] === 'active', 'refund_cancel_restores_active_package');

    Db::name('yfth_benefit_item')->where('package_instance_id', (int)$partial['instance_id'])->order('id asc')->limit(1)->update([
        'quantity_used' => '1.00',
        'quantity_available' => '0.00',
        'status' => 'used',
    ]);
    app()->make(PackageRefundServices::class)->onRefundApplied([
        'store_order_id' => (int)$partial['order_id'],
        'store_order_sn' => (string)$partial['order_sn'],
    ]);
    app()->make(PackageRefundServices::class)->onRefundSucceeded((string)$partial['order_sn'], [
        'store_order_id' => (int)$partial['order_id'],
        'refund_reason_wap' => 'real_flow_partial_refund',
    ]);
    $partialPurchase = Db::name('yfth_package_purchase')->where('id', (int)$partial['purchase_id'])->find();
    $partialInstance = Db::name('yfth_package_instance')->where('id', (int)$partial['instance_id'])->find();
    $assert((string)$partialPurchase['purchase_status'] === 'closed_after_partial_refund' && (string)$partialInstance['status'] === 'closed', 'partial_refund_after_used_item_closes_package');

    $full = vfCreateAndActivatePackage($fixture, 'real_flow_refund_full');
    app()->make(PackageRefundServices::class)->onRefundApplied([
        'store_order_id' => (int)$full['order_id'],
        'store_order_sn' => (string)$full['order_sn'],
    ]);
    app()->make(PackageRefundServices::class)->onRefundSucceeded((string)$full['order_sn'], [
        'store_order_id' => (int)$full['order_id'],
        'refund_reason_wap' => 'real_flow_full_refund',
    ]);
    $fullPurchase = Db::name('yfth_package_purchase')->where('id', (int)$full['purchase_id'])->find();
    $fullInstance = Db::name('yfth_package_instance')->where('id', (int)$full['instance_id'])->find();
    $fullPlan = Db::name('yfth_benefit_plan')->where('package_instance_id', (int)$full['instance_id'])->find();
    $assert((string)$fullPurchase['purchase_status'] === 'refunded' && (string)$fullInstance['status'] === 'refunded' && (string)$fullPlan['status'] === 'refunded', 'full_refund_without_used_item_refunds_package_plan');
}

function vfRunRuleImmutabilityFlow(array $fixture, callable $assert): void
{
    try {
        app()->make(PackageTemplateServices::class)->saveRuleVersion([
            'id' => (int)$fixture['rule_version_id'],
            'template_id' => (int)$fixture['template_id'],
            'version_no' => 1,
            'rule_code' => 'RULE-' . (int)$fixture['template_id'] . '-1',
            'status' => 'published',
            'package_price' => '5980.00',
            'month_count' => 10,
            'agreement_title' => 'Runtime Validation Agreement Changed',
            'agreement_content' => 'This edit must be rejected after publish/reference.',
            'benefit_rule_snapshot' => [],
        ]);
        $assert(false, 'published_or_referenced_rule_rejects_in_place_edit');
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), 'published_or_referenced_rule_is_immutable') !== false, 'published_or_referenced_rule_rejects_in_place_edit');
    }

    $copy = app()->make(PackageTemplateServices::class)->copyRuleVersion((int)$fixture['rule_version_id'], 0);
    $copyId = (int)($copy['id'] ?? 0);
    $assert($copyId > 0 && $copyId !== (int)$fixture['rule_version_id'] && (string)$copy['status'] === 'draft', 'copy_rule_version_creates_new_draft');
    $assert(vfCount('yfth_monthly_benefit_rule', ['rule_version_id' => $copyId]) === 10, 'copy_rule_version_copies_ten_monthly_rules');
}

function vfCreatePackageOrder(array $fixture, string $source): array
{
    /** @var PackagePurchaseServices $purchaseServices */
    $purchaseServices = app()->make(PackagePurchaseServices::class);
    $intent = $purchaseServices->createIntent((int)$fixture['uid'], [
        'template_id' => (int)$fixture['template_id'],
        'store_id' => (int)$fixture['store_id'],
        'source' => $source,
    ]);
    $payload = $purchaseServices->createOrderFromIntent((int)$fixture['uid'], (string)$intent['intent_no'], [
        'pay_type' => 'weixin',
        'shipping_type' => 2,
        'real_name' => 'YFTH Real Flow',
        'phone' => (string)$fixture['phone'],
        'source' => $source,
    ]);
    return [$intent, $payload];
}

function vfCreateAndActivatePackage(array $fixture, string $source): array
{
    [, $payload] = vfCreatePackageOrder($fixture, $source);
    $purchaseId = (int)$payload['purchase']['id'];
    $orderId = (int)$payload['order']['store_order_id'];
    $order = vfMarkPaid($orderId);
    $result = app()->make(PackageActivationServices::class)->activateByPaidOrder($order);
    return [
        'purchase_id' => $purchaseId,
        'order_id' => $orderId,
        'order_sn' => (string)$payload['order']['order_id'],
        'instance_id' => (int)$result['instance_id'],
    ];
}

function vfCreateStandaloneCrmebOrder(array $fixture, string $source): array
{
    $uid = (int)$fixture['uid'];
    $user = Db::name('user')->where('uid', $uid)->find();
    $cartKey = app()->make(StoreCartServices::class)->setCart(
        $uid,
        (int)$fixture['product_id'],
        1,
        (string)$fixture['product_attr_unique'],
        0,
        true
    );
    $confirm = app()->make(StoreOrderServices::class)->getOrderConfirmData($user, $cartKey, true, 0, 2, 0);
    $order = app()->make(StoreOrderCreateServices::class)->createOrder(
        $uid,
        (string)$confirm['orderKey'],
        $user,
        0,
        'weixin',
        false,
        0,
        $source,
        0,
        0,
        0,
        0,
        2,
        'YFTH Real Flow',
        (string)$fixture['phone'],
        (int)$fixture['store_id'],
        true,
        0,
        [],
        0,
        0,
        ''
    );
    return is_array($order) ? $order : $order->toArray();
}

function vfMarkPaid(int $orderId): array
{
    Db::name('store_order')->where('id', $orderId)->update([
        'paid' => 1,
        'pay_time' => time(),
        'pay_type' => 'weixin',
    ]);
    return Db::name('store_order')->where('id', $orderId)->find();
}

function vfAssertActivationShape(int $purchaseId, int $instanceId, callable $assert, string $label): void
{
    $assert(vfCount('yfth_package_purchase_snapshot', ['purchase_id' => $purchaseId]) === 1, $label . '_has_one_purchase_snapshot');
    $assert(vfCount('yfth_package_purchase_benefit_snapshot', ['purchase_id' => $purchaseId]) === 10, $label . '_has_ten_benefit_snapshots');
    $assert(vfCount('yfth_benefit_plan', ['package_instance_id' => $instanceId]) === 1, $label . '_has_one_benefit_plan');
    $assert(vfCount('yfth_benefit_period', ['package_instance_id' => $instanceId]) === 10, $label . '_has_ten_benefit_periods');
    $assert(vfCount('yfth_benefit_item', ['package_instance_id' => $instanceId]) === 10, $label . '_has_ten_benefit_items');
    $assert(vfCount('yfth_user_identity', ['source_type' => 'package_instance', 'source_id' => $instanceId, 'role_code' => 'member_5980', 'status' => 'active']) === 1, $label . '_grants_member_5980_identity');
}

function vfMakeSecondPeriodDue(int $instanceId): int
{
    $period = Db::name('yfth_benefit_period')->where('package_instance_id', $instanceId)->where('month_no', 2)->find();
    $periodId = (int)$period['id'];
    $now = time();
    Db::name('yfth_benefit_period')->where('id', $periodId)->update([
        'status' => 'unopened',
        'open_at' => $now - 60,
        'expire_at' => $now + 86400,
    ]);
    Db::name('yfth_benefit_item')->where('period_id', $periodId)->update([
        'status' => 'unopened',
        'available_time' => $now - 60,
        'expire_time' => $now + 86400,
    ]);
    return $periodId;
}

function vfBuildPhpCommand(): array
{
    $parts = [PHP_BINARY];
    $extensionDir = ini_get('extension_dir');
    if ($extensionDir !== '') {
        $parts[] = '-d';
        $parts[] = 'extension_dir=' . $extensionDir;
    }
    foreach (['openssl', 'pdo_mysql', 'curl', 'mbstring', 'gd2', 'fileinfo'] as $extension) {
        $parts[] = '-d';
        $parts[] = 'extension=' . $extension;
    }
    $parts[] = __FILE__;
    return $parts;
}

function vfWorkerEnv(array $extra): array
{
    $env = [];
    foreach (['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $env[$key] = $value;
        }
    }
    foreach ($_ENV as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    return array_merge($env, $extra);
}

function vfCount(string $table, array $where): int
{
    return (int)Db::name($table)->where($where)->count();
}
