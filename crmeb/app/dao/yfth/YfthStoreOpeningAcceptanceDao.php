<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStoreOpeningAcceptance;

class YfthStoreOpeningAcceptanceDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStoreOpeningAcceptance::class;
    }
}
