<?php

use Phinx\Db\Adapter\AdapterFactory;
use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__);
if ((string)getenv('YFTH_NATIVE_PROCUREMENT_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] native_procurement_migration_skipped_set_YFTH_NATIVE_PROCUREMENT_MIGRATION_EXECUTE=1\n";
    exit(0);
}

require $root . '/vendor/autoload.php';
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

$failures = [];
$assert = function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "[PASS] {$label}\n";
        return;
    }
    $failures[] = $label;
};

try {
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $config = (array)Config::get('database.connections.' . $default);
    $database = (string)($config['database'] ?? '');
    $prefix = (string)($config['prefix'] ?? '');

    $assert(strpos($version, '8.0.46') === 0, 'mysql_community_8_0_46:' . $version);
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);

    require_once $root . '/database/migrations/20260724120000_unify_yfth_procurement_with_store_orders.php';
    $adapter = AdapterFactory::instance()->getAdapter('mysql', [
        'adapter' => $config['type'],
        'host' => $config['hostname'],
        'name' => $database,
        'user' => $config['username'],
        'pass' => $config['password'],
        'port' => $config['hostport'],
        'charset' => $config['charset'],
        'table_prefix' => $prefix,
        'default_migration_table' => $prefix . Config::get('database.migration_table', 'migrations'),
    ]);
    $migration = new UnifyYfthProcurementWithStoreOrders(20260724120000);
    $migration->setAdapter(AdapterFactory::instance()->getWrapper('prefix', $adapter));

    $migration->up();
    npcAssertSchema($assert, true, $database, $prefix, 'run');
    npcAssertMenus($assert, true, 'run');

    $migration->down();
    npcAssertSchema($assert, false, $database, $prefix, 'rollback');
    npcAssertMenus($assert, false, 'rollback');

    $migration->up();
    npcAssertSchema($assert, true, $database, $prefix, 'rerun');
    npcAssertMenus($assert, true, 'rerun');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage() . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

echo "[OK] YFTH native procurement migration run/rollback/rerun passed.\n";

function npcAssertSchema(callable $assert, bool $expected, string $database, string $prefix, string $label): void
{
    foreach (['yfth_native_procurement_order', 'yfth_supply_catalog_sku'] as $table) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $prefix . $table]
        );
        $assert(((int)($rows[0]['cnt'] ?? 0) === 1) === $expected, $label . '_table_' . $table);
    }

    foreach (['source_type', 'source_id', 'store_order_id'] as $column) {
        foreach (['yfth_procurement_profit_snapshot', 'yfth_procurement_profit_ledger'] as $table) {
            $rows = Db::query(
                'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$database, $prefix . $table, $column]
            );
            $assert(((int)($rows[0]['cnt'] ?? 0) === 1) === $expected, $label . '_column_' . $table . '_' . $column);
        }
    }

    $nativeIndex = npcIndexExists($database, $prefix . 'yfth_native_procurement_order', 'uniq_yfth_native_procurement_order');
    $sourceIndex = npcIndexExists($database, $prefix . 'yfth_procurement_profit_snapshot', 'uniq_yfth_procurement_snapshot_source');
    $legacyIndex = npcIndexExists($database, $prefix . 'yfth_procurement_profit_snapshot', 'uniq_yfth_procurement_snapshot_order');
    $assert($nativeIndex === $expected, $label . '_native_order_unique_index');
    $assert($sourceIndex === $expected, $label . '_native_source_unique_index');
    $assert($legacyIndex === !$expected, $label . '_legacy_snapshot_unique_index');
}

function npcAssertMenus(callable $assert, bool $native, string $label): void
{
    $procurement = (array)Db::name('system_menus')->where('unique_auth', 'yfth-procurement-product-index')->find();
    $legacy = (array)Db::name('system_menus')->where('unique_auth', 'yfth-supply-chain-index')->find();
    $role = (array)Db::name('system_menus')->where('unique_auth', 'yfth-user-role-management-index')->find();
    $userRoot = (array)Db::name('system_menus')->where('unique_auth', 'admin-user')->find();

    $assert((int)($procurement['is_show'] ?? 0) === 1, $label . '_procurement_menu_visible');
    $assert(((int)($legacy['is_show'] ?? 0) === 0) === $native, $label . '_legacy_supply_menu_visibility');
    $assert(((int)($role['pid'] ?? 0) === (int)($userRoot['id'] ?? -1)) === $native, $label . '_user_role_parent');
}

function npcIndexExists(string $database, string $table, string $index): bool
{
    $rows = Db::query(
        'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$database, $table, $index]
    );
    return (int)($rows[0]['cnt'] ?? 0) > 0;
}
