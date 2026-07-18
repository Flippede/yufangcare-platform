<?php

namespace app\listener\yfth;

use app\services\yfth\UnifiedRewardOrchestratorServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

class MallConsumptionRewardPayListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$orderInfo] = $event;
        try {
            $orderId = (int)($orderInfo['id'] ?? 0);
            if ($orderId > 0) {
                app()->make(UnifiedRewardOrchestratorServices::class)
                    ->enqueueAndTry('mall_order_paid', 'store_order', (string)$orderId, ['order_sn' => (string)($orderInfo['order_id'] ?? '')]);
            }
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_mall_consumption_reward_pay_sync_failed',
                'order_id' => (int)($orderInfo['id'] ?? 0),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
