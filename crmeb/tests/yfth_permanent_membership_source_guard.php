<?php

$root = dirname(__DIR__);
$repo = dirname($root);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void { $condition ? $passes[] = $label : $failures[] = $label; };
$files = [
    'app/services/yfth/PermanentMembershipServices.php',
    'app/services/yfth/PermanentMembershipReferralQualificationPolicy.php',
    'app/api/controller/v1/yfth/PermanentMembershipController.php',
    'app/api/controller/v1/yfth/PermanentMembershipStoreController.php',
    'app/adminapi/controller/v1/yfth/PermanentMembership.php',
    'database/migrations/20260715100000_create_yfth_permanent_membership_tables.php',
];
$production = '';
$controllers = '';
foreach ($files as $path) {
    $file = $root . '/' . $path;
    if (!is_file($file)) { $failures[] = 'missing_file:' . $path; continue; }
    $production .= file_get_contents($file) . "\n";
    if (strpos($path, 'app/api/controller/') !== false) $controllers .= file_get_contents($file) . "\n";
}
foreach (['member_5980','yfth_reward_ledger','yfth_referral_candidate','store_order','StoreOrder','now_money','brokerage_price','spread_uid','commission','withdraw','settlement','wallet'] as $forbidden) {
    $assert(stripos($production, $forbidden) === false, 'stage2_does_not_reference_' . $forbidden);
}
$assert(strpos($controllers, "['target_uid'") === false && strpos($controllers, "['phone'") === false && strpos($controllers, "['mobile'") === false, 'controllers_do_not_accept_target_identity_fields');
$assert(strpos($production, 'force_activate') === false && strpos($production, 'refund') === false && strpos($production, 'takeover') === false, 'no_forbidden_membership_mutation');
$assert(strpos($production, 'YFTH_REAL_FLOW') === false && strpos($production, 'getenv(') === false, 'production_has_no_test_switch');

$diff = []; $exit = 0;
exec('git -c core.quotePath=false -C ' . escapeshellarg($repo) . ' diff --name-only main', $diff, $exit);
$assert($exit === 0, 'git_diff_scope_readable');
foreach ($diff as $path) {
    $normalized = str_replace('\\', '/', $path);
    if (strpos($normalized, '项目文档/') === 0) continue;
    $allowed = preg_match('#^(crmeb/(app/(model|dao|services)/yfth/Yfth?(PermanentMembership|BusinessDynamicCode|MembershipRewardCandidate)|app/services/yfth/(PermanentMembership|HqAuthoritySourceCanonicalizer|HqCustomerAttribution|HqActiveReferral)|app/(api|adminapi)/(controller|route)|database/migrations/20260715100000|tests/yfth_(permanent_membership|hq_authority_(foundation|readonly)))|template/(admin|uni-app)/|docs/)#', $normalized);
    $assert((bool)$allowed, 'diff_is_stage2_scoped:' . $normalized);
}

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n"); exit(1); }
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH permanent membership Stage 2 source guard verified.\n";
