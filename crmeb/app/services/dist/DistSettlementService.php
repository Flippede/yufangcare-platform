<?php
namespace app\services\dist;

use think\facade\Db;

class DistSettlementService
{
    /** 订单支付成功：登记待结算 */
    public function onOrderPaid(array $order): void
    {
        // 只跑直推 30%（最小闭环）；见单/平级后续再加
        $cfg = Db::table('eb_dist_config')->where('id', 1)->find();
        if (!$cfg) return;

        $sponsorUid = Db::table('eb_user_dist')->where('uid', $order['uid'])->value('sponsor_uid');
        if (!$sponsorUid) return;

        $amount = round($order['pay_price'] * (float)$cfg['direct_rate'], 2);
        if ($amount <= 0) return;

        Db::table('eb_dist_commission')->insert([
            'order_id'        => $order['id'],
            'payer_uid'       => $order['uid'],
            'beneficiary_uid' => $sponsorUid,
            'type'            => 'direct',
            'rate'            => $cfg['direct_rate'],
            'amount'          => $amount,
            'status'          => 'pending',
            'remark'          => 'direct on paid',
            'created_at'      => date('Y-m-d H:i:s')
        ]);
    }

    /** 订单完成：pending -> released（本步先不做冻结） */
    public function onOrderFinished(array $order): void
    {
        Db::table('eb_dist_commission')
            ->where(['order_id' => $order['id'], 'status' => 'pending'])
            ->update([
                'status'     => 'released',
                'updated_at' => date('Y-m-d H:i:s'),
                'remark'     => Db::raw("CASE WHEN remark IS NULL OR remark='' THEN 'released on finished' ELSE CONCAT(remark,'; released') END")
            ]);
    }

    /** 售后关闭（退款等）：按需冲减或作废 */
    public function onAfterSaleClosed(array $order, float $refundAmount = 0): void
    {
        // 先留空，等你跑通直推后再实现部分退等比冲减
    }
}
