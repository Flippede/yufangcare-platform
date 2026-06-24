<?php

namespace app\listener\yfth;

use app\services\yfth\PackageRefundServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

class PackageRefundCancelListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$refundInfo] = $event;
        try {
            app()->make(PackageRefundServices::class)->onRefundCanceled((array)$refundInfo);
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_package_refund_cancel_sync_failed',
                'store_order_id' => $refundInfo['store_order_id'] ?? 0,
                'refund_order_id' => $refundInfo['order_id'] ?? '',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
