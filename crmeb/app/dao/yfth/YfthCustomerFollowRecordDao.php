<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthCustomerFollowRecord;

class YfthCustomerFollowRecordDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthCustomerFollowRecord::class;
    }
}
