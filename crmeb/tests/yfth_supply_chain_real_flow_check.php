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
    $service = $read('app/services/yfth/SupplyChainServices.php');
    $migration = $read('database/migrations/20260708170000_create_yfth_supply_chain_inventory_tables.php');

    $assert(strpos($service, 'lockPurchaseOrder') !== false && strpos($service, '->lock(true)->find()') !== false, 'source_has_purchase_order_row_lock');
    $assert(strpos($service, "return in_array('store_purchase', \$capabilities, true);") !== false, 'source_requires_store_purchase_capability');
    $assert(strpos($service, 'supply_receive:') !== false, 'source_has_deterministic_receipt_idempotency_key');
    $assert(strpos($service, 'purchase_order_already_stocked') !== false, 'source_handles_duplicate_receipt');
    $assert(strpos($service, 'purchase_order_already_shipped') !== false, 'source_handles_duplicate_shipment');
    $assert(strpos($service, '(float)') === false, 'source_has_no_float_money_calculation');
    $assert(strpos($service, 'FIND_IN_SET(:store_type, allow_store_types)') !== false, 'source_has_exact_store_type_match');
    foreach ([
        'uniq_yfth_purchase_item_order_sku',
        'uniq_yfth_purchase_shipment_order',
        'uniq_yfth_purchase_receipt_order',
        'uniq_yfth_purchase_receipt_shipment',
        'uniq_yfth_inventory_ledger_business_sku',
    ] as $index) {
        $assert(strpos($migration, $index) !== false, 'migration_has_' . $index);
    }
} catch (Throwable $e) {
    $failures[] = 'source_check_exception:' . $e->getMessage();
}

$executeFlow = (string)getenv('YFTH_SUPPLY_CHAIN_REAL_FLOW_EXECUTE') === '1';
if (!$executeFlow) {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_SUPPLY_CHAIN_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
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
            scAssertRealIndexes($assert, $database, $prefix);
            scAssertUniquenessGuards($assert, $prefix);
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
    echo "[OK] YFTH supply chain real-flow guards verified on isolated MySQL.\n";
} else {
    echo "[OK] YFTH supply chain P1/P2 source guards passed; isolated MySQL flow skipped.\n";
}

function scAssertRealIndexes(callable $assert, string $database, string $prefix): void
{
    foreach ([
        [$prefix . 'yfth_purchase_order_item', 'uniq_yfth_purchase_item_order_sku'],
        [$prefix . 'yfth_purchase_shipment', 'uniq_yfth_purchase_shipment_order'],
        [$prefix . 'yfth_purchase_receipt', 'uniq_yfth_purchase_receipt_order'],
        [$prefix . 'yfth_purchase_receipt', 'uniq_yfth_purchase_receipt_shipment'],
        [$prefix . 'yfth_inventory_ledger', 'uniq_yfth_inventory_ledger_business_sku'],
    ] as $index) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $index[0], $index[1]]
        );
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

function scAssertUniquenessGuards(callable $assert, string $prefix): void
{
    $runId = time() . random_int(1000, 9999);
    $orderNo = 'POREAL' . $runId;
    $shipmentNo = 'SHREAL' . $runId;
    $receiptNo = 'RCREAL' . $runId;
    $sku = 'real-flow-sku-' . $runId;
    $now = time();

    Db::startTrans();
    try {
        Db::name('yfth_purchase_order')->insert([
            'purchase_no' => $orderNo,
            'store_id' => 990001,
            'supplier_subject_id' => 0,
            'status' => 'shipped',
            'audit_status' => 'approved',
            'amount_snapshot' => '1.23',
            'quantity_total' => 1,
            'operator_uid' => 990001,
            'operator_role_code' => 'store_manager',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $orderId = (int)Db::name('yfth_purchase_order')->where('purchase_no', $orderNo)->value('id');

        Db::name('yfth_purchase_shipment')->insert([
            'purchase_order_id' => $orderId,
            'shipment_no' => $shipmentNo,
            'status' => 'shipped',
            'quantity_total' => 1,
            'operator_uid' => 1,
            'shipped_time' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        scExpectDuplicate(function () use ($orderId, $now) {
            Db::name('yfth_purchase_shipment')->insert([
                'purchase_order_id' => $orderId,
                'shipment_no' => 'SHDUP' . $orderId,
                'status' => 'shipped',
                'quantity_total' => 1,
                'operator_uid' => 1,
                'shipped_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_shipment_blocked');

        Db::name('yfth_purchase_receipt')->insert([
            'purchase_order_id' => $orderId,
            'shipment_id' => 880001,
            'receipt_no' => $receiptNo,
            'status' => 'stocked',
            'quantity_total' => 1,
            'operator_uid' => 990001,
            'operator_role_code' => 'store_manager',
            'received_time' => $now,
            'stocked_time' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        scExpectDuplicate(function () use ($orderId, $now) {
            Db::name('yfth_purchase_receipt')->insert([
                'purchase_order_id' => $orderId,
                'shipment_id' => 880002,
                'receipt_no' => 'RCDUP' . $orderId,
                'status' => 'stocked',
                'quantity_total' => 1,
                'operator_uid' => 990001,
                'operator_role_code' => 'store_manager',
                'received_time' => $now,
                'stocked_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_receipt_blocked');

        Db::name('yfth_inventory_ledger')->insert([
            'store_id' => 990001,
            'location_id' => 990001,
            'product_id' => 990001,
            'sku_unique' => $sku,
            'quantity_change' => 1,
            'balance_after' => 1,
            'business_type' => 'purchase_inbound',
            'business_id' => 770001,
            'operator_uid' => 990001,
            'operator_role_code' => 'store_manager',
            'reason' => 'real_flow_guard',
            'add_time' => $now,
        ]);
        scExpectDuplicate(function () use ($sku, $now) {
            Db::name('yfth_inventory_ledger')->insert([
                'store_id' => 990001,
                'location_id' => 990001,
                'product_id' => 990001,
                'sku_unique' => $sku,
                'quantity_change' => 1,
                'balance_after' => 2,
                'business_type' => 'purchase_inbound',
                'business_id' => 770001,
                'operator_uid' => 990001,
                'operator_role_code' => 'store_manager',
                'reason' => 'real_flow_guard',
                'add_time' => $now,
            ]);
        }, $assert, 'duplicate_ledger_blocked');

        Db::rollback();
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }
}

function scExpectDuplicate(callable $callback, callable $assert, string $label): void
{
    try {
        $callback();
        $assert(false, $label);
    } catch (Throwable $e) {
        $assert(true, $label);
    }
}
