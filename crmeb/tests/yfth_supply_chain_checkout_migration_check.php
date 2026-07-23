<?php

use Phinx\Db\Adapter\AdapterFactory;
use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__);
if ((string)getenv('YFTH_SUPPLY_CHAIN_CHECKOUT_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_SUPPLY_CHAIN_CHECKOUT_MIGRATION_EXECUTE=1\n";
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
    if (!$condition) {
        $failures[] = $label;
    } else {
        echo "[PASS] {$label}\n";
    }
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

    require_once $root . '/database/migrations/20260723193000_extend_yfth_purchase_order_checkout.php';
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
    $adapter = AdapterFactory::instance()->getWrapper('prefix', $adapter);
    $migration = new ExtendYfthPurchaseOrderCheckout(20260723193000);
    $migration->setAdapter($adapter);

    $migration->up();
    assertCheckoutColumns($assert, true, $database, $prefix, 'run');
    $migration->down();
    assertCheckoutColumns($assert, false, $database, $prefix, 'rollback');
    $migration->up();
    assertCheckoutColumns($assert, true, $database, $prefix, 'rerun');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage() . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}
echo "[OK] YFTH supply-chain checkout migration run/rollback/rerun passed.\n";

function assertCheckoutColumns(callable $assert, bool $expected, string $database, string $prefix, string $label): void
{
    foreach ([
        'address_id',
        'real_name',
        'user_phone',
        'user_address',
        'freight_price',
        'pay_type',
        'pay_status',
        'buyer_mark',
    ] as $column) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $prefix . 'yfth_purchase_order', $column]
        );
        $assert(((int)($rows[0]['cnt'] ?? 0) === 1) === $expected, $label . '_column_' . $column);
    }
}
