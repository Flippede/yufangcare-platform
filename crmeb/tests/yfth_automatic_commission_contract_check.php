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

$automatic = $read('app/services/yfth/AutomaticCommissionServices.php');
$finance = $read('app/services/yfth/CommissionFinanceServices.php');
$orchestrator = $read('app/services/yfth/UnifiedRewardOrchestratorServices.php');
$legacySettlement = $read('app/services/yfth/DirectReferralRewardSettlementServices.php');
$legacyRewardWriter = $read('app/services/yfth/DirectReferralRewardServices.php');
$packageActivation = $read('app/services/yfth/PackageMembershipActivationCoordinator.php');
$sourceGuard = $read('app/services/yfth/YfthOrderSourceServices.php');
$migrationHealth = $read('app/services/yfth/AutomaticCommissionMigrationHealthServices.php');
$provider = $read('app/services/yfth/CommissionProfitSharingProviderInterface.php');
$providerFactory = $read('app/services/yfth/CommissionProfitSharingProviderFactory.php');
$mockProvider = $read('app/services/yfth/MockCommissionProfitSharingProvider.php');
$failClosedProvider = $read('app/services/yfth/FailClosedCommissionProfitSharingProvider.php');
$v1 = $read('database/migrations/20260720200000_create_yfth_automatic_commission_accounts_v1.php');
$v2 = $read('database/migrations/20260720210000_harden_yfth_automatic_commission_execution_v2.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$orderCreate = $read('app/services/order/StoreOrderCreateServices.php');
$orderTake = $read('app/services/order/StoreOrderTakeServices.php');

foreach ([
    'yfth_commission_rule_version', 'yfth_mall_commission_order_snapshot', 'yfth_commission_accrual',
    'yfth_commission_ledger', 'yfth_store_settlement_batch', 'yfth_store_settlement_batch_item',
    'yfth_store_settlement_receiver', 'yfth_store_settlement_callback', 'yfth_store_settlement_return',
] as $table) {
    $assert(strpos($v1, $table) !== false, 'v1_table_missing:' . $table);
}
foreach ([
    'yfth_commission_sequence_counter', 'yfth_commission_refund_reversal', 'yfth_commission_order_source',
    'uniq_yfth_commission_package_sequence', 'uniq_yfth_commission_sequence_referrer',
    'uniq_yfth_commission_refund_item_accrual', 'uniq_yfth_commission_order_source',
] as $needle) {
    $assert(strpos($v2, $needle) !== false, 'v2_idempotency_guard_missing:' . $needle);
}

foreach ([
    'consumePackageActivation', 'reversePackageActivation', 'snapshotMallOrderPaid', 'completeMallOrder',
    'refundMallOrder', 'processDue', 'nextPackageSequence', 'refundItemFacts',
    'yfth_commission_refund_reversal', 'package_activation|', 'due_at', 'creditLockedAccrual',
] as $needle) {
    $assert(strpos($automatic, $needle) !== false, 'automatic_execution_missing:' . $needle);
}
$assert(strpos($automatic, 'creditPackageCandidate') === false, 'legacy_package_candidate_credit_must_be_absent');
$assert(strpos($automatic, 'syncLegacyMallCandidate') === false, 'legacy_mall_candidate_write_must_be_absent');
foreach (['consumePackageActivation', 'closeForMembershipWithLockedCurrentsInTransaction', 'package_activated'] as $needle) {
    $assert(strpos($packageActivation, $needle) !== false, 'package_activation_consumer_or_close_order_missing:' . $needle);
}
foreach (['consumePackageActivation', 'reversePackageActivation', 'snapshotMallOrderPaid', 'refundMallOrder'] as $needle) {
    $assert(strpos($orchestrator, $needle) !== false, 'orchestrator_automatic_path_missing:' . $needle);
}
$assert(strpos($orchestrator, 'DirectReferralRewardServices') === false, 'orchestrator_must_not_create_legacy_candidates');
foreach (['assertAutomaticExecutionOnly', 'yfth_legacy_reward_manual_execution_disabled'] as $needle) {
    $assert(strpos($legacySettlement, $needle) !== false, 'legacy_manual_write_not_disabled:' . $needle);
}
foreach (['createPackageCandidateInTransaction', 'recordMallOrderPaid', 'adjustMallOrderCandidateAfterRefund', 'yfth_legacy_reward_manual_execution_disabled'] as $needle) {
    $assert(strpos($legacyRewardWriter, $needle) !== false, 'legacy_candidate_writer_not_explicitly_disabled:' . $needle);
}

foreach ([
    'generateSettlementBatches', 'startSettlementBatch', 'handleTrustedSettlementCallback',
    'requestSettlementReturn', 'applyTrustedReturnCallback', 'recordSettlementCallback',
    'settlement_callback_admin_write_disabled', 'whereNull(\'i.id\')', 'l.add_time asc,l.id asc',
    'waiting_receiver', 'profit_sharing_provider_unavailable',
] as $needle) {
    $assert(strpos($finance, $needle) !== false, 'settlement_execution_missing:' . $needle);
}
foreach (['registerReceiver', 'createSettlement', 'querySettlement', 'createReturn', 'queryReturn', 'verifyCallback'] as $needle) {
    $assert(strpos($provider, $needle) !== false, 'provider_boundary_missing:' . $needle);
}
foreach (['YFTH_COMMISSION_TEST_MODE', 'YFTH_REAL_FLOW_ISOLATED_DB', 'MockCommissionProfitSharingProvider', 'FailClosedCommissionProfitSharingProvider'] as $needle) {
    $assert(strpos($providerFactory, $needle) !== false, 'provider_environment_guard_missing:' . $needle);
}
foreach (['x-yfth-mock-signature', 'x-yfth-mock-timestamp', 'x-yfth-mock-nonce', 'x-yfth-mock-certificate', 'YFTH-ISOLATED-TEST', 'hash_hmac'] as $needle) {
    $assert(strpos($mockProvider, $needle) !== false, 'mock_callback_validation_missing:' . $needle);
}
$assert(strpos($failClosedProvider, 'commission_profit_sharing_provider_not_configured') !== false,
    'production_provider_must_fail_closed');

foreach (['shouldMarkCustomerOrder', 'mark(', 'excludesCrmebBrokerage', 'legacy_brokerage_excluded'] as $needle) {
    $assert(strpos($sourceGuard, $needle) !== false, 'order_source_guard_missing:' . $needle);
}
foreach (['YfthOrderSourceServices', 'excludesCrmebBrokerage', 'division_brokerage'] as $needle) {
    $assert(strpos($orderCreate . $orderTake, $needle) !== false, 'crmeb_brokerage_exclusion_missing:' . $needle);
}

foreach (['assertHealthy', 'information_schema.TABLES', 'information_schema.COLUMNS', 'information_schema.STATISTICS', 'migration_table', 'yfth_commission_rule_version', 'uniq_yfth_commission_rule_version', 'yfth-auto-commission-settlement-write'] as $needle) {
    $assert(strpos($migrationHealth, $needle) !== false, 'migration_health_gate_missing:' . $needle);
}
foreach (['commission/profit_sharing/callback', 'CommissionProfitSharingCallbackController'] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'trusted_callback_route_missing:' . $needle);
}
$assert(strpos($finance, 'recordSettlementCallback') !== false
    && strpos($finance, 'settlement_callback_admin_write_disabled') !== false,
    'ordinary_admin_callback_must_be_explicitly_disabled');

$all = implode("\n", [$automatic, $finance, $orchestrator, $legacySettlement, $apiRoute, $adminRoute]);
foreach ([
    'yfth_store_withdrawal', 'yfth_withdrawal_allocation', 'requestStoreWithdrawal',
    'completeStoreWithdrawal', 'own_available_cent', 'proxy_available_cent', 'hq_frozen_cent',
] as $forbidden) {
    $assert(strpos($all, $forbidden) === false, 'b1_withdrawal_surface_must_be_absent:' . $forbidden);
}
foreach (["now_money')->update", "brokerage_price')->update", "user_bill')->insert"] as $forbidden) {
    $assert(strpos($automatic . $finance, $forbidden) === false, 'commission_must_not_write_crmeb_asset:' . $forbidden);
}

if ($failures) {
    fwrite(STDERR, "YFTH automatic commission contract check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "YFTH automatic commission settlement contract check passed\n";
