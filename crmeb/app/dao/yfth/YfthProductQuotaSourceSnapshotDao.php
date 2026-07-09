<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthProductQuotaSourceSnapshot;

class YfthProductQuotaSourceSnapshotDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthProductQuotaSourceSnapshot::class;
    }
}
