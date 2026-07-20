<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\HqAuthorityReadParameterServices;
use app\services\yfth\RelationshipManagementServices;

class RelationshipManagement extends AuthController
{
    public function userHierarchy(RelationshipManagementServices $services, HqAuthorityReadParameterServices $parameters)
    {
        $this->assertAuth('yfth/relationship_management/user_hierarchy', 'GET');
        return app('json')->success($services->userHierarchy(
            $parameters->hierarchyFilters($this->request->get()),
            $this->adminInfo ?: []
        ));
    }

    public function storeHierarchy(RelationshipManagementServices $services, HqAuthorityReadParameterServices $parameters)
    {
        $this->assertAuth('yfth/relationship_management/store_hierarchy', 'GET');
        return app('json')->success($services->storeHierarchy(
            $parameters->storeHierarchyFilters($this->request->get()),
            $this->adminInfo ?: []
        ));
    }

    public function revokeParent(RelationshipManagementServices $services, HqAuthorityReadParameterServices $parameters, $id)
    {
        $this->assertAuth('yfth/relationship_management/user/<id>/revoke_parent', 'POST');
        return app('json')->success($services->revokeParent(
            $parameters->positiveId($id, 'attribution_id'),
            $this->request->postMore([
                ['reason', ''],
                ['request_id', ''],
                ['idempotency_key', ''],
            ]),
            (int)$this->adminId,
            $this->adminInfo ?: []
        ));
    }

    private function assertAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
