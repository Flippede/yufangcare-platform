<?php

namespace app\services\yfth;

use app\Request;
use crmeb\exceptions\ApiException;

class CurrentBusinessContextServices
{
    public function resolve(int $uid, string $roleCode = 'customer', int $storeId = 0): array
    {
        $roleCode = $roleCode ?: 'customer';
        if (!isset(YfthConstants::roles()[$roleCode])) {
            throw new ApiException('未知业务身份');
        }

        if (in_array($roleCode, YfthConstants::storeRoles(), true)) {
            /** @var UserStoreRoleServices $storeRoleServices */
            $storeRoleServices = app()->make(UserStoreRoleServices::class);
            $storeRole = $storeRoleServices->assertStoreRole($uid, $storeId, $roleCode);
            return [
                'uid' => $uid,
                'role_code' => $roleCode,
                'role_name' => YfthConstants::roles()[$roleCode],
                'store_id' => $storeId,
                'store_role_id' => (int)$storeRole['id'],
                'identity_id' => 0,
                'permission_scope' => $this->decodeScope($storeRole['permission_scope'] ?? ''),
            ];
        }

        /** @var UserIdentityServices $identityServices */
        $identityServices = app()->make(UserIdentityServices::class);
        $identity = $identityServices->assertActiveIdentity($uid, $roleCode);
        return [
            'uid' => $uid,
            'role_code' => $roleCode,
            'role_name' => YfthConstants::roles()[$roleCode],
            'store_id' => $storeId,
            'store_role_id' => 0,
            'identity_id' => (int)($identity['id'] ?? 0),
            'permission_scope' => [],
        ];
    }

    public function fromRequest(Request $request): array
    {
        $roleCode = (string)$request->param('role_code', $request->header('X-YFTH-Role', 'customer'));
        $storeId = (int)$request->param('store_id', $request->header('X-YFTH-Store-Id', 0));
        return $this->resolve((int)$request->uid(), $roleCode, $storeId);
    }

    private function decodeScope($scope): array
    {
        if (!is_string($scope) || $scope === '') {
            return [];
        }
        $decoded = json_decode($scope, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
