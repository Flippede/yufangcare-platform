<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackagePurchaseSnapshot;

class YfthPackagePurchaseSnapshotDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackagePurchaseSnapshot::class;
    }
}
