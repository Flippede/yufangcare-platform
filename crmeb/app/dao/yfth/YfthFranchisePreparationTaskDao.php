<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthFranchisePreparationTask;

class YfthFranchisePreparationTaskDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthFranchisePreparationTask::class;
    }
}
