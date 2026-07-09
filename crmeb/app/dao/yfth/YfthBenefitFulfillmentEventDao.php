<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthBenefitFulfillmentEvent;

class YfthBenefitFulfillmentEventDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthBenefitFulfillmentEvent::class;
    }
}
