<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthDirectReferralRewardCandidate;

class YfthDirectReferralRewardCandidateDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthDirectReferralRewardCandidate::class;
    }
}
