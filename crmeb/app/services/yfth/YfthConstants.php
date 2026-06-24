<?php

namespace app\services\yfth;

class YfthConstants
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_EXPIRED = 'expired';

    public static function roles(): array
    {
        return [
            'customer' => '普通用户',
            'family_member' => '家庭成员',
            'store_manager' => '门店店长',
            'store_staff' => '门店员工',
            'franchisee' => '加盟商',
            'supplier' => '供应商',
            'headquarter_operator' => '总部运营',
        ];
    }

    public static function storeRoles(): array
    {
        return ['store_manager', 'store_staff'];
    }

    public static function subjectTypes(): array
    {
        return [
            'headquarter' => '总部主体',
            'franchise_company' => '加盟公司',
            'store_company' => '门店公司',
            'individual' => '个体工商户',
            'supplier' => '供应商主体',
        ];
    }

    public static function storeTypes(): array
    {
        return [
            'direct' => '直营店',
            'franchise' => '加盟店',
            'store_in_store' => '店中店',
            'partner' => '合作点',
        ];
    }

    public static function qualificationStatus(): array
    {
        return [
            self::STATUS_PENDING => '待审核',
            self::STATUS_ACTIVE => '已通过',
            self::STATUS_REJECTED => '已驳回',
            self::STATUS_PAUSED => '已暂停',
            self::STATUS_EXPIRED => '已过期',
        ];
    }

    public static function capabilityLabels(): array
    {
        return [
            'retail_sale' => '商品销售',
            'package_sale' => '套餐销售',
            'reservation_service' => '预约服务',
            'order_writeoff' => '订单核销',
            'store_purchase' => '门店采购',
            'online_payment' => '在线收款',
        ];
    }

    public static function qualificationCapabilityMap(): array
    {
        return [
            'business_license' => ['retail_sale', 'online_payment'],
            'health_service' => ['reservation_service', 'order_writeoff'],
            'food_business' => ['retail_sale'],
            'franchise_authorization' => ['package_sale', 'store_purchase'],
            'purchase_authorization' => ['store_purchase'],
        ];
    }

    public static function paymentScenes(): array
    {
        return [
            'retail_order' => '零售订单',
            'package_order' => '套餐订单',
            'franchise_purchase' => '加盟采购',
            'service_refund' => '服务退款',
        ];
    }
}
