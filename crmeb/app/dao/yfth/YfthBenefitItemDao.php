<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthBenefitItem;

class YfthBenefitItemDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthBenefitItem::class;
    }
}
