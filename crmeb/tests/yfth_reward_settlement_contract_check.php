<?php

$root = dirname(__DIR__);
$failures = [];
$assert = function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = function (string $file) use ($root, $assert): string {
    $path = $root . '/' . $file;
    $assert(is_file($path), 'missing:' . $file);
    return is_file($path) ? (string)file_get_contents($path) : '';
};

$service = $read('app/services/yfth/DirectReferralRewardSettlementServices.php');
$migration = $read('database/migrations/20260717100000_create_yfth_reward_settlement_ledger.php');
$storeController = $read('app/api/controller/v1/yfth/RewardSettlementStoreController.php');
$adminController = $read('app/adminapi/controller/v1/yfth/RewardSettlement.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$rewardService = $read('app/services/yfth/DirectReferralRewardServices.php');
$adminPage = $read('../template/admin/src/pages/yfth/packageMembershipReferral/index.vue');
$storePage = $read('../template/uni-app/pages/yfth/workbench/package_membership/index.vue');
$userPage = $read('../template/uni-app/pages/yfth/package_membership/index.vue');

foreach (['pending', 'confirmed', 'settled', 'cancelled', 'confirmByStore', 'settleByStore', 'cancelByHeadquarters', 'correctByHeadquarters', 'IdempotencyRecordServices', 'lock(true)', 'AuditEventServices', 'reward_candidate_settlement_evidence_required'] as $needle) {
    $assert(strpos($service, $needle) !== false, 'settlement_service_missing:' . $needle);
}
foreach (['uniq_yfth_direct_settlement_candidate', 'uniq_yfth_direct_settlement_no', 'yfth-package-membership-reward-settlement-read', 'yfth-package-membership-reward-settlement-cancel', 'yfth-package-membership-reward-settlement-correct'] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_missing:' . $needle);
}
foreach (['storeContext($request)', 'assertClientAuthorityFieldsAbsent', 'confirmByStore', 'settleByStore'] as $needle) {
    $assert(strpos($storeController, $needle) !== false, 'store_controller_missing:' . $needle);
}
foreach (['assertApiAuthForAdmin', 'assertHeadquarterScope', 'cancelByHeadquarters', 'correctByHeadquarters'] as $needle) {
    $assert(strpos($adminController, $needle) !== false, 'admin_controller_missing:' . $needle);
}
foreach (['reward_settlement/candidate/:id/confirm', 'reward_settlement/candidate/:id/settle'] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'api_route_missing:' . $needle);
}
foreach (["Route::group('reward_settlement'", 'candidate/:id/cancel', 'candidate/:id/correct'] as $needle) {
    $assert(strpos($adminRoute, $needle) !== false, 'admin_route_missing:' . $needle);
}
$assert(strpos($rewardService, "['pending', 'confirmed']") !== false, 'full_refund_cancels_unsettled_candidate');
foreach ([$adminPage, $storePage, $userPage] as $page) {
    $assert(strpos($page, 'settled') !== false && strpos($page, 'confirmed') !== false, 'page_renders_four_candidate_states');
}
foreach (['now_money', 'brokerage', 'score', 'withdraw', "Db::name('store_order')->update"] as $forbidden) {
    $assert(strpos($service, $forbidden) === false, 'settlement_service_forbidden:' . $forbidden);
}

if ($failures) {
    fwrite(STDERR, "YFTH reward settlement contract check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "YFTH reward settlement contract check passed\n";
