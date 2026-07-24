<?php

namespace app\services\yfth;

use think\facade\Db;

/**
 * Explicitly marks orders produced by the YFTH storefront.  CRMEB's legacy
 * brokerage module consults this fact before any first-, second-, staff-,
 * agent-, or division-level brokerage is posted.
 */
class YfthOrderSourceServices
{
    /**
     * A YFTH commission order must have an authoritative YFTH customer-to-store
     * attribution.  Do not turn the shared CRMEB order creator into a global
     * legacy-brokerage switch for unrelated storefronts.
     */
    public function shouldMarkCustomerOrder(int $uid): bool
    {
        if ($uid <= 0) return false;
        return (int)Db::name('yfth_hq_customer_attribution_current')->where([
            'uid' => $uid,
            'status' => 'active',
        ])->count() > 0;
    }

    public function mark(int $orderId, string $sourceType = 'normal_mall'): void
    {
        if ($orderId <= 0) return;
        $now = time();
        $row = Db::name('yfth_commission_order_source')->where('order_id', $orderId)->lock(true)->find();
        if ($row) {
            Db::name('yfth_commission_order_source')->where('id', (int)$row['id'])->update([
                'source_type' => $sourceType, 'legacy_brokerage_excluded' => 1, 'update_time' => $now,
            ]);
            return;
        }
        try {
            Db::name('yfth_commission_order_source')->insert([
                'order_id' => $orderId, 'source_type' => $sourceType,
                'legacy_brokerage_excluded' => 1, 'add_time' => $now, 'update_time' => $now,
            ]);
        } catch (\Throwable $e) {
            $existing = Db::name('yfth_commission_order_source')->where('order_id', $orderId)->find();
            if (!$existing) throw $e;
        }
    }

    public function excludesCrmebBrokerage(array $order): bool
    {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0) return false;
        return (int)Db::name('yfth_commission_order_source')->where([
            'order_id' => $orderId, 'legacy_brokerage_excluded' => 1,
        ])->count() > 0;
    }

    public function sourceType(int $orderId): string
    {
        if ($orderId <= 0) return '';
        return (string)Db::name('yfth_commission_order_source')->where('order_id', $orderId)->value('source_type');
    }

    public function isSource(int $orderId, string $sourceType): bool
    {
        return $this->sourceType($orderId) === $sourceType;
    }
}
