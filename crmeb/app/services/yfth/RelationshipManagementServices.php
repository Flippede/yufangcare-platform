<?php

namespace app\services\yfth;

use app\dao\yfth\YfthHqActiveReferralCurrentDao;
use app\dao\yfth\YfthHqCustomerAttributionCurrentDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class RelationshipManagementServices
{
    private const BOUND_STATUSES = ['active', 'paused'];

    private $adminContext;
    private $read;
    private $dto;
    private $attribution;
    private $referral;
    private $attributionDao;
    private $referralDao;
    private $runner;
    private $audit;

    public function __construct(
        AdminStoreContextServices $adminContext,
        HqAuthorityReadServices $read,
        HqAuthorityDtoServices $dto,
        HqCustomerAttributionServices $attribution,
        HqActiveReferralServices $referral,
        YfthHqCustomerAttributionCurrentDao $attributionDao,
        YfthHqActiveReferralCurrentDao $referralDao,
        HqAuthorityOperationRunner $runner,
        AuditEventServices $audit
    ) {
        $this->adminContext = $adminContext;
        $this->read = $read;
        $this->dto = $dto;
        $this->attribution = $attribution;
        $this->referral = $referral;
        $this->attributionDao = $attributionDao;
        $this->referralDao = $referralDao;
        $this->runner = $runner;
        $this->audit = $audit;
    }

    public function userHierarchy(array $filters, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        $page = (int)$filters['page'];
        $limit = (int)$filters['limit'];
        $query = function () use ($filters) {
            $builder = Db::name('user')->alias('u')
                ->leftJoin('yfth_hq_customer_attribution_current a', 'a.uid=u.uid')
                ->leftJoin('yfth_hq_active_referral_current r', "r.referred_uid=u.uid AND r.active_referred_uid=u.uid AND r.status IN ('active','paused')")
                ->leftJoin('yfth_permanent_membership m', "m.uid=u.uid AND m.status='active'")
                ->where('u.is_del', 0);

            $keyword = trim((string)($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                $builder->where(function ($query) use ($keyword) {
                    if (ctype_digit($keyword)) {
                        $query->where('u.uid', (int)$keyword)->whereOr('u.nickname|u.phone', 'like', '%' . $keyword . '%');
                    } else {
                        $query->where('u.nickname|u.phone', 'like', '%' . $keyword . '%');
                    }
                });
            }
            if ((int)($filters['store_id'] ?? 0) > 0) {
                $builder->where('a.store_id', (int)$filters['store_id']);
            }
            $bindingStatus = (string)($filters['binding_status'] ?? '');
            if ($bindingStatus === 'bound') {
                $builder->whereIn('a.status', self::BOUND_STATUSES);
            } elseif ($bindingStatus === 'unbound') {
                $builder->whereRaw("(a.id IS NULL OR a.status IN ('unassigned','closed'))");
            }
            $userType = (string)($filters['user_type'] ?? '');
            if ($userType === 'c2') {
                $builder->whereNotNull('r.id');
            } elseif ($userType === 'c1') {
                $builder->whereNotNull('m.id')->whereNull('r.id');
            } elseif ($userType === 'ordinary') {
                $builder->whereNull('m.id')->whereNull('r.id');
            }
            return $builder;
        };

        $count = (int)$query()->count();
        $rows = $query()->field([
            'u.uid', 'u.nickname', 'u.avatar', 'u.phone', 'u.add_time',
            'a.id AS attribution_id', 'a.store_id AS attribution_store_id', 'a.status AS attribution_status',
            'a.authority_version', 'a.bound_at',
            'r.id AS referral_id', 'r.referrer_uid', 'r.relation_version', 'r.status AS referral_status',
            'm.id AS membership_id',
        ])->order('u.uid desc')->page($page, $limit)->select()->toArray();

        $uids = array_map('intval', array_column($rows, 'uid'));
        $referrerUids = array_map('intval', array_column($rows, 'referrer_uid'));
        $users = $this->read->userMap(array_merge($uids, $referrerUids));
        $stores = $this->read->storeMap(array_column($rows, 'attribution_store_id'));
        $childCounts = $this->activeChildCounts($uids);

        $list = array_map(function (array $row) use ($users, $stores, $childCounts) {
            $uid = (int)$row['uid'];
            $attribution = (int)$row['attribution_id'] > 0
                ? $this->read->attributionById((int)$row['attribution_id']) : [];
            $referral = (int)$row['referral_id'] > 0
                ? $this->read->referralById((int)$row['referral_id']) : [];
            $attributionConsistent = !$attribution || $this->read->isAttributionConsistent($attribution);
            $referralConsistent = !$referral || $this->read->isReferralConsistent($referral);
            $bound = $attributionConsistent && $attribution
                && in_array((string)$attribution['status'], self::BOUND_STATUSES, true);
            $isC2 = $referralConsistent && $referral
                && in_array((string)$referral['status'], self::BOUND_STATUSES, true);
            $crossConsistent = !$isC2 || ($bound && (int)$referral['store_id'] === (int)$attribution['store_id']);
            $roleConsistent = !($isC2 && (int)$row['membership_id'] > 0);
            $consistent = $attributionConsistent && $referralConsistent && $crossConsistent && $roleConsistent;
            $isC1 = !$isC2 && (int)$row['membership_id'] > 0;
            $storeId = $bound ? (int)$attribution['store_id'] : 0;
            $referrerUid = $isC2 ? (int)$referral['referrer_uid'] : 0;
            $childCount = (int)($childCounts[$uid] ?? 0);
            $canRevoke = $consistent && $bound && $childCount === 0;

            return [
                'uid' => $uid,
                'customer' => $this->dto->userSummary($users[$uid] ?? $row),
                'user_type' => $isC2 ? 'c2' : ($isC1 ? 'c1' : 'ordinary'),
                'user_type_label' => $isC2 ? 'C2 普通用户' : ($isC1 ? 'C1 会员' : '普通用户'),
                'direct_parent_type' => $isC2 ? 'c1' : ($bound ? 'store' : 'none'),
                'direct_parent_label' => $isC2
                    ? ($referrerUid . ' · ' . (string)(($users[$referrerUid]['nickname'] ?? '') ?: '-'))
                    : ($bound ? (string)(($stores[$storeId]['name'] ?? '') ?: ('门店 #' . $storeId)) : '无上级'),
                'referrer_uid' => $referrerUid,
                'referrer' => $isC2 ? $this->dto->userSummary($users[$referrerUid] ?? []) : null,
                'store_id' => $storeId,
                'store' => $bound ? $this->dto->storeSummary($stores[$storeId] ?? []) : null,
                'binding_status' => $bound ? 'bound' : 'unbound',
                'binding_status_label' => $bound ? '已绑定门店' : '未绑定门店',
                'attribution_id' => (int)$row['attribution_id'],
                'attribution_status' => $attribution ? (string)$attribution['status'] : 'unassigned',
                'authority_version' => $attribution ? (int)$attribution['authority_version'] : 0,
                'referral_id' => $isC2 ? (int)$referral['id'] : 0,
                'relation_version' => $isC2 ? (int)$referral['relation_version'] : 0,
                'active_child_count' => $childCount,
                'can_revoke_parent' => $canRevoke,
                'revoke_block_reason' => !$consistent ? '数据异常，需先完成总部治理'
                    : ($childCount > 0 ? '请先撤销该用户的下级关系' : (!$bound ? '当前未绑定上级' : '')),
                'data_inconsistent' => !$consistent,
                'data_inconsistent_label' => $consistent ? '' : '数据异常，需总部治理',
            ];
        }, $rows);

        return compact('list', 'count', 'page', 'limit');
    }

    public function storeHierarchy(array $filters, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        $page = (int)$filters['page'];
        $limit = (int)$filters['limit'];
        $query = function () use ($filters) {
            $builder = Db::name('system_store')->where('is_del', 0);
            $keyword = trim((string)($filters['keyword'] ?? ''));
            if ($keyword !== '') {
                $builder->where('id|name|phone|address', 'like', '%' . $keyword . '%');
            }
            $status = (string)($filters['status'] ?? '');
            if ($status === 'active') {
                $builder->where('is_show', 1);
            } elseif ($status === 'disabled') {
                $builder->where('is_show', 0);
            }
            return $builder;
        };
        $count = (int)$query()->count();
        $rows = $query()->field('id,name,image,address,phone,is_show,is_del')->order('id desc')
            ->page($page, $limit)->select()->toArray();
        $storeIds = array_map('intval', array_column($rows, 'id'));
        $grants = $storeIds ? Db::name('yfth_franchise_identity_grant')
            ->whereIn('store_id', $storeIds)->where('status', 'active')
            ->where('role_code', 'store_manager')
            ->field('id,target_uid,store_id,role_code,grant_time')->order('id asc')->select()->toArray() : [];
        $users = $this->read->userMap(array_column($grants, 'target_uid'));
        $partnersByStore = [];
        foreach ($grants as $grant) {
            $storeId = (int)$grant['store_id'];
            $uid = (int)$grant['target_uid'];
            if (!isset($partnersByStore[$storeId][$uid])) {
                $partnersByStore[$storeId][$uid] = [
                    'uid' => $uid,
                    'partner' => $this->dto->userSummary($users[$uid] ?? []),
                    'roles' => [],
                    'role_labels' => [],
                    'joined_at' => (int)$grant['grant_time'],
                ];
            }
            $role = (string)$grant['role_code'];
            $partnersByStore[$storeId][$uid]['roles'][] = $role;
            $partnersByStore[$storeId][$uid]['role_labels'][] = '店长';
        }
        $list = array_map(function (array $row) use ($partnersByStore) {
            $storeId = (int)$row['id'];
            $partners = array_values($partnersByStore[$storeId] ?? []);
            return [
                'store_id' => $storeId,
                'store' => $this->dto->storeSummary($row),
                'status' => (int)$row['is_show'] === 1 ? 'active' : 'disabled',
                'status_label' => (int)$row['is_show'] === 1 ? '营业中' : '已停用',
                'partner_count' => count($partners),
                'partners' => $partners,
            ];
        }, $rows);
        return compact('list', 'count', 'page', 'limit');
    }

    public function revokeParent(int $attributionId, array $data, int $adminId, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        if ($attributionId <= 0 || $adminId <= 0) {
            throw new ApiException('relationship_revoke_target_invalid');
        }
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new ApiException('relationship_revoke_reason_required');
        }
        $idempotencyKey = trim((string)($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new ApiException('authority_idempotency_key_required');
        }
        $requestId = trim((string)($data['request_id'] ?? ''));
        if ($requestId === '') {
            $requestId = 'hq-parent-revoke-' . substr(hash('sha256', $idempotencyKey), 0, 40);
        }
        $requestId = substr($requestId, 0, 64);
        $sourceId = (int)base_convert(substr(hash('sha256', $requestId), 0, 12), 16, 10);
        $source = HqAuthoritySource::fromTrusted('headquarters_parent_revoke', max(1, $sourceId), $data);
        $mutation = new HqAuthorityMutation(
            $source,
            $adminId,
            'headquarter_operator',
            $reason,
            $requestId,
            $idempotencyKey
        );

        $result = $this->runner->run(
            'headquarters_parent_revoke',
            $mutation,
            ['attribution_id' => $attributionId],
            'attribution:' . $attributionId,
            function () use ($attributionId, $mutation) {
                $snapshot = $this->attributionDao->get($attributionId);
                $snapshot = $this->row($snapshot);
                $uid = (int)($snapshot['uid'] ?? 0);
                if ($uid <= 0) {
                    throw new ApiException('relationship_revoke_target_not_found');
                }
                $parentSnapshot = $this->row($this->referralDao->search([])
                    ->where('active_referred_uid', $uid)->order('id desc')->find());
                $lockUids = [$uid];
                if ((int)($parentSnapshot['referrer_uid'] ?? 0) > 0) {
                    $lockUids[] = (int)$parentSnapshot['referrer_uid'];
                }
                $locked = $this->attribution->lockCurrents($lockUids);
                $current = (array)$locked[$uid];
                if ((int)$current['id'] !== $attributionId
                    || !in_array((string)$current['status'], self::BOUND_STATUSES, true)) {
                    throw new ApiException('relationship_revoke_target_unbound');
                }

                $children = $this->referralDao->search([])->where('referrer_uid', $uid)
                    ->whereIn('status', self::BOUND_STATUSES)->lock(true)->select()->toArray();
                if ($children) {
                    throw new ApiException('relationship_revoke_has_active_children');
                }
                $parent = $this->referralDao->search([])->where('active_referred_uid', $uid)
                    ->order('id desc')->lock(true)->find();
                $parent = $this->row($parent);
                if ((int)($parent['id'] ?? 0) !== (int)($parentSnapshot['id'] ?? 0)
                    || (int)($parent['referrer_uid'] ?? 0) !== (int)($parentSnapshot['referrer_uid'] ?? 0)) {
                    throw new ApiException('relationship_revoke_lock_set_changed');
                }
                $referralResult = null;
                if ($parent) {
                    $referralResult = $this->referral->invalidateWithLockedCurrentsInTransaction(
                        (int)$parent['id'],
                        (int)$parent['relation_version'],
                        'headquarters_parent_revoked',
                        $mutation,
                        $locked
                    );
                }
                $attributionResult = $this->attribution->unassignForRebindingWithLockedCurrentInTransaction(
                    $uid,
                    (int)$current['authority_version'],
                    $mutation,
                    $current
                );
                $now = time();
                $legacyRelations = Db::name('yfth_customer_relation')->where('uid', $uid)
                    ->where('status', 'active')->update([
                        'status' => 'inactive', 'active_key' => null, 'update_time' => $now,
                    ]);
                $invalidatedInvites = Db::name('yfth_direct_referral_invite')->where('owner_uid', $uid)
                    ->where('status', 'active')->update([
                        'status' => 'invalidated', 'active_key' => null,
                        'invalidated_at' => $now, 'request_id' => $mutation->requestId(), 'update_time' => $now,
                    ]);
                return [
                    'changed' => true,
                    'uid' => $uid,
                    'attribution_id' => $attributionId,
                    'binding_status' => 'unbound',
                    'referral_revoked' => $referralResult !== null,
                    'legacy_relations_revoked' => (int)$legacyRelations,
                    'active_invites_invalidated' => (int)$invalidatedInvites,
                    'attribution' => $attributionResult['after'],
                ];
            }
        );

        if (empty($result['idempotent_replay']) && !empty($result['changed'])) {
            $this->audit->recordSafely(
                'yfth_relationship_management',
                'customer_parent_relationship',
                (string)$attributionId,
                'revoke_parent',
                ['attribution_id' => $attributionId],
                ['uid' => (int)$result['uid'], 'binding_status' => 'unbound'],
                $adminId,
                'headquarter_operator',
                0,
                $reason,
                $requestId
            );
        }
        return $result;
    }

    private function activeChildCounts(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids) {
            return [];
        }
        $rows = Db::name('yfth_hq_active_referral_current')->whereIn('referrer_uid', $uids)
            ->whereIn('status', self::BOUND_STATUSES)
            ->field('referrer_uid,COUNT(*) AS child_count')->group('referrer_uid')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['referrer_uid']] = (int)$row['child_count'];
        }
        return $result;
    }

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
