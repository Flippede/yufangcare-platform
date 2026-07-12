<?php

namespace app\services\yfth;

use app\dao\yfth\YfthPermanentMembershipDao;
use crmeb\exceptions\ApiException;

class PermanentMembershipReferralQualificationPolicy implements ReferralQualificationPolicy
{
    private $dao;

    public function __construct(YfthPermanentMembershipDao $dao)
    {
        $this->dao = $dao;
    }

    public function assertQualified(int $referrerUid, int $storeId): void
    {
        $row = $this->dao->getOne(['uid' => $referrerUid, 'store_id' => $storeId, 'status' => 'active']);
        if (!$row) {
            throw new ApiException('permanent_membership_referral_qualification_required');
        }
    }
}
