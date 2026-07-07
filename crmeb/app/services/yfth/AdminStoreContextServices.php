<?php

namespace app\services\yfth;

use app\dao\yfth\YfthAdminStoreScopeDao;
use crmeb\exceptions\AdminException;

class AdminStoreContextServices extends YfthFoundationBaseServices
{
    public const ROLE_HEADQUARTER = 'headquarter_operator';
    public const OPERATOR_ADMIN = 'admin';
    public const OPERATOR_USER_STORE_ROLE = 'user_store_role';

    public function __construct(YfthAdminStoreScopeDao $dao)
    {
        $this->dao = $dao;
    }

    public function enrichAdminInfo(array $adminInfo): array
    {
        $context = $this->resolve($adminInfo);
        $adminInfo['yfth_admin_context'] = $context;
        $adminInfo['yfth_is_super_admin'] = (int)$context['is_super_admin'];
        $adminInfo['yfth_is_headquarter_admin'] = (int)$context['is_headquarter_admin'];
        $adminInfo['yfth_store_ids'] = $context['store_ids'];
        $adminInfo['yfth_store_role_codes'] = $context['store_role_codes'];
        $adminInfo['yfth_store_scope_roles'] = $context['store_scope_roles'];
        $adminInfo['yfth_store_role_code'] = $context['primary_role_code'];
        return $adminInfo;
    }

    public function resolve(array $adminInfo): array
    {
        if (!empty($adminInfo['yfth_operator_context']) && is_array($adminInfo['yfth_operator_context'])) {
            return $this->normalizeOperatorContext($adminInfo['yfth_operator_context']);
        }

        if (!empty($adminInfo['yfth_admin_context']) && is_array($adminInfo['yfth_admin_context'])) {
            return $this->normalizeContext($adminInfo['yfth_admin_context']);
        }

        $adminId = (int)($adminInfo['id'] ?? 0);
        if ((int)($adminInfo['level'] ?? -1) === 0) {
            return $this->superContext($adminId);
        }

        $context = $this->emptyContext($adminId);
        if ($adminId <= 0) {
            return $context;
        }

        $rows = $this->applyActiveWindow(
            $this->dao->search([])
                ->where('admin_id', $adminId)
                ->where('status', YfthConstants::STATUS_ACTIVE),
            'start_time',
            'end_time'
        )->select()->toArray();

        $storeIds = [];
        $roleCodes = [];
        $storeScopeRoles = [];
        $permissionScope = [];
        foreach ($rows as $row) {
            $roleCode = trim((string)($row['role_code'] ?? ''));
            $storeId = (int)($row['store_id'] ?? 0);
            if ($roleCode === self::ROLE_HEADQUARTER && $storeId === 0) {
                $context['is_headquarter_admin'] = true;
                $roleCodes[] = $roleCode;
                $permissionScope[] = $this->jsonDecode((string)($row['permission_scope'] ?? ''));
                continue;
            }
            if (!in_array($roleCode, YfthConstants::storeRoles(), true) || $storeId <= 0) {
                continue;
            }
            try {
                app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
            } catch (\Throwable $e) {
                continue;
            }
            $storeIds[] = $storeId;
            $roleCodes[] = $roleCode;
            if (!isset($storeScopeRoles[$storeId])) {
                $storeScopeRoles[$storeId] = [];
            }
            $storeScopeRoles[$storeId][] = $roleCode;
            if ($roleCode === 'store_staff') {
                $context['is_store_staff'] = true;
            }
            if (in_array($roleCode, ['store_manager', 'franchisee'], true)) {
                $context['is_store_manager'] = true;
            }
            $permissionScope[] = $this->jsonDecode((string)($row['permission_scope'] ?? ''));
        }

        $context['store_ids'] = array_values(array_unique(array_map('intval', $storeIds)));
        $context['store_role_codes'] = array_values(array_unique($roleCodes));
        foreach ($storeScopeRoles as $storeId => $roles) {
            $storeScopeRoles[$storeId] = array_values(array_unique($roles));
        }
        $context['store_scope_roles'] = $storeScopeRoles;
        $context['primary_role_code'] = $context['is_headquarter_admin']
            ? self::ROLE_HEADQUARTER
            : (string)($context['store_role_codes'][0] ?? '');
        $context['permission_scope'] = $permissionScope;
        $context['source'] = 'yfth_admin_store_scope';
        return $context;
    }

