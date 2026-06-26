<?php

namespace app\services\yfth;

use app\dao\yfth\YfthServiceProjectDao;
use app\dao\yfth\YfthStoreServiceDao;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;

class StoreServiceAppointmentServices extends ServiceAppointmentBaseServices
{
    public function __construct(YfthStoreServiceDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where, array $adminInfo = []): array
    {
        $where = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'service_project_id' => (int)($where['service_project_id'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
        ]);
        $where = $this->applyAdminStoreFilter($where, $adminInfo);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->formatStoreServiceRow($row);
        });
    }

    public function saveStoreService(array $data, int $operatorUid = 0, array $adminInfo = [])
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeStoreService($data, $id, $before ? $before->toArray() : [], $operatorUid, $adminInfo);
        return $this->transaction(function () use ($id, $data, $before, $operatorUid) {
            $this->assertOneActiveBinding($data, $id);
            $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
            $objectId = $id ?: (int)$result->id;
            $after = $id ? $this->dao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
            $this->recordServiceAudit('store_service', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin', (int)$data['store_id'], (string)($data['close_reason'] ?? ''));
            return $result;
        });
    }

    public function disableStoreService(int $storeServiceId, string $reason, int $operatorUid = 0, array $adminInfo = []): void
    {
        $before = $this->requireRow($this->dao->get($storeServiceId), 'store_service_not_found');
        $this->assertStoreConfigScope($adminInfo, (int)$before['store_id']);
        if ((string)$before['status'] === YfthConstants::STATUS_DISABLED) {
            return;
        }
        $data = [
            'status' => YfthConstants::STATUS_DISABLED,
            'appointment_enabled' => 0,
            'active_key' => null,
            'disabled_uid' => $operatorUid,
            'disabled_time' => time(),
            'updated_uid' => $operatorUid,
            'close_reason' => trim($reason) ?: 'admin_disabled',
            'update_time' => time(),
        ];
        $this->dao->update($storeServiceId, $data);
        $after = $this->dao->get($storeServiceId)->toArray();
        $this->recordServiceAudit('store_service', (string)$storeServiceId, 'disable', $before, $after, $operatorUid, 'admin', (int)$before['store_id'], $data['close_reason']);
    }

    public function activeBinding(int $storeId, int $projectId): array
    {
        $binding = $this->dao->getOne([
            'store_id' => $storeId,
            'service_project_id' => $projectId,
            'status' => YfthConstants::STATUS_ACTIVE,
            'appointment_enabled' => 1,
        ]);
        return $this->formatStoreServiceRow($this->requireRow($binding, 'store_service_not_available'));
    }

    public function bindingById(int $storeServiceId, bool $requireActive = false): array
    {
        $binding = $this->formatStoreServiceRow($this->requireRow($this->dao->get($storeServiceId), 'store_service_not_found'));
        if ($requireActive && ((string)$binding['status'] !== YfthConstants::STATUS_ACTIVE || (int)$binding['appointment_enabled'] !== 1)) {
            throw new ApiException('store_service_not_available');
        }
        return $binding;
    }

    public function activeBindingsForProject(int $projectId): array
    {
        $rows = $this->dao->selectList([
            'service_project_id' => $projectId,
            'status' => YfthConstants::STATUS_ACTIVE,
            'appointment_enabled' => 1,
        ], '*', 0, 0, 'id desc', [], false)->toArray();
        return array_map(function ($row) {
            return $this->formatStoreServiceRow($row);
        }, $rows);
    }

    private function normalizeStoreService(array $data, int $id, array $before, int $operatorUid, array $adminInfo): array
    {
        $data['store_id'] = (int)($data['store_id'] ?? ($before['store_id'] ?? 0));
        $data['service_project_id'] = (int)($data['service_project_id'] ?? ($before['service_project_id'] ?? 0));
        if ($data['store_id'] <= 0 || $data['service_project_id'] <= 0) {
            throw new AdminException('store_and_service_project_required');
        }
        $this->assertStoreConfigScope($adminInfo, $data['store_id']);
        /** @var StoreAccessServices $storeAccess */
        $storeAccess = app()->make(StoreAccessServices::class);
        $storeAccess->assertStoreActive($data['store_id']);
        /** @var YfthServiceProjectDao $projectDao */
        $projectDao = app()->make(YfthServiceProjectDao::class);
        $project = $this->requireRow($projectDao->get($data['service_project_id']), 'service_project_not_found');

        $data['status'] = $this->normalizeStatus((string)($data['status'] ?? ($before['status'] ?? YfthConstants::STATUS_ACTIVE)));
        if ($data['status'] === YfthConstants::STATUS_ACTIVE && (string)$project['status'] !== YfthConstants::STATUS_ACTIVE) {
            throw new AdminException('active_store_service_requires_active_project');
        }
        if ($data['status'] === YfthConstants::STATUS_ACTIVE) {
            /** @var StoreCapabilityServices $capabilityServices */
            $capabilityServices = app()->make(StoreCapabilityServices::class);
            if (!$capabilityServices->isAvailable($data['store_id'], 'reservation_service')) {
                throw new AdminException('store_reservation_capability_required');
            }
        }

        $data['service_alias'] = trim((string)($data['service_alias'] ?? ''));
        $data['service_description'] = (string)($data['service_description'] ?? '');
        $data['duration_minutes'] = $this->boundedInt($data['duration_minutes'] ?? ($project['suggested_duration_minutes'] ?? 30), 5, 480, 'invalid_service_duration');
        $data['requires_confirmation'] = $this->normalizeBool($data['requires_confirmation'] ?? 0);
        $data['appointment_enabled'] = $this->normalizeBool($data['appointment_enabled'] ?? 1);
        $data['advance_min_minutes'] = $this->boundedInt($data['advance_min_minutes'] ?? 120, 0, 43200, 'invalid_advance_min_minutes');
        $data['advance_max_days'] = $this->boundedInt($data['advance_max_days'] ?? 30, 1, 180, 'invalid_advance_max_days');
        $data['cancel_deadline_minutes'] = $this->boundedInt($data['cancel_deadline_minutes'] ?? 1440, 0, 43200, 'invalid_cancel_deadline_minutes');
        $data['default_capacity'] = $this->boundedInt($data['default_capacity'] ?? 1, 1, 999, 'invalid_default_capacity');
        $data['timezone'] = $this->normalizeTimezone((string)($data['timezone'] ?? self::DEFAULT_TIMEZONE));
        $data['active_key'] = $this->activeKey([$data['store_id'], $data['service_project_id']], $data['status']);
        $data['updated_uid'] = $operatorUid;
        if ($id === 0) {
            $data['created_uid'] = $operatorUid;
        }
        if ($data['status'] === YfthConstants::STATUS_ACTIVE) {
            $data['disabled_uid'] = 0;
            $data['disabled_time'] = 0;
            $data['close_reason'] = '';
        }
        return $this->withTimestamps($data, $id === 0);
    }

    private function assertOneActiveBinding(array $data, int $id): void
    {
        if ((string)$data['status'] !== YfthConstants::STATUS_ACTIVE) {
            return;
        }
        $query = $this->dao->search([])
            ->where('store_id', (int)$data['store_id'])
            ->where('service_project_id', (int)$data['service_project_id'])
            ->where('status', YfthConstants::STATUS_ACTIVE);
        if ($id > 0) {
            $query->where('id', '<>', $id);
        }
        if ($query->count() > 0) {
            throw new AdminException('active_store_service_already_exists');
        }
    }

    private function applyAdminStoreFilter(array $where, array $adminInfo): array
    {
        $storeIds = $this->adminStoreIds($adminInfo);
        if (!$storeIds) {
            return $where;
        }
        if (!empty($where['store_id']) && !in_array((int)$where['store_id'], $storeIds, true)) {
            throw new AdminException('store_scope_forbidden');
        }
        if (empty($where['store_id'])) {
            $where['store_id'] = $storeIds;
        }
        return $where;
    }

    public function formatStoreServiceRow(array $row): array
    {
        $row['requires_confirmation'] = (int)($row['requires_confirmation'] ?? 0);
        $row['appointment_enabled'] = (int)($row['appointment_enabled'] ?? 0);
        /** @var YfthServiceProjectDao $projectDao */
        $projectDao = app()->make(YfthServiceProjectDao::class);
        $project = !empty($row['service_project_id']) ? $projectDao->get((int)$row['service_project_id']) : null;
        if ($project) {
            $project = $project->toArray();
            $row['service_code'] = (string)$project['service_code'];
            $row['service_name'] = (string)$project['service_name'];
            $row['project_status'] = (string)$project['status'];
        }
        return $row;
    }
}
