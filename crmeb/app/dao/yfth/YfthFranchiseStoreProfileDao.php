<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthFranchiseStoreProfile;

class YfthFranchiseStoreProfileDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthFranchiseStoreProfile::class;
    }
}
