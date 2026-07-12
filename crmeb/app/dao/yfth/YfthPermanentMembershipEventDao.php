<?php
namespace app\dao\yfth;
use app\dao\BaseDao;
use app\model\yfth\YfthPermanentMembershipEvent;
class YfthPermanentMembershipEventDao extends BaseDao { protected function setModel(): string { return YfthPermanentMembershipEvent::class; } }
