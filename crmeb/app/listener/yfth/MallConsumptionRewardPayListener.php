<?php

namespace app\listener\yfth;

use app\services\yfth\DirectReferralRewardServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

class MallConsumptionRewardPayListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$orderInfo] = $event;
        try {
            app()->make(DirectReferralRewardServices::class)->recordMallOrderPaid((int)($orderInfo['id'] ?? 0));
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_mall_consumption_reward_pay_sync_failed',
                'order_id' => (int)($orderInfo['id'] ?? 0),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
