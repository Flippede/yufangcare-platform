<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackagePurchaseIntent;

class YfthPackagePurchaseIntentDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackagePurchaseIntent::class;
    }
}
