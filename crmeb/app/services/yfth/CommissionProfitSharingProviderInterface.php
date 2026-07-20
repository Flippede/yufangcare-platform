<?php

namespace app\services\yfth;

/**
 * Boundary for a trusted B1 WeChat profit-sharing adapter.
 * Implementations own provider protocol and callback signature verification.
 */
interface CommissionProfitSharingProviderInterface
{
    public function registerReceiver(array $receiver): array;

    public function createSettlement(array $batch): array;

    public function querySettlement(array $batch): array;

    public function createReturn(array $return, array $batch): array;

    public function queryReturn(array $return): array;

    /**
     * @return array{event_id:string,type:string,status:string,batch_no:string,return_no:string,amount_cent:int,receiver_account_masked:string,message:string,raw:array}
     */
    public function verifyCallback(array $headers, string $rawBody): array;
}
