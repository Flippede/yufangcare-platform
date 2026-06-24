<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackageInstance;

class YfthPackageInstanceDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackageInstance::class;
    }
}
