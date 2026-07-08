<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthInventoryLedger;

class YfthInventoryLedgerDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthInventoryLedger::class;
    }
}
