<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthBenefitTemplate;

class YfthBenefitTemplateDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthBenefitTemplate::class;
    }
}
