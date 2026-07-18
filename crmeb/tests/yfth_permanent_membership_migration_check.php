<?php

use Phinx\Db\Adapter\AdapterFactory;
use think\App;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
if ((string)getenv('YFTH_PERMANENT_MEMBERSHIP_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_execute=1\n";
    exit(0);
}

$app = new class() extends App {
    public function loadEnv(string $envName = ''): void
    {
        parent::loadEnv($envName);
        foreach (['HOSTNAME'=>'hostname','HOSTPORT'=>'hostport','USERNAME'=>'username','PASSWORD'=>'password','DATABASE'=>'database','PREFIX'=>'prefix','CHARSET'=>'charset'] as $env => $key) {
            $value = getenv('YFTH_REAL_FLOW_DB_' . $env);
            if ($value !== false) $this->env->set('database.' . $key, $value);
        }
        if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') $this->env->set('database.password', '');
        $this->env->set('cache.driver', 'file');
    }
};
$app->initialize();
require_once dirname(__DIR__) . '/database/migrations/20260715100000_create_yfth_permanent_membership_tables.php';

$failures = [];
$passes = [];
$assert = function (bool $ok, string $label) use (&$failures, &$passes): void {
    $ok ? $passes[] = $label : $failures[] = $label;
};

try {
    $config = Config::get('database.connections.mysql');
    $version = (string)(Db::query('SELECT VERSION() version')[0]['version'] ?? '');
    $database = (string)(Db::query('SELECT DATABASE() db')[0]['db'] ?? '');
    $prefix = (string)$config['prefix'];
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_guard');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_8_0_46:' . $version);
    $assert((bool)preg_match('/(test|sandbox|validation)/i', $database), 'isolated_database_name:' . $database);

    $raw = AdapterFactory::instance()->getAdapter('mysql', [
        'adapter'=>$config['type'],'host'=>$config['hostname'],'name'=>$config['database'],
        'user'=>$config['username'],'pass'=>$config['password'],'port'=>$config['hostport'],
        'charset'=>$config['charset'],'table_prefix'=>$prefix,
        'default_migration_table'=>$prefix . Config::get('database.migration_table', 'migrations'),
    ]);
    $migration = new CreateYfthPermanentMembershipTables(20260715100000);
    $migration->setAdapter(AdapterFactory::instance()->getWrapper('prefix', $raw));

    pmDeleteMigrationRecord();
    pmResetStage2($prefix);
    $migration->up();
    $migration->up();
    pmAssertComplete($prefix, $assert, 'initial');

    Db::execute('ALTER TABLE `' . $prefix . 'yfth_permanent_membership` DROP INDEX `uniq_yfth_pm_uid`');
    $migration->up();
    $index = Db::query("SHOW INDEX FROM `{$prefix}yfth_permanent_membership` WHERE Key_name='uniq_yfth_pm_uid'");
    $assert(count($index) === 1 && (int)$index[0]['Non_unique'] === 0 && (string)$index[0]['Column_name'] === 'uid', 'compatible_missing_index_repaired');

    $migration->down();
    pmAssertRemoved($prefix, $assert, 'down');
    $migration->up();
    pmAssertComplete($prefix, $assert, 'rerun');

    pmResetStage2($prefix);
    Db::execute('CREATE TABLE `' . $prefix . 'yfth_permanent_membership` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `membership_no` VARCHAR(64) NOT NULL DEFAULT \'\', PRIMARY KEY (`id`)) ENGINE=InnoDB');
    pmExpectFailure(function () use ($migration) { $migration->up(); }, 'missing_column:yfth_permanent_membership:uid', 'same_name_table_missing_uid_fails_closed', $assert);
    $assert(pmStage2TableCount($prefix) === 1 && pmPermissionCount() === 0, 'malformed_table_preflight_has_no_additional_artifacts');

    pmResetStage2($prefix);
    $migration->up();
    Db::execute('ALTER TABLE `' . $prefix . 'yfth_permanent_membership` DROP INDEX `uniq_yfth_pm_uid`, ADD INDEX `uniq_yfth_pm_uid` (`uid`)');
    pmExpectFailure(function () use ($migration) { $migration->up(); }, 'index_signature_mismatch:yfth_permanent_membership:uniq_yfth_pm_uid', 'wrong_unique_index_fails_closed', $assert);

    pmResetStage2($prefix);
    $migration->up();
    $page = Db::name('system_menus')->where('unique_auth', 'yfth-permanent-membership-index')->find();
    $duplicate = $page;
    unset($duplicate['id']);
    $duplicateId = (int)Db::name('system_menus')->insertGetId($duplicate);
    pmExpectFailure(function () use ($migration) { $migration->up(); }, 'permission_duplicate', 'duplicate_permission_fails_closed', $assert);
    Db::name('system_menus')->where('id', $duplicateId)->delete();

    $apiAuth = 'yfth-permanent-membership-enrollment-read';
    $api = Db::name('system_menus')->where('unique_auth', $apiAuth)->find();
    foreach ([
        ['api_url', 'yfth/wrong', 'wrong_permission_url_fails_closed'],
        ['methods', 'DELETE', 'wrong_permission_method_fails_closed'],
        ['auth_type', 1, 'wrong_permission_auth_type_fails_closed'],
        ['pid', (int)$api['pid'] + 999, 'wrong_permission_parent_fails_closed'],
        ['path', 'broken/path', 'wrong_permission_path_fails_closed'],
    ] as [$field, $wrong, $label]) {
        Db::name('system_menus')->where('id', (int)$api['id'])->update([$field => $wrong]);
        pmExpectFailure(function () use ($migration) { $migration->up(); }, 'permission_signature_mismatch', $label, $assert);
        Db::name('system_menus')->where('id', (int)$api['id'])->update([$field => $api[$field]]);
    }

    Db::name('system_menus')->where('id', (int)$api['id'])->update(['methods' => 'DELETE']);
    pmExpectFailure(function () use ($migration) { $migration->down(); }, 'down_signature_ambiguous', 'down_wrong_permission_stops_safely', $assert);
    $assert(pmStage2TableCount($prefix) === 5 && pmPermissionCount() === 7, 'down_failure_preserves_all_stage2_artifacts');
    Db::name('system_menus')->where('id', (int)$api['id'])->update(['methods' => $api['methods']]);

    pmInsertMigrationRecord();
    Db::execute('ALTER TABLE `' . $prefix . 'yfth_permanent_membership` DROP INDEX `uniq_yfth_pm_uid`');
    pmExpectFailure(function () use ($migration) { $migration->up(); }, 'forward_repair_required', 'recorded_missing_index_requires_forward_repair', $assert);
    pmDeleteMigrationRecord();
    $migration->up();

    pmInsertMigrationRecord();
    Db::name('system_menus')->where('unique_auth', 'yfth-permanent-membership-confirmation-code')->delete();
    pmExpectFailure(function () use ($migration) { $migration->up(); }, 'forward_repair_required', 'recorded_missing_permission_requires_forward_repair', $assert);
    pmDeleteMigrationRecord();
    $migration->up();
    pmAssertComplete($prefix, $assert, 'final');
} catch (Throwable $e) {
    $failures[] = 'migration_exception:' . $e->getMessage() . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] Stage 2 migration lifecycle and fail-closed counterexamples verified.\n";

