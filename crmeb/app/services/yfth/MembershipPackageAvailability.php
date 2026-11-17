<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

final class MembershipPackageAvailability
{
    public static function enabled(): bool
    {
        return filter_var(config('yfth.membership_package_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function assertOpen(): void
    {
        if (!self::enabled()) {
            throw new ApiException('会员套餐暂未开放，请使用商品商城或加盟申请。');
        }
    }

    public static function assertProductsAvailable(array $productIds): void
    {
        if (self::enabled() || !$productIds) {
            return;
        }
        if (Db::name('yfth_package_product_binding')->whereIn('product_id', $productIds)
            ->where('binding_status', 'active')->count()) {
            self::assertOpen();
        }
    }

    public static function assertOrderAvailable(int $orderId): void
    {
        if (self::enabled() || $orderId <= 0) {
            return;
        }
        if (Db::name('yfth_commission_order_source')->where('order_id', $orderId)
            ->where('source_type', 'package')->count()) {
            self::assertOpen();
        }
    }
}
