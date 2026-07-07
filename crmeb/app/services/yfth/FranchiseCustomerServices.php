<?php

namespace app\services\yfth;

use app\Request;
use app\dao\user\UserDao;
use app\dao\yfth\YfthCustomerFollowRecordDao;
use app\dao\yfth\YfthCustomerRelationDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthServiceAppointmentDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class FranchiseCustomerServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_franchise_customer';
    private const STORE_ROLES = ['franchisee', 'store_manager', 'store_staff'];
    private const CUSTOMER_STATUSES = ['potential', 'leads', 'registered', 'purchased', 'serving', 'repeat', 'lost'];
    private const SOURCES = ['franchise_referral', 'store_visit', 'qr_scan', 'activity', 'online', 'headquarters_assign'];
    private const FOLLOW_TYPES = ['phone', 'wechat', 'store_visit', 'other'];

    public function __construct(YfthCustomerRelationDao $dao)
    {
        $this->dao = $dao;
    }

    public function bindCustomer(Request $request, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        $uid = (int)($data['uid'] ?? 0);
        if ($uid <= 0) {
            throw new ApiException('customer_uid_required');
        }
        $user = app()->make(UserDao::class)->get($uid, ['uid', 'nickname', 'avatar', 'phone']);
        if (!$user) {
            throw new ApiException('customer_user_not_found');
        }

        $source = $this->normalizeSource((string)($data['source'] ?? 'store_visit'));
        $customerStatus = $this->normalizeCustomerStatus((string)($data['customer_status'] ?? 'potential'));
        $storeId = (int)$scope['context']['store_id'];
        $operatorUid = (int)$scope['context']['uid'];

        return Db::transaction(function () use ($uid, $storeId, $operatorUid, $source, $customerStatus, $scope) {
            $active = $this->dao->getOne(['active_key' => (string)$uid]);
            if ($active) {
                $active = is_array($active) ? $active : $active->toArray();
                if ((int)$active['store_id'] !== $storeId) {
                    throw new ApiException('customer_relation_already_bound');
                }
                return ['relation' => $this->formatRelation($active, true)];
            }

            $now = time();
            $row = $this->dao->save([
                'uid' => $uid,
                'store_id' => $storeId,
                'owner_uid' => $operatorUid,
                'source' => $source,
                'customer_status' => $customerStatus,
                'status' => YfthConstants::STATUS_ACTIVE,
                'bind_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
                'active_key' => (string)$uid,
            ]);
            $relation = is_array($row) ? $row : $row->toArray();
            $this->audit('customer_relation', (int)$relation['id'], 'bind', [], $relation, $scope);
            return ['relation' => $this->formatRelation($relation, true)];
        });
    }

    public function customerList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request);
        $storeId = (int)$scope['context']['store_id'];
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;

        $buildQuery = function () use ($storeId, $where) {
            $query = $this->dao->search([])
                ->where('store_id', $storeId)
                ->where('status', YfthConstants::STATUS_ACTIVE);
            $status = trim((string)($where['customer_status'] ?? ''));
            if ($status !== '') {
                $query->where('customer_status', $this->normalizeCustomerStatus($status));
            }
            $source = trim((string)($where['source'] ?? ''));
            if ($source !== '') {
                $query->where('source', $this->normalizeSource($source));
            }
            $keyword = trim((string)($where['keyword'] ?? ''));
            if ($keyword !== '' && ctype_digit($keyword)) {
                $query->where('uid', (int)$keyword);
            }
            return $query;
        };

        $count = (int)$buildQuery()->count();
        $relations = $buildQuery()
            ->field('id,uid,store_id,owner_uid,source,customer_status,status,bind_time,create_time,update_time')
            ->page($page, $limit)
            ->order('update_time desc,id desc')
            ->select()
            ->toArray();

        $users = $this->userMap(array_column($relations, 'uid'));
        $list = array_map(function ($relation) use ($users) {
            return $this->formatRelation($relation, false, $users[(int)$relation['uid']] ?? []);
        }, $relations);

        return compact('list', 'count');
    }

    public function customerDetail(Request $request, int $relationId): array
    {
        $scope = $this->resolveStoreScope($request);
        $relation = $this->requireRelation($relationId, (int)$scope['context']['store_id']);
        $users = $this->userMap([(int)$relation['uid']]);
        $follows = app()->make(YfthCustomerFollowRecordDao::class)->search([])
            ->where('customer_relation_id', (int)$relation['id'])
            ->where('store_id', (int)$relation['store_id'])
            ->field('id,customer_relation_id,uid,store_id,operator_uid,follow_type,content,next_follow_time,create_time')
            ->order('create_time desc,id desc')
            ->limit(20)
            ->select()
            ->toArray();

        return [
            'customer' => $this->formatRelation($relation, true, $users[(int)$relation['uid']] ?? []),
            'follow_records' => array_map(function ($row) {
                return $this->formatFollow($row);
            }, $follows),
        ];
    }

    public function addFollow(Request $request, int $relationId, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        $relation = $this->requireRelation($relationId, (int)$scope['context']['store_id']);
        $content = trim((string)($data['content'] ?? ''));
        if ($content === '') {
            throw new ApiException('follow_content_required');
        }
        if (mb_strlen($content) > 1000) {
            throw new ApiException('follow_content_too_long');
        }
        $followType = $this->normalizeFollowType((string)($data['follow_type'] ?? 'other'));
        $now = time();
        $record = app()->make(YfthCustomerFollowRecordDao::class)->save([
            'customer_relation_id' => (int)$relation['id'],
            'uid' => (int)$relation['uid'],
            'store_id' => (int)$relation['store_id'],
            'operator_uid' => (int)$scope['context']['uid'],
            'follow_type' => $followType,
            'content' => $content,
            'next_follow_time' => $this->parseTime($data['next_follow_time'] ?? 0),
            'create_time' => $now,
        ]);
        $record = is_array($record) ? $record : $record->toArray();
        $this->dao->update((int)$relation['id'], ['update_time' => $now]);
        $this->audit('customer_follow_record', (int)$record['id'], 'create', [], $record, $scope);
        return ['follow_record' => $this->formatFollow($record)];
    }

    private function resolveStoreScope(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $roleCode = (string)($context['role_code'] ?? '');
        if (!in_array($roleCode, self::STORE_ROLES, true)) {
            throw new ApiException('franchise_customer_role_forbidden');
        }
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new ApiException('store_id_required_for_franchise_customer');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        return [
            'context' => $context,
            'role_code' => $roleCode,
            'store_id' => $storeId,
            'operator_uid' => (int)($context['uid'] ?? 0),
        ];
    }

    private function requireRelation(int $relationId, int $storeId): array
    {
        $relation = $this->dao->getOne([
            'id' => $relationId,
            'store_id' => $storeId,
            'status' => YfthConstants::STATUS_ACTIVE,
        ]);
        if (!$relation) {
            throw new ApiException('customer_relation_not_found');
        }
        return is_array($relation) ? $relation : $relation->toArray();
    }

    private function formatRelation(array $relation, bool $detail, array $user = []): array
    {
        if (!$user && !empty($relation['uid'])) {
            $users = $this->userMap([(int)$relation['uid']]);
            $user = $users[(int)$relation['uid']] ?? [];
        }
        $relationId = (int)($relation['id'] ?? 0);
        $uid = (int)($relation['uid'] ?? 0);
        $storeId = (int)($relation['store_id'] ?? 0);
        $payload = [
            'id' => $relationId,
            'uid' => $uid,
            'store_id' => $storeId,
            'owner_uid' => (int)($relation['owner_uid'] ?? 0),
            'nickname' => (string)($user['nickname'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            'source' => (string)($relation['source'] ?? ''),
            'source_text' => $this->sourceText((string)($relation['source'] ?? '')),
            'customer_status' => (string)($relation['customer_status'] ?? ''),
            'customer_status_text' => $this->customerStatusText((string)($relation['customer_status'] ?? '')),
            'status' => (string)($relation['status'] ?? ''),
            'bind_time' => (int)($relation['bind_time'] ?? 0),
            'create_time' => (int)($relation['create_time'] ?? 0),
            'update_time' => (int)($relation['update_time'] ?? 0),
            'has_5980_package' => $this->hasPackage($uid),
            'has_appointment' => $this->hasAppointment($uid, $storeId),
            'latest_follow_time' => $this->latestFollowTime($relationId, $storeId),
        ];
        if ($detail) {
            $payload['follow_summary'] = [
                'latest_follow_time' => $payload['latest_follow_time'],
                'next_follow_time' => $this->nextFollowTime($relationId, $storeId),
            ];
        }
        return $payload;
    }

    private function formatFollow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'customer_relation_id' => (int)($row['customer_relation_id'] ?? 0),
            'uid' => (int)($row['uid'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'operator_uid' => (int)($row['operator_uid'] ?? 0),
            'follow_type' => (string)($row['follow_type'] ?? ''),
            'follow_type_text' => $this->followTypeText((string)($row['follow_type'] ?? '')),
            'content' => (string)($row['content'] ?? ''),
            'next_follow_time' => (int)($row['next_follow_time'] ?? 0),
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
    }

    private function userMap(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids) {
            return [];
        }
        $rows = app()->make(UserDao::class)->search([])
            ->whereIn('uid', $uids)
            ->field('uid,nickname,avatar,phone')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = $row;
        }
        return $map;
    }

    private function hasPackage(int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }
        return (int)app()->make(YfthPackageInstanceDao::class)->search([])
            ->where('uid', $uid)
            ->whereIn('status', ['active', 'refunding'])
            ->count() > 0;
    }

    private function hasAppointment(int $uid, int $storeId): bool
    {
        if ($uid <= 0 || $storeId <= 0) {
            return false;
        }
        return (int)app()->make(YfthServiceAppointmentDao::class)->search([])
            ->where('uid', $uid)
            ->where('store_id', $storeId)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->count() > 0;
    }

    private function latestFollowTime(int $relationId, int $storeId): int
    {
        return (int)app()->make(YfthCustomerFollowRecordDao::class)->search([])
            ->where('customer_relation_id', $relationId)
            ->where('store_id', $storeId)
            ->max('create_time');
    }

    private function nextFollowTime(int $relationId, int $storeId): int
    {
        return (int)app()->make(YfthCustomerFollowRecordDao::class)->search([])
            ->where('customer_relation_id', $relationId)
            ->where('store_id', $storeId)
            ->where('next_follow_time', '>', 0)
            ->order('next_follow_time asc')
            ->value('next_follow_time');
    }

    private function audit(string $objectType, int $objectId, string $action, array $before, array $after, array $scope): void
    {
        app()->make(AuditEventServices::class)->recordSafely(
            self::DOMAIN,
            $objectType,
            (string)$objectId,
            $action,
            $this->sanitizeState($before),
            $this->sanitizeState($after),
            (int)$scope['operator_uid'],
            (string)$scope['role_code'],
            (int)$scope['store_id'],
            '',
            ''
        );
    }

    private function normalizeCustomerStatus(string $status): string
    {
        $status = trim($status);
        return in_array($status, self::CUSTOMER_STATUSES, true) ? $status : 'potential';
    }

    private function normalizeSource(string $source): string
    {
        $source = trim($source);
        return in_array($source, self::SOURCES, true) ? $source : 'store_visit';
    }

    private function normalizeFollowType(string $type): string
    {
        $type = trim($type);
        return in_array($type, self::FOLLOW_TYPES, true) ? $type : 'other';
    }

    private function customerStatusText(string $status): string
    {
        $map = [
            'potential' => '潜在客户',
            'leads' => '线索客户',
            'registered' => '已注册',
            'purchased' => '已购买',
            'serving' => '服务中',
            'repeat' => '复购客户',
            'lost' => '流失客户',
        ];
        return $map[$status] ?? $status;
    }

    private function sourceText(string $source): string
    {
        $map = [
            'franchise_referral' => '加盟商推荐',
            'store_visit' => '到店',
            'qr_scan' => '扫码',
            'activity' => '活动',
            'online' => '线上',
            'headquarters_assign' => '总部分配',
        ];
        return $map[$source] ?? $source;
    }

    private function followTypeText(string $type): string
    {
        $map = [
            'phone' => '电话',
            'wechat' => '微信',
            'store_visit' => '到店沟通',
            'other' => '其他',
        ];
        return $map[$type] ?? $type;
    }
}
