<?php

use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_FUND_WITHDRAWAL_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_FUND_WITHDRAWAL_MIGRATION_EXECUTE=1\n";
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
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    $app->console->call('migrate:run');
    assertFundWithdrawalSchema($assert, true, 'run');

    $app->console->call('migrate:rollback', ['--target', '20260724120000']);
    assertFundWithdrawalSchema($assert, false, 'targeted_rollback');

    $app->console->call('migrate:run');
    assertFundWithdrawalSchema($assert, true, 'rerun');
    $app->console->call('migrate:run');
    assertFundWithdrawalSchema($assert, true, 'duplicate_run');
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
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
echo "[OK] YFTH fund withdrawal migration lifecycle verified.\n";

function assertFundWithdrawalSchema(callable $assert, bool $exists, string $label): void
{
    $default = (string)Config::get('database.default');
    $prefix = (string)Config::get('database.connections.' . $default . '.prefix', '');
    foreach ([
        'yfth_fund_withdrawal_setting',
        'yfth_fund_withdrawal_request',
        'yfth_fund_withdrawal_allocation',
        'yfth_fund_beneficiary_profile',
    ] as $table) {
        $found = (int)Db::query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$prefix . $table]
        )[0]['aggregate'] > 0;
        $assert($found === $exists, $label . ':table:' . $table . ':' . ($exists ? 'exists' : 'missing'));
    }
    $beneficiaryIndex = (int)Db::query(
        'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
        [$prefix . 'yfth_fund_beneficiary_profile', 'uniq_yfth_fund_beneficiary_uid']
    )[0]['aggregate'];
    $assert($beneficiaryIndex === ($exists ? 1 : 0), $label . ':beneficiary_unique_index:' . $beneficiaryIndex);
    $count = (int)Db::name('system_menus')->whereIn('unique_auth', [
        'yfth-finance',
        'yfth-finance-withdrawal-index',
        'yfth-finance-withdrawal-read',
        'yfth-finance-withdrawal-detail',
        'yfth-finance-withdrawal-review',
        'yfth-finance-withdrawal-pay',
    ])->count();
    $assert($count === ($exists ? 6 : 0), $label . ':finance_permission_count:' . $count);
}
