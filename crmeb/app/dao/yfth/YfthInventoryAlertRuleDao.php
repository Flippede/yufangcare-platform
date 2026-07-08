<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthInventoryAlertRule;

class YfthInventoryAlertRuleDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthInventoryAlertRule::class;
    }
}

