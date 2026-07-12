<?php

use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Migration\MigrationInterface;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_hq_authority_foundation_test_bootstrap.php';

if ((string)getenv('YFTH_HQ_AUTHORITY_READONLY_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_HQ_AUTHORITY_READONLY_MIGRATION_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$passes = [];
$notes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

try {
    $app = hqAuthorityBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $prefix = (string)Config::get('database.connections.' . $default . '.prefix');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);

    $run = $app->console->call('migrate:run');
    $notes[] = 'initial_migrate_run:' . trim($run->fetch());
    hqrMigrationAssertPermissions($assert, 7, 'initial_run');
    hqrMigrationAssertStage1aTables($assert, true, $prefix, 'initial_run');

    require_once dirname(__DIR__) . '/database/migrations/20260714100000_add_yfth_hq_authority_readonly_permissions.php';
    $adapter = hqrMigrationAdapter();
    $migration = new AddYfthHqAuthorityReadonlyPermissions(20260714100000);
    $migration->setAdapter($adapter);
    $migrationTable = $prefix . Config::get('database.migration_table', 'migrations');

    $migration->up();
    hqrMigrationAssertPermissions($assert, 7, 'duplicate_up_with_record');
    hqrMigrationAssertConflictGates($migration, $adapter, $migrationTable, $assert);

    $otherYfthMenus = (int)Db::name('system_menus')->where('mark', 'yfth')->whereNotLike('unique_auth', 'yfth-hq-authority-%')->count();
    $stage1aTableSignatures = hqrStage1aTableSignatures($prefix);
    Db::execute('DELETE FROM `' . $migrationTable . '` WHERE `version` = 20260714100000');
    $migration->down();
    hqrMigrationAssertPermissions($assert, 0, 'direct_down');
    $assert((int)Db::name('system_menus')->where('mark', 'yfth')->whereNotLike('unique_auth', 'yfth-hq-authority-%')->count() === $otherYfthMenus, 'direct_down_preserves_other_yfth_menus');
    $assert(hqrStage1aTableSignatures($prefix) === $stage1aTableSignatures, 'direct_down_preserves_stage1a_tables');

    $migration->up();
    $adapter->migrated($migration, MigrationInterface::UP, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'));
    hqrMigrationAssertPermissions($assert, 7, 'no_record_no_permission_recovery');

    Db::execute('DELETE FROM `' . $migrationTable . '` WHERE `version` = 20260714100000');
    Db::name('system_menus')->whereIn('unique_auth', [
        'yfth-hq-authority-referral-detail', 'yfth-hq-authority-attribution-audit', 'yfth-hq-authority-referral-audit',
    ])->delete();
    $migration->up();
    $adapter->migrated($migration, MigrationInterface::UP, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'));
    hqrMigrationAssertPermissions($assert, 7, 'no_record_partial_permission_recovery');

    $rollback = $app->console->call('migrate:rollback', ['--target', '0']);
    $notes[] = 'rollback_to_zero:' . trim($rollback->fetch());
    hqrMigrationAssertPermissions($assert, 0, 'rollback_to_zero');
    hqrMigrationAssertStage1aTables($assert, false, $prefix, 'rollback_to_zero');

    $rerun = $app->console->call('migrate:run');
    $notes[] = 'rerun:' . trim($rerun->fetch());
    hqrMigrationAssertPermissions($assert, 7, 'rerun');
    hqrMigrationAssertStage1aTables($assert, true, $prefix, 'rerun');
    $duplicate = $app->console->call('migrate:run');
    $notes[] = 'duplicate_run:' . trim($duplicate->fetch());
    hqrMigrationAssertPermissions($assert, 7, 'duplicate_run');

    $names = Db::name('system_menus')->whereLike('unique_auth', 'yfth-hq-authority-%')->column('menu_name');
    $maxLength = 0;
    foreach ($names as $name) {
        $maxLength = max($maxLength, mb_strlen((string)$name));
    }
    $assert($maxLength <= 32, 'menu_name_strict_length_max:' . $maxLength);
    $distinct = (int)Db::query("SELECT COUNT(DISTINCT unique_auth) AS c FROM `{$prefix}system_menus` WHERE unique_auth LIKE 'yfth-hq-authority-%'")[0]['c'];
    $assert($distinct === 7, 'unique_auth_has_no_duplicates');
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
echo '[OK] YFTH headquarters authority Stage 1B permission migration lifecycle verified.' . PHP_EOL;

function hqrMigrationAssertPermissions(callable $assert, int $expected, string $label): void
{
    $rows = Db::name('system_menus')->whereLike('unique_auth', 'yfth-hq-authority-%')->field('unique_auth,api_url,methods,auth_type,menu_name')->select()->toArray();
    $assert(count($rows) === $expected, $label . ':permission_count_' . count($rows));
    if ($expected === 0) {
        return;
    }
    foreach ($rows as $row) {
        $assert((string)$row['methods'] === 'GET', $label . ':get_method:' . $row['unique_auth']);
        $assert(in_array((int)$row['auth_type'], [1, 2], true), $label . ':auth_type:' . $row['unique_auth']);
        $assert(mb_strlen((string)$row['menu_name']) <= 32, $label . ':menu_name_length:' . $row['unique_auth']);
    }
}

function hqrMigrationAssertStage1aTables(callable $assert, bool $expected, string $prefix, string $label): void
{
    foreach ([
        'yfth_hq_customer_attribution_current', 'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current', 'yfth_hq_active_referral_event',
    ] as $table) {
        $count = Db::query('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$prefix . $table]);
        $assert(((int)$count[0]['c'] === 1) === $expected, $label . ':stage1a_table:' . $table);
    }
}

function hqrMigrationAssertConflictGates($migration, $adapter, string $migrationTable, callable $assert): void
{
    $table = 'system_menus';
    $targetAuth = 'yfth-hq-authority-attribution-list';
    $row = Db::name($table)->where('unique_auth', $targetAuth)->find();
    $allBefore = Db::name($table)->whereLike('unique_auth', 'yfth-hq-authority-%')->order('id asc')->select()->toArray();
    $stage1aBefore = hqrStage1aTableSignatures((string)Config::get('database.connections.' . Config::get('database.default') . '.prefix'));
    $roleId = (int)Db::name('system_role')->insertGetId([
        'role_name' => 'HQR migration sentinel', 'rules' => (string)$row['id'], 'level' => 1, 'status' => 1,
    ]);
    $roleRules = (string)Db::name('system_role')->where('id', $roleId)->value('rules');

    $duplicate = $row;
    unset($duplicate['id']);
    $duplicateId = (int)Db::name($table)->insertGetId($duplicate);
    hqrMigrationExpectFailure(function () use ($migration) { $migration->up(); }, 'permission_duplicate', 'record_duplicate_fails_closed', $assert);
    $assert((int)Db::name($table)->where('unique_auth', $targetAuth)->count() === 2, 'record_duplicate_rows_not_deleted');
    $assert((string)Db::name('system_role')->where('id', $roleId)->value('rules') === $roleRules, 'record_duplicate_role_rules_unchanged');
    Db::name($table)->where('id', $duplicateId)->delete();

    $wrongFields = [
        'api_url' => 'yfth/hq_authority/wrong', 'methods' => 'POST', 'auth_type' => 1,
        'pid' => (int)$row['pid'] + 1000, 'menu_path' => '/wrong-stage1b-path',
    ];
    foreach ($wrongFields as $field => $wrong) {
        Db::name($table)->where('id', $row['id'])->update([$field => $wrong]);
        hqrMigrationExpectFailure(function () use ($migration) { $migration->up(); }, 'forward_repair_required', 'record_wrong_' . $field . '_fails_closed', $assert);
        $assert((string)Db::name($table)->where('id', $row['id'])->value($field) === (string)$wrong, 'record_wrong_' . $field . '_not_overwritten');
        Db::name($table)->where('id', $row['id'])->update([$field => $row[$field]]);
    }

    $missing = Db::name($table)->where('unique_auth', 'yfth-hq-authority-referral-detail')->find();
    Db::name($table)->where('id', $missing['id'])->delete();
    hqrMigrationExpectFailure(function () use ($migration) { $migration->up(); }, 'forward_repair_required', 'record_missing_permission_fails_closed', $assert);
    $assert((int)Db::name($table)->where('unique_auth', 'yfth-hq-authority-referral-detail')->count() === 0, 'record_missing_permission_not_silently_recreated');
    Db::name($table)->insert($missing);

    Db::execute('DELETE FROM `' . $migrationTable . '` WHERE `version` = 20260714100000');
    foreach ($wrongFields as $field => $wrong) {
        Db::name($table)->where('id', $row['id'])->update([$field => $wrong]);
        hqrMigrationExpectFailure(function () use ($migration) { $migration->up(); }, 'permission_signature_mismatch', 'no_record_wrong_' . $field . '_fails_closed', $assert);
        $assert((string)Db::name($table)->where('id', $row['id'])->value($field) === (string)$wrong, 'no_record_wrong_' . $field . '_not_overwritten');
        Db::name($table)->where('id', $row['id'])->update([$field => $row[$field]]);
    }
    $duplicateId = (int)Db::name($table)->insertGetId($duplicate);
    hqrMigrationExpectFailure(function () use ($migration) { $migration->up(); }, 'permission_duplicate', 'no_record_duplicate_fails_closed', $assert);
    Db::name($table)->where('id', $duplicateId)->delete();
    $migration->up();
    $adapter->migrated($migration, MigrationInterface::UP, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'));

    $duplicateId = (int)Db::name($table)->insertGetId($duplicate);
    hqrMigrationExpectFailure(function () use ($migration) { $migration->down(); }, 'permission_duplicate', 'down_duplicate_fails_closed', $assert);
    $assert((int)Db::name($table)->whereLike('unique_auth', 'yfth-hq-authority-%')->count() === 8, 'down_duplicate_deletes_nothing');
    Db::name($table)->where('id', $duplicateId)->delete();

    $assert((string)Db::name('system_role')->where('id', $roleId)->value('rules') === $roleRules, 'all_counterexamples_preserve_role_rules');
    $assert(hqrStage1aTableSignatures((string)Config::get('database.connections.' . Config::get('database.default') . '.prefix')) === $stage1aBefore, 'all_counterexamples_preserve_stage1a_signatures');
    $assert(Db::name($table)->whereLike('unique_auth', 'yfth-hq-authority-%')->order('id asc')->select()->toArray() === $allBefore, 'all_counterexamples_restore_permission_rows_exactly');
    Db::name('system_role')->where('id', $roleId)->delete();
}

function hqrMigrationExpectFailure(callable $operation, string $messagePart, string $label, callable $assert): void
{
    try {
        $operation();
        $assert(false, $label);
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $messagePart) !== false, $label . ':' . $e->getMessage());
    }
}

function hqrStage1aTableSignatures(string $prefix): array
{
    $result = [];
    foreach ([
        'yfth_hq_customer_attribution_current', 'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current', 'yfth_hq_active_referral_event',
    ] as $table) {
        $result[$table] = Db::query(
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
            [$prefix . $table]
        );
    }
    return $result;
}

function hqrMigrationAdapter()
{
    $default = (string)Config::get('database.default');
    $config = Config::get('database.connections.' . $default);
    $adapter = AdapterFactory::instance()->getAdapter('mysql', [
        'adapter' => $config['type'], 'host' => $config['hostname'], 'name' => $config['database'],
        'user' => $config['username'], 'pass' => $config['password'], 'port' => $config['hostport'],
        'charset' => $config['charset'], 'table_prefix' => $config['prefix'],
        'default_migration_table' => $config['prefix'] . Config::get('database.migration_table', 'migrations'),
    ]);
    return AdapterFactory::instance()->getWrapper('prefix', $adapter);
}
