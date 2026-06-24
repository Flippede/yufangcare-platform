<?php

namespace app\services\yfth;

use app\dao\yfth\YfthStoreCapabilityDao;

class StoreCapabilityServices extends YfthFoundationBaseServices
{
    public function __construct(YfthStoreCapabilityDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'capability_code' => $where['capability_code'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->formatCapabilityRow($row);
        });
    }

    public function listForStore(int $storeId): array
    {
        return $this->dao->selectList(['store_id' => $storeId], '*', 0, 0, 'id desc', [], false)->toArray();
    }

    public function isAvailable(int $storeId, string $capabilityCode): bool
    {
        $record = $this->activeCapabilityQuery($storeId, $capabilityCode)->find();
        if (!$record) {
            return false;
        }
        $row = $record->toArray();
        if (!empty($row['source_qualification_id'])) {
            /** @var StoreQualificationServices $qualificationServices */
            $qualificationServices = app()->make(StoreQualificationServices::class);
            if (!$qualificationServices->isQualificationActive((int)$row['source_qualification_id'])) {
                $this->dao->update((int)$row['id'], [
                    'status' => YfthConstants::STATUS_PAUSED,
                    'active_key' => null,
                    'close_reason' => 'source_qualification_inactive',
                    'update_time' => time(),
                ]);
                return false;
            }
        }
        return true;
    }

    public function activeCodesForStore(int $storeId): array
    {
        $rows = $this->activeCapabilityQuery($storeId, '')->select()->toArray();
        $codes = [];
        foreach ($rows as $row) {
            if (!empty($row['capability_code']) && $this->isAvailable($storeId, (string)$row['capability_code'])) {
                $codes[] = (string)$row['capability_code'];
            }
        }
        return array_values(array_unique($codes));
    }

    public function syncFromQualification(array $qualification): void
    {
        $codes = YfthConstants::qualificationCapabilityMap()[$qualification['qualification_type']] ?? [];
        foreach ($codes as $code) {
            $existing = $this->dao->search([])
                ->where('store_id', (int)$qualification['store_id'])
                ->where('capability_code', $code)
                ->where(function ($query) use ($qualification) {
                    $query->where('status', YfthConstants::STATUS_ACTIVE)
                        ->whereOr('source_qualification_id', (int)$qualification['id']);
                })
                ->order('id desc')
                ->find();
            $data = [
                'store_id' => (int)$qualification['store_id'],
                'capability_code' => $code,
                'source_qualification_id' => (int)$qualification['id'],
                'source_authorization' => $qualification['qualification_type'],
                'status' => YfthConstants::STATUS_ACTIVE,
                'effective_time' => (int)($qualification['start_time'] ?? 0),
                'expire_time' => (int)($qualification['expire_time'] ?? 0),
                'close_reason' => '',
                'active_key' => $this->activeKey([(int)$qualification['store_id'], $code], YfthConstants::STATUS_ACTIVE),
            ];
            $data = $this->withTimestamps($data, !$existing);
            $existing ? $this->dao->update((int)$existing->id, $data) : $this->dao->save($data);
        }
    }

    public function suspendByQualification(int $qualificationId, string $reason): void
    {
        $this->dao->search([])
            ->where('source_qualification_id', $qualificationId)
            ->where('status', YfthConstants::STATUS_ACTIVE)
            ->update([
                'status' => YfthConstants::STATUS_PAUSED,
                'active_key' => null,
                'close_reason' => $reason,
                'update_time' => time(),
            ]);
    }

    private function activeCapabilityQuery(int $storeId, string $capabilityCode)
    {
        $query = $this->dao->search([])
            ->where('store_id', $storeId)
            ->where('status', YfthConstants::STATUS_ACTIVE);
        if ($capabilityCode !== '') {
            $query->where('capability_code', $capabilityCode);
        }
        return $this->applyActiveWindow($query);
    }

    private function formatCapabilityRow(array $row): array
    {
        $row['capability_name'] = YfthConstants::capabilityLabels()[$row['capability_code']] ?? $row['capability_code'];
        $row['available'] = $row['status'] === YfthConstants::STATUS_ACTIVE
            && ((int)$row['effective_time'] === 0 || (int)$row['effective_time'] <= time())
            && ((int)$row['expire_time'] === 0 || (int)$row['expire_time'] > time());
        return $row;
    }
}
