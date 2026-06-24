<?php

namespace app\listener\yfth;

use app\services\yfth\PackageRefundServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

class PackageRefundApplyListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$order] = $event;
        try {
            app()->make(PackageRefundServices::class)->onRefundApplied((array)$order);
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_package_refund_apply_sync_failed',
                'order_id' => $order['id'] ?? 0,
                'order_sn' => $order['order_id'] ?? '',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
