<?php

use app\listener\yfth\PackagePaySuccessListener;
use app\services\yfth\BenefitPeriodServices;
use app\services\yfth\IdempotencyRecordServices;
use app\services\yfth\PackageActivationRecoveryServices;
use app\services\yfth\PackageActivationServices;
use app\services\yfth\PackageLifecycleServices;
use app\services\yfth\PackagePurchaseServices;
use app\services\yfth\PackageRefundServices;
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

$assert = function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
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

$versionRow = $query('SELECT VERSION() AS version');
$mysqlVersion = (string)($versionRow[0]['version'] ?? '');
$assert($mysqlVersion !== '', 'mysql_version_not_available');
$assert(stripos($mysqlVersion, 'mariadb') === false, 'mariadb_is_not_accepted_for_final_package_validation');
$assert((bool)preg_match('/^(5\.7|8\.)\./', $mysqlVersion), 'mysql_version_must_be_5_7_or_8_0:' . $mysqlVersion);

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
        $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'missing_real_table:' . $fullTable);
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
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'missing_real_index:' . $index[0] . '.' . $index[1]);
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
    } catch (Throwable $e) {
        $failures[] = 'service_or_listener_not_resolvable:' . $class . ':' . $e->getMessage();
    }
}

$uid = (int)getenv('YFTH_REAL_FLOW_UID');
$templateId = (int)getenv('YFTH_REAL_FLOW_TEMPLATE_ID');
$storeId = (int)getenv('YFTH_REAL_FLOW_STORE_ID');
$executeFlow = (string)getenv('YFTH_REAL_FLOW_EXECUTE') === '1';

if ($executeFlow) {
    $assert($uid > 0 && $templateId > 0 && $storeId > 0, 'real_flow_requires_YFTH_REAL_FLOW_UID_TEMPLATE_ID_STORE_ID');
    if (!$failures) {
        /** @var PackagePurchaseServices $purchaseServices */
        $purchaseServices = app()->make(PackagePurchaseServices::class);
        $intent = $purchaseServices->createIntent($uid, [
            'template_id' => $templateId,
            'store_id' => $storeId,
            'source' => 'real_flow_check',
        ]);
        $orderPayload = $purchaseServices->createOrderFromIntent($uid, (string)$intent['intent_no'], [
            'pay_type' => getenv('YFTH_REAL_FLOW_PAY_TYPE') ?: 'weixin',
            'shipping_type' => 2,
            'source' => 'real_flow_check',
        ]);
        $purchaseNo = (string)$orderPayload['purchase']['purchase_no'];
        $storeOrderId = (int)$orderPayload['order']['store_order_id'];
        $order = Db::name('store_order')->where('id', $storeOrderId)->find();
        $assert((int)($order['id'] ?? 0) === $storeOrderId, 'real_flow_order_not_created');
        if ((string)getenv('YFTH_REAL_FLOW_MARK_PAID') === '1') {
            Db::name('store_order')->where('id', $storeOrderId)->update([
                'paid' => 1,
                'pay_time' => time(),
                'update_time' => time(),
            ]);
            $order = Db::name('store_order')->where('id', $storeOrderId)->find();
            app()->make(PackagePaySuccessListener::class)->handle([$order]);
            $status = $purchaseServices->purchaseStatus($uid, $purchaseNo);
            $assert((string)$status['activation_status'] === 'succeeded', 'real_flow_activation_not_succeeded:' . ($status['last_activation_error'] ?? ''));
            $assert((int)$status['instance_id'] > 0, 'real_flow_instance_not_created');
        } else {
            $notes[] = 'real_flow_order_created_without_mark_paid:' . $purchaseNo;
        }
    }
} else {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_REAL_FLOW_EXECUTE=1_with_isolated_seed_data_to_create_order_and_activation';
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

echo "[OK] YFTH package benefit real application checks verified on MySQL {$mysqlVersion}.\n";
