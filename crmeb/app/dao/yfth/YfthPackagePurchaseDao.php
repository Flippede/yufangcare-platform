<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackagePurchase;

class YfthPackagePurchaseDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackagePurchase::class;
    }
}
