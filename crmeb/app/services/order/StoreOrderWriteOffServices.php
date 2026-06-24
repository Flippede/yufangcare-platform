<?php

namespace app\services\order;

use app\dao\order\StoreOrderDao;
use app\services\activity\combination\StorePinkServices;
use app\services\BaseServices;
use app\services\system\store\SystemStoreStaffServices;
use app\services\user\UserServices;
use app\services\yfth\AuditEventServices;
use crmeb\exceptions\ApiException;
use think\facade\Log;

class StoreOrderWriteOffServices extends BaseServices
{
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    public function writeOffOrder(string $code, int $confirm, int $uid = 0)
    {
        $orderInfo = $this->findWriteOffOrder($code);
        $this->assertBasicWriteOffState($orderInfo, $uid);

        if ((int)$orderInfo->status === 2) {
            $this->recordWriteOffAudit($orderInfo, $uid, true);
            return $this->writeOffResponse($orderInfo, true);
        }
        $this->assertReadyForFirstWriteOff($orderInfo);

        if ($confirm === 0) {
            return $this->writeOffResponse($orderInfo);
        }

        $result = $this->transaction(function () use ($orderInfo, $uid) {
            $locked = $this->dao->search([])
                ->where('id', (int)$orderInfo['id'])
                ->lock(true)
                ->find();
            if (!$locked) {
                throw new ApiException(410173);
            }

            $this->assertBasicWriteOffState($locked, $uid);
            if ((int)$locked->status === 2) {
                return ['order' => $locked, 'repeat' => true];
            }
            $this->assertReadyForFirstWriteOff($locked);

            $locked->status = 2;
            if ($uid && (int)$locked->shipping_type === 2) {
                $locked->clerk_id = $uid;
            }
            if (!$locked->save()) {
                throw new ApiException(410272);
            }

            /** @var StoreOrderTakeServices $storeOrderTask */
            $storeOrderTask = app()->make(StoreOrderTakeServices::class);
            $taken = $storeOrderTask->storeProductOrderUserTakeDelivery($locked, false);
            if (!$taken) {
                throw new ApiException(410272);
            }
            if ((int)$locked['shipping_type'] === 2) {
                event('OrderShippingListener', ['product', $locked, 4, '', '']);
            }

            return ['order' => $locked, 'repeat' => false];
        });

        $this->recordWriteOffAudit($result['order'], $uid, (bool)$result['repeat']);
        return $this->writeOffResponse($result['order'], (bool)$result['repeat']);
    }

    private function findWriteOffOrder(string $code)
    {
        $orderInfo = $this->dao->getOne([
            ['verify_code', '=', $code],
            ['paid', '=', 1],
            ['refund_status', '=', 0],
            ['is_del', '=', 0],
            ['pid', '>=', 0],
        ]);
        if (!$orderInfo) {
            throw new ApiException(410173);
        }
        return $orderInfo;
    }

    private function assertBasicWriteOffState($orderInfo, int $uid): void
    {
        if (!$orderInfo['verify_code'] || ((int)$orderInfo->shipping_type !== 2 && $orderInfo->delivery_type !== 'send')) {
            throw new ApiException(410267);
        }

        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        if ($storeOrderRefundServices->count(['store_order_id' => $orderInfo['id'], 'refund_type' => [1, 2, 4, 5], 'is_cancel' => 0, 'is_del' => 0])) {
            throw new ApiException(410268);
        }

        if ($uid) {
            $isAuth = true;
            switch ((int)$orderInfo['shipping_type']) {
                case 1:
                    /** @var DeliveryServiceServices $deliverServiceServices */
                    $deliverServiceServices = app()->make(DeliveryServiceServices::class);
                    $isAuth = $deliverServiceServices->getCount(['uid' => $uid, 'status' => 1]) > 0;
                    break;
                case 2:
                    /** @var SystemStoreStaffServices $storeStaffServices */
                    $storeStaffServices = app()->make(SystemStoreStaffServices::class);
                    $isAuth = (bool)$storeStaffServices->assertWriteOffStoreAccess($uid, (int)$orderInfo->store_id);
                    break;
            }
            if (!$isAuth) {
                throw new ApiException(410269);
            }
        }
    }

    private function assertReadyForFirstWriteOff($orderInfo): void
    {
        if ((int)$orderInfo->shipping_type === 2 && (int)$orderInfo->status > 0) {
            throw new ApiException(410270);
        }
        if ($orderInfo->combination_id && $orderInfo->pink_id) {
            /** @var StorePinkServices $services */
            $services = app()->make(StorePinkServices::class);
            $res = $services->getCount([['id', '=', $orderInfo->pink_id], ['status', '<>', 2]]);
            if ($res) {
                throw new ApiException(410271);
            }
        }
    }

    private function writeOffResponse($orderInfo, bool $repeat = false): array
    {
        /** @var StoreOrderCartInfoServices $orderCartInfo */
        $orderCartInfo = app()->make(StoreOrderCartInfoServices::class);
        $cartIds = $orderInfo['cart_id'] ?? [];
        $cartId = is_array($cartIds) ? ($cartIds[0] ?? 0) : $cartIds;
        if ($cartId) {
            $cartInfo = $orderCartInfo->getOne([
                ['cart_id', '=', $cartId],
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

    private function recordWriteOffAudit($orderInfo, int $uid, bool $repeat): void
    {
        try {
            /** @var AuditEventServices $audit */
            $audit = app()->make(AuditEventServices::class);
            $verifyCode = (string)($orderInfo['verify_code'] ?? '');
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
                    'verify_code_hash' => $verifyCode === '' ? '' : substr(hash('sha256', $verifyCode), 0, 16),
                    'verify_code_tail' => $verifyCode === '' ? '' : substr($verifyCode, -4),
                    'is_repeat_writeoff' => $repeat ? 1 : 0,
                ],
                $uid,
                $uid ? 'store_staff' : 'admin',
                (int)$orderInfo['store_id']
            );
        } catch (\Throwable $e) {
            Log::error([
                'msg' => 'writeoff_audit_record_failed',
                'order_id' => $orderInfo['id'] ?? 0,
                'store_id' => (int)($orderInfo['store_id'] ?? 0),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
