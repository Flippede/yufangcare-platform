<?php

use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_STORE_ACQUISITION_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_STORE_ACQUISITION_MIGRATION_EXECUTE=1\n";
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
    $config = (array)Config::get('database.connections.' . $default);
    $database = (string)($config['database'] ?? '');
    $prefix = (string)($config['prefix'] ?? '');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $app->console->call('migrate:run');
    $assert(tableExists($prefix . 'yfth_store_acquisition_code'), 'migration_run_creates_code_table');
    $assert(tableExists($prefix . 'yfth_store_acquisition_acceptance'), 'migration_run_creates_acceptance_table');
    $assert(indexSignature($prefix . 'yfth_store_acquisition_code', 'uniq_yfth_acquisition_active', ['active_key'], true), 'active_code_unique_guard_exists');
    $assert(indexSignature($prefix . 'yfth_store_acquisition_acceptance', 'uniq_yfth_acquisition_customer', ['customer_uid'], true), 'single_customer_acceptance_unique_guard_exists');

    $app->console->call('migrate:rollback', ['--target', '20260718130000']);
    $assert(!tableExists($prefix . 'yfth_store_acquisition_code'), 'targeted_rollback_drops_code_table');
    $assert(!tableExists($prefix . 'yfth_store_acquisition_acceptance'), 'targeted_rollback_drops_acceptance_table');
    $app->console->call('migrate:run');
    $assert(tableExists($prefix . 'yfth_store_acquisition_code'), 'targeted_rerun_restores_code_table');
    $assert(tableExists($prefix . 'yfth_store_acquisition_acceptance'), 'targeted_rerun_restores_acceptance_table');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
}

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n"); exit(1); }
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH store acquisition migration lifecycle verified.\n";

function tableExists(string $table): bool
{
    return (int)(Db::query('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table])[0]['c'] ?? 0) === 1;
}

function indexSignature(string $table, string $index, array $columns, bool $unique): bool
{
    $rows = Db::query('SHOW INDEX FROM `' . $table . '` WHERE Key_name=?', [$index]);
    usort($rows, function ($a, $b) { return (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']; });
    return array_column($rows, 'Column_name') === $columns
        && (!$rows || ((int)$rows[0]['Non_unique'] === ($unique ? 0 : 1)));
}
