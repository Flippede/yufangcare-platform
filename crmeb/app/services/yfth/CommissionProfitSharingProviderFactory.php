<?php

namespace app\services\yfth;

use think\facade\Env;

class CommissionProfitSharingProviderFactory
{
    public function make(): CommissionProfitSharingProviderInterface
    {
        $provider = strtolower(trim((string)$this->environmentValue('YFTH_COMMISSION_PROFIT_SHARING_PROVIDER', 'yfth.commission_profit_sharing_provider', '')));
        $environment = strtolower(trim((string)$this->environmentValue('APP_ENV', 'app.app_env', Env::get('app.env', 'production'))));
        $testMode = filter_var($this->environmentValue('YFTH_COMMISSION_TEST_MODE', 'yfth.commission_test_mode', false), FILTER_VALIDATE_BOOLEAN);
        $isolated = filter_var($this->environmentValue('YFTH_REAL_FLOW_ISOLATED_DB', 'yfth.real_flow_isolated_db', false), FILTER_VALIDATE_BOOLEAN);
        if ($provider === 'mock' && $testMode && $isolated && in_array($environment, ['test', 'testing'], true)) {
            return new MockCommissionProfitSharingProvider();
        }
        return new FailClosedCommissionProfitSharingProvider();
    }

    private function environmentValue(string $name, string $configKey, $default)
    {
        $value = getenv($name);
        return $value === false ? Env::get($configKey, $default) : $value;
    }
}
