<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackagePurchaseBenefitSnapshot;

class YfthPackagePurchaseBenefitSnapshotDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackagePurchaseBenefitSnapshot::class;
    }
}
