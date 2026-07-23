<?php

use Phinx\Db\Adapter\AdapterFactory;
use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__);
if ((string)getenv('YFTH_PROCUREMENT_PARTNER_PROFIT_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_PROCUREMENT_PARTNER_PROFIT_MIGRATION_EXECUTE=1\n";
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
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};

try {
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = Config::get('database.default');
    $config = Config::get('database.connections.' . $default);
    $database = (string)$config['database'];
    $prefix = (string)$config['prefix'];
    $assert(strpos($version, '8.0.46') === 0, 'mysql_community_8_0_46:' . $version);
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);

    require_once $root . '/database/migrations/20260723150000_create_yfth_procurement_partner_profit_v1.php';
    $options = [
        'adapter' => $config['type'],
        'host' => $config['hostname'],
        'name' => $database,
        'user' => $config['username'],
        'pass' => $config['password'],
        'port' => $config['hostport'],
        'charset' => $config['charset'],
        'table_prefix' => $prefix,
        'default_migration_table' => $prefix . Config::get('database.migration_table', 'migrations'),
    ];
    $adapter = AdapterFactory::instance()->getAdapter('mysql', $options);
    $adapter = AdapterFactory::instance()->getWrapper('prefix', $adapter);
    $migration = new CreateYfthProcurementPartnerProfitV1(20260723150000);
    $migration->setAdapter($adapter);

    $migration->up();
    ppMigrationAssertState($assert, true, $database, $prefix, 'run');

    $migration->down();
    ppMigrationAssertState($assert, false, $database, $prefix, 'rollback');

    $migration->up();
    ppMigrationAssertState($assert, true, $database, $prefix, 'rerun');

    $migration->up();
    ppMigrationAssertState($assert, true, $database, $prefix, 'duplicate_run');
} catch (Throwable $e) {
    $failures[] = 'migration_check_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
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
echo "[OK] YFTH procurement partner profit migration run/rollback/rerun passed.\n";

function ppMigrationAssertState(callable $assert, bool $expected, string $database, string $prefix, string $label): void
{
    foreach ([
        'yfth_procurement_profit_snapshot',
        'yfth_procurement_profit_ledger',
        'yfth_partner_opening_reward_ledger',
        'yfth_platform_dividend_batch',
        'yfth_platform_dividend_item',
        'yfth_partner_service_area',
    ] as $table) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $prefix . $table]
        );
        $assert(((int)($rows[0]['cnt'] ?? 0) === 1) === $expected, $label . '_table_' . $table);
    }
    foreach (['procurement_rate_bps', 'opening_reward_amount_cent'] as $column) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $prefix . 'yfth_partner_rank_rule', $column]
        );
        $assert(((int)($rows[0]['cnt'] ?? 0) === 1) === $expected, $label . '_column_' . $column);
    }
    foreach ([
        'yfth-franchise-partner-procurement-profit-list',
        'yfth-franchise-partner-opening-reward-list',
        'yfth-franchise-partner-dividend-list',
        'yfth-franchise-partner-dividend-generate',
    ] as $permission) {
        $count = (int)Db::name('system_menus')->where('unique_auth', $permission)->count();
        $assert(($count === 1) === $expected, $label . '_permission_' . $permission);
    }
    if ($expected) {
        foreach ([
            ['yfth_procurement_profit_snapshot', 'uniq_yfth_procurement_snapshot_order'],
            ['yfth_procurement_profit_ledger', 'uniq_yfth_procurement_profit_source'],
            ['yfth_partner_opening_reward_ledger', 'uniq_yfth_opening_reward_source'],
            ['yfth_platform_dividend_batch', 'uniq_yfth_platform_dividend_batch'],
            ['yfth_platform_dividend_item', 'uniq_yfth_platform_dividend_item'],
        ] as $index) {
            $rows = Db::query(
                'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$database, $prefix . $index[0], $index[1]]
            );
            $assert((int)($rows[0]['cnt'] ?? 0) > 0, $label . '_index_' . $index[1]);
        }
    }
}
