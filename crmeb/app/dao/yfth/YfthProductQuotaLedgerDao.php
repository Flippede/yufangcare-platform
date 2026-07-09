<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthProductQuotaLedger;

class YfthProductQuotaLedgerDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthProductQuotaLedger::class;
    }
}
