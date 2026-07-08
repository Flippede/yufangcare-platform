<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPurchaseOrder;

class YfthPurchaseOrderDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPurchaseOrder::class;
    }
}

