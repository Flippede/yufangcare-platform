<?php

use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Migration\MigrationInterface;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_USER_ROLE_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_USER_ROLE_MIGRATION_EXECUTE=1\n";
    exit(0);
}

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
    $app = packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $config = (array)Config::get('database.connections.' . $default);
    $database = (string)($config['database'] ?? '');
    $prefix = (string)($config['prefix'] ?? '');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $app->console->call('migrate:run');
    $table = $prefix . 'yfth_permanent_membership';
    $migrationTable = $prefix . 'migrations';
    $assert(columnNullable($table), 'migration_run_allows_null_package_source');

    require_once dirname(__DIR__) . '/database/migrations/20260718130000_allow_headquarters_permanent_membership_grant.php';
    $adapter = migrationAdapter($config, $migrationTable);
    $migration = new AllowHeadquartersPermanentMembershipGrant(20260718130000);
    $migration->setAdapter($adapter);
    Db::execute('DELETE FROM `' . $migrationTable . '` WHERE `version`=20260718130000');
    $migration->down();
    $assert(!columnNullable($table), 'targeted_rollback_restores_required_package_source');
    $migration->up();
    $adapter->migrated($migration, MigrationInterface::UP, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'));
    $assert(columnNullable($table), 'targeted_rerun_restores_nullable_package_source');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH headquarters membership grant migration lifecycle verified.\n";

function columnNullable(string $table): bool
{
    $row = Db::query('SHOW COLUMNS FROM `' . $table . "` LIKE 'source_package_instance_id'");
    return strtoupper((string)($row[0]['Null'] ?? '')) === 'YES';
}

function migrationAdapter(array $config, string $migrationTable)
{
    $options = [
        'adapter' => $config['type'], 'host' => $config['hostname'], 'name' => $config['database'],
        'user' => $config['username'], 'pass' => $config['password'], 'port' => $config['hostport'],
        'charset' => $config['charset'], 'table_prefix' => $config['prefix'],
        'default_migration_table' => $migrationTable,
    ];
    $adapter = AdapterFactory::instance()->getAdapter($config['type'], $options);
    $adapter->setOptions($options);
    $adapter->connect();
    return $adapter;
}
