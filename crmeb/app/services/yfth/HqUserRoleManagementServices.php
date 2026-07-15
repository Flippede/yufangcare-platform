<?php

namespace app\services\yfth;

use app\dao\system\store\SystemStoreDao;
use app\dao\user\UserDao;
use app\dao\yfth\YfthUserStoreRoleDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class HqUserRoleManagementServices
{
    private const DOMAIN = 'yfth_user_role_management';
    private const ROLE_NAMES = [
        'franchisee' => '加盟商',
        'store_manager' => '店长',
        'store_staff' => '店员',
    ];

    private $users;
    private $roles;
    private $roleServices;
    private $stores;
    private $membership;
    private $identities;
    private $audit;
    private $adminScope;

    public function __construct(
        UserDao $users,
        YfthUserStoreRoleDao $roles,
        UserStoreRoleServices $roleServices,
        SystemStoreDao $stores,
        PackageMembershipServices $membership,
        UserIdentityServices $identities,
        AuditEventServices $audit,
        AdminStoreContextServices $adminScope
    ) {
        $this->users = $users;
        $this->roles = $roles;
        $this->roleServices = $roleServices;
        $this->stores = $stores;
        $this->membership = $membership;
        $this->identities = $identities;
        $this->audit = $audit;
        $this->adminScope = $adminScope;
    }

    public function users(array $filters, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = max(1, min(50, (int)($filters['limit'] ?? 20)));
        $query = $this->users->search(['is_del' => 0]);
        if ($keyword !== '') {
            $query->where(function ($where) use ($keyword) {
                $where->where('uid', ctype_digit($keyword) ? (int)$keyword : -1)
                    ->whereOr('phone', 'like', '%' . $keyword . '%')
                    ->whereOr('nickname', 'like', '%' . $keyword . '%')
                    ->whereOr('account', 'like', '%' . $keyword . '%');
            });
        }
        $count = (clone $query)->count();
        $rows = $query->field('uid,nickname,avatar,phone,account,status')->page($page, $limit)->order('uid desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row = $this->userDto($row, false);
        }
        return [
            'list' => $rows,
            'count' => $count,
            'stores' => $this->activeStores(),
            'role_options' => $this->roleOptions(),
        ];
    }

    public function detail(int $uid, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        return $this->userDto($this->user($uid), true);
    }

    public function grant(int $uid, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $storeId = (int)($data['store_id'] ?? 0);
        $roleCode = trim((string)($data['role_code'] ?? ''));
        $reason = $this->reason($data);
        $requestId = $this->requestId($data);
        $this->user($uid);
        if (!isset(self::ROLE_NAMES[$roleCode])) {
            throw new ApiException('user_store_role_code_invalid');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);

        return Db::transaction(function () use ($uid, $storeId, $roleCode, $reason, $requestId, $adminId) {
            $existing = $this->roleRow($this->roles->search([])
                ->where('uid', $uid)->where('store_id', $storeId)->where('role_code', $roleCode)
                ->where('status', YfthConstants::STATUS_ACTIVE)->lock(true)->find());
            if ($existing) {
                return ['changed' => false, 'idempotent' => true, 'role' => $this->roleDto($existing)];
            }
            $saved = $this->roleServices->saveRole([
                'uid' => $uid,
                'store_id' => $storeId,
                'role_code' => $roleCode,
                'status' => YfthConstants::STATUS_ACTIVE,
                'permission_scope' => [],
                'start_time' => time(),
                'end_time' => 0,
                'creator_uid' => $adminId,
            ]);
            $role = $this->roleRow($saved);
            $this->audit->record(
                self::DOMAIN,
                'user_store_role',
                (string)$role['id'],
                'grant',
                [],
                $this->roleDto($role),
                $adminId,
                'headquarters_admin',
                $storeId,
                $reason,
                $requestId
            );
            return ['changed' => true, 'idempotent' => false, 'role' => $this->roleDto($role)];
        });
    }

    public function revoke(int $roleId, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->reason($data);
        $requestId = $this->requestId($data);
        return Db::transaction(function () use ($roleId, $reason, $requestId, $adminId) {
            $before = $this->roleRow($this->roles->search([])->where('id', $roleId)->lock(true)->find());
            if (!$before || !isset(self::ROLE_NAMES[(string)($before['role_code'] ?? '')])) {
                throw new ApiException('user_store_role_not_found');
            }
            if ((string)$before['status'] !== YfthConstants::STATUS_ACTIVE) {
                return ['changed' => false, 'idempotent' => true, 'role' => $this->roleDto($before)];
            }
            $this->roles->update($roleId, [
                'status' => 'disabled',
                'active_key' => null,
                'end_time' => time(),
                'update_time' => time(),
            ]);
            $after = $this->roleRow($this->roles->get($roleId));
            $this->audit->record(
                self::DOMAIN,
                'user_store_role',
                (string)$roleId,
                'revoke',
                $this->roleDto($before),
                $this->roleDto($after),
                $adminId,
                'headquarters_admin',
                (int)$after['store_id'],
                $reason,
                $requestId
            );
            return ['changed' => true, 'idempotent' => false, 'role' => $this->roleDto($after)];
        });
    }

    private function userDto(array $user, bool $includeHistory): array
    {
        $uid = (int)$user['uid'];
        $membership = $this->membership->effectiveMembership($uid);
        $query = $this->roles->search([])->where('uid', $uid);
        if (!$includeHistory) {
            $query->where('status', YfthConstants::STATUS_ACTIVE);
        }
        $roleRows = $query->order('id desc')->select()->toArray();
        $roles = array_map(function ($row) {
            return $this->roleDto($row);
        }, $roleRows);
        return [
            'uid' => $uid,
            'nickname' => (string)($user['nickname'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            'account' => (string)($user['account'] ?? ''),
            'user_status' => (int)($user['status'] ?? 0),
            'customer' => true,
            'permanent_member' => (bool)$membership['is_member'],
            'membership' => $membership['is_member'] ? $membership['member'] : null,
            'identities' => $this->identities->listUserIdentities($uid),
            'store_roles' => $roles,
        ];
    }

    private function roleDto(array $row): array
    {
        $store = $this->stores->get((int)($row['store_id'] ?? 0), ['id', 'name', 'is_show', 'is_del']);
        $store = $store ? (is_array($store) ? $store : $store->toArray()) : [];
        return [
            'id' => (int)($row['id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'store_name' => (string)($store['name'] ?? ''),
            'role_code' => (string)($row['role_code'] ?? ''),
            'role_name' => self::ROLE_NAMES[(string)($row['role_code'] ?? '')] ?? (string)($row['role_code'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'start_time' => (int)($row['start_time'] ?? 0),
            'end_time' => (int)($row['end_time'] ?? 0),
        ];
    }

    private function activeStores(): array
    {
        return $this->stores->search([])->where('is_show', 1)->where('is_del', 0)
            ->field('id,name')->order('id desc')->select()->toArray();
    }

    private function roleOptions(): array
    {
        $result = [];
        foreach (self::ROLE_NAMES as $value => $label) {
            $result[] = compact('value', 'label');
        }
        return $result;
    }

    private function user(int $uid): array
    {
        $row = $uid > 0 ? $this->users->get($uid, ['uid', 'nickname', 'avatar', 'phone', 'account', 'status', 'is_del']) : null;
        $row = $row ? (is_array($row) ? $row : $row->toArray()) : [];
        if (!$row || (int)($row['is_del'] ?? 0) !== 0) {
            throw new ApiException('user_not_found');
        }
        return $row;
    }

    private function assertHeadquarters(array $adminInfo): void
    {
        $this->adminScope->assertHeadquarterScope($adminInfo);
    }

    private function reason(array $data): string
    {
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException('user_store_role_reason_required');
        }
        return mb_substr($reason, 0, 255);
    }

    private function requestId(array $data): string
    {
        $value = trim((string)($data['request_id'] ?? ''));
        return $value !== '' ? substr($value, 0, 64) : 'user-role-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    private function maskPhone(string $phone): string
    {
        return preg_match('/^(.{3}).*(.{4})$/', $phone, $matches) ? $matches[1] . '****' . $matches[2] : $phone;
    }

    private function roleRow($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
