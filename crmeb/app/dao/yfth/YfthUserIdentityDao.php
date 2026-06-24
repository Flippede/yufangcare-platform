<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthUserIdentity;

class YfthUserIdentityDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthUserIdentity::class;
    }
}
