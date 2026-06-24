<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthBusinessSubject;

class YfthBusinessSubjectDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthBusinessSubject::class;
    }
}
