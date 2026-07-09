<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthProductQuotaAdjustment;

class YfthProductQuotaAdjustmentDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthProductQuotaAdjustment::class;
    }
}
