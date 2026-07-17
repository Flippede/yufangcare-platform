<?php

use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_PARTNER_MANUAL_GRANT_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_PARTNER_MANUAL_GRANT_MIGRATION_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$permissionKeys = [
    'yfth-user-role-partner-grant-options',
    'yfth-user-role-partner-grant',
];

try {
    $app = packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    $app->console->call('migrate:run');
    assertPartnerGrantPermissions($assert, $permissionKeys, 2, 'run');

    $app->console->call('migrate:rollback', ['--target', '20260719100000']);
    assertPartnerGrantPermissions($assert, $permissionKeys, 0, 'targeted_rollback');
    $assert((int)Db::name('yfth_partner_profile')->count() >= 0, 'targeted_rollback_preserves_partner_tables');

    $app->console->call('migrate:run');
    assertPartnerGrantPermissions($assert, $permissionKeys, 2, 'rerun');
    $app->console->call('migrate:run');
    assertPartnerGrantPermissions($assert, $permissionKeys, 2, 'duplicate_run');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
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
echo "[OK] YFTH partner manual grant permission migration lifecycle verified.\n";

function assertPartnerGrantPermissions(callable $assert, array $keys, int $expected, string $label): void
{
    $rows = Db::name('system_menus')->whereIn('unique_auth', $keys)
        ->field('unique_auth,api_url,methods,auth_type,is_show')->order('unique_auth asc')->select()->toArray();
    $assert(count($rows) === $expected, $label . ':permission_count_' . count($rows));
    foreach ($rows as $row) {
        $assert((int)$row['auth_type'] === 2 && (int)$row['is_show'] === 0, $label . ':hidden_api_permission:' . $row['unique_auth']);
    }
}
