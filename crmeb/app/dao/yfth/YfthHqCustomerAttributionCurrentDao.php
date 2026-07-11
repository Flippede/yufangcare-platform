<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthHqCustomerAttributionCurrent;

class YfthHqCustomerAttributionCurrentDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthHqCustomerAttributionCurrent::class;
    }
}
