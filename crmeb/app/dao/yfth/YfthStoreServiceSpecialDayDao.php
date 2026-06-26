<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStoreServiceSpecialDay;

class YfthStoreServiceSpecialDayDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStoreServiceSpecialDay::class;
    }
}
