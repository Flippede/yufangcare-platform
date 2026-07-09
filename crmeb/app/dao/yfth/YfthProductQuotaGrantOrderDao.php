<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthProductQuotaGrantOrder;

class YfthProductQuotaGrantOrderDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthProductQuotaGrantOrder::class;
    }
}
