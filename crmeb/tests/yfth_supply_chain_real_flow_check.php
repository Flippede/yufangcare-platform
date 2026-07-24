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
    $orderCreate = $read('app/services/order/StoreOrderCreateServices.php');
    $nativeMigration = $read('database/migrations/20260724120000_unify_yfth_procurement_with_store_orders.php');

    $assert(strpos($service, 'prepareNativeCheckout') !== false, 'source_prepares_native_checkout');
    $assert(
        substr_count($service, "throw new ApiException('procurement_legacy_runtime_disabled')") >= 5,
        'source_disables_legacy_procurement_writes'
    );
    $assert(
        strpos($service, "'order_confirm_url' => '/pages/goods/order_confirm/index") !== false,
        'source_reuses_native_order_confirmation'
    );
    $assert(strpos($service, "return in_array('store_purchase', \$capabilities, true);") === false, 'source_has_no_legacy_capability_gate');
    $assert(strpos($service, "Db::name('store_order')->insert") === false, 'source_does_not_insert_native_orders_directly');
    $assert(strpos($orderCreate, "Db::name('yfth_native_procurement_order')->insert") !== false, 'native_order_create_writes_sidecar');
    $assert(strpos($orderCreate, 'excludesCrmebBrokerage') !== false, 'native_procurement_excludes_legacy_brokerage');
    foreach ([
        'yfth_native_procurement_order',
        'yfth_supply_catalog_sku',
        'uniq_yfth_native_procurement_order',
        'uniq_yfth_catalog_sku',
        'yfth_native_procurement_snapshot_must_be_empty_before_rollback',
    ] as $schemaPart) {
        $assert(strpos($nativeMigration, $schemaPart) !== false, 'native_migration_contains:' . $schemaPart);
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
        $connection = Config::get('database.default');
        $database = (string)Config::get('database.connections.' . $connection . '.database');
        $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');

        $assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);
        $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
        $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);

        if (!$failures) {
            scAssertNativeSchema($assert, $database, $prefix);
            scAssertNativeUniquenessAndLegacyReadOnly($assert);
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

echo $executeFlow
    ? "[OK] YFTH native procurement runtime guards verified on isolated MySQL.\n"
    : "[OK] YFTH native procurement source guards passed; isolated MySQL flow skipped.\n";

function scAssertNativeSchema(callable $assert, string $database, string $prefix): void
{
    foreach (['yfth_native_procurement_order', 'yfth_supply_catalog_sku'] as $table) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $prefix . $table]
        );
        $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'native_table_exists:' . $table);
    }

    foreach ([
        [$prefix . 'yfth_native_procurement_order', 'uniq_yfth_native_procurement_order'],
        [$prefix . 'yfth_supply_catalog_sku', 'uniq_yfth_catalog_sku'],
        [$prefix . 'yfth_procurement_profit_snapshot', 'uniq_yfth_procurement_snapshot_source'],
    ] as $index) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $index[0], $index[1]]
        );
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'native_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

function scAssertNativeUniquenessAndLegacyReadOnly(callable $assert): void
{
    $runId = 900000000 + random_int(1000, 999999);
    $now = time();
    $legacyTables = [
        'yfth_purchase_order',
        'yfth_purchase_order_item',
        'yfth_purchase_shipment',
        'yfth_purchase_receipt',
        'yfth_inventory_balance',
        'yfth_inventory_ledger',
    ];
    $before = [];
    foreach ($legacyTables as $table) {
        $before[$table] = (int)Db::name($table)->count();
    }

    Db::startTrans();
    try {
        Db::name('yfth_native_procurement_order')->insert([
            'store_order_id' => $runId,
            'order_no' => 'NATIVE-' . $runId,
            'store_id' => 990001,
            'operator_uid' => 990001,
            'status' => 'created',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        scExpectDuplicate(function () use ($runId, $now) {
            Db::name('yfth_native_procurement_order')->insert([
                'store_order_id' => $runId,
                'order_no' => 'NATIVE-DUP-' . $runId,
                'store_id' => 990001,
                'operator_uid' => 990001,
                'status' => 'created',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_native_order_sidecar_blocked');

        Db::name('yfth_supply_catalog_sku')->insert([
            'catalog_id' => $runId,
            'product_id' => $runId,
            'sku_unique' => 'native-sku-' . $runId,
            'purchase_price' => '12.34',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        scExpectDuplicate(function () use ($runId, $now) {
            Db::name('yfth_supply_catalog_sku')->insert([
                'catalog_id' => $runId,
                'product_id' => $runId,
                'sku_unique' => 'native-sku-' . $runId,
                'purchase_price' => '56.78',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_catalog_sku_price_blocked');

        foreach ($legacyTables as $table) {
            $assert((int)Db::name($table)->count() === $before[$table], 'legacy_history_unchanged:' . $table);
        }
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
