<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStoreQualification;

class YfthStoreQualificationDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStoreQualification::class;
    }
}
