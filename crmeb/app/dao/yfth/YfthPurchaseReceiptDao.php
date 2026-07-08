<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPurchaseReceipt;

class YfthPurchaseReceiptDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPurchaseReceipt::class;
    }
}

