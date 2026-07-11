<?php

$root = dirname(__DIR__);
$repo = dirname($root);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};

$productionFiles = [
    'app/services/yfth/HqAuthoritySource.php',
    'app/services/yfth/HqAuthorityMutation.php',
    'app/services/yfth/HqAuthoritySourceCanonicalizer.php',
    'app/services/yfth/HqAuthorityOperationRunner.php',
    'app/services/yfth/HqCustomerAttributionServices.php',
    'app/services/yfth/HqActiveReferralServices.php',
    'app/services/yfth/ReferralQualificationPolicy.php',
    'app/services/yfth/FailClosedReferralQualificationPolicy.php',
];
$production = '';
foreach ($productionFiles as $path) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        $failures[] = 'missing_file:' . $path;
        continue;
    }
    $production .= (string)file_get_contents($full) . "\n";
}
$migration = (string)file_get_contents($root . '/database/migrations/20260713100000_create_yfth_hq_authority_foundation_tables.php');

foreach ([
    'member_5980', 'member_yfth', 'yfth_customer_relation', 'yfth_referral_candidate',
    'yfth_referral_attribution', 'yfth_reward_ledger', 'yfth_service_dynamic_code',
    'StoreOrder', 'store_order', 'now_money', 'brokerage_price', 'spread_uid',
] as $forbidden) {
    $assert(strpos($production, $forbidden) === false, 'production_authority_does_not_reference_' . $forbidden);
}
foreach (['test_', 'YFTH_', 'getenv(', 'Config::', 'skip', 'force'] as $forbidden) {
    $assert(strpos($production, $forbidden) === false, 'production_authority_has_no_test_or_bypass_marker_' . preg_replace('/\W+/', '_', $forbidden));
}
$assert(strpos($migration, 'system_menus') === false, 'migration_has_no_system_menus');
$assert(strpos($migration, 'api_url') === false && strpos($migration, 'unique_auth') === false, 'migration_has_no_api_permission');
$assert(strpos($migration, 'INSERT INTO') === false, 'migration_seeds_no_fixture_or_business_data');

$searchRoots = [
    'app/api/controller', 'app/adminapi/controller', 'app/api/route', 'app/adminapi/route',
    'app/command', 'app/listener', 'app/event.php',
];
foreach ($searchRoots as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!file_exists($path)) {
        continue;
    }
    $files = is_file($path) ? [$path] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $filePath = is_string($file) ? $file : $file->getPathname();
        if (pathinfo($filePath, PATHINFO_EXTENSION) !== 'php') {
            continue;
        }
        $text = (string)file_get_contents($filePath);
        if (strpos($text, 'HqCustomerAttributionServices') !== false || strpos($text, 'HqActiveReferralServices') !== false) {
            $failures[] = 'production_entry_references_authority_service:' . str_replace('\\', '/', $filePath);
        }
    }
}
$assert(!array_filter($failures, function ($item) { return strpos($item, 'production_entry_references_') === 0; }), 'no_controller_route_command_listener_or_job_entry');

$diff = [];
$exit = 0;
exec('git -C ' . escapeshellarg($repo) . ' diff --name-only main', $diff, $exit);
$assert($exit === 0, 'git_diff_scope_readable');
foreach ($diff as $path) {
    $normalized = str_replace('\\', '/', $path);
    $forbiddenPath = preg_match('#^(crmeb/app/(api|adminapi)/(controller|route)|crmeb/app/(command|listener)|template/)#', $normalized)
        || strpos($normalized, 'crmeb/app/event.php') === 0;
    $assert(!$forbiddenPath, 'no_forbidden_entry_diff:' . $normalized);
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
echo "[OK] YFTH headquarters authority foundation production-entry source guard verified.\n";
