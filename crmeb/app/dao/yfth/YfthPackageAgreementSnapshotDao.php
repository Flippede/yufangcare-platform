<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackageAgreementSnapshot;

class YfthPackageAgreementSnapshotDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackageAgreementSnapshot::class;
    }
}
