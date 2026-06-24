<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthUserStoreRole;

class YfthUserStoreRoleDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthUserStoreRole::class;
    }
}
