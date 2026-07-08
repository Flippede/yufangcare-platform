<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthRewardRuleVersion;

class YfthRewardRuleVersionDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthRewardRuleVersion::class;
    }
}
