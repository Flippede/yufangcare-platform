<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPermanentMembership;

class YfthPermanentMembershipDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPermanentMembership::class;
    }
}
