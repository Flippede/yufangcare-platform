<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStockLocation;

class YfthStockLocationDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStockLocation::class;
    }
}

