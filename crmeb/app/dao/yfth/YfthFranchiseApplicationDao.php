<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthFranchiseApplication;

class YfthFranchiseApplicationDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthFranchiseApplication::class;
    }
}
