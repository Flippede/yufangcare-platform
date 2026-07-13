<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthDirectReferralRuleVersion;

class YfthDirectReferralRuleVersionDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthDirectReferralRuleVersion::class;
    }
}
