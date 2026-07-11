<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthHqActiveReferralEvent;

class YfthHqActiveReferralEventDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthHqActiveReferralEvent::class;
    }
}
