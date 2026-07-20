<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

/** Production default until a signed WeChat adapter is explicitly configured. */
class FailClosedCommissionProfitSharingProvider implements CommissionProfitSharingProviderInterface
{
    public function registerReceiver(array $receiver): array
    {
        throw new ApiException('commission_profit_sharing_provider_not_configured');
    }

    public function createSettlement(array $batch): array
    {
        throw new ApiException('commission_profit_sharing_provider_not_configured');
    }

    public function querySettlement(array $batch): array
    {
        throw new ApiException('commission_profit_sharing_provider_not_configured');
    }

    public function createReturn(array $return, array $batch): array
    {
        throw new ApiException('commission_profit_sharing_provider_not_configured');
    }

    public function queryReturn(array $return): array
    {
        throw new ApiException('commission_profit_sharing_provider_not_configured');
    }

    public function verifyCallback(array $headers, string $rawBody): array
    {
        throw new ApiException('commission_profit_sharing_callback_provider_unavailable');
    }
}
