<?php

use app\services\yfth\CommissionFinanceServices;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_AUTOMATIC_COMMISSION_WORKER') !== '1') {
    fwrite(STDERR, "automatic_commission_worker_guard_required\n");
    exit(2);
}

try {
    packageMembershipReferralBootTestApp();
    $action = (string)($argv[1] ?? '');
    if ($action !== 'store_withdrawal') {
        throw new InvalidArgumentException('automatic_commission_worker_action_invalid');
    }
    $storeId = (int)($argv[2] ?? 0);
    $amountCent = (int)($argv[3] ?? 0);
    $requestId = (string)($argv[4] ?? '');
    $operatorUid = (int)($argv[5] ?? 0);
    $row = app()->make(CommissionFinanceServices::class)->requestStoreWithdrawal([
        'uid' => $operatorUid,
        'role_code' => 'store_manager',
        'store_id' => $storeId,
    ], $amountCent, $requestId);
    echo json_encode(['ok' => true, 'id' => (int)($row['id'] ?? 0)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
