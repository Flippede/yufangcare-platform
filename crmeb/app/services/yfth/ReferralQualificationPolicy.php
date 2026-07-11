<?php

namespace app\services\yfth;

interface ReferralQualificationPolicy
{
    public function assertQualified(int $referrerUid, int $storeId): void;
}
