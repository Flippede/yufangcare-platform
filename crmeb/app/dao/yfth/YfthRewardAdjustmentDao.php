<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthRewardAdjustment;

class YfthRewardAdjustmentDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthRewardAdjustment::class;
    }
}
