<?php

use think\facade\Config;
use think\facade\Db;
use Phinx\Db\Adapter\AdapterFactory;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_CUSTOMER_SERVICE_POINTS_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_CUSTOMER_SERVICE_POINTS_MIGRATION_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};

try {
    $app = packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $config = (array)Config::get('database.connections.' . $default);
    $prefix = (string)($config['prefix'] ?? '');
    require_once dirname(__DIR__) . '/database/migrations/20260804100000_create_yfth_member_points_and_customer_service_v1.php';
    $adapter = AdapterFactory::instance()->getAdapter('mysql', [
        'adapter' => $config['type'], 'host' => $config['hostname'], 'name' => $database,
        'user' => $config['username'], 'pass' => $config['password'], 'port' => $config['hostport'],
        'charset' => $config['charset'], 'table_prefix' => $prefix,
        'default_migration_table' => $prefix . Config::get('database.migration_table', 'migrations'),
    ]);
    $migration = new CreateYfthMemberPointsAndCustomerServiceV1(20260804100000);
    $migration->setAdapter(AdapterFactory::instance()->getWrapper('prefix', $adapter));

    $migration->up();
    assertCustomerServicePointsSchema($assert, true, 'run');
    $migration->down();
    assertCustomerServicePointsSchema($assert, false, 'targeted_rollback');
    $migration->up();
    assertCustomerServicePointsSchema($assert, true, 'rerun');
    $migration->up();
    assertCustomerServicePointsSchema($assert, true, 'duplicate_run');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH customer service and member points migration lifecycle verified.\n";

function assertCustomerServicePointsSchema(callable $assert, bool $exists, string $label): void
{
    $default = (string)Config::get('database.default');
    $prefix = (string)Config::get('database.connections.' . $default . '.prefix', '');
    foreach ([
        'yfth_member_points_account', 'yfth_member_points_ledger', 'yfth_member_points_config',
        'yfth_member_points_product_rule', 'yfth_customer_service_store_binding',
    ] as $table) {
        $found = (int)Db::query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
            [$prefix . $table]
        )[0]['aggregate'] > 0;
        $assert($found === $exists, $label . ':table:' . $table . ':' . ($found ? 'exists' : 'missing'));
    }
    foreach ([
        [$prefix . 'yfth_member_points_account', 'uniq_yfth_member_points_uid'],
        [$prefix . 'yfth_member_points_ledger', 'uniq_yfth_member_points_source'],
        [$prefix . 'yfth_customer_service_store_binding', 'uniq_yfth_customer_service_active_store'],
    ] as $index) {
        $count = (int)Db::query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            $index
        )[0]['aggregate'];
        $assert($count === ($exists ? 1 : 0), $label . ':index:' . $index[1] . ':' . $count);
    }
}
