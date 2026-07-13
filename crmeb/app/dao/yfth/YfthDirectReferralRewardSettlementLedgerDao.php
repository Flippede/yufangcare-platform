<?php

namespace app\dao\yfth;

use app\dao\BaseDao;
use app\model\yfth\YfthDirectReferralRewardSettlementLedger;

class YfthDirectReferralRewardSettlementLedgerDao extends BaseDao
{
    protected function setModel(): string
    {
        return YfthDirectReferralRewardSettlementLedger::class;
    }
}
