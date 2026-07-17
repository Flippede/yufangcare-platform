<?php

use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_USER_ACCOUNT_CLOSURE_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_USER_ACCOUNT_CLOSURE_MIGRATION_EXECUTE=1\n";
    exit(0);
}
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$legacy = [
    'yfth-user-debug-purge-preflight',
    'yfth-user-debug-purge-execute',
];
$formal = [
    'yfth-user-account-closure-preflight',
    'yfth-user-account-closure-execute',
];

try {
    $app = packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $app->console->call('migrate:run');
    assertClosurePermissions($assert, $formal, $legacy, true, 'run');

    $app->console->call('migrate:rollback', ['--target', '20260719110000']);
    assertClosurePermissions($assert, $formal, $legacy, false, 'targeted_rollback');

    $app->console->call('migrate:run');
    assertClosurePermissions($assert, $formal, $legacy, true, 'rerun');
    $app->console->call('migrate:run');
    assertClosurePermissions($assert, $formal, $legacy, true, 'duplicate_run');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH formal user account closure permission migration lifecycle verified.\n";

function assertClosurePermissions(callable $assert, array $formal, array $legacy, bool $expectFormal, string $label): void
{
    $formalRows = Db::name('system_menus')->whereIn('unique_auth', $formal)
        ->field('unique_auth,api_url,methods,auth_type,is_show')->order('unique_auth asc')->select()->toArray();
    $legacyCount = (int)Db::name('system_menus')->whereIn('unique_auth', $legacy)->count();
    $assert(count($formalRows) === ($expectFormal ? 2 : 0), $label . ':formal_permission_count_' . count($formalRows));
    $assert($legacyCount === ($expectFormal ? 0 : 2), $label . ':legacy_permission_count_' . $legacyCount);
    foreach ($formalRows as $row) {
        $assert((int)$row['auth_type'] === 2 && (int)$row['is_show'] === 0, $label . ':hidden_api_permission:' . $row['unique_auth']);
        $assert(in_array(strtoupper((string)$row['methods']), ['GET', 'DELETE'], true), $label . ':method:' . $row['unique_auth']);
        $assert(strpos((string)$row['api_url'], '/closure') !== false, $label . ':route:' . $row['unique_auth']);
    }
}
