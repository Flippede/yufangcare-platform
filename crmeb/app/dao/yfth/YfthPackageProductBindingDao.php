<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackageProductBinding;

class YfthPackageProductBindingDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackageProductBinding::class;
    }
}
