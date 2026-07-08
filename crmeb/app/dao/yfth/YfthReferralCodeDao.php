<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthReferralCode;

class YfthReferralCodeDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthReferralCode::class;
    }
}
