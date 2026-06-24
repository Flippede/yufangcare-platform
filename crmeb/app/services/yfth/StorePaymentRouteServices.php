<?php

namespace app\services\yfth;

use app\dao\yfth\YfthStorePaymentRouteDao;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;

class StorePaymentRouteServices extends YfthFoundationBaseServices
{
    public function __construct(YfthStorePaymentRouteDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'business_scene' => $where['business_scene'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->sanitizeRoute($row);
        });
    }

    public function resolveRoute(int $storeId, string $scene): array
    {
        $routes = $this->activeRouteQuery($storeId, $scene)
            ->order('priority desc, version_no desc, id desc')
            ->limit(2)
            ->select()
            ->toArray();
        if (!$routes) {
            throw new ApiException('payment_route_not_found');
        }
        if (count($routes) > 1) {
            throw new ApiException('payment_route_conflict');
        }
        return $this->sanitizeRoute($routes[0]);
    }

    public function saveRoute(array $data, int $operatorUid = 0)
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id'], $data['secret'], $data['private_key'], $data['api_key'], $data['cert'], $data['certificate']);

        $data = $this->normalizeRoute($data, $id);
        if ($id && $before) {
            if ($data['merchant_ref'] === '') {
                $data['merchant_ref'] = (string)$before['merchant_ref'];
            }
            if ($data['sub_merchant_ref'] === '') {
                $data['sub_merchant_ref'] = (string)$before['sub_merchant_ref'];
            }
        }
        return $this->transaction(function () use ($id, $data, $before, $operatorUid) {
            $this->assertRoute($data, $id);
            $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
            $objectId = $id ?: (int)$result->id;
            $after = $id ? ($this->dao->get($id)->toArray()) : array_merge($data, ['id' => $objectId]);
            $this->recordAuditSafely((string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, (int)$data['store_id']);
            return $result;
        });
    }

    public function disableRoute(int $id, int $operatorUid = 0)
    {
        $row = $this->dao->get($id);
        if (!$row) {
            throw new AdminException('payment_route_not_found');
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

    private function normalizeRoute(array $data, int $id): array
    {
        foreach (['store_id', 'subject_id', 'receiver_subject_id', 'invoice_subject_id', 'refund_subject_id'] as $field) {
            $data[$field] = (int)($data[$field] ?? 0);
        }
        foreach (['business_scene', 'route_type', 'merchant_ref', 'sub_merchant_ref', 'status', 'config_status'] as $field) {
            $data[$field] = trim((string)($data[$field] ?? ''));
        }
        $data['status'] = $data['status'] ?: YfthConstants::STATUS_ACTIVE;
        $data['config_status'] = $data['config_status'] ?: 'metadata_only';
        $data['version_no'] = (int)($data['version_no'] ?? 0);
        if ($data['version_no'] <= 0) {
            $data['version_no'] = $this->nextVersionNo($data['store_id'], $data['business_scene']);
        }
        $data['priority'] = (int)($data['priority'] ?? 0);
        $data['effective_time'] = $this->parseTime($data['effective_time'] ?? 0);
        $data['expire_time'] = $this->parseTime($data['expire_time'] ?? 0);
        if ($data['status'] === YfthConstants::STATUS_ACTIVE && $data['expire_time'] > 0 && $data['expire_time'] <= time()) {
            $data['status'] = YfthConstants::STATUS_EXPIRED;
        }
        $data['active_key'] = $this->activeKey([$data['store_id'], $data['business_scene']], $data['status']);
        return $this->withTimestamps($data, $id === 0);
    }

    private function assertRoute(array $data, int $id): void
    {
        if ($data['store_id'] <= 0 || $data['business_scene'] === '' || $data['route_type'] === '') {
            throw new AdminException('store_scene_and_route_type_are_required');
        }
        if (!isset(YfthConstants::paymentScenes()[$data['business_scene']])) {
            throw new AdminException('invalid_payment_scene');
        }

        /** @var StoreAccessServices $storeAccessServices */
        $storeAccessServices = app()->make(StoreAccessServices::class);
        $storeAccessServices->assertStoreActive($data['store_id']);

        if ($data['active_key']) {
            $existing = $this->dao->getOne(['active_key' => $data['active_key']]);
            if ($existing && (int)$existing['id'] !== $id) {
                throw new AdminException('active_payment_route_exists');
            }
            $active = $this->activeRouteQuery($data['store_id'], $data['business_scene'])
                ->where('id', '<>', $id)
                ->find();
            if ($active) {
                throw new AdminException('active_payment_route_exists');
            }
        }
    }

    private function activeRouteQuery(int $storeId, string $scene)
    {
        $query = $this->dao->search([])
            ->where('store_id', $storeId)
            ->where('business_scene', $scene)
            ->where('status', YfthConstants::STATUS_ACTIVE);
        return $this->applyActiveWindow($query);
    }

    private function nextVersionNo(int $storeId, string $scene): int
    {
        $max = (int)$this->dao->search([])
            ->where('store_id', $storeId)
            ->where('business_scene', $scene)
            ->max('version_no');
        return $max + 1;
    }

    private function sanitizeRoute(array $row): array
    {
        $row['business_scene_name'] = YfthConstants::paymentScenes()[$row['business_scene']] ?? $row['business_scene'];
        $row['merchant_ref_masked'] = $this->maskRef((string)($row['merchant_ref'] ?? ''));
        $row['sub_merchant_ref_masked'] = $this->maskRef((string)($row['sub_merchant_ref'] ?? ''));
        $row['merchant_ref'] = '';
        $row['sub_merchant_ref'] = '';
        $row['snapshot_requirement'] = 'persist_store_scene_subjects_route_version_on_order';
        unset($row['secret'], $row['private_key'], $row['api_key'], $row['cert'], $row['certificate']);
        return $row;
    }

    private function recordAuditSafely(string $objectId, string $action, array $before, array $after, int $operatorUid, int $storeId): void
    {
        /** @var AuditEventServices $audit */
        $audit = app()->make(AuditEventServices::class);
        $audit->recordSafely('yfth_foundation', 'payment_route', $objectId, $action, $before, $after, $operatorUid, 'admin', $storeId);
    }
}
