<?php

use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__);
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

$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

try {
    $service = $read('app/services/yfth/MonthlyBenefitFulfillmentServices.php');
    $migration = $read('database/migrations/20260712100000_create_yfth_monthly_benefit_fulfillment_tables.php');

    foreach ([
        'claim_requires_idempotency' => 'monthly_benefit_idempotency_key_required',
        'claim_rejects_client_uid' => 'monthly_benefit_claim_field_forbidden',
        'active_key_guard' => 'uniq_yfth_benefit_fulfillment_active',
        'final_consumption' => "fulfillment_status' => 'product_fulfilled'",
        'event_written' => 'appendEvent',
        'audit_written' => 'AuditEventServices::class',
        'store_context_resolved' => 'CurrentBusinessContextServices::class',
        'admin_headquarter_required' => 'assertHeadquarterScope',
        'no_crmeb_order_write' => "Db::name('store_order')",
        'no_crmeb_stock_write' => 'decStockIncSales',
        'no_product_quota_write' => 'yfth_product_quota_account',
    ] as $label => $needle) {
        if (strpos($label, 'no_') === 0) {
            $assert(strpos($service, $needle) === false, $label);
        } else {
            $assert(strpos($service . $migration, $needle) !== false, $label);
        }
    }
} catch (Throwable $e) {
    $failures[] = 'source_check_exception:' . $e->getMessage();
}

$executeFlow = (string)getenv('YFTH_MONTHLY_BENEFIT_REAL_FLOW_EXECUTE') === '1';
if (!$executeFlow) {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_MONTHLY_BENEFIT_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
} else {
    require $root . '/vendor/autoload.php';
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
                if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') {
                    $this->env->set('database.password', '');
                }
                $this->env->set('cache.driver', 'file');
            }
        };
        $app->initialize();

        $versionRow = Db::query('SELECT VERSION() AS version');
        $mysqlVersion = (string)($versionRow[0]['version'] ?? '');
        $assert($mysqlVersion !== '', 'mysql_version_available');
        $assert(stripos($mysqlVersion, 'mariadb') === false, 'mysql_vendor_is_not_mariadb');
        $assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);

        $connection = Config::get('database.default');
        $database = (string)Config::get('database.connections.' . $connection . '.database');
        $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');
        $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
        $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);

        if (!$failures) {
            mbfAssertIndexes($assert, $database, $prefix);
            mbfAssertUniqueness($assert);
        }
    } catch (Throwable $e) {
        $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
    }
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

if ($executeFlow) {
    echo "[OK] YFTH monthly benefit fulfillment real-flow guards verified on isolated MySQL.\n";
} else {
    echo "[OK] YFTH monthly benefit fulfillment source guards passed; isolated MySQL flow skipped.\n";
}

function mbfAssertIndexes(callable $assert, string $database, string $prefix): void
{
    foreach ([
        [$prefix . 'yfth_benefit_fulfillment', 'uniq_yfth_benefit_fulfillment_idem'],
        [$prefix . 'yfth_benefit_fulfillment', 'uniq_yfth_benefit_fulfillment_active'],
        [$prefix . 'yfth_benefit_fulfillment', 'idx_yfth_benefit_fulfillment_pickup'],
        [$prefix . 'yfth_benefit_fulfillment_event', 'idx_yfth_benefit_fulfillment_event_order'],
    ] as $index) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $index[0], $index[1]]
        );
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

function mbfAssertUniqueness(callable $assert): void
{
    $now = time();
    $runId = (string)$now . random_int(1000, 9999);
    Db::startTrans();
    try {
        Db::name('yfth_benefit_fulfillment')->insert([
            'fulfillment_no' => 'MBF' . $runId,
            'uid' => 990001,
            'store_id' => 880001,
            'package_instance_id' => 1,
            'benefit_plan_id' => 1,
            'benefit_period_id' => 1,
            'benefit_item_id' => 990001,
            'benefit_template_id' => 1,
            'month_no' => 1,
            'period_code' => '202607',
            'benefit_code' => 'product_monthly',
            'benefit_name' => 'monthly product',
            'fulfillment_type' => 'product',
            'fulfillment_method' => 'self_pickup',
            'status' => 'pending_confirm',
            'quantity_total' => '1.00',
            'product_id' => 0,
            'sku_unique' => '',
            'pickup_store_id' => 880001,
            'idempotency_key' => 'mbf_idem_' . $runId,
            'active_key' => 'benefit_item:990001',
            'claim_time' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        mbfExpectDuplicate(function () use ($runId, $now) {
            Db::name('yfth_benefit_fulfillment')->insert([
                'fulfillment_no' => 'MBF_DUP_' . $runId,
                'uid' => 990001,
                'store_id' => 880001,
                'package_instance_id' => 1,
                'benefit_plan_id' => 1,
                'benefit_period_id' => 1,
                'benefit_item_id' => 990001,
                'benefit_template_id' => 1,
                'month_no' => 1,
                'period_code' => '202607',
                'benefit_code' => 'product_monthly',
                'benefit_name' => 'monthly product',
                'fulfillment_type' => 'product',
                'fulfillment_method' => 'self_pickup',
                'status' => 'pending_confirm',
                'quantity_total' => '1.00',
                'product_id' => 0,
                'sku_unique' => '',
                'pickup_store_id' => 880001,
                'idempotency_key' => 'mbf_idem_dup_' . $runId,
                'active_key' => 'benefit_item:990001',
                'claim_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_active_fulfillment_rejected');

        mbfExpectDuplicate(function () use ($runId, $now) {
            Db::name('yfth_benefit_fulfillment')->insert([
                'fulfillment_no' => 'MBF_IDEM_DUP_' . $runId,
                'uid' => 990002,
                'store_id' => 880001,
                'package_instance_id' => 2,
                'benefit_plan_id' => 2,
                'benefit_period_id' => 2,
                'benefit_item_id' => 990002,
                'benefit_template_id' => 1,
                'month_no' => 1,
                'period_code' => '202607',
                'benefit_code' => 'product_monthly',
                'benefit_name' => 'monthly product',
                'fulfillment_type' => 'product',
                'fulfillment_method' => 'self_pickup',
                'status' => 'pending_confirm',
                'quantity_total' => '1.00',
                'product_id' => 0,
                'sku_unique' => '',
                'pickup_store_id' => 880001,
                'idempotency_key' => 'mbf_idem_' . $runId,
                'active_key' => 'benefit_item:990002',
                'claim_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_claim_idempotency_rejected');
    } finally {
        Db::rollback();
    }
}

function mbfExpectDuplicate(callable $fn, callable $assert, string $label): void
{
    try {
        $fn();
        $assert(false, $label);
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        $assert(strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000', $label);
    }
}
