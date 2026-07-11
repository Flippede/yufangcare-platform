<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthHqActiveReferralCurrent;

class YfthHqActiveReferralCurrentDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthHqActiveReferralCurrent::class;
    }
}
