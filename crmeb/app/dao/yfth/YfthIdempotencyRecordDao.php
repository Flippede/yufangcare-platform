<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthIdempotencyRecord;

class YfthIdempotencyRecordDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthIdempotencyRecord::class;
    }
}
