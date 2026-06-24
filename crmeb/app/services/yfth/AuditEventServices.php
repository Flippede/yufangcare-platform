<?php

namespace app\services\yfth;

use app\dao\yfth\YfthAuditEventDao;

class AuditEventServices extends YfthFoundationBaseServices
{
    public function __construct(YfthAuditEventDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'business_domain' => $where['business_domain'] ?? '',
            'object_type' => $where['object_type'] ?? '',
            'object_id' => $where['object_id'] ?? '',
            'operator_uid' => (int)($where['operator_uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            $row['before_state'] = $this->jsonDecode($row['before_state'] ?? '');
            $row['after_state'] = $this->jsonDecode($row['after_state'] ?? '');
            return $row;
        });
    }

    public function record(
        string $domain,
        string $objectType,
        string $objectId,
        string $action,
        array $before = [],
        array $after = [],
        int $operatorUid = 0,
        string $roleCode = '',
        int $storeId = 0,
        string $reason = '',
        string $requestId = ''
    ) {
        $data = [
            'business_domain' => $domain,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'action' => $action,
            'before_state' => $this->jsonEncode($this->sanitizeState($before)),
            'after_state' => $this->jsonEncode($this->sanitizeState($after)),
            'operator_uid' => $operatorUid,
            'role_code' => $roleCode,
            'store_id' => $storeId,
            'request_id' => $requestId,
            'reason' => $reason,
            'ip' => app()->request ? app()->request->ip() : '',
        ];
        $data = $this->withTimestamps($data, true);
        return $this->dao->save($data);
    }
}
