<?php

namespace app\services\yfth;

use app\dao\yfth\YfthPermanentMembershipDao;
use app\dao\yfth\YfthPermanentMembershipEventDao;
use app\dao\yfth\YfthHqCustomerAttributionCurrentDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class PackageMembershipServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_package_membership_referral';
    private $eventDao;
    private $attribution;
    private $attributionDao;
    private $audit;

    public function __construct(
        YfthPermanentMembershipDao $dao,
        YfthPermanentMembershipEventDao $eventDao,
        YfthHqCustomerAttributionCurrentDao $attributionDao,
        HqCustomerAttributionServices $attribution,
        AuditEventServices $audit
    ) {
        $this->dao = $dao;
        $this->eventDao = $eventDao;
        $this->attributionDao = $attributionDao;
        $this->attribution = $attribution;
        $this->audit = $audit;
    }

    public function effectiveMembership(int $uid): array
    {
        $authority = $this->effectiveMembershipAuthority($uid);
        if (!$authority) {
            return ['is_member' => false, 'persisted' => false, 'member' => null];
        }
        if ((string)$authority['authority_type'] === 'persisted') {
            return ['is_member' => true, 'persisted' => true, 'member' => $this->userDto($authority)];
        }
        return [
            'is_member' => true,
            'persisted' => false,
            'member' => [
                'membership_no' => '',
                'store_id' => (int)$authority['store_id'],
                'binding_status' => (string)($authority['binding_status'] ?? ((int)$authority['store_id'] > 0 ? 'bound' : 'unbound')),
                'status' => 'active',
                'activated_at' => (int)$authority['activated_at'],
                'actual_paid_amount_cent' => $this->moneyToCents($authority['order_pay_price']),
                'currency' => 'CNY',
                'source' => 'historical_package_pending_controlled_backfill',
            ],
        ];
    }

    public function effectiveMembershipAuthority(int $uid, bool $lockPersisted = false): array
    {
        if ($uid <= 0) {
            return [];
        }
        $query = $this->dao->search([])->where('uid', $uid)->where('status', 'active');
        if ($lockPersisted) {
            $query = $query->lock(true);
        }
        $member = $this->row($query->find());
        if ($member) {
            $member['authority_type'] = 'persisted';
            $member = $this->applyCurrentBindingStore($member);
            return $member;
        }
        $legacy = $this->legacyPackageFactForUid($uid);
        if (!$legacy) {
            return [];
        }
        return $this->applyCurrentBindingStore(array_merge($legacy, [
            'authority_type' => 'historical_package_activation',
            'source_package_instance_id' => (int)$legacy['instance_id'],
            'source_purchase_id' => (int)$legacy['purchase_id'],
        ]));
    }

    public function assertEffectiveActive(int $uid, int $storeId = 0, bool $lockPersisted = false): array
    {
        $member = $this->effectiveMembershipAuthority($uid, $lockPersisted);
        if (!$member || ($storeId > 0 && (int)$member['store_id'] !== $storeId)) {
            throw new ApiException('permanent_membership_required');
        }
        return $member;
    }

    public function assertPersistedActive(int $uid, int $storeId = 0, bool $lock = false): array
    {
        $query = $this->dao->search([])->where('uid', $uid)->where('status', 'active');
        if ($lock) {
            $query = $query->lock(true);
        }
        $member = $this->row($query->find());
        if ($member) {
            $member = $this->applyCurrentBindingStore($member);
        }
        if (!$member || ($storeId > 0 && (int)$member['store_id'] !== $storeId)) {
            throw new ApiException('permanent_membership_required');
        }
        return $member;
    }

    private function applyCurrentBindingStore(array $member): array
    {
        $uid = (int)($member['uid'] ?? 0);
        if ($uid <= 0) {
            return $member;
        }
        $current = $this->row($this->attributionDao->getOne(['uid' => $uid]));
        if (!$current || ((string)$current['status'] === 'unassigned' && (int)$current['authority_version'] === 0)) {
            return $member;
        }
        $member['membership_origin_store_id'] = (int)($member['store_id'] ?? 0);
        $member['store_id'] = in_array((string)$current['status'], ['active', 'paused'], true)
            ? (int)$current['store_id'] : 0;
        $member['binding_status'] = (int)$member['store_id'] > 0 ? 'bound' : 'unbound';
        return $member;
    }

    public function grantFromPackageInTransaction(
        array $purchase,
        array $snapshot,
        int $instanceId,
        string $sourceType,
        string $requestId
    ): array {
        $uid = (int)$purchase['uid'];
        $storeId = (int)$purchase['store_id'];
        $existing = $this->row($this->dao->search([])->where('uid', $uid)->lock(true)->find());
        if ($existing) {
            if ((string)$existing['status'] !== 'active' || (int)$existing['store_id'] !== $storeId) {
                throw new ApiException('permanent_membership_authority_conflict');
            }
            $this->syncCustomerProjection($existing, $uid, 'customer', $sourceType, $requestId);
            return ['member' => $existing, 'created' => false];
        }

        $amountCent = $this->moneyToCents($snapshot['order_pay_price'] ?? $purchase['order_pay_price'] ?? '0.00');
        if ($amountCent <= 0 || $instanceId <= 0) {
            throw new ApiException('permanent_membership_package_snapshot_invalid');
        }
        $now = time();
        try {
            $member = $this->dao->save([
                'membership_no' => $this->makeNo('YFPM'),
                'uid' => $uid,
                'store_id' => $storeId,
                'source_package_instance_id' => $instanceId,
                'source_purchase_id' => (int)$purchase['id'],
                'source_rule_version_id' => (int)$purchase['rule_version_id'],
                'actual_paid_amount_cent' => $amountCent,
                'currency' => (string)($snapshot['currency'] ?? 'CNY'),
                'status' => 'active',
                'authority_version' => 1,
                'source_type' => $sourceType,
                'activated_at' => (int)($snapshot['paid_time'] ?? 0) ?: $now,
                'request_id' => substr($requestId, 0, 64),
                'add_time' => $now,
                'update_time' => $now,
            ])->toArray();
        } catch (\Throwable $e) {
            if ($this->isUniqueConflict($e)) {
                throw new ApiException('permanent_membership_unique_conflict');
            }
            throw $e;
        }
        $sourceUniqueKey = hash('sha256', 'membership|' . $sourceType . '|package_instance|' . $instanceId);
        $this->eventDao->save([
            'event_no' => $this->makeNo('YFPME'),
            'membership_id' => (int)$member['id'],
            'uid' => $uid,
            'store_id' => $storeId,
            'authority_version' => 1,
            'event_type' => 'membership_activated',
            'source_type' => $sourceType,
            'source_id' => (string)$instanceId,
            'source_unique_key' => $sourceUniqueKey,
            'actual_paid_amount_cent' => $amountCent,
            'operator_uid' => $uid,
            'operator_role_code' => 'customer',
            'request_id' => substr($requestId, 0, 64),
            'add_time' => $now,
        ]);
        $this->audit->recordSafely(
            self::DOMAIN,
            'permanent_membership',
            (string)$member['id'],
            'membership_activated',
            [],
            $this->userDto($member),
            $uid,
            'customer',
            $storeId,
            $sourceType,
            $requestId
        );
        $this->syncCustomerProjection($member, $uid, 'customer', $sourceType, $requestId);
        return ['member' => $member, 'created' => true];
    }

    public function grantByHeadquarters(
        int $uid,
        int $storeId,
        int $operatorUid,
        string $reason,
        string $requestId
    ): array {
        $reason = trim($reason);
        $requestId = trim($requestId);
        if ($uid <= 0 || $storeId <= 0 || $operatorUid <= 0 || $reason === '' || $requestId === '') {
            throw new ApiException('headquarters_membership_grant_invalid');
        }

        return Db::transaction(function () use ($uid, $storeId, $operatorUid, $reason, $requestId) {
            $sourceType = 'headquarters_membership_grant';
            $source = HqAuthoritySource::fromTrusted($sourceType, $uid);
            $mutation = new HqAuthorityMutation(
                $source,
                $operatorUid,
                'headquarters_admin',
                $reason,
                $requestId,
                'headquarters_membership_grant:' . $uid
            );
            $lockedCurrents = $this->attribution->lockCurrents([$uid]);
            $attribution = $this->attribution->assignFirstWithLockedCurrentsInTransaction(
                $uid,
                $storeId,
                $mutation,
                $lockedCurrents
            );
            $existing = $this->row($this->dao->search([])->where('uid', $uid)->lock(true)->find());
            if ($existing) {
                if ((string)$existing['status'] !== 'active' || (int)$existing['store_id'] !== $storeId) {
                    throw new ApiException('permanent_membership_authority_conflict');
                }
                $projection = $this->syncCustomerProjection(
                    $existing,
                    $operatorUid,
                    'headquarters_admin',
                    $reason,
                    $requestId
                );
                return [
                    'member' => $this->userDto($existing),
                    'created' => false,
                    'idempotent' => true,
                    'attribution' => $attribution['after'],
                    'customer_projection' => $projection,
                ];
            }

            $now = time();
            try {
                $member = $this->dao->save([
                    'membership_no' => $this->makeNo('YFPM'),
                    'uid' => $uid,
                    'store_id' => $storeId,
                    'source_package_instance_id' => null,
                    'source_purchase_id' => 0,
                    'source_rule_version_id' => 0,
                    'actual_paid_amount_cent' => 0,
                    'currency' => 'CNY',
                    'status' => 'active',
                    'authority_version' => 1,
                    'source_type' => $sourceType,
                    'activated_at' => $now,
                    'request_id' => substr($requestId, 0, 64),
                    'add_time' => $now,
                    'update_time' => $now,
                ])->toArray();
            } catch (\Throwable $e) {
                if ($this->isUniqueConflict($e)) {
                    throw new ApiException('permanent_membership_unique_conflict');
                }
                throw $e;
            }
            $this->eventDao->save([
                'event_no' => $this->makeNo('YFPME'),
                'membership_id' => (int)$member['id'],
                'uid' => $uid,
                'store_id' => $storeId,
                'authority_version' => 1,
                'event_type' => 'membership_granted_by_headquarters',
                'source_type' => $sourceType,
                'source_id' => (string)$uid,
                'source_unique_key' => hash('sha256', 'membership|' . $sourceType . '|uid|' . $uid),
                'actual_paid_amount_cent' => 0,
                'operator_uid' => $operatorUid,
                'operator_role_code' => 'headquarters_admin',
                'request_id' => substr($requestId, 0, 64),
                'add_time' => $now,
            ]);
            $this->audit->recordSafely(
                self::DOMAIN,
                'permanent_membership',
                (string)$member['id'],
                'membership_granted_by_headquarters',
                [],
                $this->userDto($member),
                $operatorUid,
                'headquarters_admin',
                $storeId,
                $reason,
                $requestId
            );
            $projection = $this->syncCustomerProjection(
                $member,
                $operatorUid,
                'headquarters_admin',
                $reason,
                $requestId
            );
            return [
                'member' => $this->userDto($member),
                'created' => true,
                'idempotent' => false,
                'attribution' => $attribution['after'],
                'customer_projection' => $projection,
            ];
        });
    }

    public function adminList(array $where): array
    {
        return $this->pageList($this->cleanWhere([
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
        ]), '*', 'id desc', function ($row) {
            return $this->adminDto($row);
        });
    }

    public function storeList(int $storeId, array $where): array
    {
        return $this->pageList($this->cleanWhere([
            'store_id' => $storeId,
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
        ]), '*', 'id desc', function ($row) {
            return $this->storeDto($row);
        });
    }

    public function legacyBackfill(bool $execute, int $limit, int $operatorUid, string $reason, string $requestId): array
    {
        $limit = max(1, min(200, $limit));
        if ($execute && trim($reason) === '') {
            throw new ApiException('legacy_membership_backfill_reason_required');
        }
        $rows = $this->legacyPackageFacts($limit);
        $result = ['mode' => $execute ? 'execute' : 'dry_run', 'eligible' => count($rows), 'created' => 0, 'existing' => 0, 'failed' => 0, 'items' => []];
        foreach ($rows as $row) {
            if (!$execute) {
                $result['items'][] = [
                    'uid' => (int)$row['uid'],
                    'store_id' => (int)$row['store_id'],
                    'package_instance_id' => (int)$row['instance_id'],
                ];
                continue;
            }
            try {
                $item = Db::transaction(function () use ($row, $operatorUid, $reason, $requestId) {
                    $uid = (int)$row['uid'];
                    $storeId = (int)$row['store_id'];
                    $source = HqAuthoritySource::fromTrusted('historical_package_activation', (int)$row['instance_id']);
                    $mutation = new HqAuthorityMutation($source, $operatorUid, 'admin', $reason, $requestId . ':' . $row['instance_id'], 'legacy_membership:' . $row['instance_id']);
                    $locked = $this->attribution->lockCurrents([$uid]);
                    $this->attribution->assignFirstWithLockedCurrentsInTransaction($uid, $storeId, $mutation, $locked);
                    return $this->grantFromPackageInTransaction([
                        'id' => (int)$row['purchase_id'],
                        'uid' => $uid,
                        'store_id' => $storeId,
                        'rule_version_id' => (int)$row['rule_version_id'],
                        'order_pay_price' => (string)$row['order_pay_price'],
                    ], [
                        'order_pay_price' => (string)$row['order_pay_price'],
                        'currency' => 'CNY',
                        'paid_time' => (int)$row['activated_at'],
                    ], (int)$row['instance_id'], 'historical_package_activation', $requestId . ':' . $row['instance_id']);
                });
                if ($item['created']) {
                    $result['created']++;
                } else {
                    $result['existing']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['items'][] = [
                    'uid' => (int)$row['uid'],
                    'package_instance_id' => (int)$row['instance_id'],
                    'error' => substr($e->getMessage(), 0, 120),
                ];
            }
        }
        $this->audit->recordSafely(self::DOMAIN, 'legacy_package_membership_backfill', $requestId, $execute ? 'execute' : 'dry_run', [], $result, $operatorUid, 'admin', 0, $reason, $requestId);
        return $result;
    }

    private function legacyPackageFacts(int $limit): array
    {
        return Db::name('yfth_package_instance')->alias('i')
            ->join('yfth_package_purchase p', 'p.id=i.purchase_id AND p.instance_id=i.id')
            ->join('store_order o', 'o.id=p.order_id AND o.id=i.order_id')
            ->leftJoin('yfth_package_purchase_snapshot s', 's.purchase_id=p.id')
            ->leftJoin('yfth_permanent_membership m', 'm.uid=i.uid')
            ->where('p.activation_status', 'succeeded')
            ->where('o.paid', 1)
            ->where('i.activated_time', '>', 0)
            ->whereRaw('(s.id IS NULL OR s.grants_permanent_membership IS NULL OR s.grants_permanent_membership=1)')
            ->whereNull('m.id')
            ->field('i.id instance_id,i.uid,i.store_id,i.purchase_id,i.rule_version_id,i.activated_time activated_at,p.order_pay_price')
            ->order('i.id asc')->limit($limit)->select()->toArray();
    }

    private function legacyPackageFactForUid(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        $row = Db::name('yfth_package_instance')->alias('i')
            ->join('yfth_package_purchase p', 'p.id=i.purchase_id AND p.instance_id=i.id')
            ->join('store_order o', 'o.id=p.order_id AND o.id=i.order_id')
            ->leftJoin('yfth_package_purchase_snapshot s', 's.purchase_id=p.id')
            ->where('i.uid', $uid)
            ->where('p.activation_status', 'succeeded')
            ->where('o.paid', 1)
            ->where('i.activated_time', '>', 0)
            ->whereRaw('(s.id IS NULL OR s.grants_permanent_membership IS NULL OR s.grants_permanent_membership=1)')
            ->field('i.id instance_id,i.uid,i.store_id,i.purchase_id,i.rule_version_id,i.activated_time activated_at,p.order_pay_price')
            ->order('i.id asc')->find();
        return $this->row($row);
    }

    private function userDto(array $row): array
    {
        return [
            'membership_no' => (string)$row['membership_no'],
            'store_id' => (int)$row['store_id'],
            'binding_status' => (string)($row['binding_status'] ?? ((int)$row['store_id'] > 0 ? 'bound' : 'unbound')),
            'status' => (string)$row['status'],
            'activated_at' => (int)$row['activated_at'],
            'actual_paid_amount_cent' => (int)$row['actual_paid_amount_cent'],
            'currency' => (string)$row['currency'],
            'source' => (string)($row['source_type'] ?? 'package_activation'),
        ];
    }

    private function storeDto(array $row): array
    {
        return [
            'membership_no' => (string)$row['membership_no'],
            'uid' => (int)$row['uid'],
            'store_id' => (int)$row['store_id'],
            'status' => (string)$row['status'],
            'activated_at' => (int)$row['activated_at'],
        ];
    }

    private function adminDto(array $row): array
    {
        return array_merge($this->storeDto($row), [
            'source_package_instance_id' => (int)$row['source_package_instance_id'],
            'source_purchase_id' => (int)$row['source_purchase_id'],
            'source_rule_version_id' => (int)$row['source_rule_version_id'],
            'actual_paid_amount_cent' => (int)$row['actual_paid_amount_cent'],
            'currency' => (string)$row['currency'],
        ]);
    }

    private function moneyToCents($value): int
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new ApiException('money_snapshot_invalid');
        }
        return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
    }

    private function syncCustomerProjection(
        array $member,
        int $operatorUid,
        string $operatorRole,
        string $reason,
        string $requestId
    ): array {
        return app()->make(FranchiseCustomerServices::class)->syncAuthorityCustomerInTransaction(
            (int)$member['uid'],
            (int)$member['store_id'],
            'permanent_membership',
            (int)$member['id'],
            0,
            $operatorUid,
            $operatorRole,
            $reason,
            $requestId
        );
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }

    private function isUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000';
    }

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
