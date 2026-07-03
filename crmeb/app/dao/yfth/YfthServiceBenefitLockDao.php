<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthServiceBenefitLock;

class YfthServiceBenefitLockDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthServiceBenefitLock::class;
    }
}
