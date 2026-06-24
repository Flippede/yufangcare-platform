<?php

namespace app\listener\yfth;

use app\services\yfth\PackageActivationServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

class PackagePaySuccessListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$orderInfo] = $event;
        try {
            app()->make(PackageActivationServices::class)->activateByPaidOrder((array)$orderInfo);
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_package_activation_failed',
                'order_id' => $orderInfo['id'] ?? 0,
                'order_sn' => $orderInfo['order_id'] ?? '',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
