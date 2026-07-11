<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthHqCustomerAttributionEvent;

class YfthHqCustomerAttributionEventDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthHqCustomerAttributionEvent::class;
    }
}
