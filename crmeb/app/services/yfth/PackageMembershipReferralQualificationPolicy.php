<?php

namespace app\services\yfth;

use app\dao\yfth\YfthPermanentMembershipDao;
use crmeb\exceptions\ApiException;

class PackageMembershipReferralQualificationPolicy implements ReferralQualificationPolicy
{
    private $membershipDao;

    public function __construct(YfthPermanentMembershipDao $membershipDao = null)
    {
        $this->membershipDao = $membershipDao ?: app()->make(YfthPermanentMembershipDao::class);
    }

    public function assertQualified(int $referrerUid, int $storeId): void
    {
        $member = $this->membershipDao->getOne([
            'uid' => $referrerUid,
            'status' => 'active',
        ]);
        if (!$member || (int)$member['store_id'] !== $storeId || $storeId <= 0) {
            throw new ApiException('permanent_membership_required');
        }
    }
}
