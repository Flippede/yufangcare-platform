<?php

use app\services\yfth\PackageActivationServices;
use app\services\yfth\PackageMembershipActivationCoordinator;
use app\services\yfth\PackageMembershipReferralServices;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

try {
    packageMembershipReferralBootTestApp();
    $action = (string)($argv[1] ?? '');
    if ($action === 'activate_order') {
        $orderId = (int)($argv[2] ?? 0);
        $order = Db::name('store_order')->where('id', $orderId)->find();
        if (!$order) {
            throw new RuntimeException('worker_order_not_found');
        }
        $result = app()->make(PackageActivationServices::class)->activateByPaidOrder($order);
    } elseif ($action === 'accept_invite') {
        $uid = (int)($argv[2] ?? 0);
        $token = (string)($argv[3] ?? '');
        $key = (string)($argv[4] ?? '');
        $result = app()->make(PackageMembershipReferralServices::class)->acceptInvite($uid, $token, [
            'idempotency_key' => $key,
            'request_id' => $key,
        ]);
    } elseif ($action === 'coordinator') {
        $uid = (int)($argv[2] ?? 0);
        $storeId = (int)($argv[3] ?? 0);
        $purchaseId = (int)($argv[4] ?? 0);
        $instanceId = (int)($argv[5] ?? 0);
        $amount = (string)($argv[6] ?? '0.00');
        $result = Db::transaction(function () use ($uid, $storeId, $purchaseId, $instanceId, $amount) {
            return app()->make(PackageMembershipActivationCoordinator::class)->activateInTransaction([
                'id' => $purchaseId,
                'uid' => $uid,
                'store_id' => $storeId,
                'rule_version_id' => 900001,
            ], [
                'grants_permanent_membership' => 1,
                'order_pay_price' => $amount,
                'currency' => 'CNY',
                'paid_time' => time(),
            ], $instanceId);
        });
    } else {
        throw new RuntimeException('worker_action_invalid');
    }
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
