<?php

namespace app\services\yfth;

use app\dao\yfth\YfthStoreSubjectDao;
use crmeb\exceptions\AdminException;

class StoreSubjectServices extends YfthFoundationBaseServices
{
    public function __construct(YfthStoreSubjectDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'subject_id' => (int)($where['subject_id'] ?? 0) ?: '',
            'subject_role' => $where['subject_role'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            $row['store_type_name'] = YfthConstants::storeTypes()[$row['store_type']] ?? $row['store_type'];
            $row['subject_role_name'] = YfthConstants::subjectRoles()[$row['subject_role']] ?? $row['subject_role'];
            return $row;
        });
    }

    public function saveStoreSubject(array $data, int $operatorUid = 0)
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id']);

        $data = $this->normalizeStoreSubject($data, $id);
        return $this->transaction(function () use ($id, $data, $before, $operatorUid) {
            $this->assertStoreSubject($data, $id);
            $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
            $objectId = $id ?: (int)$result->id;
            $after = $id ? ($this->dao->get($id)->toArray()) : array_merge($data, ['id' => $objectId]);
            $this->recordAuditSafely((string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, (int)$data['store_id']);
            return $result;
        });
    }

    public function disableStoreSubject(int $id, int $operatorUid = 0)
    {
        $row = $this->dao->get($id);
        if (!$row) {
            throw new AdminException('store_subject_not_found');
        }
        $before = $row->toArray();
        $data = [
            'status' => YfthConstants::STATUS_DISABLED,
            'active_key' => null,
            'update_time' => time(),
        ];
        $result = $this->dao->update($id, $data);
        $after = $this->dao->get($id)->toArray();
        $this->recordAuditSafely((string)$id, 'disable', $before, $after, $operatorUid, (int)$before['store_id']);
        return $result;
    }

    public function listActiveByStore(int $storeId): array
    {
        return $this->activeSubjectQuery($storeId)->order('id desc')->select()->toArray();
    }

    public function activeStoreType(int $storeId): string
    {
        $row = $this->activeSubjectQuery($storeId)->where('subject_role', 'host')->order('id desc')->find();
        if (!$row) {
            $row = $this->activeSubjectQuery($storeId)->order('id desc')->find();
        }
        return $row ? (string)$row['store_type'] : '';
    }

    public function contextStatus(int $storeId): string
    {
        return $this->activeSubjectQuery($storeId)->count() > 0 ? 'active' : 'missing_subject';
    }

    private function normalizeStoreSubject(array $data, int $id): array
    {
        $data['store_id'] = (int)($data['store_id'] ?? 0);
        $data['subject_id'] = (int)($data['subject_id'] ?? 0);
        $data['store_type'] = trim((string)($data['store_type'] ?? ''));
        $data['subject_role'] = trim((string)($data['subject_role'] ?? 'sales'));
        $data['is_sales_subject'] = (int)!empty($data['is_sales_subject']);
        $data['is_service_subject'] = (int)!empty($data['is_service_subject']);
        $data['is_payment_subject'] = (int)!empty($data['is_payment_subject']);
        $data['is_fulfillment_subject'] = (int)!empty($data['is_fulfillment_subject']);
        $data['is_invoice_subject'] = (int)!empty($data['is_invoice_subject']);
        $data['is_refund_subject'] = (int)!empty($data['is_refund_subject']);
        $data['is_host_subject'] = (int)!empty($data['is_host_subject']);
        $data['status'] = $data['status'] ?? YfthConstants::STATUS_ACTIVE;
        $data['effective_time'] = $this->parseTime($data['effective_time'] ?? 0);
        $data['expire_time'] = $this->parseTime($data['expire_time'] ?? 0);
        if ($data['status'] === YfthConstants::STATUS_ACTIVE && $data['expire_time'] > 0 && $data['expire_time'] <= time()) {
            $data['status'] = YfthConstants::STATUS_EXPIRED;
        }
        $data['active_key'] = $this->activeKey([$data['store_id'], $data['subject_role']], $data['status']);
        return $this->withTimestamps($data, $id === 0);
    }

    private function assertStoreSubject(array $data, int $id): void
    {
        if ($data['store_id'] <= 0 || $data['subject_id'] <= 0) {
            throw new AdminException('store_id_and_subject_id_are_required');
        }
        if (!isset(YfthConstants::storeTypes()[$data['store_type']])) {
            throw new AdminException('invalid_store_type');
        }
        if (!isset(YfthConstants::subjectRoles()[$data['subject_role']])) {
            throw new AdminException('invalid_subject_role');
        }

        /** @var StoreAccessServices $storeAccessServices */
        $storeAccessServices = app()->make(StoreAccessServices::class);
        $storeAccessServices->assertStoreActive($data['store_id']);

        /** @var BusinessSubjectServices $subjectServices */
        $subjectServices = app()->make(BusinessSubjectServices::class);
        if (!$subjectServices->get($data['subject_id'])) {
            throw new AdminException('business_subject_not_found');
        }

        if ($data['active_key']) {
            $existing = $this->dao->getOne(['active_key' => $data['active_key']]);
            if ($existing && (int)$existing['id'] !== $id) {
                throw new AdminException('active_store_subject_role_exists');
            }
            $active = $this->activeSubjectQuery($data['store_id'])
                ->where('subject_role', $data['subject_role'])
                ->where('id', '<>', $id)
                ->find();
            if ($active) {
                throw new AdminException('active_store_subject_role_exists');
            }
        }
    }

    private function activeSubjectQuery(int $storeId)
    {
        $query = $this->dao->search([])
            ->where('store_id', $storeId)
            ->where('status', YfthConstants::STATUS_ACTIVE);
        return $this->applyActiveWindow($query);
    }

    private function recordAuditSafely(string $objectId, string $action, array $before, array $after, int $operatorUid, int $storeId): void
    {
        /** @var AuditEventServices $audit */
        $audit = app()->make(AuditEventServices::class);
        $audit->recordSafely('yfth_foundation', 'store_subject', $objectId, $action, $before, $after, $operatorUid, 'admin', $storeId);
    }
}
