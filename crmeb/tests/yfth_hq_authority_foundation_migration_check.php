<?php

use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Migration\MigrationInterface;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_hq_authority_foundation_test_bootstrap.php';

if ((string)getenv('YFTH_HQ_AUTHORITY_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_HQ_AUTHORITY_MIGRATION_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$passes = [];
$notes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};

try {
    $app = hqAuthorityBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $database = (string)Config::get('database.connections.' . Config::get('database.default') . '.database');
    $prefix = (string)Config::get('database.connections.' . Config::get('database.default') . '.prefix');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);

    $output = $app->console->call('migrate:run');
    $notes[] = 'initial_migrate_run:' . trim($output->fetch());
    hqMigrationAssertTables($assert, true, $prefix, 'initial_run');

    require_once dirname(__DIR__) . '/database/migrations/20260713100000_create_yfth_hq_authority_foundation_tables.php';
    $adapter = hqMigrationAdapter();
    $migration = new CreateYfthHqAuthorityFoundationTables(20260713100000);
    $migration->setAdapter($adapter);
    $migrationTable = $prefix . 'migrations';

    $menuCount = (int)Db::name('system_menus')->count();
    $idempotencyTableExists = hqMigrationTableExists($prefix . 'yfth_idempotency_record');
    Db::execute('DELETE FROM `' . $migrationTable . '` WHERE `version` = 20260713100000');
    $migration->down();
    hqMigrationAssertTables($assert, false, $prefix, 'direct_down');
    $assert((int)Db::name('system_menus')->count() === $menuCount, 'stage1a_down_does_not_touch_menus');
    $assert(hqMigrationTableExists($prefix . 'yfth_idempotency_record') === $idempotencyTableExists, 'stage1a_down_does_not_touch_old_tables');
    $migration->up();
    $adapter->migrated($migration, MigrationInterface::UP, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'));
    hqMigrationAssertTables($assert, true, $prefix, 'direct_no_record_no_schema_up');

    $migration->up();
    hqMigrationAssertTables($assert, true, $prefix, 'record_present_full_schema_duplicate_up');
    $passes[] = 'duplicate_up_full_schema_is_noop';

    Db::execute('ALTER TABLE `' . $prefix . 'yfth_hq_customer_attribution_current` DROP INDEX `idx_yfth_hq_attr_status_update`');
    $blocked = false;
    try {
        $migration->up();
    } catch (Throwable $e) {
        $blocked = strpos($e->getMessage(), 'forward_repair_required') !== false;
    }
    $assert($blocked, 'record_present_incomplete_schema_blocks_for_forward_repair');
    Db::execute('ALTER TABLE `' . $prefix . 'yfth_hq_customer_attribution_current` ADD INDEX `idx_yfth_hq_attr_status_update` (`status`,`update_time`)');

    Db::execute('DELETE FROM `' . $migrationTable . '` WHERE `version` = 20260713100000');
    foreach (['yfth_hq_active_referral_event', 'yfth_hq_active_referral_current', 'yfth_hq_customer_attribution_event'] as $table) {
        Db::execute('DROP TABLE `' . $prefix . $table . '`');
    }
    Db::execute('ALTER TABLE `' . $prefix . 'yfth_hq_customer_attribution_current` DROP INDEX `idx_yfth_hq_attr_status_update`');
    $migration->up();
    $adapter->migrated($migration, MigrationInterface::UP, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'));
    hqMigrationAssertTables($assert, true, $prefix, 'record_absent_compatible_partial_schema_recovered');
    $assert(hqMigrationIndexExists($prefix . 'yfth_hq_customer_attribution_current', 'idx_yfth_hq_attr_status_update'), 'safe_missing_index_restored');

    $rollback = $app->console->call('migrate:rollback', ['--target', '0']);
    $notes[] = 'rollback_to_zero:' . trim($rollback->fetch());
    hqMigrationAssertTables($assert, false, $prefix, 'rollback_to_zero');

    $rerun = $app->console->call('migrate:run');
    $notes[] = 'rerun:' . trim($rerun->fetch());
    hqMigrationAssertTables($assert, true, $prefix, 'rerun');
} catch (Throwable $e) {
    $failures[] = 'migration_check_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
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
echo "[OK] YFTH headquarters authority migration lifecycle and half-state paths verified.\n";

function hqMigrationAdapter()
{
    $default = Config::get('database.default');
    $config = Config::get('database.connections.' . $default);
    $options = [
        'adapter' => $config['type'], 'host' => $config['hostname'], 'name' => $config['database'],
        'user' => $config['username'], 'pass' => $config['password'], 'port' => $config['hostport'],
        'charset' => $config['charset'], 'table_prefix' => $config['prefix'],
        'default_migration_table' => $config['prefix'] . Config::get('database.migration_table', 'migrations'),
    ];
    $adapter = AdapterFactory::instance()->getAdapter('mysql', $options);
    return AdapterFactory::instance()->getWrapper('prefix', $adapter);
}

function hqMigrationAssertTables(callable $assert, bool $expected, string $prefix, string $label): void
{
    foreach ([
        'yfth_hq_customer_attribution_current', 'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current', 'yfth_hq_active_referral_event',
    ] as $table) {
        $assert(hqMigrationTableExists($prefix . $table) === $expected, $label . ':' . $table);
    }
}

function hqMigrationTableExists(string $table): bool
{
    $row = Db::query('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
    return (int)$row[0]['c'] === 1;
}

function hqMigrationIndexExists(string $table, string $index): bool
{
    $row = Db::query('SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$table, $index]);
    return (int)$row[0]['c'] > 0;
}
