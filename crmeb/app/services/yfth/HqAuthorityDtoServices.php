<?php

namespace app\services\yfth;

class HqAuthorityDtoServices extends YfthFoundationBaseServices
{
    private const STATUS_LABELS = [
        'active' => '归属有效',
        'paused' => '归属保留，服务暂停',
        'unassigned' => '暂未归属',
        'closed' => '归属已关闭',
        'invalid' => '关系已失效',
    ];

    private const SOURCE_LABELS = [
        'direct_referral' => '一级推荐确认',
        'customer_direct_referral' => '一级推荐确认',
        'membership_confirmation' => '会员确认',
        'package_sale_confirmation' => '套餐成交确认',
        'headquarters_correction' => '总部治理',
        'headquarters_parent_revoke' => '总部撤销上级',
        'store_qr_binding' => '扫码绑定门店',
    ];

    public function userAttribution(array $row, array $store, bool $hasActiveReferral): array
    {
        if (!$row) {
            return [
                'has_attribution' => false,
                'attribution_status' => 'unassigned',
                'attribution_status_label' => self::STATUS_LABELS['unassigned'],
                'bound_at' => 0,
                'paused_at' => 0,
                'closed_at' => 0,
                'store' => null,
                'has_active_referral' => false,
                'tips' => '暂未形成正式门店归属',
            ];
        }

        $status = (string)$row['status'];
        $tips = [
            'active' => '当前归属有效，商城仍由总部统一提供',
            'paused' => '归属仍保留，部分服务暂时不可用',
            'unassigned' => (int)$row['authority_version'] > 0 ? '当前暂无服务门店，请联系总部处理' : '暂未形成正式门店归属',
            'closed' => '归属已关闭，请联系总部',
        ][$status] ?? '归属数据需由总部处理';

        return [
            'has_attribution' => in_array($status, ['active', 'paused'], true),
            'attribution_status' => $status,
            'attribution_status_label' => $this->statusLabel($status),
            'bound_at' => (int)$row['bound_at'],
            'paused_at' => (int)$row['paused_at'],
            'closed_at' => (int)$row['closed_at'],
            'store' => in_array($status, ['active', 'paused'], true) ? $this->storeSummary($store) : null,
            'has_active_referral' => $hasActiveReferral,
            'tips' => $tips,
        ];
    }

    public function storeAttribution(array $row, array $user, bool $hasActiveReferral): array
    {
        return [
            'attribution_id' => (int)$row['id'],
            'customer' => $this->userSummary($user),
            'attribution_status' => (string)$row['status'],
            'attribution_status_label' => $this->statusLabel((string)$row['status']),
            'bound_at' => (int)$row['bound_at'],
            'paused_at' => (int)$row['paused_at'],
            'source_label' => $this->sourceLabel((string)$row['source_type']),
            'has_active_referral' => $hasActiveReferral,
        ];
    }

    public function adminAttribution(array $row, array $user, array $store, bool $hasActiveReferral, bool $consistent): array
    {
        if (!$consistent) {
            return [
                'attribution_id' => (int)$row['id'],
                'uid' => (int)$row['uid'],
                'customer' => $this->userSummary($user),
                'store_id' => 0,
                'store' => null,
                'attribution_status' => 'inconsistent',
                'attribution_status_label' => '数据异常',
                'bound_at' => 0,
                'paused_at' => 0,
                'closed_at' => 0,
                'source_label' => '系统来源',
                'has_active_referral' => false,
                'data_inconsistent' => true,
                'data_inconsistent_label' => '数据异常，需总部治理',
            ];
        }
        return [
            'attribution_id' => (int)$row['id'],
            'uid' => (int)$row['uid'],
            'customer' => $this->userSummary($user),
            'store_id' => (int)$row['store_id'],
            'store' => (int)$row['store_id'] > 0 ? $this->storeSummary($store) : null,
            'attribution_status' => (string)$row['status'],
            'attribution_status_label' => $this->statusLabel((string)$row['status']),
            'bound_at' => (int)$row['bound_at'],
            'paused_at' => (int)$row['paused_at'],
            'closed_at' => (int)$row['closed_at'],
            'source_label' => $this->sourceLabel((string)$row['source_type']),
            'has_active_referral' => $hasActiveReferral,
            'data_inconsistent' => false,
            'data_inconsistent_label' => '',
        ];
    }