function pmStage2Tables(): array
{
    return ['yfth_permanent_membership_enrollment','yfth_permanent_membership','yfth_permanent_membership_event','yfth_business_dynamic_code','yfth_membership_reward_candidate'];
}

function pmAuths(): array
{
    return ['yfth-permanent-membership-index','yfth-permanent-membership-enrollment-read','yfth-permanent-membership-member-read','yfth-permanent-membership-enrollment-create','yfth-permanent-membership-enrollment-bind','yfth-permanent-membership-payment-confirm','yfth-permanent-membership-confirmation-code'];
}

function pmResetStage2(string $prefix): void
{
    foreach (array_reverse(pmStage2Tables()) as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
    Db::name('system_menus')->whereIn('unique_auth', pmAuths())->delete();
    pmDeleteMigrationRecord();
}

function pmStage2TableCount(string $prefix): int
{
    $count = 0;
    foreach (pmStage2Tables() as $table) if (Db::query("SHOW TABLES LIKE '{$prefix}{$table}'")) $count++;
    return $count;
}

function pmPermissionCount(): int
{
    return (int)Db::name('system_menus')->whereIn('unique_auth', pmAuths())->count();
}

function pmAssertComplete(string $prefix, callable $assert, string $label): void
{
    $assert(pmStage2TableCount($prefix) === 5, $label . '_five_tables');
    $assert(pmPermissionCount() === 7, $label . '_seven_permissions');
}

function pmAssertRemoved(string $prefix, callable $assert, string $label): void
{
    $assert(pmStage2TableCount($prefix) === 0, $label . '_tables_removed');
    $assert(pmPermissionCount() === 0, $label . '_permissions_removed');
}

function pmExpectFailure(callable $callback, string $needle, string $label, callable $assert): void
{
    try {
        $callback();
        $assert(false, $label);
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $needle) !== false, $label . ':' . $e->getMessage());
    }
}

function pmInsertMigrationRecord(): void
{
    pmDeleteMigrationRecord();
    Db::name('migrations')->insert([
        'version' => '20260715100000',
        'migration_name' => 'CreateYfthPermanentMembershipTables',
        'start_time' => date('Y-m-d H:i:s'),
        'end_time' => date('Y-m-d H:i:s'),
        'breakpoint' => 0,
    ]);
}

function pmDeleteMigrationRecord(): void
{
    try {
        Db::name('migrations')->where('version', '20260715100000')->delete();
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'doesn\'t exist') === false) throw $e;
    }
}
