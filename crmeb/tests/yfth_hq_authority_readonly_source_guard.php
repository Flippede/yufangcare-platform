<?php

$root = dirname(__DIR__);
$repo = dirname($root);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

$relativeFiles = [
    'app/services/yfth/HqAuthorityDtoServices.php',
    'app/services/yfth/HqAuthorityReadServices.php',
    'app/services/yfth/HqAuthorityUserReadServices.php',
    'app/services/yfth/HqAuthorityStoreReadServices.php',
    'app/services/yfth/HqAuthorityAdminReadServices.php',
    'app/services/yfth/HqAuthorityAuditReadServices.php',
    'app/api/controller/v1/yfth/HqAuthorityReadController.php',
    'app/api/controller/v1/yfth/HqAuthorityStoreReadController.php',
    'app/adminapi/controller/v1/yfth/HqAuthorityRead.php',
];
$production = '';
foreach ($relativeFiles as $file) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (!is_file($full)) {
        $failures[] = 'missing_file:' . $file;
        continue;
    }
    $production .= file_get_contents($full) . "\n";
}

foreach ([
    'HqCustomerAttributionServices', 'HqActiveReferralServices', 'HqAuthorityOperationRunner',
    'ensurePlaceholder', 'assignFirst(', 'markHistoricalUnassigned(', '->pause(', '->resume(', '->close(', '->invalidate(',
    '->save(', '->update(', '->delete(', 'Db::transaction', 'yfth_idempotency_record',
    'source_unique_key', 'idempotency_key', 'member_5980', 'member_yfth',
    'yfth_customer_relation', 'yfth_referral_candidate', 'yfth_reward_ledger', 'store_order',
] as $forbidden) {
    $assert(strpos($production, $forbidden) === false, 'production_readonly_excludes:' . preg_replace('/\W+/', '_', $forbidden));
}

$apiRoute = (string)file_get_contents($root . '/app/api/route/yfth_service.php');
$adminRoute = (string)file_get_contents($root . '/app/adminapi/route/yfth.php');
foreach (preg_split('/\R/', $apiRoute . "\n" . $adminRoute) as $line) {
    if (strpos($line, 'HqAuthority') === false && strpos($line, "group('hq_authority'") === false) {
        continue;
    }
    if (strpos($line, 'Route::group') === false && strpos($line, 'Route::') !== false) {
        $assert(strpos($line, 'Route::get(') !== false, 'stage1b_route_get_only:' . trim($line));
    }
}

$migration = (string)file_get_contents($root . '/database/migrations/20260714100000_add_yfth_hq_authority_readonly_permissions.php');
foreach (['INSERT INTO `' . 'eb_yfth', 'addColumn(', 'create()', 'seedFixture', 'test_'] as $forbidden) {
    $assert(strpos($migration, $forbidden) === false, 'permission_migration_has_no_business_seed_or_schema:' . preg_replace('/\W+/', '_', $forbidden));
}

$canonicalizer = (string)file_get_contents($root . '/app/services/yfth/HqAuthoritySourceCanonicalizer.php');
$qualification = (string)file_get_contents($root . '/app/services/yfth/FailClosedReferralQualificationPolicy.php');
$assert(strpos($canonicalizer, '__construct(array $allowedSourceTypes = [])') !== false, 'production_source_allowlist_remains_empty_by_default');
$assert(strpos($qualification, 'permanent_membership_authority_unavailable') !== false, 'production_referral_qualification_remains_fail_closed');

$adminApi = (string)file_get_contents($repo . '/template/admin/src/api/yfth.js');
$uniApi = (string)file_get_contents($repo . '/template/uni-app/api/yfth.js');
$adminStage = substr($adminApi, strpos($adminApi, 'export function yfthHqAuthorityAttributionList'));
$uniStart = strpos($uniApi, 'export function getYfthMyHqAuthority');
$uniEnd = strpos($uniApi, 'function splitYfthContext', $uniStart);
$uniStage = substr($uniApi, $uniStart, $uniEnd - $uniStart);
$assert(strpos($adminStage, "method: 'post'") === false, 'admin_stage1b_api_get_only');
$assert(strpos($uniStage, 'request.post') === false, 'uni_stage1b_api_get_only');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}
foreach ($passes as $pass) {
    echo "[PASS] {$pass}\n";
}
echo '[OK] YFTH headquarters authority Stage 1B read-only source guard verified.' . PHP_EOL;
