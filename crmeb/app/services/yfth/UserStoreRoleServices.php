<?php

namespace app\services\yfth;

use app\dao\yfth\YfthUserStoreRoleDao;
use crmeb\exceptions\ApiException;

class UserStoreRoleServices extends YfthFoundationBaseServices
{
    public function __construct(YfthUserStoreRoleDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'role_code' => $where['role_code'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            $row['role_name'] = YfthConstants::roles()[$row['role_code']] ?? $row['role_code'];
            $row['permission_scope'] = $this->jsonDecode($row['permission_scope'] ?? '');
            return $row;
        });
    }

    public function listActiveByUid(int $uid): array
    {
        return $this->activeRoleQuery($uid)->select()->toArray();
    }

    public function getActiveRole(int $uid, int $storeId, string $roleCode = '')
    {
        $query = $this->activeRoleQuery($uid)
            ->where('store_id', $storeId);
        if ($roleCode !== '') {
            $query->where('role_code', $roleCode);
        }
        return $query->find();
    }

    public function assertStoreRole(int $uid, int $storeId, string $roleCode = ''): array
    {
        if ($storeId <= 0) {
            throw new ApiException('门店身份必须指定门店');
        }
        $role = $this->getActiveRole($uid, $storeId, $roleCode);
        if (!$role) {
            throw new ApiException('当前用户无权访问该门店');
        }
        return is_array($role) ? $role : $role->toArray();
    }

    public function saveRole(array $data)
    {
        $id = (int)($data['id'] ?? 0);
        unset($data['id']);
        $data['uid'] = (int)($data['uid'] ?? 0);
        $data['store_id'] = (int)($data['store_id'] ?? 0);
        $data['role_code'] = trim((string)($data['role_code'] ?? ''));
        $data['status'] = $data['status'] ?? YfthConstants::STATUS_ACTIVE;
        $data['permission_scope'] = $this->jsonEncode($data['permission_scope'] ?? '');
        $data['start_time'] = $this->parseTime($data['start_time'] ?? 0);
        $data['end_time'] = $this->parseTime($data['end_time'] ?? 0);
        $data['inviter_uid'] = (int)($data['inviter_uid'] ?? 0);
        $data['creator_uid'] = (int)($data['creator_uid'] ?? 0);
        $data['active_key'] = $this->activeKey([$data['uid'], $data['store_id'], $data['role_code']], $data['status']);
        $data = $this->withTimestamps($data, $id === 0);
        return $id ? $this->dao->update($id, $data) : $this->dao->save($data);
    }

    private function activeRoleQuery(int $uid)
    {
        $query = $this->dao->search([])
            ->where('uid', $uid)
            ->where('status', YfthConstants::STATUS_ACTIVE);
        return $this->applyActiveWindow($query, 'start_time', 'end_time');
    }
}
