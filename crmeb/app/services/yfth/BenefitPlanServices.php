<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitPlanDao;

class BenefitPlanServices extends PackageBenefitBaseServices
{
    public function __construct(YfthBenefitPlanDao $dao)
    {
        $this->dao = $dao;
    }

    public function planByInstance(int $instanceId): array
    {
        $row = $this->dao->getOne(['package_instance_id' => $instanceId]);
        return $row ? $row->toArray() : [];
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'package_instance_id' => (int)($where['package_instance_id'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where);
    }
}
