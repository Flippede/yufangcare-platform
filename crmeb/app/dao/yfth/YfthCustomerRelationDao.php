<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthCustomerRelation;

class YfthCustomerRelationDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthCustomerRelation::class;
    }
}
