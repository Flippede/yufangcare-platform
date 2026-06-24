<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthBenefitPlan;

class YfthBenefitPlanDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthBenefitPlan::class;
    }
}
