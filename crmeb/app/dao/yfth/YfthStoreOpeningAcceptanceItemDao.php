<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStoreOpeningAcceptanceItem;

class YfthStoreOpeningAcceptanceItemDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStoreOpeningAcceptanceItem::class;
    }
}
