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
            'customer' => 'Customer',
            'family_member' => 'Family member',
            'member_5980' => '5980 member',
            'franchise_applicant' => 'Franchise applicant',
            'franchisee' => 'Franchisee',
            'store_manager' => 'Store manager',
            'store_staff' => 'Store staff',
            'service_mentor' => 'Service mentor',
            'supplier' => 'Supplier',
            'headquarter_operator' => 'Headquarter operator',
        ];
    }

    public static function storeRoles(): array
    {
        return ['franchisee', 'store_manager', 'store_staff'];
    }

    public static function globalRoles(): array
    {
        return array_values(array_diff(array_keys(self::roles()), self::storeRoles()));
    }

    public static function subjectTypes(): array
    {
        return [
            'headquarter' => 'Headquarter subject',
            'franchise_company' => 'Franchise company',
            'store_company' => 'Store company',
            'individual' => 'Individual business',
            'supplier' => 'Supplier subject',
        ];
    }

    public static function storeTypes(): array
    {
        return [
            'direct' => 'Direct store',
            'franchise' => 'Franchise store',
            'store_in_store' => 'Store in store',
            'partner' => 'Partner site',
        ];
    }

    public static function subjectRoles(): array
    {
        return [
            'sales' => 'Sales subject',
            'payment' => 'Payment subject',
            'fulfillment' => 'Fulfillment subject',
            'invoice' => 'Invoice subject',
            'refund' => 'Refund subject',
            'host' => 'Host subject',
        ];
    }

    public static function qualificationStatus(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_EXPIRED => 'Expired',
        ];
    }

    public static function capabilityLabels(): array
    {
        return [
            'retail_sale' => 'Retail sale',
            'package_sale' => 'Package sale',
            'reservation_service' => 'Reservation service',
            'order_writeoff' => 'Order writeoff',
            'store_purchase' => 'Store purchase',
            'online_payment' => 'Online payment',
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
            'store_retail' => 'Store retail order',
            'retail_order' => 'Retail order',
            'package_5980' => '5980 package order',
            'package_order' => 'Package order',
            'paid_service' => 'Paid service',
            'headquarter_purchase' => 'Headquarter purchase',
            'franchise_purchase' => 'Franchise purchase',
            'service_refund' => 'Service refund',
        ];
    }
}
