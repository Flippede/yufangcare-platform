<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthFranchiseIdentityGrant;

class YfthFranchiseIdentityGrantDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthFranchiseIdentityGrant::class;
    }
}
