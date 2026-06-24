<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStoreCapability;

class YfthStoreCapabilityDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStoreCapability::class;
    }
}
