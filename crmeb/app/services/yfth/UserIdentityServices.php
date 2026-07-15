<?php

namespace app\services\yfth;

use app\dao\yfth\YfthUserIdentityDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class UserIdentityServices extends YfthFoundationBaseServices
{
    public function __construct(YfthUserIdentityDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'role_code' => $where['role_code'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->formatIdentityRow($row);
        });
    }

    public function listUserIdentities(int $uid): array
    {
        if ($uid <= 0) {
            throw new ApiException('用户未登录');
        }
        $roles = [];
        $roles[] = [
            'identity_id' => 0,
            'role_code' => 'customer',
            'role_name' => YfthConstants::roles()['customer'],
            'store_id' => 0,
            'status' => YfthConstants::STATUS_ACTIVE,
            'source_type' => 'implicit_user',
        ];

        $identityRows = $this->activeIdentityQuery($uid)->select()->toArray();
        foreach ($identityRows as $row) {
            if ($row['role_code'] === 'customer') {
                continue;
            }
            $roles[] = $this->formatIdentityRow($row);
        }

        /** @var UserStoreRoleServices $storeRoleServices */
        $storeRoleServices = app()->make(UserStoreRoleServices::class);
        foreach ($storeRoleServices->listActiveByUid($uid) as $row) {
            $roles[] = [
                'identity_id' => 0,
                'store_role_id' => $row['id'],
                'role_code' => $row['role_code'],
                'role_name' => YfthConstants::roles()[$row['role_code']] ?? $row['role_code'],
                'store_id' => (int)$row['store_id'],
                'status' => $row['status'],
                'source_type' => 'store_role',
                'permission_scope' => $this->jsonDecode($row['permission_scope'] ?? ''),
            ];
        }

        $roles = array_values($this->uniqueIdentityRows($roles));
        $storeIds = array_values(array_unique(array_filter(array_map(function ($row) {
            return (int)($row['store_id'] ?? 0);
        }, $roles))));
        $storeNames = $storeIds ? Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id') : [];
        foreach ($roles as &$role) {
            $role['store_name'] = (string)($storeNames[(int)($role['store_id'] ?? 0)] ?? '');
        }
        unset($role);
        return $roles;
    }

    public function getActiveIdentity(int $uid, string $roleCode)
    {
        if ($roleCode === 'customer') {
            return ['id' => 0, 'uid' => $uid, 'role_code' => 'customer', 'status' => YfthConstants::STATUS_ACTIVE];
        }
        return $this->activeIdentityQuery($uid, $roleCode)->find();
    }

    public function assertActiveIdentity(int $uid, string $roleCode): array
    {
        $identity = $this->getActiveIdentity($uid, $roleCode);
        if (!$identity) {
            throw new ApiException('当前用户没有该有效身份');
        }
        return is_array($identity) ? $identity : $identity->toArray();
    }

    public function saveIdentity(array $data)
    {
        $id = (int)($data['id'] ?? 0);
        unset($data['id']);
        $data['uid'] = (int)($data['uid'] ?? 0);
        $data['role_code'] = trim((string)($data['role_code'] ?? ''));
        $data['status'] = $data['status'] ?? YfthConstants::STATUS_ACTIVE;
        $data['source_type'] = $data['source_type'] ?? 'manual';
        $data['source_id'] = (int)($data['source_id'] ?? 0);
        $data['effective_time'] = $this->parseTime($data['effective_time'] ?? 0);
        $data['expire_time'] = $this->parseTime($data['expire_time'] ?? 0);
        $data['active_key'] = $this->activeKey([$data['uid'], $data['role_code'], $data['source_type'], $data['source_id']], $data['status']);
        if (!$id && $data['active_key']) {
            $existing = $this->dao->getOne(['active_key' => $data['active_key']]);
            if ($existing) {
                $id = (int)$existing['id'];
            }
        }
        $data = $this->withTimestamps($data, $id === 0);
        return $id ? $this->dao->update($id, $data) : $this->dao->save($data);
    }

    private function activeIdentityQuery(int $uid, string $roleCode = '')
    {
        $query = $this->dao->search([])
            ->where('uid', $uid)
            ->where('status', YfthConstants::STATUS_ACTIVE);
        if ($roleCode !== '') {
            $query->where('role_code', $roleCode);
        }
        return $this->applyActiveWindow($query);
    }

    private function formatIdentityRow(array $row): array
    {
        $row['role_name'] = YfthConstants::roles()[$row['role_code']] ?? $row['role_code'];
        $row['identity_id'] = (int)($row['id'] ?? 0);
        $row['store_id'] = (int)($row['store_id'] ?? 0);
        return $row;
    }

    private function uniqueIdentityRows(array $roles): array
    {
        $unique = [];
        foreach ($roles as $row) {
            $key = ($row['role_code'] ?? '') . ':' . (int)($row['store_id'] ?? 0);
            $unique[$key] = $row;
        }
        return $unique;
    }
}
