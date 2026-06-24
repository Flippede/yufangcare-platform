<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackageTemplate;

class YfthPackageTemplateDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackageTemplate::class;
    }
}
