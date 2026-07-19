<?php

use Phinx\Db\Adapter\AdapterFactory;
use think\App;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if ((string)getenv('YFTH_PERMANENT_MEMBERSHIP_COMPAT_EXECUTE') !== '1') {
    echo "[NOTE] compatibility_check_skipped_set_execute=1\n";
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
$assert = function (bool $ok, string $label) use (&$failures): void {
    if ($ok) echo "[PASS] {$label}\n";
    else $failures[] = $label;
};

try {
    $config = Config::get('database.connections.mysql');
    $prefix = (string)$config['prefix'];
    $version = (string)(Db::query('SELECT VERSION() version')[0]['version'] ?? '');
    $database = (string)(Db::query('SELECT DATABASE() db')[0]['db'] ?? '');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_guard');
    $assert(strpos($version, '8.0.46') === 0, 'mysql_8_0_46');
    $assert((bool)preg_match('/(test|validation|sandbox)/i', $database), 'isolated_database_name');
    $assert(pmCompatMigrationExists('20260716100000'), 'package_membership_v2_preexists');
    $assert(!pmCompatMigrationExists('20260715100000'), 'offline_membership_not_recorded');

    $membershipCount = (int)Db::name('yfth_permanent_membership')->count();
    $eventCount = (int)Db::name('yfth_permanent_membership_event')->count();
    $raw = AdapterFactory::instance()->getAdapter('mysql', [
        'adapter'=>$config['type'],'host'=>$config['hostname'],'name'=>$config['database'],
        'user'=>$config['username'],'pass'=>$config['password'],'port'=>$config['hostport'],
        'charset'=>$config['charset'],'table_prefix'=>$prefix,
        'default_migration_table'=>$prefix . Config::get('database.migration_table', 'migrations'),
    ]);
    $migration = new CreateYfthPermanentMembershipTables(20260715100000);
    $migration->setAdapter(AdapterFactory::instance()->getWrapper('prefix', $raw));

    $migration->up();
    $migration->up();
    foreach (['enrollment_id','amount_cents','source_id','source_package_instance_id'] as $column) {
        $assert(pmCompatColumnExists($prefix . 'yfth_permanent_membership', $column), 'membership_column_' . $column);
    }
    foreach (['membership_no','source_unique_key','actual_paid_amount_cent'] as $column) {
        $assert(pmCompatColumnExists($prefix . 'yfth_permanent_membership_event', $column), 'event_column_' . $column);
    }
    foreach (['yfth_permanent_membership_enrollment','yfth_business_dynamic_code','yfth_membership_reward_candidate'] as $table) {
        $assert(pmCompatTableExists($prefix . $table), 'offline_table_' . $table);
    }
    $assert((int)Db::name('yfth_permanent_membership')->count() === $membershipCount, 'membership_rows_preserved');
    $assert((int)Db::name('yfth_permanent_membership_event')->count() === $eventCount, 'event_rows_preserved');

    $migration->down();
    $assert(pmCompatTableExists($prefix . 'yfth_permanent_membership'), 'shared_membership_preserved_on_down');
    $assert(pmCompatTableExists($prefix . 'yfth_permanent_membership_event'), 'shared_event_preserved_on_down');
    $assert(!pmCompatColumnExists($prefix . 'yfth_permanent_membership', 'enrollment_id'), 'offline_membership_column_removed');
    $assert(!pmCompatColumnExists($prefix . 'yfth_permanent_membership_event', 'membership_no'), 'offline_event_column_removed');
    $assert((int)Db::name('yfth_permanent_membership')->count() === $membershipCount, 'membership_rows_preserved_after_down');

    $migration->up();
    $assert(pmCompatColumnExists($prefix . 'yfth_permanent_membership', 'enrollment_id'), 'compatibility_rerun');
} catch (Throwable $e) {
    $failures[] = 'compatibility_exception:' . $e->getMessage() . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
echo "[OK] Permanent membership production-order compatibility verified.\n";

function pmCompatMigrationExists(string $version): bool
{
    return (int)Db::name('migrations')->where('version', $version)->count() === 1;
}

function pmCompatTableExists(string $table): bool
{
    return !empty(Db::query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1', [$table]));
}

function pmCompatColumnExists(string $table, string $column): bool
{
    return !empty(Db::query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1', [$table, $column]));
}
