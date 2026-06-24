<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace app\services\order;


use app\dao\order\StoreOrderDao;
use app\services\activity\combination\StorePinkServices;
use app\services\BaseServices;
use app\services\system\store\SystemStoreStaffServices;
use app\services\user\UserServices;
use app\services\yfth\AuditEventServices;
use crmeb\exceptions\ApiException;

/**
 * 核销订单
 * Class StoreOrderWriteOffServices
 * @package app\sservices\order
 */
class StoreOrderWriteOffServices extends BaseServices
{

    /**
     * 构造方法
     * StoreOrderWriteOffServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 订单核销
     * @param string $code
     * @param int $confirm
     * @param int $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function writeOffOrder(string $code, int $confirm, int $uid = 0)
    {
        $orderInfo = $this->dao->getOne([
            ['verify_code', '=', $code],
            ['paid', '=', 1],
            ['refund_status', '=', 0],
            ['is_del', '=', 0],
            ['pid', '>=', 0]
        ]);
        if (!$orderInfo) {
            throw new ApiException(410173);
        }
        if (!$orderInfo['verify_code'] || ($orderInfo->shipping_type != 2 && $orderInfo->delivery_type != 'send')) {
            throw new ApiException(410267);
        }
        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        if ($storeOrderRefundServices->count(['store_order_id' => $orderInfo['id'], 'refund_type' => [1, 2, 4, 5], 'is_cancel' => 0, 'is_del' => 0])) {
            throw new ApiException(410268);
        }
        if ($uid) {
            $isAuth = true;
            switch ($orderInfo['shipping_type']) {
                case 1://配送订单
                    /** @var DeliveryServiceServices $deliverServiceServices */
                    $deliverServiceServices = app()->make(DeliveryServiceServices::class);
                    $isAuth = $deliverServiceServices->getCount(['uid' => $uid, 'status' => 1]) > 0;
                    break;
                case 2://自提订单
                    /** @var SystemStoreStaffServices $storeStaffServices */
                    $storeStaffServices = app()->make(SystemStoreStaffServices::class);
                    $isAuth = (bool)$storeStaffServices->assertWriteOffStoreAccess($uid, (int)$orderInfo->store_id);
                    break;
            }
            if (!$isAuth) {
                throw new ApiException(410269);
            }
        }
        if ($orderInfo->status == 2) {
            $this->recordWriteOffAudit($orderInfo, $uid, true);
            return $this->writeOffResponse($orderInfo, true);
        }
        if ($orderInfo->shipping_type == 2) {
            if ($orderInfo->status > 0) {
                throw new ApiException(410270);
            }
        }
        if ($orderInfo->combination_id && $orderInfo->pink_id) {
            /** @var StorePinkServices $services */
            $services = app()->make(StorePinkServices::class);
            $res = $services->getCount([['id', '=', $orderInfo->pink_id], ['status', '<>', 2]]);
            if ($res) throw new ApiException(410271);
        }
        if ($confirm == 0) {
            return $this->writeOffResponse($orderInfo);
        }
        $orderInfo->status = 2;
        if ($uid) {
            if ($orderInfo->shipping_type == 2) {
                $orderInfo->clerk_id = $uid;
            }
        }
        if ($orderInfo->save()) {
            /** @var StoreOrderTakeServices $storeOrderTask */
            $storeOrderTask = app()->make(StoreOrderTakeServices::class);
            $re = $storeOrderTask->storeProductOrderUserTakeDelivery($orderInfo);
            if (!$re) {
                throw new ApiException(410272);
            }
            if ($orderInfo['shipping_type'] == 2) {
                event('OrderShippingListener', ['product', $orderInfo, 4, '', '']);
            }
            $this->recordWriteOffAudit($orderInfo, $uid, false);
            return $this->writeOffResponse($orderInfo);
        } else {
            throw new ApiException(410272);
        }
    }

    /**
     * 组装核销预览/结果返回，重复核销不再进入扣减履约流程。
     * @param $orderInfo
     * @param bool $repeat
     * @return array
     */
    private function writeOffResponse($orderInfo, bool $repeat = false): array
    {
        /** @var StoreOrderCartInfoServices $orderCartInfo */
        $orderCartInfo = app()->make(StoreOrderCartInfoServices::class);
        $cartIds = $orderInfo['cart_id'] ?? [];
        $cartId = is_array($cartIds) ? ($cartIds[0] ?? 0) : $cartIds;
        if ($cartId) {
            $cartInfo = $orderCartInfo->getOne([
                ['cart_id', '=', $cartId]
            ], 'cart_info');
            if ($cartInfo) {
                $orderInfo['image'] = $cartInfo['cart_info']['productInfo']['image'] ?? '';
            }
        }

        /** @var UserServices $services */
        $services = app()->make(UserServices::class);
        $orderInfo['nickname'] = $services->value(['uid' => $orderInfo['uid']], 'nickname');
        $data = $orderInfo->toArray();
        $data['is_repeat_writeoff'] = $repeat ? 1 : 0;
        $data['writeoff_status'] = $repeat ? 'already_written_off' : 'ok';
        return $data;
    }

    /**
     * 新基础域审计表未迁移时不影响既有核销主流程。
     * @param $orderInfo
     * @param int $uid
     * @param bool $repeat
     */
    private function recordWriteOffAudit($orderInfo, int $uid, bool $repeat): void
    {
        try {
            /** @var AuditEventServices $audit */
            $audit = app()->make(AuditEventServices::class);
            $audit->record(
                'order',
                'store_order',
                (string)$orderInfo['id'],
                $repeat ? 'writeoff_repeat' : 'writeoff',
                [],
                [
                    'order_id' => $orderInfo['order_id'] ?? '',
                    'store_id' => (int)$orderInfo['store_id'],
                    'status' => (int)$orderInfo['status'],
                    'verify_code' => $orderInfo['verify_code'] ?? '',
                ],
                $uid,
                $uid ? 'store_staff' : 'admin',
                (int)$orderInfo['store_id']
            );
        } catch (\Throwable $e) {
        }
    }
}
