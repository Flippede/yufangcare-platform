<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStoreService;

class YfthStoreServiceDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStoreService::class;
    }
}
