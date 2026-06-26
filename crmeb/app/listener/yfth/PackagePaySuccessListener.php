<?php

namespace app\listener\yfth;

use app\services\yfth\PackageActivationServices;
use app\services\yfth\PackagePurchaseServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

class PackagePaySuccessListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$orderInfo] = $event;
        try {
            $result = app()->make(PackageActivationServices::class)->activateByPaidOrder((array)$orderInfo);
            if (($result['reason'] ?? '') === 'package_order_missing_purchase' && !empty($result['pending_compensation'])) {
                $recovery = app()->make(PackagePurchaseServices::class)->markPaidOrderMissingPurchaseForRecovery((array)$orderInfo, 'pay_success_listener');
                Log::error([
                    'msg' => 'yfth_package_paid_order_missing_purchase',
                    'order_id' => $orderInfo['id'] ?? 0,
                    'order_sn_masked' => $this->maskOrderSn((string)($orderInfo['order_id'] ?? '')),
                    'intent_id' => $recovery['intent_id'] ?? 0,
                    'attempt_id' => $recovery['attempt_id'] ?? 0,
                    'request_id' => $recovery['request_id'] ?? '',
                    'error_code' => 'package_order_missing_purchase',
                    'tracked' => $recovery['tracked'] ?? false,
                    'reason' => $recovery['reason'] ?? '',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_package_activation_failed',
                'order_id' => $orderInfo['id'] ?? 0,
                'order_sn_masked' => $this->maskOrderSn((string)($orderInfo['order_id'] ?? '')),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    private function maskOrderSn(string $orderSn): string
    {
        $orderSn = trim($orderSn);
        if ($orderSn === '') {
            return '';
        }
        if (strlen($orderSn) <= 8) {
            return substr($orderSn, 0, 2) . '***';
        }
        return substr($orderSn, 0, 4) . '***' . substr($orderSn, -4);
    }
}
