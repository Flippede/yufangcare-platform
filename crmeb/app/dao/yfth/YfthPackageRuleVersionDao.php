<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthPackageRuleVersion;

class YfthPackageRuleVersionDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthPackageRuleVersion::class;
    }
}
