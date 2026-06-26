<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackageOrderAttempt;

class YfthPackageOrderAttemptDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackageOrderAttempt::class;
    }
}
