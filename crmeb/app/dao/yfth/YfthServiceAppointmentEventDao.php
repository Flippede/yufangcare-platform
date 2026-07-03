<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthServiceAppointmentEvent;

class YfthServiceAppointmentEventDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthServiceAppointmentEvent::class;
    }
}
