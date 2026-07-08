<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthRewardSettlementRecord;

class YfthRewardSettlementRecordDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthRewardSettlementRecord::class;
    }
}
