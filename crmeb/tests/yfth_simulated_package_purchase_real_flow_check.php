<?php

use app\services\yfth\HqAcceptanceFixtureServices;
use app\services\yfth\PackageMembershipReferralServices;
use app\services\yfth\SimulatedPackagePurchaseServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};

if ((string)getenv('YFTH_SIMULATED_PACKAGE_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_SIMULATED_PACKAGE_REAL_FLOW_EXECUTE=1\n";
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
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    $credentialFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth-simulated-package-' . getmypid() . '.txt';
    @unlink($credentialFile);
    Config::set([
        'acceptance_fixture_enabled' => true,
        'acceptance_account_file' => $credentialFile,
    ], 'yfth');
    $fixtureService = app()->make(HqAcceptanceFixtureServices::class);
    $fixture = $fixtureService->generate([
        'reason' => 'isolated simulated package purchase',
        'request_id' => 'simulated-package-fixture-' . getmypid(),
    ], 1, ['id' => 1, 'level' => 0]);
    $accounts = array_column($fixture['accounts'], null, 'fixture_role');
    $memberUid = (int)$accounts['member']['uid'];
    $customerUid = (int)$accounts['customer']['uid'];
    $storeId = (int)$fixture['store']['id'];
    $template = Db::name('yfth_package_template')->where('package_code', 'YFTH-TEST-PACKAGE-V1')->find();
    $templateId = (int)($template['id'] ?? 0);
    $assert($templateId > 0 && (string)$template['base_price'] === '0.10', 'controlled_point_one_package_ready');

    /** @var SimulatedPackagePurchaseServices $simulation */
    $simulation = app()->make(SimulatedPackagePurchaseServices::class);
    $unbound = $simulation->context($customerUid, $templateId);
    $assert(!$unbound['store_bound'] && !$unbound['can_simulate'], 'unbound_customer_cannot_simulate');

    /** @var PackageMembershipReferralServices $referral */
    $referral = app()->make(PackageMembershipReferralServices::class);
    $runId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $invite = $referral->issueInvite($memberUid, ['request_id' => 'simulation-invite-' . $runId]);
    $referral->acceptInvite($customerUid, (string)$invite['invite_token'], [
        'idempotency_key' => 'simulation-accept-' . $runId,
        'request_id' => 'simulation-accept-' . $runId,
    ]);

    $bound = $simulation->context($customerUid, $templateId);
    $assert($bound['can_simulate'] && (int)$bound['store']['store_id'] === $storeId, 'authoritative_b1_store_displayed');
    $storeOrderBefore = (int)Db::name('store_order')->where('uid', $customerUid)->count();
    $first = $simulation->simulate($customerUid, [
        'template_id' => $templateId,
        'agreement_accepted' => 1,
        'request_id' => 'simulation-purchase-' . $runId,
    ]);
    $assert(!$first['idempotent_replay'] && (string)$first['activation_status'] === 'succeeded', 'simulation_activates_membership');
    $assert((int)$first['store']['store_id'] === $storeId && (string)$first['price'] === '0.10', 'simulation_uses_bound_store_and_price');

    $replay = $simulation->simulate($customerUid, [
        'template_id' => $templateId,
        'agreement_accepted' => 1,
        'request_id' => 'simulation-replay-' . $runId,
    ]);
    $assert($replay['idempotent_replay'] && $replay['purchase_no'] === $first['purchase_no'], 'duplicate_simulation_is_idempotent');
    $assert((int)Db::name('store_order')->where('uid', $customerUid)->count() === $storeOrderBefore, 'no_crmeb_store_order_created');
    $assert((int)Db::name('yfth_package_purchase')->where('uid', $customerUid)->where('source', 'controlled_simulated_purchase')->count() === 1, 'single_simulated_purchase_created');
    $assert((int)Db::name('yfth_package_instance')->where('uid', $customerUid)->where('order_id', 0)->whereNull('order_unique_key')->count() === 1, 'simulated_instance_uses_nullable_order_key');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid', $customerUid)->where('store_id', $storeId)->where('status', 'active')->count() === 1, 'permanent_membership_granted');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $customerUid)->where('status', 'closed')->count() === 1, 'active_referral_closed_after_membership');
    $after = $simulation->context($customerUid, $templateId);
    $assert($after['is_member'] && !$after['can_simulate'], 'member_cannot_repeat_simulation');

    // This test intentionally runs on a disposable isolated database. The
    // generated membership is an immutable business fact and must not be
    // removed through the acceptance-fixture reset shortcut.
    @unlink($credentialFile);
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
echo "[OK] YFTH simulated package purchase real flow verified.\n";
