<?php

namespace app\services\yfth;

use app\dao\yfth\YfthStorePaymentRouteDao;

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
        $query = $this->dao->search([])
            ->where('store_id', $storeId)
            ->where('business_scene', $scene)
            ->where('status', YfthConstants::STATUS_ACTIVE);
        $route = $this->applyActiveWindow($query)->find();
        return $route ? $this->sanitizeRoute($route->toArray()) : [];
    }

    public function saveRoute(array $data)
    {
        $id = (int)($data['id'] ?? 0);
        unset($data['id'], $data['secret'], $data['private_key'], $data['api_key'], $data['cert']);
        foreach (['store_id', 'subject_id', 'receiver_subject_id', 'invoice_subject_id', 'refund_subject_id'] as $field) {
            $data[$field] = (int)($data[$field] ?? 0);
        }
        foreach (['business_scene', 'route_type', 'merchant_ref', 'sub_merchant_ref', 'status', 'config_status'] as $field) {
            $data[$field] = trim((string)($data[$field] ?? ''));
        }
        $data['status'] = $data['status'] ?: YfthConstants::STATUS_ACTIVE;
        $data['config_status'] = $data['config_status'] ?: 'metadata_only';
        $data['effective_time'] = $this->parseTime($data['effective_time'] ?? 0);
        $data['expire_time'] = $this->parseTime($data['expire_time'] ?? 0);
        $data = $this->withTimestamps($data, $id === 0);
        return $id ? $this->dao->update($id, $data) : $this->dao->save($data);
    }

    private function sanitizeRoute(array $row): array
    {
        $row['business_scene_name'] = YfthConstants::paymentScenes()[$row['business_scene']] ?? $row['business_scene'];
        $row['merchant_ref_masked'] = $this->maskRef((string)($row['merchant_ref'] ?? ''));
        $row['sub_merchant_ref_masked'] = $this->maskRef((string)($row['sub_merchant_ref'] ?? ''));
        unset($row['secret'], $row['private_key'], $row['api_key'], $row['cert']);
        return $row;
    }
}