    public function adminReferral(array $row, array $referrer, array $referred, array $store, bool $consistent): array
    {
        if (!$consistent) {
            return [
                'referral_id' => (int)$row['id'],
                'relation_display' => $this->safeRelationDisplay((string)$row['relation_no']),
                'referrer_uid' => (int)$row['referrer_uid'],
                'referrer' => $this->userSummary($referrer),
                'referred_uid' => (int)$row['referred_uid'],
                'referred' => $this->userSummary($referred),
                'store_id' => 0,
                'store' => null,
                'relation_status' => 'inconsistent',
                'relation_status_label' => '数据异常',
                'started_at' => 0,
                'paused_at' => 0,
                'closed_at' => 0,
                'close_label' => '',
                'source_label' => '系统来源',
                'data_inconsistent' => true,
                'data_inconsistent_label' => '数据异常，需总部治理',
            ];
        }
        return [
            'referral_id' => (int)$row['id'],
            'relation_display' => $this->safeRelationDisplay((string)$row['relation_no']),
            'referrer_uid' => (int)$row['referrer_uid'],
            'referrer' => $this->userSummary($referrer),
            'referred_uid' => (int)$row['referred_uid'],
            'referred' => $this->userSummary($referred),
            'store_id' => (int)$row['store_id'],
            'store' => $this->storeSummary($store),
            'relation_status' => (string)$row['status'],
            'relation_status_label' => $this->statusLabel((string)$row['status']),
            'started_at' => (int)$row['started_at'],
            'paused_at' => (int)$row['paused_at'],
            'closed_at' => (int)$row['closed_at'],
            'close_label' => $this->closeLabel((string)$row['close_reason']),
            'source_label' => $this->sourceLabel((string)$row['source_type']),
            'data_inconsistent' => false,
            'data_inconsistent_label' => '',
        ];
    }

    public function attributionEvent(array $row): array
    {
        return [
            'event_no' => (string)$row['event_no'],
            'authority_version' => (int)$row['authority_version'],
            'event_type' => (string)$row['event_type'],
            'source_type' => (string)$row['source_type'],
            'source_id' => (string)$row['source_id'],
            'operator_uid' => (int)$row['operator_uid'],
            'operator_role_code' => (string)$row['operator_role_code'],
            'request_id' => $this->safeRequestId((string)$row['request_id']),
            'before_status_reason_code' => (string)$row['before_status_reason_code'],
            'after_status_reason_code' => (string)$row['after_status_reason_code'],
            'before_store_id' => (int)$row['before_store_id'],
            'after_store_id' => (int)$row['after_store_id'],
            'before_status' => (string)$row['before_status'],
            'after_status' => (string)$row['after_status'],
            'event_time' => (int)$row['add_time'],
        ];
    }

    public function referralEvent(array $row): array
    {
        return [
            'event_no' => (string)$row['event_no'],
            'relation_version' => (int)$row['relation_version'],
            'event_type' => (string)$row['event_type'],
            'source_type' => (string)$row['source_type'],
            'source_id' => (string)$row['source_id'],
            'operator_uid' => (int)$row['operator_uid'],
            'operator_role_code' => (string)$row['operator_role_code'],
            'request_id' => $this->safeRequestId((string)$row['request_id']),
            'before_status' => (string)$row['before_status'],
            'after_status' => (string)$row['after_status'],
            'event_time' => (int)$row['add_time'],
        ];
    }

    public function userSummary(array $row): array
    {
        return [
            'nickname' => (string)($row['nickname'] ?? ''),
            'avatar' => (string)($row['avatar'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
        ];
    }

    public function storeSummary(array $row): array
    {
        if (!$row) {
            return ['name' => '', 'logo' => '', 'district' => ''];
        }
        return [
            'name' => (string)($row['name'] ?? ''),
            'logo' => (string)($row['image'] ?? ''),
            'district' => (string)($row['address'] ?? ''),
        ];
    }

    public function sourceLabel(string $sourceType): string
    {
        return self::SOURCE_LABELS[$sourceType] ?? '系统来源';
    }

    public function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? '数据异常';
    }

    private function closeLabel(string $reason): string
    {
        $labels = [
            'referred_became_member' => '被推荐人已成为会员',
            'headquarters_correction_closed' => '总部治理关闭',
            'headquarters_parent_revoked' => '总部撤销上级关系',
            'account_closed' => '账号关闭',
        ];
        return $reason === '' ? '' : ($labels[$reason] ?? '关系已结束');
    }

    private function safeRelationDisplay(string $relationNo): string
    {
        $length = strlen($relationNo);
        if ($length <= 8) {
            return $relationNo;
        }
        return substr($relationNo, 0, 4) . '...' . substr($relationNo, -4);
    }

    private function safeRequestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            return '';
        }
        if (strlen($requestId) <= 64 && preg_match('/^[A-Za-z0-9:_\-.]+$/', $requestId)) {
            return $requestId;
        }
        return hash('sha256', $requestId);
    }
}
