<?php

namespace app\services\yfth;

use think\facade\Env;

class CommissionProfitSharingProviderFactory
{
    public function make(): CommissionProfitSharingProviderInterface
    {
        $provider = strtolower(trim((string)(getenv('YFTH_COMMISSION_PROFIT_SHARING_PROVIDER') ?: Env::get('yfth.commission_profit_sharing_provider', ''))));
        $environment = strtolower(trim((string)Env::get('app.app_env', Env::get('app.env', 'production'))));
        $testMode = filter_var(getenv('YFTH_COMMISSION_TEST_MODE') ?: Env::get('yfth.commission_test_mode', false), FILTER_VALIDATE_BOOLEAN);
        $isolated = filter_var(getenv('YFTH_REAL_FLOW_ISOLATED_DB') ?: Env::get('yfth.real_flow_isolated_db', false), FILTER_VALIDATE_BOOLEAN);
        if ($provider === 'mock' && $testMode && ($isolated || in_array($environment, ['test', 'testing', 'local'], true))) {
            return new MockCommissionProfitSharingProvider();
        }
        return new FailClosedCommissionProfitSharingProvider();
    }
}
