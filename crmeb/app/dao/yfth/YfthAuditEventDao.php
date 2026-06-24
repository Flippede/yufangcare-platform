<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthAuditEvent;

class YfthAuditEventDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthAuditEvent::class;
    }
}
