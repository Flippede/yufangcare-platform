<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthServiceProject;

class YfthServiceProjectDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthServiceProject::class;
    }
}
