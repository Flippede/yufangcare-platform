<?php

namespace app\listener\yfth;

use app\services\yfth\ProcurementPartnerProfitServices;
use app\services\yfth\YfthOrderSourceServices;
use think\facade\Db;
use think\facade\Log;

class ProcurementPartnerProfitCustomEventListener
{
    public function handle($event): void
    {
        [$mark, $data] = $event;
        if (!in_array($mark, ['order_take', 'admin_order_refund_success'], true)) {
            return;
        }
        try {
            $orderId = $mark === 'order_take'
                ? (int)($data['id'] ?? 0)
                : (int)Db::name('store_order')->where('order_id', (string)($data['order_id'] ?? ''))->value('id');
            if (!app()->make(YfthOrderSourceServices::class)->isSource($orderId, 'procurement')) {
                return;
            }
            $services = app()->make(ProcurementPartnerProfitServices::class);
            if ($mark === 'order_take') {
                $services->recognizeForStoreOrder($orderId);
                Db::name('yfth_native_procurement_order')->where('store_order_id', $orderId)->update([
                    'status' => 'completed',
                    'update_time' => time(),
                ]);
                return;
            }
            $refundCent = (int)($data['refund_amount_cent'] ?? 0);
            if ($refundCent <= 0 && isset($data['refund_price'])) {
                $refundCent = (int)bcmul((string)$data['refund_price'], '100', 0);
            }
            if ($refundCent > 0) {
                $services->synchronizeStoreOrderRefund($orderId, $refundCent);
            }
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'yfth_procurement_profit_event_failed',
                'event' => (string)$mark,
                'order_id' => (string)($data['order_id'] ?? $data['id'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
