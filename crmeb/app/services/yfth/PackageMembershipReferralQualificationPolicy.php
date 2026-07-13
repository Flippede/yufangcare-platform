<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

class PackageMembershipReferralQualificationPolicy implements ReferralQualificationPolicy
{
    private $membership;

    public function __construct(PackageMembershipServices $membership = null)
    {
        $this->membership = $membership ?: app()->make(PackageMembershipServices::class);
    }

    public function assertQualified(int $referrerUid, int $storeId): void
    {
        if ($storeId <= 0) {
            throw new ApiException('permanent_membership_required');
        }
        $this->membership->assertEffectiveActive($referrerUid, $storeId);
    }
}
