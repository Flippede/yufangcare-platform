<?php

namespace app\services\yfth;

use app\dao\system\store\SystemStoreDao;
use app\dao\user\UserDao;
use app\dao\yfth\YfthHqActiveReferralCurrentDao;
use app\dao\yfth\YfthHqActiveReferralEventDao;
use app\dao\yfth\YfthHqCustomerAttributionCurrentDao;
use app\dao\yfth\YfthHqCustomerAttributionEventDao;
use crmeb\exceptions\ApiException;

class HqAuthorityReadServices
{
    private const ATTRIBUTION_FIELDS = 'id,uid,store_id,status,status_reason_code,authority_version,source_type,source_id,bound_at,paused_at,closed_at,close_reason,add_time,update_time';
    private const REFERRAL_FIELDS = 'id,relation_no,referrer_uid,referred_uid,store_id,attribution_current_id,status,active_referred_uid,source_type,started_at,paused_at,closed_at,close_reason,relation_version,add_time,update_time';
    private const ATTRIBUTION_STATUSES = ['active', 'paused', 'unassigned', 'closed'];
    private const REFERRAL_STATUSES = ['active', 'paused', 'closed', 'invalid'];

    private $attributionDao;
    private $attributionEventDao;
    private $referralDao;
    private $referralEventDao;
    private $userDao;
    private $storeDao;
    private $consistency;

    public function __construct(
        YfthHqCustomerAttributionCurrentDao $attributionDao,
        YfthHqCustomerAttributionEventDao $attributionEventDao,
        YfthHqActiveReferralCurrentDao $referralDao,
        YfthHqActiveReferralEventDao $referralEventDao,
        UserDao $userDao,
        SystemStoreDao $storeDao,
        HqAuthorityConsistencyValidator $consistency
    ) {
        $this->attributionDao = $attributionDao;
        $this->attributionEventDao = $attributionEventDao;
        $this->referralDao = $referralDao;
        $this->referralEventDao = $referralEventDao;
        $this->userDao = $userDao;
        $this->storeDao = $storeDao;
        $this->consistency = $consistency;
    }

    public function attributionByUid(int $uid): array
    {
        if ($uid <= 0) {
            throw new ApiException('authority_uid_invalid');
        }
        return $this->row($this->attributionDao->getOne(['uid' => $uid], self::ATTRIBUTION_FIELDS));
    }

    public function attributionById(int $id): array
    {
        if ($id <= 0) {
            throw new ApiException('authority_attribution_id_invalid');
        }
        return $this->row($this->attributionDao->getOne(['id' => $id], self::ATTRIBUTION_FIELDS));
    }

    public function referralById(int $id): array
    {
        if ($id <= 0) {
            throw new ApiException('authority_referral_id_invalid');
        }
        return $this->row($this->referralDao->getOne(['id' => $id], self::REFERRAL_FIELDS));
    }

    public function hasActiveReferral(int $referredUid, int $storeId = 0): bool
    {
        $summary = $this->activeReferralSummary($referredUid, $storeId);
        if (!$summary['consistent']) {
            throw new ApiException('authority_data_requires_headquarters_review');
        }
        return $summary['has_active_referral'];
    }

    public function activeReferralSummary(int $referredUid, int $storeId = 0): array
    {
        if ($referredUid <= 0) {
            return ['has_active_referral' => false, 'consistent' => true];
        }
        $query = $this->referralDao->search([])
            ->where('referred_uid', $referredUid);
        if ($storeId > 0) {
            $query = $query->where('store_id', $storeId);
        }
        $rows = $query->field(self::REFERRAL_FIELDS)->select()->toArray();
        $hasActiveReferral = false;
        foreach ($rows as $row) {
            if (!$this->consistency->isReferralConsistent($row)) {
                return ['has_active_referral' => false, 'consistent' => false];
            }
            if ((string)$row['status'] === 'active') {
                $hasActiveReferral = true;
            }
        }
        return ['has_active_referral' => $hasActiveReferral, 'consistent' => true];
    }

