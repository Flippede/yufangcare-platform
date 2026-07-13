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

$productionFiles = [
    'app/services/yfth/PackageMembershipServices.php',
    'app/services/yfth/PackageMembershipReferralServices.php',
    'app/services/yfth/PackageMembershipActivationCoordinator.php',
    'app/services/yfth/PackageMembershipReferralQualificationPolicy.php',
    'app/services/yfth/DirectReferralRewardServices.php',
    'app/api/controller/v1/yfth/PackageMembershipReferralController.php',
    'app/api/controller/v1/yfth/PackageMembershipReferralStoreController.php',
    'app/adminapi/controller/v1/yfth/PackageMembershipReferral.php',
];
$production = '';
foreach ($productionFiles as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $failures[] = 'missing_file:' . $file;
        continue;
    }
    $production .= (string)file_get_contents($path) . "\n";
}

foreach ([
    'member_5980', 'member_yfth', 'yfth_customer_relation', 'yfth_referral_candidate',
    'yfth_reward_ledger', 'now_money', 'brokerage_price', 'spread_uid', 'agent_level',
    'commission', 'settlement', 'payout', 'cash_out', 'withdraw',
] as $forbidden) {
    $assert(stripos($production, $forbidden) === false, 'production_excludes:' . $forbidden);
}
$assert(!preg_match('/\b(?:5980|9800)\b/', $production), 'production_has_no_hardcoded_package_price');

$canonicalizer = (string)file_get_contents($root . '/app/services/yfth/HqAuthoritySourceCanonicalizer.php');
preg_match('/__construct\(array \$allowedSourceTypes = \[(.*?)\]\)/s', $canonicalizer, $matches);
preg_match_all("/'([^']+)'/", $matches[1] ?? '', $sourceMatches);
$actualSources = $sourceMatches[1] ?? [];
$assert($actualSources === [
    'package_membership_referral_invite',
    'package_membership_activation',
    'historical_package_activation',
], 'production_authority_source_allowlist_is_exact');

$listeners = '';
foreach (['app/event.php', 'app/listener', 'app/command'] as $relative) {
    $path = $root . '/' . $relative;
    if (!file_exists($path)) {
        continue;
    }
    $iterator = is_file($path) ? [$path] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $filePath = is_string($file) ? $file : $file->getPathname();
        if (is_file($filePath)) {
            $listeners .= (string)file_get_contents($filePath) . "\n";
        }
    }
}
$assert(strpos($listeners, 'recordMallOrderPaid') === false, 'mall_order_extension_is_not_prematurely_wired');

$controller = (string)file_get_contents($root . '/app/api/controller/v1/yfth/PackageMembershipReferralController.php');
$acceptStart = strpos($controller, 'public function acceptInvite');
$acceptEnd = strpos($controller, 'public function candidates', $acceptStart);
$accept = substr($controller, $acceptStart, $acceptEnd - $acceptStart);
$payloadStart = strpos($accept, '$data = $request->postMore');
$payloadEnd = strpos($accept, '$data[\'idempotency_key\']', $payloadStart);
$payload = substr($accept, $payloadStart, $payloadEnd - $payloadStart);
$assert(strpos($accept, '(int)$request->uid()') !== false, 'invite_accept_uid_comes_from_token');
$assert(strpos($payload, "['invite_token', '']") !== false, 'invite_accept_reads_only_token_payload');
$assert(strpos($payload, "['idempotency_key', '']") !== false, 'invite_accept_requires_idempotency_payload');
$assert(strpos($payload, "['store_id'") === false, 'invite_accept_does_not_accept_store_id');

$diff = [];
$exit = 0;
exec('git -C ' . escapeshellarg($repo) . ' diff --name-only main', $diff, $exit);
$assert($exit === 0, 'git_diff_scope_readable');
foreach ($diff as $path) {
    $normalized = str_replace('\\', '/', $path);
    $assert(strpos($normalized, 'codex/yfth-hq-mall-stage2-permanent-membership-v1') === false, 'stopped_branch_not_imported:' . $normalized);
    $assert(!preg_match('#(^|/)(?:node_modules|unpackage|dist|runtime|logs?)(/|$)#i', $normalized), 'no_generated_or_runtime_artifact:' . $normalized);
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
echo "[OK] YFTH package membership and direct referral V2 source guard verified.\n";
