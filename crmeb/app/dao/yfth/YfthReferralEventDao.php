<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthReferralEvent;

class YfthReferralEventDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthReferralEvent::class;
    }
}
