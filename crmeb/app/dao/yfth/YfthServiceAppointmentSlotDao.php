<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthServiceAppointmentSlot;

class YfthServiceAppointmentSlotDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthServiceAppointmentSlot::class;
    }
}
