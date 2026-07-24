<?php

namespace app\services\yfth;

use app\Request;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class CurrentBusinessContextServices
{
    public function resolve(int $uid, string $roleCode = 'customer', int $storeId = 0): array
    {
        $roleCode = $roleCode ?: 'customer';
        if (!isset(YfthConstants::roles()[$roleCode])) {
            throw new ApiException('unknown_business_role');
        }

        if (in_array($roleCode, YfthConstants::storeRoles(), true)) {
            /** @var UserStoreRoleServices $storeRoleServices */
            $storeRoleServices = app()->make(UserStoreRoleServices::class);
            $storeRole = $storeRoleServices->assertStoreRole($uid, $storeId, $roleCode);

            /** @var StoreAccessServices $storeAccessServices */
            $storeAccessServices = app()->make(StoreAccessServices::class);
            $store = $storeAccessServices->assertStoreActive((int)$storeRole['store_id']);

            return array_merge($this->baseContext($uid, $roleCode), $store, [
                'store_role_id' => (int)$storeRole['id'],
                'identity_id' => 0,
                'permission_scope' => $this->decodeScope($storeRole['permission_scope'] ?? ''),
                'business_context_source' => 'server_store_role',
            ], $this->storeBusinessSummary((int)$storeRole['store_id']));
        }

        if (in_array($roleCode, YfthConstants::partnerRoles(), true)) {
            $profile = Db::name('yfth_partner_profile')->where([
                'uid' => $uid, 'rank_code' => $roleCode, 'status' => 'active', 'qualification_status' => 'effective',
            ])->find();
            if (!$profile) throw new ApiException('partner_context_not_effective');
            $managedStoreCount = (int)Db::name('yfth_partner_store_binding')->where([
                'partner_uid' => $uid, 'status' => 'active',
            ])->count();
            return array_merge($this->baseContext($uid, $roleCode), [
                'store_id' => 0,
                'store_name' => '',
                'store_status' => '',
                'store_type' => '',
                'store_role_id' => 0,
                'identity_id' => (int)$profile['id'],
                'permission_scope' => [
                    'source' => 'partner_profile',
                    'managed_store_count' => $managedStoreCount,
                ],
                'business_context_source' => 'server_partner_profile',
                'subject_status' => '',
                'qualification_status' => (string)$profile['qualification_status'],
                'capabilities' => [],
            ]);
        }

        /** @var UserIdentityServices $identityServices */
        $identityServices = app()->make(UserIdentityServices::class);
        $identity = $identityServices->assertActiveIdentity($uid, $roleCode);
        return array_merge($this->baseContext($uid, $roleCode), [
            'store_id' => 0,
            'store_name' => '',
            'store_status' => '',
            'store_type' => '',
            'store_role_id' => 0,
            'identity_id' => (int)($identity['id'] ?? 0),
            'permission_scope' => [],
            'business_context_source' => 'server_identity',
            'subject_status' => '',
            'qualification_status' => '',
            'capabilities' => [],
        ]);
    }

    public function fromRequest(Request $request): array
    {
        $roleCode = (string)$request->param('role_code', $request->header('X-YFTH-Role', 'customer'));
        $storeId = (int)$request->param('store_id', $request->header('X-YFTH-Store-Id', 0));
        return $this->resolve((int)$request->uid(), $roleCode, $storeId);
    }

    private function baseContext(int $uid, string $roleCode): array
    {
        return [
            'uid' => $uid,
            'role_code' => $roleCode,
            'role_name' => YfthConstants::roles()[$roleCode],
        ];
    }

    private function storeBusinessSummary(int $storeId): array
    {
        /** @var StoreSubjectServices $storeSubjectServices */
        $storeSubjectServices = app()->make(StoreSubjectServices::class);
        /** @var StoreQualificationServices $qualificationServices */
        $qualificationServices = app()->make(StoreQualificationServices::class);
        /** @var StoreCapabilityServices $capabilityServices */
        $capabilityServices = app()->make(StoreCapabilityServices::class);

        return [
            'store_type' => $storeSubjectServices->activeStoreType($storeId),
            'subject_status' => $storeSubjectServices->contextStatus($storeId),
            'qualification_status' => $qualificationServices->contextStatus($storeId),
            'capabilities' => $capabilityServices->activeCodesForStore($storeId),
        ];
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
