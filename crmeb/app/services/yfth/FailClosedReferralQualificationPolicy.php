<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

class FailClosedReferralQualificationPolicy implements ReferralQualificationPolicy
{
    public function assertQualified(int $referrerUid, int $storeId): void
    {
        throw new ApiException('permanent_membership_authority_unavailable');
    }
}
