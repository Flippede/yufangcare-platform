<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthDirectReferralInvite;

class YfthDirectReferralInviteDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthDirectReferralInvite::class;
    }
}
