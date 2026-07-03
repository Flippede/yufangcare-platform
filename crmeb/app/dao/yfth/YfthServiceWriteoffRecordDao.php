<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthServiceWriteoffRecord;

class YfthServiceWriteoffRecordDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthServiceWriteoffRecord::class;
    }
}
