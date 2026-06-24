<?php

namespace app\listener\yfth;

use app\services\yfth\PackageRefundServices;
use think\facade\Log;

class PackageCustomEventListener
{
    public function handle($event): void
    {
        [$mark, $data] = $event;
        try {
            if ($mark === 'admin_order_refund_success') {
                app()->make(PackageRefundServices::class)->onRefundSucceeded((string)($data['order_id'] ?? ''), (array)$data);
            }
            if ($mark === 'admin_order_refund_fail') {
                app()->make(PackageRefundServices::class)->onRefundFailed((string)($data['order_id'] ?? ''), (array)$data);
            }
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_package_custom_event_sync_failed',
                'mark' => $mark,
                'order_id' => $data['order_id'] ?? '',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
