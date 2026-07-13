<?php

namespace app\listener\yfth;

use app\services\yfth\DirectReferralRewardServices;
use think\facade\Log;

class MallConsumptionRewardCustomEventListener
{
    public function handle($event): void
    {
        [$mark, $data] = $event;
        if ($mark !== 'admin_order_refund_success') {
            return;
        }
        try {
            app()->make(DirectReferralRewardServices::class)
                ->cancelMallOrderCandidateAfterFullRefund((string)($data['order_id'] ?? ''));
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_mall_consumption_reward_refund_sync_failed',
                'order_id' => (string)($data['order_id'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
