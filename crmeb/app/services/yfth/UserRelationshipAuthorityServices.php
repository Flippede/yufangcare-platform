<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

/**
 * Single read authority for the relationship shown to a signed-in user.
 * Financial customer-attribution history remains intact, but an active
 * operating identity takes precedence for current store and upstream display.
 */
class UserRelationshipAuthorityServices
{
    private const ROLE_NAMES = [
        'store_manager' => '店长',
        'store_staff' => '店员',
    ];

    private const RANK_NAMES = [
        'county_partner' => '县级合伙人',
        'prefecture_partner' => '地级合伙人',
        'province_partner' => '省级合伙人',
        'regional_director' => '大区总监',
        'platform_director' => '平台董事',
    ];

    private $read;
    private $dto;

    public function __construct(HqAuthorityReadServices $read, HqAuthorityDtoServices $dto)
    {
        $this->read = $read;
        $this->dto = $dto;
    }

    public function resolve(int $uid): array
    {
        if ($uid <= 0) {
            throw new ApiException('user_relationship_uid_invalid');
        }

        $partner = (array)Db::name('yfth_partner_profile')->where('uid', $uid)
            ->where('status', 'active')->find();
        if ($partner) {
            return $this->partnerRelationship($partner);
        }

        $role = (array)Db::name('yfth_user_store_role')->where('uid', $uid)
            ->whereIn('role_code', array_keys(self::ROLE_NAMES))->where('status', 'active')
            ->orderRaw("FIELD(role_code,'store_manager','store_staff') ASC, id DESC")->find();
        if ($role) {
            return $this->operatingRelationship($role);
        }

        return $this->customerRelationship($uid);
    }

    public function purchaseStore(int $uid): array
    {
        $relationship = $this->resolve($uid);
        $storeId = (int)($relationship['store_id'] ?? 0);
        $status = (string)($relationship['attribution_status'] ?? '');
        if ($storeId <= 0 || $status !== 'active') {
            return [];
        }

        return [
            'store_id' => $storeId,
            'store_name' => (string)($relationship['store_name'] ?? ''),
            'relationship_type' => (string)($relationship['relationship_type'] ?? ''),
            'source_type' => (string)($relationship['source_type'] ?? ''),
        ];
    }

    public function requirePurchaseStore(int $uid, int $requestedStoreId = 0): array
    {
        $store = $this->purchaseStore($uid);
        if (!$store) {
            throw new ApiException('package_purchase_authoritative_store_required');
        }
        if ($requestedStoreId > 0 && $requestedStoreId !== (int)$store['store_id']) {
            throw new ApiException('package_purchase_cross_store_forbidden');
        }
        return $store;
    }

    private function partnerRelationship(array $partner): array
    {
        $uid = (int)$partner['uid'];
        $store = $this->store((int)($partner['primary_store_id'] ?? 0));
        $relation = (array)Db::name('yfth_partner_relation')->where('partner_uid', $uid)
            ->where('status', 'active')->order('id desc')->find();
        $upstream = $this->partnerSummary((int)($relation['parent_uid'] ?? 0));
        return $this->baseRelationship(
            'partner',
            self::RANK_NAMES[(string)$partner['rank_code']] ?? (string)$partner['rank_code'],
            $store,
            $upstream,
            [
                'partner_rank_code' => (string)$partner['rank_code'],
                'promotion_code_type' => 'partner_invite',
                'source_type' => (string)($partner['source_type'] ?? 'partner_profile'),
            ]
        );
    }

