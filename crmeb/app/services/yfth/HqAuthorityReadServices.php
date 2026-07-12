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
    private const ATTRIBUTION_FIELDS = 'id,uid,store_id,status,status_reason_code,authority_version,source_type,bound_at,paused_at,closed_at,close_reason,add_time,update_time';
    private const REFERRAL_FIELDS = 'id,relation_no,referrer_uid,referred_uid,store_id,attribution_current_id,status,active_referred_uid,source_type,started_at,paused_at,closed_at,close_reason,relation_version,add_time,update_time';
    private const ATTRIBUTION_STATUSES = ['active', 'paused', 'unassigned', 'closed'];
    private const REFERRAL_STATUSES = ['active', 'paused', 'closed', 'invalid'];

    private $attributionDao;
    private $attributionEventDao;
    private $referralDao;
    private $referralEventDao;
    private $userDao;
    private $storeDao;

    public function __construct(
        YfthHqCustomerAttributionCurrentDao $attributionDao,
        YfthHqCustomerAttributionEventDao $attributionEventDao,
        YfthHqActiveReferralCurrentDao $referralDao,
        YfthHqActiveReferralEventDao $referralEventDao,
        UserDao $userDao,
        SystemStoreDao $storeDao
    ) {
        $this->attributionDao = $attributionDao;
        $this->attributionEventDao = $attributionEventDao;
        $this->referralDao = $referralDao;
        $this->referralEventDao = $referralEventDao;
        $this->userDao = $userDao;
        $this->storeDao = $storeDao;
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
        if ($referredUid <= 0) {
            return false;
        }
        $query = $this->referralDao->search([])
            ->where('referred_uid', $referredUid)
            ->where('status', 'active');
        if ($storeId > 0) {
            $query = $query->where('store_id', $storeId);
        }
        return (int)$query->count() > 0;
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
        if (!$row || !in_array((string)$row['status'], self::ATTRIBUTION_STATUSES, true)) {
            return false;
        }
        $status = (string)$row['status'];
        $storeId = (int)$row['store_id'];
        if ((in_array($status, ['active', 'paused'], true) && $storeId <= 0)
            || (in_array($status, ['unassigned', 'closed'], true) && $storeId !== 0)) {
            return false;
        }
        $version = (int)$row['authority_version'];
        $eventCount = (int)$this->attributionEventDao->search([])
            ->where('attribution_current_id', (int)$row['id'])->count();
        if ($version === 0) {
            return $status === 'unassigned' && $storeId === 0
                && (string)$row['status_reason_code'] === 'initial_placeholder' && $eventCount === 0;
        }
        return $version > 0 && $eventCount === $version
            && (int)$this->attributionEventDao->search([])
                ->where('attribution_current_id', (int)$row['id'])
                ->where('authority_version', $version)->count() === 1;
    }

    public function isReferralConsistent(array $row): bool
    {
        if (!$row || !in_array((string)$row['status'], self::REFERRAL_STATUSES, true)) {
            return false;
        }
        $status = (string)$row['status'];
        $activeUid = $row['active_referred_uid'] === null ? null : (int)$row['active_referred_uid'];
        if ((in_array($status, ['active', 'paused'], true) && $activeUid !== (int)$row['referred_uid'])
            || (in_array($status, ['closed', 'invalid'], true) && $activeUid !== null)) {
            return false;
        }
        $version = (int)$row['relation_version'];
        return $version >= 1
            && (int)$this->referralEventDao->search([])->where('referral_current_id', (int)$row['id'])->count() === $version
            && (int)$this->referralEventDao->search([])
                ->where('referral_current_id', (int)$row['id'])
                ->where('relation_version', $version)->count() === 1;
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
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = (int)($filters['limit'] ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        return [$page, min($limit, 50)];
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
        $start = $this->timestamp($filters['start_date'] ?? 0, false);
        $end = $this->timestamp($filters['end_date'] ?? 0, true);
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
        if ($value === '' || $value === null || (int)$value === 0) {
            return 0;
        }
        if (is_numeric($value) && (int)$value > 1000000000) {
            return (int)$value;
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
