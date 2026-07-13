<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

$files = [
    'app/services/yfth/DirectReferralRewardServices.php',
    'app/listener/yfth/MallConsumptionRewardPayListener.php',
    'app/listener/yfth/MallConsumptionRewardCustomEventListener.php',
    'app/event.php',
    'app/api/controller/v1/yfth/PackageMembershipReferralController.php',
    'app/api/controller/v1/yfth/PackageMembershipReferralStoreController.php',
    'app/adminapi/controller/v1/yfth/PackageMembershipReferral.php',
];
foreach ($files as $file) {
    $assert(is_file($root . '/' . $file), 'file_exists:' . $file);
}

$service = (string)file_get_contents($root . '/app/services/yfth/DirectReferralRewardServices.php');
$event = (string)file_get_contents($root . '/app/event.php');
$payListener = (string)file_get_contents($root . '/app/listener/yfth/MallConsumptionRewardPayListener.php');
$refundListener = (string)file_get_contents($root . '/app/listener/yfth/MallConsumptionRewardCustomEventListener.php');

foreach ([
    'recordMallOrderPaid',
    'cancelMallOrderCandidateAfterFullRefund',
    "(int)\$order['paid'] !== 1",
    "(int)(\$order['pid'] ?? 0) !== 0",
    "(int)(\$order['refund_status'] ?? 0) !== 0",
    "(int)(\$order['is_del'] ?? 0) !== 0",
    "(int)(\$order['is_system_del'] ?? 0) !== 0",
    "(int)(\$order['is_cancel'] ?? 0) !== 0",
    "(int)(\$order['status'] ?? 0) < 0",
    'package_order_not_mall_consumption',
    'membershipLockContext',
    'mall_consumption_attribution_store_mismatch',
    'mall_consumption_rule_unavailable',
    'mall_consumption_positive_payment_required',
    "'candidate_type' => 'mall_consumption'",
    "'responsibility_type' => 'store_mall_revenue'",
    "'status' => 'cancelled'",
    'mall_consumption_full_refund_not_confirmed',
    'cancel_after_full_refund',
] as $needle) {
    $assert(strpos($service, $needle) !== false, 'service_contains:' . $needle);
}

$preRead = strpos($service, '$relationSnapshot =');
$sharedGate = strpos($service, 'membershipLockContext($referredUid)');
$lockedRule = strpos($service, '$rule = $this->activeRule(true, false);');
$assert($preRead !== false && $sharedGate !== false && $lockedRule !== false
    && $preRead < $sharedGate && $sharedGate < $lockedRule,
    'payment_uses_nonwriting_relation_preread_then_shared_gate_then_rule_lock');
$assert(strpos($service, "Db::name('store_order')->update") === false, 'stage3_service_never_updates_crmeb_order');
$assert(strpos($service, "Db::name('store_order')->where") !== false, 'stage3_service_reads_real_crmeb_order');
$assert(strpos($service, "where('source_unique_key', \$sourceKey)->lock(true)") !== false, 'duplicate_payment_reuses_source_unique_candidate');
$assert(strpos($service, "where('candidate_type', 'mall_consumption')") !== false, 'refund_only_targets_mall_candidate');
$assert(strpos($service, '$refundedCent < $paidCent') !== false, 'refund_requires_full_paid_amount');

$assert(strpos($event, 'MallConsumptionRewardPayListener::class') !== false, 'pay_listener_registered_on_existing_event');
$assert(strpos($event, 'MallConsumptionRewardCustomEventListener::class') !== false, 'refund_listener_registered_on_existing_event');
$assert(strpos($payListener, 'recordMallOrderPaid') !== false && strpos($payListener, 'catch (\\Throwable $e)') !== false,
    'pay_listener_is_failure_isolated');
$assert(strpos($refundListener, "admin_order_refund_success") !== false
    && strpos($refundListener, 'cancelMallOrderCandidateAfterFullRefund') !== false
    && strpos($refundListener, 'catch (\\Throwable $e)') !== false,
    'refund_listener_is_failure_isolated');

$userPage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/package_membership/index.vue');
$storePage = (string)file_get_contents(dirname($root) . '/template/uni-app/pages/yfth/workbench/package_membership/index.vue');
$adminPage = (string)file_get_contents(dirname($root) . '/template/admin/src/pages/yfth/packageMembershipReferral/index.vue');
foreach ([$userPage, $storePage, $adminPage] as $index => $page) {
    $assert(strpos($page, '不代表已支付') !== false, 'surface_discloses_candidate_not_paid:' . $index);
}
$assert(strpos($adminPage, 'value="cancelled"') !== false, 'admin_can_filter_cancelled_candidates');

foreach (['now_money', 'brokerage_price', 'spread_uid', 'commission', 'settlement', 'payout', 'withdraw'] as $forbidden) {
    $assert(stripos($service . $payListener . $refundListener, $forbidden) === false, 'stage3_excludes_funding_field:' . $forbidden);
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
echo "[OK] YFTH Stage 3 mall consumption reward contract verified.\n";
