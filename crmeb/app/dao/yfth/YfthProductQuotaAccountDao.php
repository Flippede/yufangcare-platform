<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthProductQuotaAccount;

class YfthProductQuotaAccountDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthProductQuotaAccount::class;
    }
}
