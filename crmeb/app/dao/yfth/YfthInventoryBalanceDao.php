<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthInventoryBalance;

class YfthInventoryBalanceDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthInventoryBalance::class;
    }
}

