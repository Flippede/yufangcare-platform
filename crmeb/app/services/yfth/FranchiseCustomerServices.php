<?php

namespace app\services\yfth;

use app\Request;
use app\dao\order\StoreOrderDao;
use app\dao\user\UserDao;
use app\dao\yfth\YfthCustomerFollowRecordDao;
use app\dao\yfth\YfthCustomerRelationDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthServiceAppointmentDao;
use app\dao\yfth\YfthServiceWriteoffRecordDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class FranchiseCustomerServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_franchise_customer';
    private const STORE_ROLES = ['franchisee', 'store_manager', 'store_staff'];
    private const CUSTOMER_STATUSES = ['potential', 'leads', 'registered', 'purchased', 'serving', 'repeat', 'lost'];
    private const TRUSTED_ATTRIBUTION_SOURCES = ['order', 'appointment', 'writeoff'];
    private const AUTHORITY_PROJECTION_SOURCES = ['direct_referral', 'permanent_membership', 'permanent_attribution', 'store_acquisition'];
    private const FOLLOW_TYPES = ['phone', 'wechat', 'store_visit', 'other'];

    public function __construct(YfthCustomerRelationDao $dao)
    {
        $this->dao = $dao;
    }

    public function bindCustomer(Request $request, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        if (!empty($data['_direct_customer_field_submitted'])) {
            throw new ApiException('direct_customer_binding_forbidden');
        }

        $storeId = (int)$scope['context']['store_id'];
        $source = $this->normalizeAttributionSource((string)($data['source'] ?? ''), true);
        $referenceId = (int)($data['reference_id'] ?? 0);
        $sourceRecord = $this->resolveTrustedAttribution($source, $referenceId, $storeId);
        $uid = (int)$sourceRecord['uid'];
        $customerStatus = $this->normalizeCustomerStatus((string)($data['customer_status'] ?? 'potential'));
        $operatorUid = (int)$scope['context']['uid'];

        return Db::transaction(function () use ($uid, $storeId, $operatorUid, $source, $referenceId, $customerStatus, $scope, $sourceRecord) {
            $active = $this->dao->getOne(['active_key' => (string)$uid]);
            if ($active) {
                throw new ApiException('already_bound');
            }

            $now = time();
            $row = $this->dao->save([
                'uid' => $uid,
                'store_id' => $storeId,
                'owner_uid' => $operatorUid,
                'source' => $source,
                'reference_id' => $referenceId,
                'customer_status' => $customerStatus,
                'status' => YfthConstants::STATUS_ACTIVE,
                'bind_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
                'active_key' => (string)$uid,
            ]);
            $relation = is_array($row) ? $row : $row->toArray();
            $this->audit('customer_relation', (int)$relation['id'], 'bind', [], array_merge($relation, [
                'trusted_source' => $sourceRecord['summary'],
            ]), $scope);
            return ['relation' => $this->formatRelation($relation, true)];
        });
    }

    /**
     * Projects an already-validated YFTH authority fact into the store CRM view.
     * This method is intentionally not exposed as a client binding endpoint.
     */
    public function syncAuthorityCustomerInTransaction(
        int $uid,
        int $storeId,
        string $source,
        int $referenceId,
        int $ownerUid,
        int $operatorUid,
        string $operatorRole,
        string $reason,
        string $requestId
    ): array {
        if ($uid <= 0 || $storeId <= 0 || $referenceId <= 0 || !in_array($source, self::AUTHORITY_PROJECTION_SOURCES, true)) {
            throw new ApiException('customer_authority_projection_invalid');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);

        $active = $this->row($this->dao->search([])->where('active_key', (string)$uid)->lock(true)->find());
        if ($active) {
            if ((int)$active['store_id'] !== $storeId) {
                throw new ApiException('customer_relation_authority_conflict');
            }
            return ['changed' => false, 'relation' => $this->formatRelation($active, true)];
        }

        $now = time();
        try {
            $saved = $this->dao->save([
                'uid' => $uid,
                'store_id' => $storeId,
                'owner_uid' => max(0, $ownerUid),
                'source' => $source,
                'reference_id' => $referenceId,
                'customer_status' => 'registered',
                'status' => YfthConstants::STATUS_ACTIVE,
                'bind_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
                'active_key' => (string)$uid,
            ]);
        } catch (\Throwable $e) {
            if (!$this->isUniqueConflict($e)) {
                throw $e;
            }
            $active = $this->row($this->dao->search([])->where('active_key', (string)$uid)->lock(true)->find());
            if (!$active || (int)$active['store_id'] !== $storeId) {
                throw new ApiException('customer_relation_authority_conflict');
            }
            return ['changed' => false, 'relation' => $this->formatRelation($active, true)];
        }

        $relation = $this->row($saved);
        app()->make(AuditEventServices::class)->recordSafely(
            self::DOMAIN,
            'customer_relation',
            (string)$relation['id'],
            'authority_projection',
            [],
            $this->sanitizeState($relation),
            $operatorUid,
            $operatorRole,
            $storeId,
            $reason,
            $requestId
        );
        return ['changed' => true, 'relation' => $this->formatRelation($relation, true)];
    }

    public function backfillAuthorityCustomers(
        int $storeId,
        int $limit,
        int $operatorUid,
        string $reason,
        string $requestId
    ): array {
        if ($operatorUid <= 0 || trim($reason) === '' || trim($requestId) === '') {
            throw new ApiException('customer_authority_backfill_operator_required');
        }
        $limit = max(1, min(1000, $limit));
        $query = Db::name('yfth_hq_customer_attribution_current')
            ->where('status', 'active')->where('store_id', '>', 0)->order('id asc')->limit($limit);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $rows = $query->field('id,uid,store_id')->select()->toArray();
        $result = ['scanned' => count($rows), 'created' => 0, 'existing' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $row) {
            try {
                $item = Db::transaction(function () use ($row, $operatorUid, $reason, $requestId) {
                    $current = (array)Db::name('yfth_hq_customer_attribution_current')
                        ->where('id', (int)$row['id'])->lock(true)->find();
                    if (!$current || (string)($current['status'] ?? '') !== 'active') {
                        throw new ApiException('customer_authority_backfill_current_changed');
                    }
                    app()->make(HqAuthorityConsistencyValidator::class)->assertAttribution($current, true);
                    $referrerUid = (int)Db::name('yfth_hq_active_referral_current')
                        ->where('referred_uid', (int)$current['uid'])->where('store_id', (int)$current['store_id'])
                        ->where('status', 'active')->value('referrer_uid');
                    return $this->syncAuthorityCustomerInTransaction(
                        (int)$current['uid'],
                        (int)$current['store_id'],
                        'permanent_attribution',
                        (int)$row['id'],
                        $referrerUid,
                        $operatorUid,
                        'headquarters_admin',
                        $reason,
                        $requestId . ':' . (int)$row['id']
                    );
                });
                $result[$item['changed'] ? 'created' : 'existing']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['uid' => (int)$row['uid'], 'error' => substr($e->getMessage(), 0, 120)];
            }
        }
        return $result;
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
                $normalizedSource = $this->normalizeAttributionSource($source, false);
                if ($normalizedSource !== '') {
                    $query->where('source', $normalizedSource);
                }
            }
            $keyword = trim((string)($where['keyword'] ?? ''));
            if ($keyword !== '' && ctype_digit($keyword)) {
                $query->where('id', (int)$keyword);
            }
            return $query;
        };

        $count = (int)$buildQuery()->count();
        $relations = $buildQuery()
            ->field('id,uid,store_id,source,reference_id,customer_status,status')
            ->page($page, $limit)
            ->order('id desc')
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
            'nickname' => (string)($user['nickname'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            'source' => (string)($relation['source'] ?? ''),
            'source_text' => $this->sourceText((string)($relation['source'] ?? '')),
            'customer_status' => (string)($relation['customer_status'] ?? ''),
            'customer_status_text' => $this->customerStatusText((string)($relation['customer_status'] ?? '')),
            'has_5980_package' => $this->hasPackage($uid),
            'has_appointment' => $this->hasAppointment($uid, $storeId),
            'latest_follow_time' => $this->latestFollowTime($relationId, $storeId),
        ];
        $payload['package_status'] = $payload['has_5980_package'] ? 'active' : 'none';
        $payload['service_status'] = $payload['has_appointment'] ? 'has_appointment' : 'none';
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
            'follow_type' => (string)($row['follow_type'] ?? ''),
            'follow_type_text' => $this->followTypeText((string)($row['follow_type'] ?? '')),
            'content' => (string)($row['content'] ?? ''),
            'next_follow_time' => (int)($row['next_follow_time'] ?? 0),
            'follow_time' => (int)($row['create_time'] ?? 0),
        ];
    }

    private function resolveTrustedAttribution(string $source, int $referenceId, int $storeId): array
    {
        if ($referenceId <= 0) {
            throw new ApiException('customer_reference_id_required');
        }

        if ($source === 'order') {
            $row = app()->make(StoreOrderDao::class)->get($referenceId, ['id', 'uid', 'store_id', 'order_id', 'paid', 'pid', 'is_del', 'is_system_del']);
            $row = $this->trustedSourceRow($row);
            $this->assertTrustedSourceStore($row, $storeId);
            if ((int)($row['paid'] ?? 0) !== 1 || (int)($row['pid'] ?? 0) !== 0 || (int)($row['is_del'] ?? 0) !== 0 || (int)($row['is_system_del'] ?? 0) !== 0) {
                throw new ApiException('customer_source_not_eligible');
            }
            return $this->trustedSourcePayload($row, 'order', ['order_id' => (string)($row['order_id'] ?? '')]);
        }

        if ($source === 'appointment') {
            $row = app()->make(YfthServiceAppointmentDao::class)->get($referenceId, ['id', 'uid', 'store_id', 'appointment_no', 'status']);
            $row = $this->trustedSourceRow($row);
            $this->assertTrustedSourceStore($row, $storeId);
            if (in_array((string)($row['status'] ?? ''), ['cancelled', 'rejected'], true)) {
                throw new ApiException('customer_source_not_eligible');
            }
            return $this->trustedSourcePayload($row, 'appointment', ['appointment_no' => (string)($row['appointment_no'] ?? '')]);
        }

        if ($source === 'writeoff') {
            $row = app()->make(YfthServiceWriteoffRecordDao::class)->get($referenceId, ['id', 'uid', 'store_id', 'writeoff_no', 'appointment_id', 'status']);
            $row = $this->trustedSourceRow($row);
            $this->assertTrustedSourceStore($row, $storeId);
            if ((string)($row['status'] ?? '') !== 'succeeded') {
                throw new ApiException('customer_source_not_eligible');
            }
            return $this->trustedSourcePayload($row, 'writeoff', ['writeoff_no' => (string)($row['writeoff_no'] ?? '')]);
        }

        throw new ApiException('customer_source_not_supported');
    }

    private function trustedSourceRow($row): array
    {
        if (!$row) {
            throw new ApiException('customer_source_not_found');
        }
        $row = is_array($row) ? $row : $row->toArray();
        if ((int)($row['uid'] ?? 0) <= 0) {
            throw new ApiException('customer_source_uid_missing');
        }
        return $row;
    }

    private function assertTrustedSourceStore(array $row, int $storeId): void
    {
        if ((int)($row['store_id'] ?? 0) !== $storeId) {
            throw new ApiException('customer_source_store_forbidden');
        }
    }

    private function trustedSourcePayload(array $row, string $source, array $summary = []): array
    {
        return [
            'uid' => (int)$row['uid'],
            'source' => $source,
            'reference_id' => (int)$row['id'],
            'summary' => array_merge([
                'source' => $source,
                'reference_id' => (int)$row['id'],
                'uid' => (int)$row['uid'],
                'store_id' => (int)$row['store_id'],
            ], $summary),
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

    private function normalizeAttributionSource(string $source, bool $strict): string
    {
        $source = trim($source);
        if (in_array($source, array_merge(self::TRUSTED_ATTRIBUTION_SOURCES, self::AUTHORITY_PROJECTION_SOURCES), true)) {
            return $source;
        }
        if ($strict) {
            throw new ApiException('customer_source_not_supported');
        }
        return '';
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
            'order' => '订单',
            'appointment' => '预约',
            'writeoff' => '核销',
            'headquarters_assign' => '总部分配',
            'direct_referral' => '一级推荐归属',
            'permanent_membership' => '永久会员归属',
            'permanent_attribution' => '权威归属同步',
            'store_acquisition' => '门店员工获客码',
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

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }

    private function isUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000';
    }
}
