<?php

namespace app\listener\yfth;

use app\services\yfth\UnifiedRewardOrchestratorServices;
use think\facade\Log;
use think\facade\Db;

class MallConsumptionRewardCustomEventListener
{
    public function handle($event): void
    {
        [$mark, $data] = $event;
        if ($mark !== 'admin_order_refund_success') {
            return;
        }
        try {
            $orderSn = (string)($data['order_id'] ?? '');
            $orderId = (int)Db::name('store_order')->where('order_id', $orderSn)->value('id');
            if ($orderId > 0) {
                app()->make(UnifiedRewardOrchestratorServices::class)->enqueueAndTry(
                    'mall_order_refunded', 'store_order', (string)$orderId,
                    ['order_sn' => $orderSn, 'refunded_amount_cent' => (int)($data['refund_amount_cent'] ?? 0)]
                );
            }
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_mall_consumption_reward_refund_sync_failed',
                'order_id' => (string)($data['order_id'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