    public function assertHeadquarterScope(array $adminInfo): void
    {
        $context = $this->resolve($adminInfo);
        if ($context['is_super_admin'] || $context['is_headquarter_admin']) {
            return;
        }
        throw new AdminException('headquarter_permission_required');
    }

    public function assertStoreWritable(array $adminInfo, int $storeId): void
    {
        if ($storeId <= 0) {
            throw new AdminException('store_id_required');
        }
        try {
            app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage() ?: 'store_not_active');
        }

        $context = $this->resolve($adminInfo);
        if ($context['is_super_admin'] || $context['is_headquarter_admin']) {
            return;
        }

        $roles = $context['store_scope_roles'][$storeId] ?? [];
        if (in_array('store_staff', $roles, true) && !array_intersect($roles, ['store_manager', 'franchisee'])) {
            throw new AdminException('store_staff_cannot_configure_service_appointment');
        }
        if (array_intersect($roles, ['store_manager', 'franchisee'])) {
            return;
        }
        if ($context['is_store_staff']) {
            throw new AdminException('store_staff_cannot_configure_service_appointment');
        }
        throw new AdminException('store_scope_forbidden');
    }

    public function applyStoreFilter(array $where, array $adminInfo): array
    {
        $context = $this->resolve($adminInfo);
        if ($context['is_super_admin'] || $context['is_headquarter_admin']) {
            return $where;
        }
        $storeIds = array_values(array_filter(array_map('intval', $context['store_ids'])));
        if (!$storeIds) {
            throw new AdminException('store_scope_forbidden');
        }
        if (!empty($where['store_id']) && !in_array((int)$where['store_id'], $storeIds, true)) {
            throw new AdminException('store_scope_forbidden');
        }
        if (empty($where['store_id'])) {
            $where['store_id'] = $storeIds;
        }
        return $where;
    }

    public function saveScope(array $data, int $operatorUid = 0)
    {
        $id = (int)($data['id'] ?? 0);
        unset($data['id']);
        $data['admin_id'] = (int)($data['admin_id'] ?? 0);
        $data['store_id'] = (int)($data['store_id'] ?? 0);
        $data['role_code'] = trim((string)($data['role_code'] ?? ''));
        if ($data['admin_id'] <= 0 || $data['role_code'] === '') {
            throw new AdminException('admin_scope_required');
        }
        if ($data['role_code'] === self::ROLE_HEADQUARTER) {
            $data['store_id'] = 0;
        } elseif (!in_array($data['role_code'], YfthConstants::storeRoles(), true) || $data['store_id'] <= 0) {
            throw new AdminException('invalid_admin_store_scope');
        }
        $data['status'] = trim((string)($data['status'] ?? YfthConstants::STATUS_ACTIVE)) ?: YfthConstants::STATUS_ACTIVE;
        if (!in_array($data['status'], [YfthConstants::STATUS_ACTIVE, YfthConstants::STATUS_DISABLED], true)) {
            throw new AdminException('invalid_status');
        }
        $data['permission_scope'] = $this->jsonEncode($data['permission_scope'] ?? '');
        $data['start_time'] = $this->parseTime($data['start_time'] ?? 0);
        $data['end_time'] = $this->parseTime($data['end_time'] ?? 0);
        $data['updated_uid'] = $operatorUid;
        if ($id === 0) {
            $data['created_uid'] = $operatorUid;
        }
        if ($data['status'] === YfthConstants::STATUS_ACTIVE) {
            $data['active_key'] = $this->activeKey([$data['admin_id'], $data['store_id'], $data['role_code']], $data['status']);
            $data['disabled_uid'] = 0;
            $data['disabled_time'] = 0;
            $data['close_reason'] = '';
        } else {
            $data['active_key'] = null;
        }
        $data = $this->withTimestamps($data, $id === 0);
        return $id ? $this->dao->update($id, $data) : $this->dao->save($data);
    }

    private function emptyContext(int $adminId): array
    {
        return [
            'admin_id' => $adminId,
            'operator_type' => self::OPERATOR_ADMIN,
            'operator_uid' => 0,
            'is_super_admin' => false,
            'is_headquarter_admin' => false,
            'is_store_manager' => false,
            'is_store_staff' => false,
            'store_ids' => [],
            'store_role_codes' => [],
            'store_scope_roles' => [],
            'primary_role_code' => '',
            'permission_scope' => [],
            'allowed_actions' => [],
            'source' => 'none',
        ];
    }

    private function superContext(int $adminId): array
    {
        $context = $this->emptyContext($adminId);
        $context['is_super_admin'] = true;
        $context['is_headquarter_admin'] = true;
        $context['primary_role_code'] = 'super_admin';
        $context['source'] = 'system_admin_super';
        return $context;
    }

    private function normalizeContext(array $context): array
    {
        $context = array_merge($this->emptyContext((int)($context['admin_id'] ?? 0)), $context);
        $context['operator_type'] = self::OPERATOR_ADMIN;
        $context['operator_uid'] = (int)($context['operator_uid'] ?? 0);
        $context['is_super_admin'] = (bool)$context['is_super_admin'];
        $context['is_headquarter_admin'] = (bool)$context['is_headquarter_admin'];
        $context['is_store_manager'] = (bool)$context['is_store_manager'];
        $context['is_store_staff'] = (bool)$context['is_store_staff'];
        $context['store_ids'] = array_values(array_filter(array_unique(array_map('intval', (array)$context['store_ids']))));
        return $context;
    }

    private function normalizeOperatorContext(array $context): array
    {
        $operatorUid = (int)($context['operator_uid'] ?? 0);
        $roleCode = trim((string)($context['role_code'] ?? ($context['primary_role_code'] ?? '')));
        $storeId = (int)($context['store_id'] ?? 0);
        $storeIds = array_values(array_filter(array_unique(array_map('intval', (array)($context['authorized_store_ids'] ?? ($context['store_ids'] ?? []))))));
        if ($storeId > 0 && !in_array($storeId, $storeIds, true)) {
            $storeIds[] = $storeId;
        }

        $storeScopeRoles = [];
        foreach ($storeIds as $currentStoreId) {
            $roles = (array)($context['store_scope_roles'][$currentStoreId] ?? []);
            if ($roleCode !== '' && !in_array($roleCode, $roles, true)) {
                $roles[] = $roleCode;
            }
            $storeScopeRoles[$currentStoreId] = array_values(array_unique(array_filter($roles)));
        }

        $normalized = array_merge($this->emptyContext(0), [
            'operator_type' => self::OPERATOR_USER_STORE_ROLE,
            'operator_uid' => $operatorUid,
            'is_super_admin' => false,
            'is_headquarter_admin' => false,
            'is_store_manager' => in_array($roleCode, ['store_manager', 'franchisee'], true),
            'is_store_staff' => $roleCode === 'store_staff',
            'store_ids' => $storeIds,
            'store_role_codes' => $roleCode === '' ? [] : [$roleCode],
            'store_scope_roles' => $storeScopeRoles,
            'primary_role_code' => $roleCode,
            'permission_scope' => (array)($context['permission_scope'] ?? []),
            'allowed_actions' => array_values((array)($context['allowed_actions'] ?? [])),
            'source' => (string)($context['source'] ?? 'yfth_user_store_role_operator'),
        ]);
        return $normalized;
    }
}
