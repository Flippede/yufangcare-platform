<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthBenefitFulfillment;

class YfthBenefitFulfillmentDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthBenefitFulfillment::class;
    }
}
