<?php

use app\services\yfth\FranchiseCustomerServices;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if (PHP_SAPI !== 'cli' || (string)getenv('YFTH_CUSTOMER_PROJECTION_REPAIR_EXECUTE') !== '1') {
    fwrite(STDERR, "Refused. Set YFTH_CUSTOMER_PROJECTION_REPAIR_EXECUTE=1 in an explicit CLI operation.\n");
    exit(2);
}

$operatorUid = (int)getenv('YFTH_CUSTOMER_PROJECTION_REPAIR_OPERATOR_UID');
$reason = trim((string)getenv('YFTH_CUSTOMER_PROJECTION_REPAIR_REASON'));
$storeId = (int)getenv('YFTH_CUSTOMER_PROJECTION_REPAIR_STORE_ID');
$limit = (int)getenv('YFTH_CUSTOMER_PROJECTION_REPAIR_LIMIT') ?: 1000;
if ($operatorUid <= 0 || $reason === '') {
    fwrite(STDERR, "Operator UID and reason are required.\n");
    exit(2);
}

packageMembershipReferralBootTestApp();
$result = app()->make(FranchiseCustomerServices::class)->backfillAuthorityCustomers(
    $storeId,
    $limit,
    $operatorUid,
    $reason,
    'customer-projection-repair-' . date('YmdHis')
);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(!empty($result['failed']) ? 1 : 0);
