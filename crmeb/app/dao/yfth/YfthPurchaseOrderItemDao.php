<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPurchaseOrderItem;

class YfthPurchaseOrderItemDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPurchaseOrderItem::class;
    }
}

