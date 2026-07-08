<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthFranchiseContract;

class YfthFranchiseContractDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthFranchiseContract::class;
    }
}
