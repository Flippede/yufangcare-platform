<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthSupplyCatalog;

class YfthSupplyCatalogDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthSupplyCatalog::class;
    }
}