    public function attributionPage(array $filters, int $forcedStoreId = 0, array $forcedStatuses = []): array
    {
        [$page, $limit] = $this->page($filters);
        $statuses = $forcedStatuses ?: $this->statusFilter((string)($filters['status'] ?? ''), self::ATTRIBUTION_STATUSES);
        $uids = $this->keywordUids((string)($filters['keyword'] ?? ''));
        if ($uids !== null) {
            if (!$uids) {
                return ['list' => [], 'count' => 0, 'page' => $page, 'limit' => $limit];
            }
        }
        $query = function () use ($filters, $forcedStoreId, $statuses, $uids) {
            $builder = $this->attributionDao->search([]);
            if ($forcedStoreId > 0) {
                $builder = $builder->where('store_id', $forcedStoreId);
            } elseif ((int)($filters['store_id'] ?? 0) > 0) {
                $builder = $builder->where('store_id', (int)$filters['store_id']);
            }
            if ($statuses) {
                $builder = $builder->whereIn('status', $statuses);
            }
            if ((int)($filters['uid'] ?? 0) > 0) {
                $builder = $builder->where('uid', (int)$filters['uid']);
            }
            if ($uids !== null) {
                $builder = $builder->whereIn('uid', $uids);
            }
            $this->applyDateRange($builder, $filters, 'update_time');
            return $builder;
        };
        $count = (int)$query()->count();
        $list = $query()->field(self::ATTRIBUTION_FIELDS)->order('id desc')->page($page, $limit)->select()->toArray();
        return compact('list', 'count', 'page', 'limit');
    }

    public function referralPage(array $filters): array
    {
        [$page, $limit] = $this->page($filters);
        $statuses = $this->statusFilter((string)($filters['status'] ?? ''), self::REFERRAL_STATUSES);
        $query = function () use ($filters, $statuses) {
            $builder = $this->referralDao->search([]);
            foreach (['store_id', 'referrer_uid', 'referred_uid'] as $field) {
                if ((int)($filters[$field] ?? 0) > 0) {
                    $builder = $builder->where($field, (int)$filters[$field]);
                }
            }
            if ($statuses) {
                $builder = $builder->whereIn('status', $statuses);
            }
            $this->applyDateRange($builder, $filters, 'update_time');
            return $builder;
        };
        $count = (int)$query()->count();
        $list = $query()->field(self::REFERRAL_FIELDS)->order('id desc')->page($page, $limit)->select()->toArray();
        return compact('list', 'count', 'page', 'limit');
    }

    public function attributionEvents(int $attributionId): array
    {
        return $this->attributionEventDao->search([])
            ->where('attribution_current_id', $attributionId)
            ->field('event_no,authority_version,event_type,before_store_id,after_store_id,before_status,after_status,before_status_reason_code,after_status_reason_code,source_type,source_id,operator_uid,operator_role_code,request_id,add_time')
            ->order('authority_version asc,id asc')->select()->toArray();
    }

    public function referralEvents(int $referralId): array
    {
        return $this->referralEventDao->search([])
            ->where('referral_current_id', $referralId)
            ->field('event_no,relation_version,event_type,before_status,after_status,source_type,source_id,operator_uid,operator_role_code,request_id,add_time')
            ->order('relation_version asc,id asc')->select()->toArray();
    }

    public function isAttributionConsistent(array $row): bool
    {
        return $row && $this->consistency->isAttributionConsistent($row);
    }

    public function isReferralConsistent(array $row): bool
    {
        return $row && $this->consistency->isReferralConsistent($row);
    }

    public function userMap(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids) {
            return [];
        }
        $rows = $this->userDao->search([])->whereIn('uid', $uids)
            ->field('uid,nickname,avatar,phone')->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = $row;
        }
        return $map;
    }

    public function storeMap(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (!$storeIds) {
            return [];
        }
        $rows = $this->storeDao->search([])->whereIn('id', $storeIds)
            ->field('id,name,image,address,is_show,is_del')->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }
        return $map;
    }

    private function page(array $filters): array
    {
        return [(int)$filters['page'], (int)$filters['limit']];
    }

    private function statusFilter(string $status, array $allowed): array
    {
        $status = trim($status);
        if ($status === '') {
            return [];
        }
        if (!in_array($status, $allowed, true)) {
            throw new ApiException('authority_status_filter_invalid');
        }
        return [$status];
    }

    private function keywordUids(string $keyword): ?array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }
        if (mb_strlen($keyword) > 50) {
            throw new ApiException('authority_keyword_too_long');
        }
        return array_map('intval', $this->userDao->search([])
            ->where('nickname|phone', 'like', '%' . $keyword . '%')
            ->limit(200)->column('uid'));
    }

    private function applyDateRange(&$query, array $filters, string $field): void
    {
        $start = $this->timestamp($filters['start_date'] ?? '', false);
        $end = $this->timestamp($filters['end_date'] ?? '', true);
        if ($start && $end && ($end < $start || $end - $start > 366 * 86400)) {
            throw new ApiException('authority_date_range_invalid');
        }
        if ($start) {
            $query = $query->where($field, '>=', $start);
        }
        if ($end) {
            $query = $query->where($field, '<=', $end);
        }
    }

    private function timestamp($value, bool $endOfDay): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        $text = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            throw new ApiException('authority_date_filter_invalid');
        }
        $time = strtotime($text . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
        if (!$time) {
            throw new ApiException('authority_date_filter_invalid');
        }
        return $time;
    }

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
