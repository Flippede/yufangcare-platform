<?php

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_RELEASE_CANDIDATE_MIGRATION_EXECUTE') !== '1') {
    echo "[NOTE] migration_check_skipped_set_YFTH_RELEASE_CANDIDATE_MIGRATION_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    $app = packageMembershipReferralBootTestApp();
    $config = $app->config->get('database.connections.' . $app->config->get('database.default'));
    $database = (string)($config['database'] ?? '');
    $prefix = (string)($config['prefix'] ?? 'eb_');
    $version = (string)(\think\facade\Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');

    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'isolated_database_name:' . $database);
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_8_0_46:' . $version);

    if (!$failures) {
        $app->console->call('migrate:run');
        $assert(true, 'migration_run');
        releaseCandidateAssertCoreTables($assert, $prefix, 'run');

        $app->console->call('migrate:rollback', ['--target', '0']);
        $assert(true, 'migration_rollback_to_zero');
        releaseCandidateAssertCoreTables($assert, $prefix, 'rollback', false);

        $app->console->call('migrate:run');
        $assert(true, 'migration_rerun');
        releaseCandidateAssertCoreTables($assert, $prefix, 'rerun');
    }
} catch (Throwable $e) {
    $failures[] = 'migration_check_exception:' . $e->getMessage();
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

echo "[OK] YFTH release candidate migration lifecycle verified.\n";

function releaseCandidateAssertCoreTables(callable $assert, string $prefix, string $phase, bool $expected = true): void
{
    foreach ([
        'yfth_permanent_membership',
        'yfth_direct_referral_reward_candidate',
        'yfth_direct_referral_reward_settlement_ledger',
    ] as $table) {
        $exists = \think\facade\Db::query("SHOW TABLES LIKE '" . addslashes($prefix . $table) . "'") !== [];
        $assert($exists === $expected, $phase . '_table_' . ($expected ? 'exists:' : 'removed:') . $table);
    }

    if ($expected) {
        $indexRows = \think\facade\Db::query('SHOW INDEX FROM `' . $prefix . 'yfth_direct_referral_reward_settlement_ledger` WHERE Key_name = "uniq_yfth_direct_settlement_candidate"');
        $assert($indexRows !== [], $phase . '_settlement_candidate_unique_index');
    }
}
