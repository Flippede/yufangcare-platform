<?php

namespace app\listener\yfth;

use app\services\yfth\ProcurementPartnerProfitServices;
use app\services\yfth\YfthOrderSourceServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

class ProcurementPartnerProfitPayListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$order] = $event;
        $orderId = (int)($order['id'] ?? 0);
        if (!app()->make(YfthOrderSourceServices::class)->isSource($orderId, 'procurement')) {
            return;
        }
        try {
            app()->make(ProcurementPartnerProfitServices::class)->freezeForStoreOrder((array)$order);
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_procurement_profit_freeze_failed',
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
