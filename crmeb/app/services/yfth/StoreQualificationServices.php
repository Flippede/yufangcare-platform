<?php

namespace app\services\yfth;

use app\dao\yfth\YfthStoreQualificationDao;
use crmeb\exceptions\AdminException;

class StoreQualificationServices extends YfthFoundationBaseServices
{
    public function __construct(YfthStoreQualificationDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'subject_id' => (int)($where['subject_id'] ?? 0) ?: '',
            'qualification_type' => $where['qualification_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            $row['status_name'] = YfthConstants::qualificationStatus()[$row['status']] ?? $row['status'];
            $row['scope'] = $this->jsonDecode($row['scope'] ?? '');
            return $row;
        });
    }

    public function saveQualification(array $data, int $operatorUid = 0)
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id']);
        $data = [
            'store_id' => (int)($data['store_id'] ?? 0),
            'subject_id' => (int)($data['subject_id'] ?? 0),
            'qualification_type' => trim((string)($data['qualification_type'] ?? '')),
            'certificate_no' => trim((string)($data['certificate_no'] ?? '')),
            'attachment_id' => (int)($data['attachment_id'] ?? 0),
            'scope' => $this->jsonEncode($data['scope'] ?? ''),
            'start_time' => $this->parseTime($data['start_time'] ?? 0),
            'expire_time' => $this->parseTime($data['expire_time'] ?? 0),
            'status' => $data['status'] ?? YfthConstants::STATUS_PENDING,
            'reject_reason' => trim((string)($data['reject_reason'] ?? '')),
        ];
        if ($data['store_id'] <= 0 || $data['subject_id'] <= 0 || $data['qualification_type'] === '') {
            throw new AdminException('门店、主体和资质类型不能为空');
        }
        if ($data['status'] === YfthConstants::STATUS_ACTIVE && $data['expire_time'] > 0 && $data['expire_time'] <= time()) {
            $data['status'] = YfthConstants::STATUS_EXPIRED;
        }
        $data = $this->withTimestamps($data, $id === 0);
        $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
        $objectId = $id ?: (int)$result->id;
        $after = $id ? ($this->dao->get($id)->toArray()) : array_merge($data, ['id' => $objectId]);
        $this->recordAudit((string)$objectId, $id ? 'submit_update' : 'submit_create', $before ? $before->toArray() : [], $after, $operatorUid, $data['store_id']);
        return $result;
    }

    public function auditQualification(int $id, string $status, string $reason, int $operatorUid = 0)
    {
        $qualification = $this->dao->get($id);
        if (!$qualification) {
            throw new AdminException('资质不存在');
        }
        if (!in_array($status, [YfthConstants::STATUS_ACTIVE, YfthConstants::STATUS_REJECTED, YfthConstants::STATUS_PAUSED, YfthConstants::STATUS_EXPIRED], true)) {
            throw new AdminException('不支持的资质审核状态');
        }
        $before = $qualification->toArray();
        if ($status === YfthConstants::STATUS_ACTIVE && (int)$before['expire_time'] > 0 && (int)$before['expire_time'] <= time()) {
            $status = YfthConstants::STATUS_EXPIRED;
            $reason = $reason ?: 'qualification_expired';
        }
        $data = [
            'status' => $status,
            'audit_uid' => $operatorUid,
            'audit_time' => time(),
            'reject_reason' => $reason,
            'update_time' => time(),
        ];
        $this->dao->update($id, $data);
        $after = $this->dao->get($id)->toArray();

        /** @var StoreCapabilityServices $capabilityServices */
        $capabilityServices = app()->make(StoreCapabilityServices::class);
        if ($status === YfthConstants::STATUS_ACTIVE) {
            $capabilityServices->syncFromQualification($after);
        } else {
            $capabilityServices->suspendByQualification($id, $status . ($reason ? ':' . $reason : ''));
        }
        $this->recordAudit((string)$id, 'audit_' . $status, $before, $after, $operatorUid, (int)$after['store_id'], $reason);
        return $after;
    }

    public function isQualificationActive(int $id): bool
    {
        $qualification = $this->dao->get($id);
        if (!$qualification) {
            return false;
        }
        $row = $qualification->toArray();
        if ($row['status'] !== YfthConstants::STATUS_ACTIVE) {
            return false;
        }
        if ((int)$row['expire_time'] > 0 && (int)$row['expire_time'] <= time()) {
            $this->auditQualification($id, YfthConstants::STATUS_EXPIRED, 'qualification_expired', 0);
            return false;
        }
        return true;
    }

    public function contextStatus(int $storeId): string
    {
        $active = $this->dao->search([])
            ->where('store_id', $storeId)
            ->where('status', YfthConstants::STATUS_ACTIVE)
            ->where(function ($query) {
                $query->where('expire_time', '=', 0)->whereOr('expire_time', '>', time());
            })
            ->count();
        return $active > 0 ? 'active' : 'missing_or_inactive';
    }

    private function recordAudit(string $objectId, string $action, array $before, array $after, int $operatorUid, int $storeId, string $reason = ''): void
    {
        /** @var AuditEventServices $audit */
        $audit = app()->make(AuditEventServices::class);
        $audit->recordSafely('yfth_foundation', 'store_qualification', $objectId, $action, $before, $after, $operatorUid, 'admin', $storeId, $reason);
    }
}