    private function operatingRelationship(array $role): array
    {
        $storeId = (int)$role['store_id'];
        $store = $this->store($storeId);
        if (!$store) {
            throw new ApiException('authority_data_requires_headquarters_review');
        }
        $application = (array)Db::name('yfth_franchise_application')->where('approved_store_id', $storeId)
            ->order('id desc')->find();
        $source = $application ? (array)Db::name('yfth_franchise_recruit_source')
            ->where('application_id', (int)$application['id'])->find() : [];
        $upstream = $this->partnerSummary((int)($source['direct_partner_uid'] ?? 0));
        if (!$upstream) {
            $binding = (array)Db::name('yfth_partner_store_binding')->where('store_id', $storeId)
                ->where('status', 'active')->order('id desc')->find();
            $upstream = $this->partnerSummary((int)($binding['partner_uid'] ?? 0));
        }
        return $this->baseRelationship(
            'business_role',
            self::ROLE_NAMES[(string)$role['role_code']] ?? (string)$role['role_code'],
            $store,
            $upstream,
            [
                'role_code' => (string)$role['role_code'],
                'role_id' => (int)$role['id'],
                'promotion_code_type' => 'store_acquisition',
                'source_type' => $source ? 'franchise_recruit_source' : 'partner_store_binding',
            ]
        );
    }

    private function customerRelationship(int $uid): array
    {
        $row = $this->read->attributionByUid($uid);
        if (!$row) {
            return array_merge($this->dto->userAttribution([], [], false), [
                'relationship_type' => 'unassigned',
                'relationship_type_label' => '普通顾客',
                'upstream' => null,
                'promotion_code_type' => 'direct_referral',
                'source_type' => '',
            ]);
        }
        if (!$this->read->isAttributionConsistent($row)) {
            throw new ApiException('authority_data_requires_headquarters_review');
        }
        $stores = $this->read->storeMap([(int)$row['store_id']]);
        $store = $stores[(int)$row['store_id']] ?? [];
        if (in_array((string)$row['status'], ['active', 'paused'], true) && !$store) {
            throw new ApiException('authority_data_requires_headquarters_review');
        }
        return array_merge($this->dto->userAttribution(
            $row,
            $store,
            $this->read->hasActiveReferral($uid, (int)$row['store_id'])
        ), [
            'relationship_type' => 'customer_attribution',
            'relationship_type_label' => '顾客归属',
            'upstream' => null,
            'store_id' => in_array((string)$row['status'], ['active', 'paused'], true) ? (int)$row['store_id'] : 0,
            'store_name' => in_array((string)$row['status'], ['active', 'paused'], true) ? (string)($store['name'] ?? '') : '',
            'promotion_code_type' => 'direct_referral',
            'source_type' => (string)($row['source_type'] ?? ''),
        ]);
    }

    private function baseRelationship(string $type, string $label, array $store, array $upstream, array $extra): array
    {
        return array_merge([
            'relationship_type' => $type,
            'relationship_type_label' => $label,
            'has_attribution' => true,
            'attribution_status' => 'active',
            'attribution_status_label' => '当前关系有效',
            'bound_at' => 0,
            'paused_at' => 0,
            'closed_at' => 0,
            'store' => $this->dto->storeSummary($store),
            'store_id' => (int)($store['id'] ?? 0),
            'store_name' => (string)($store['name'] ?? ''),
            'upstream' => $upstream ?: null,
            'has_active_referral' => false,
            'tips' => $upstream
                ? '当前经营关系由总部授权门店及该门店招商来源统一确定'
                : '当前经营关系由总部授权门店统一确定',
        ], $extra);
    }

    private function store(int $storeId): array
    {
        if ($storeId <= 0) return [];
        return (array)Db::name('system_store')->where('id', $storeId)
            ->where('is_del', 0)->field('id,name,image,address')->find();
    }

    private function partnerSummary(int $uid): array
    {
        if ($uid <= 0) return [];
        $row = (array)Db::name('yfth_partner_profile')->alias('p')
            ->leftJoin('user u', 'u.uid=p.uid')->where('p.uid', $uid)->where('p.status', 'active')
            ->field('p.rank_code,u.nickname,u.account')->find();
        if (!$row) return [];
        return [
            'display_name' => (string)($row['nickname'] ?: $row['account']),
            'rank_code' => (string)$row['rank_code'],
            'rank_name' => self::RANK_NAMES[(string)$row['rank_code']] ?? (string)$row['rank_code'],
        ];
    }
}
