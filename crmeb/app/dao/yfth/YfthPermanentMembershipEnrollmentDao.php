<?php
namespace app\dao\yfth;
use app\dao\BaseDao;
use app\model\yfth\YfthPermanentMembershipEnrollment;
class YfthPermanentMembershipEnrollmentDao extends BaseDao { protected function setModel(): string { return YfthPermanentMembershipEnrollment::class; } }
