<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthFranchisePreparationTaskRecord;

class YfthFranchisePreparationTaskRecordDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthFranchisePreparationTaskRecord::class;
    }
}
