<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthServiceAppointment;

class YfthServiceAppointmentDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthServiceAppointment::class;
    }
}
