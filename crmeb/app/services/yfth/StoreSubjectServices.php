<?php

namespace app\services\yfth;

use app\dao\yfth\YfthStoreSubjectDao;

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
            return $row;
        });
    }

    public function saveStoreSubject(array $data)
    {
        $id = (int)($data['id'] ?? 0);
        unset($data['id']);
        $data['store_id'] = (int)($data['store_id'] ?? 0);
        $data['subject_id'] = (int)($data['subject_id'] ?? 0);
        $data['store_type'] = trim((string)($data['store_type'] ?? ''));
        $data['subject_role'] = trim((string)($data['subject_role'] ?? 'sales'));
        $data['is_sales_subject'] = (int)!empty($data['is_sales_subject']);
        $data['is_service_subject'] = (int)!empty($data['is_service_subject']);
        $data['is_invoice_subject'] = (int)!empty($data['is_invoice_subject']);
        $data['status'] = $data['status'] ?? YfthConstants::STATUS_ACTIVE;
        $data['effective_time'] = $this->parseTime($data['effective_time'] ?? 0);
        $data['expire_time'] = $this->parseTime($data['expire_time'] ?? 0);
        $data['active_key'] = $this->activeKey([$data['store_id'], $data['subject_id'], $data['subject_role']], $data['status']);
        $data = $this->withTimestamps($data, $id === 0);
        return $id ? $this->dao->update($id, $data) : $this->dao->save($data);
    }
}
