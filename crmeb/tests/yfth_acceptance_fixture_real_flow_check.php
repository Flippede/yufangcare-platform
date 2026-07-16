<?php

use app\services\yfth\HqAcceptanceFixtureServices;
use app\services\yfth\PackageMembershipReferralServices;
use app\services\user\LoginServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$expect = function (callable $operation, string $message, string $label) use ($assert): void {
    try { $operation(); $assert(false, $label . ':no_exception'); }
    catch (Throwable $e) { $assert(strpos($e->getMessage(), $message) !== false, $label . ':' . $e->getMessage()); }
};

if ((string)getenv('YFTH_ACCEPTANCE_FIXTURE_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_ACCEPTANCE_FIXTURE_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $credentialFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth-acceptance-fixture-' . getmypid() . '.txt';
    @unlink($credentialFile);
    Config::set(['acceptance_fixture_enabled' => true, 'acceptance_account_file' => $credentialFile], 'yfth');
    $hq = ['id' => 1, 'level' => 0];
    $storeAdmin = ['id' => 990001, 'level' => 1];
    $service = app()->make(HqAcceptanceFixtureServices::class);

    $expect(function () use ($service, $storeAdmin) {
        $service->generate(['reason' => 'forbidden store generation'], 990001, $storeAdmin);
    }, 'headquarter_permission_required', 'store_admin_cannot_generate_fixture');

    $first = $service->generate(['reason' => 'isolated acceptance generation', 'request_id' => 'fixture-real-flow-1'], 1, $hq);
    $assert($first['exists'] && $first['status'] === 'active', 'fixture_generated');
    $assert(is_file($credentialFile) && strpos((string)file_get_contents($credentialFile), 'FRANCHISEE_PASSWORD=') !== false, 'credential_written_outside_web_tree');
    $assert($first['password_exposed'] === false, 'password_not_exposed_by_api');
    $storeId = (int)$first['store']['id'];
    $uids = array_column($first['accounts'], 'uid', 'fixture_role');
    $memberUid = (int)$uids['member'];
    $customerUid = (int)$uids['customer'];
    $assert($storeId > 0 && (int)Db::name('system_store')->where('id', $storeId)->where('is_show', 1)->count() === 1, 'test_store_active');
    $assert((int)Db::name('user')->where('mark', '[YFTH-ACCEPTANCE-TEST-V1]')->where('status', 1)->count() === 5, 'five_test_users_active');
    $assert((int)Db::name('yfth_user_store_role')->where('store_id', $storeId)->where('status', 'active')->count() === 3, 'three_store_roles_active');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $memberUid)->where('store_id', $storeId)->where('status', 'active')->count() === 1, 'c1_permanent_membership_active');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $customerUid)->where('status', 'active')->count() === 0, 'c2_starts_non_member');
    $assert((int)Db::name('yfth_direct_referral_rule_version')->where('status', 'published')->where('package_ratio_first_bps', 1500)->where('package_ratio_second_bps', 2500)->where('package_ratio_third_bps', 6000)->count() === 1, 'reward_rule_ready');
    foreach (['yfth_stg_b1_franchisee', 'yfth_stg_b1_manager', 'yfth_stg_b1_staff', 'yfth_stg_c1_member', 'yfth_stg_c2_customer'] as $account) {
        $assert((int)Db::name('user')->where('account', $account)->where('status', 1)->count() === 1, 'staging_account_ready:' . $account);
    }

    $passwordReset = $service->resetPasswords([
        'reason' => 'isolated password reset', 'request_id' => 'fixture-password-reset-' . getmypid(),
    ], 1, $hq);
    $assert(count($passwordReset['temporary_passwords_once'] ?? []) === 5, 'password_reset_returns_once_to_authorized_admin');
    $assert(strpos((string)file_get_contents($credentialFile), 'CUSTOMER_PASSWORD=') !== false, 'password_reset_updates_private_file');
    $login = app()->make(LoginServices::class);
    foreach ($passwordReset['temporary_passwords_once'] as $credential) {
        $loginResult = $login->login((string)$credential['account'], (string)$credential['password'], 0, 0);
        $assert(!empty($loginResult['token']), 'account_password_login_works:' . $credential['account']);
    }
    $summaryAfterPasswordReset = $service->summary($hq);
    $assert(!isset($summaryAfterPasswordReset['temporary_passwords_once']), 'passwords_not_returned_by_later_summary');

    $countsBeforeReplay = [
        'store' => (int)Db::name('system_store')->where('name', 'TEST 隔离测试 B1 门店')->count(),
        'users' => (int)Db::name('user')->where('mark', '[YFTH-ACCEPTANCE-TEST-V1]')->count(),
        'roles' => (int)Db::name('yfth_user_store_role')->where('store_id', $storeId)->count(),
        'membership' => (int)Db::name('yfth_permanent_membership')->where('uid', $memberUid)->count(),
    ];
    $service->generate(['reason' => 'isolated acceptance replay', 'request_id' => 'fixture-real-flow-2'], 1, $hq);
    $countsAfterReplay = [
        'store' => (int)Db::name('system_store')->where('name', 'TEST 隔离测试 B1 门店')->count(),
        'users' => (int)Db::name('user')->where('mark', '[YFTH-ACCEPTANCE-TEST-V1]')->count(),
        'roles' => (int)Db::name('yfth_user_store_role')->where('store_id', $storeId)->count(),
        'membership' => (int)Db::name('yfth_permanent_membership')->where('uid', $memberUid)->count(),
    ];
    $assert($countsBeforeReplay === $countsAfterReplay, 'fixture_generation_is_idempotent');

    $referral = app()->make(PackageMembershipReferralServices::class);
    $runId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $invite = $referral->issueInvite($memberUid, ['request_id' => 'fixture-c1-invite-' . $runId]);
    $accepted = $referral->acceptInvite($customerUid, (string)$invite['invite_token'], [
        'idempotency_key' => 'fixture-c1-c2-accept-' . $runId, 'request_id' => 'fixture-c1-c2-accept-' . $runId,
    ]);
    $assert($accepted['changed'] && (int)$accepted['store_id'] === $storeId, 'c1_invites_c2_into_b1');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referrer_uid', $memberUid)->where('referred_uid', $customerUid)->where('store_id', $storeId)->where('status', 'active')->count() === 1, 'direct_referral_persisted');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $customerUid)->where('store_id', $storeId)->where('status', 'active')->count() === 1, 'c2_attribution_persisted');
    $expect(function () use ($referral, $memberUid, $invite) {
        $referral->acceptInvite($memberUid, (string)$invite['invite_token'], ['idempotency_key' => 'fixture-self-scan-' . bin2hex(random_bytes(3))]);
    }, 'direct_referral_invite', 'self_scan_rejected');

    $reset = $service->reset(['reason' => 'isolated acceptance reset', 'request_id' => 'fixture-reset'], 1, $hq);
    $assert($reset['status'] === 'disabled', 'fixture_reset_status');
    $assert((int)Db::name('system_store')->where('id', $storeId)->where('is_show', 0)->count() === 1, 'reset_disables_test_store');
    $assert((int)Db::name('user')->whereIn('uid', array_values($uids))->where('mark', '[YFTH-ACCEPTANCE-TEST-V1]')->where('status', 0)->count() === 5, 'reset_disables_only_current_test_users');
    $assert((int)Db::name('yfth_user_store_role')->where('store_id', $storeId)->where('status', 'active')->count() === 0, 'reset_revokes_test_roles');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $customerUid)->where('status', 'active')->count() === 0, 'reset_closes_test_referral');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $customerUid)->where('status', 'active')->count() === 0, 'reset_closes_test_attribution');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $memberUid)->where('status', 'active')->count() === 1, 'reset_preserves_immutable_membership_fact');

    $regenerated = $service->generate([
        'reason' => 'isolated acceptance regeneration',
        'request_id' => 'fixture-regenerate-' . $runId,
    ], 1, $hq);
    $regeneratedUids = array_column($regenerated['accounts'], 'uid', 'fixture_role');
    $regeneratedCustomerUid = (int)$regeneratedUids['customer'];
    $assert($regenerated['status'] === 'active', 'fixture_regenerated_after_reset');
    $assert($regeneratedCustomerUid > 0 && $regeneratedCustomerUid !== $customerUid, 'regeneration_rotates_customer_with_immutable_history');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', (int)$regeneratedUids['member'])->where('status', 'active')->count() === 1, 'regeneration_reuses_valid_member_fact');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $regeneratedCustomerUid)->count() === 0, 'regenerated_customer_has_clean_attribution');

    $secondReset = $service->reset([
        'reason' => 'isolated acceptance second reset',
        'request_id' => 'fixture-second-reset-' . $runId,
    ], 1, $hq);
    $assert($secondReset['status'] === 'disabled', 'regenerated_fixture_can_be_reset');
    @unlink($credentialFile);
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
}

if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n"); exit(1); }
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH acceptance fixture real flow verified.\n";
