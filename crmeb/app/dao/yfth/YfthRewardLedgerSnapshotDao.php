<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthRewardLedgerSnapshot;

class YfthRewardLedgerSnapshotDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthRewardLedgerSnapshot::class;
    }
}
