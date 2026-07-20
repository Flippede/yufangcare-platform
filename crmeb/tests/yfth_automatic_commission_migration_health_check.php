<?php

use app\services\yfth\AutomaticCommissionMigrationHealthServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_AUTOMATIC_COMMISSION_MIGRATION_HEALTH_EXECUTE') !== '1') {
    echo "[NOTE] migration_health_skipped_set_YFTH_AUTOMATIC_COMMISSION_MIGRATION_HEALTH_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $app = app();
    $health = app()->make(AutomaticCommissionMigrationHealthServices::class);
    $app->console->call('migrate:run');
    $complete = $health->report();
    $assert(!empty($complete['healthy']), 'complete_migration_is_healthy');

    $app->console->call('migrate:rollback', ['--target', '20260720200000']);
    $partial = $health->report();
    $assert(empty($partial['healthy']) && in_array('migration:20260720210000', (array)$partial['missing'], true),
        'partial_v2_rollback_is_fail_closed');

    $app->console->call('migrate:rollback', ['--target', '20260720100000']);
    $unmigrated = $health->report();
    $assert(empty($unmigrated['healthy']) && in_array('migration:20260720200000', (array)$unmigrated['missing'], true),
        'unmigrated_automatic_commission_is_fail_closed');

    $app->console->call('migrate:run');
    $rerun = $health->report();
    $assert(!empty($rerun['healthy']), 'rerun_restores_healthy_state');
    $app->console->call('migrate:run');
    $duplicate = $health->report();
    $assert(!empty($duplicate['healthy']), 'duplicate_run_remains_healthy');

    $prefix = (string)Config::get('database.connections.' . $default . '.prefix');
    $indexTable = $prefix . 'yfth_commission_accrual';
    $indexName = 'uniq_yfth_commission_accrual_source';
    $indexRows = Db::query(
        'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX ASC',
        [$indexTable, $indexName]
    );
    $assert(!empty($indexRows), 'fixture_index_exists_before_removal');
    $quotedTable = '`' . str_replace('`', '``', $indexTable) . '`';
    $quotedIndex = '`' . str_replace('`', '``', $indexName) . '`';
    Db::execute('ALTER TABLE ' . $quotedTable . ' DROP INDEX ' . $quotedIndex);
    $missingIndex = $health->report();
    $assert(empty($missingIndex['healthy'])
        && in_array('index:yfth_commission_accrual.' . $indexName, (array)$missingIndex['missing'], true),
        'missing_key_index_is_fail_closed');
    $quotedColumns = implode(', ', array_map(function (array $row): string {
        return '`' . str_replace('`', '``', (string)$row['COLUMN_NAME']) . '`';
    }, $indexRows));
    $unique = (int)$indexRows[0]['NON_UNIQUE'] === 0 ? 'UNIQUE ' : '';
    Db::execute('ALTER TABLE ' . $quotedTable . ' ADD ' . $unique . 'INDEX ' . $quotedIndex . ' (' . $quotedColumns . ')');
    $assert(!empty($health->report()['healthy']), 'restored_key_index_is_healthy');

    $permissionAuth = 'yfth-auto-commission-settlement-write';
    $permission = Db::name('system_menus')->where('unique_auth', $permissionAuth)->find();
    $assert(!empty($permission), 'fixture_permission_exists_before_removal');
    Db::name('system_menus')->where('unique_auth', $permissionAuth)->delete();
    $missingPermission = $health->report();
    $assert(empty($missingPermission['healthy'])
        && in_array('permission:' . $permissionAuth, (array)$missingPermission['missing'], true),
        'missing_permission_is_fail_closed');
    if ($permission) {
        Db::name('system_menus')->insert($permission);
    }
    $assert(!empty($health->report()['healthy']), 'restored_permission_is_healthy');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, '[FAIL] ' . $failure . "\n");
    exit(1);
}
foreach ($passes as $pass) echo '[PASS] ' . $pass . "\n";
echo "[OK] automatic_commission_migration_health_lifecycle_verified\n";
