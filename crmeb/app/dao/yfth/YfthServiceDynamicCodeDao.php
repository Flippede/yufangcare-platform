<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthServiceDynamicCode;

class YfthServiceDynamicCodeDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthServiceDynamicCode::class;
    }
}
