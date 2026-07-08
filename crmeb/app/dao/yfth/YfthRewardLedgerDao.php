<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthRewardLedger;

class YfthRewardLedgerDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthRewardLedger::class;
    }
}
