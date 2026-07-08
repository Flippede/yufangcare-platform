<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthReferralAttribution;

class YfthReferralAttributionDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthReferralAttribution::class;
    }
}
