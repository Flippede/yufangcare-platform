<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthMonthlyBenefitRule;

class YfthMonthlyBenefitRuleDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthMonthlyBenefitRule::class;
    }
}
