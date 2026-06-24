<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthStoreSubject;

class YfthStoreSubjectDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthStoreSubject::class;
    }
}
