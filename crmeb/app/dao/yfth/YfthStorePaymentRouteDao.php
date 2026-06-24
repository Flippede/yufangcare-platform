<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStorePaymentRoute;

class YfthStorePaymentRouteDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStorePaymentRoute::class;
    }
}
