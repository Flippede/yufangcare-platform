<?php

namespace app\services\yfth;

use app\dao\yfth\YfthPackageAgreementSnapshotDao;
use app\dao\yfth\YfthPackagePurchaseBenefitSnapshotDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use app\dao\yfth\YfthPackagePurchaseIntentDao;
use app\dao\yfth\YfthPackagePurchaseSnapshotDao;
use app\dao\yfth\YfthStoreCapabilityDao;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\user\UserServices;
use crmeb\exceptions\ApiException;

class PackagePurchaseServices extends PackageBenefitBaseServices
{
    public function __construct(YfthPackagePurchaseDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'template_id' => (int)($where['template_id'] ?? 0) ?: '',
            'purchase_status' => $where['purchase_status'] ?? '',
            'activation_status' => $where['activation_status'] ?? '',
            'order_sn' => $where['order_sn'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->formatPurchase($row);
        });
    }

    public function createIntent(int $uid, array $data): array
    {
        $data = $this->fillDefaultBinding($data);
        $data['agreement_accepted'] = 1;
        $validation = $this->validatePurchaseRequest($uid, $data);

        /** @var YfthPackagePurchaseIntentDao $intentDao */
        $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
        return $this->transaction(function () use ($intentDao, $uid, $data, $validation) {
            $agreement = $this->createAgreementSnapshot($uid, $validation, $data);
            $intentNo = $this->makeNo('YFINT');
            $row = $this->withTimestamps([
                'intent_no' => $intentNo,
                'uid' => $uid,
                'store_id' => (int)$validation['store']['store_id'],
                'template_id' => (int)$validation['template']['id'],
                'rule_version_id' => (int)$validation['rule']['id'],
                'product_id' => (int)$validation['product']['product_id'],
                'product_attr_unique' => (string)$validation['product']['product_attr_unique'],
                'agreement_snapshot_id' => (int)$agreement['id'],
                'expected_pay_price' => $validation['rule']['package_price'],
                'month_count' => (int)$validation['rule']['month_count'],
                'benefit_hash' => (string)$validation['rule']['benefit_hash'],
                'status' => 'created',
                'order_id' => 0,
                'order_sn' => '',
                'creating_started_at' => 0,
                'creating_request_id' => '',
                'bound_order_id' => 0,
                'bound_order_sn' => '',
                'purchase_id' => 0,
                'expires_at' => time() + 1800,
                'source' => trim((string)($data['source'] ?? 'mobile')) ?: 'mobile',
                'validation_snapshot' => $this->jsonEncode($validation),
                'fail_reason' => '',
                'last_error_code' => '',
                'last_error_message' => '',
                'retry_count' => 0,
                'orphan_order_id' => 0,
                'orphan_order_sn' => '',
                'orphan_close_status' => '',
                'orphan_close_error' => '',
            ], true);
            $intent = $intentDao->save($row);
            $row['id'] = (int)$intent->id;
            $this->recordPackageAudit('package_purchase_intent', (string)$intent->id, 'create', [], $row, $uid, 'customer', (int)$row['store_id']);
            return $this->formatIntent($row);
        });
    }

    public function createOrderFromIntent(int $uid, string $intentNo, array $data): array
    {
        /** @var YfthPackagePurchaseIntentDao $intentDao */
        $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
        $requestId = trim((string)($data['request_id'] ?? '')) ?: $this->makeNo('YFREQ');
        $claim = $this->claimIntentForOrder($intentDao, $uid, $intentNo, $requestId);
        $intentRow = $claim['intent'];
        if ($claim['state'] === 'bound') {
            return $this->intentBoundPayload($intentRow);
        }
        if ($claim['state'] === 'creating') {
            return $this->intentCreatingPayload($intentRow);
        }

        $validation = $this->jsonDecode($intentRow['validation_snapshot'] ?? '');
        if (!$validation) {
            $this->markIntentFailed($intentDao, $intentRow, $requestId, 'package_purchase_intent_snapshot_missing', 'package_purchase_intent_snapshot_missing');
            throw new ApiException('package_purchase_intent_snapshot_missing');
        }

        try {
            $order = $this->createCrmebOrderFromSnapshot($uid, $intentRow, $validation, $data);
        } catch (\Throwable $e) {
            $this->markIntentFailed($intentDao, $intentRow, $requestId, $this->errorCode($e->getMessage()), $e->getMessage());
            throw $e;
        }

        try {
            $purchase = $this->bindCreatedOrderToIntent($intentDao, $intentRow, $validation, $order, $uid, $data, $requestId);
        } catch (\Throwable $e) {
            $this->markIntentFailed($intentDao, $intentRow, $requestId, $this->errorCode($e->getMessage()), $e->getMessage(), $order);
            $this->compensateUnboundPackageOrder($intentDao, $intentRow, $order, $uid, $requestId, $e->getMessage());
            throw $e;
        }

        return [
            'intent' => $this->formatIntent(array_merge($intentRow, [
                'status' => 'bound',
                'order_id' => (int)$order['id'],
                'order_sn' => (string)$order['order_id'],
                'bound_order_id' => (int)$order['id'],
                'bound_order_sn' => (string)$order['order_id'],
                'purchase_id' => (int)$purchase['id'],
                'creating_request_id' => $requestId,
            ])),
            'purchase' => $purchase,
            'order' => [
                'order_id' => (string)$order['order_id'],
                'store_order_id' => (int)$order['id'],
                'pay_price' => $this->normalizeMoney($order['pay_price'] ?? '0.00'),
                'paid' => (int)($order['paid'] ?? 0),
            ],
            'pay' => [
                'order_id' => (string)$order['order_id'],
                'total_price' => $this->normalizeMoney($order['pay_price'] ?? '0.00'),
                'pay_weixin_open' => $data['pay_weixin_open'] ?? null,
            ],
        ];
    }

    private function claimIntentForOrder(YfthPackagePurchaseIntentDao $intentDao, int $uid, string $intentNo, string $requestId): array
    {
        return $this->transaction(function () use ($intentDao, $uid, $intentNo, $requestId) {
            $locked = $intentDao->search([])->where('intent_no', $intentNo)->lock(true)->find();
            $row = $this->requireRow($locked, 'package_purchase_intent_not_found');
            if ((int)$row['uid'] !== $uid) {
                throw new ApiException('package_purchase_intent_forbidden');
            }

            if ((int)($row['purchase_id'] ?? 0) > 0 || (string)($row['status'] ?? '') === 'bound') {
                return ['state' => 'bound', 'intent' => $row];
            }

            $status = (string)($row['status'] ?? '');
            if ((int)($row['expires_at'] ?? 0) > 0 && (int)$row['expires_at'] < time() && in_array($status, ['created', 'failed'], true)) {
                $update = [
                    'status' => 'expired',
                    'last_error_code' => 'package_purchase_intent_expired',
                    'last_error_message' => 'package_purchase_intent_expired',
                    'fail_reason' => 'package_purchase_intent_expired',
                    'update_time' => time(),
                ];
                $intentDao->update((int)$row['id'], $update);
                throw new ApiException('package_purchase_intent_expired');
            }

            if ($status === 'creating') {
                return ['state' => 'creating', 'intent' => $row];
            }
            if (in_array($status, ['expired', 'cancelled', 'canceled'], true)) {
                throw new ApiException('package_purchase_intent_' . ($status === 'canceled' ? 'cancelled' : $status));
            }
            if (!in_array($status, ['created', 'failed'], true)) {
                throw new ApiException('package_purchase_intent_not_available');
            }
            if ($status === 'failed'
                && (int)($row['orphan_order_id'] ?? 0) > 0
                && !in_array((string)($row['orphan_close_status'] ?? ''), ['cancelled'], true)) {
                throw new ApiException('package_purchase_intent_orphan_order_pending');
            }

            $now = time();
            $update = [
                'status' => 'creating',
                'creating_started_at' => $now,
                'creating_request_id' => $requestId,
                'last_error_code' => '',
                'last_error_message' => '',
                'fail_reason' => '',
                'retry_count' => (int)($row['retry_count'] ?? 0) + 1,
                'update_time' => $now,
            ];
            $intentDao->update((int)$row['id'], $update);
            return ['state' => 'claimed', 'intent' => array_merge($row, $update)];
        });
    }

    private function bindCreatedOrderToIntent(
        YfthPackagePurchaseIntentDao $intentDao,
        array $intentRow,
        array $validation,
        array $order,
        int $uid,
        array $data,
        string $requestId
    ): array {
        return $this->transaction(function () use ($intentDao, $intentRow, $validation, $order, $uid, $data, $requestId) {
            /** @var YfthPackagePurchaseDao $purchaseDao */
            $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
            $lockedIntent = $intentDao->search([])->where('id', (int)$intentRow['id'])->lock(true)->find();
            $lockedIntentRow = $this->requireRow($lockedIntent, 'package_purchase_intent_not_found');
            if ((int)($lockedIntentRow['purchase_id'] ?? 0) > 0 || (string)($lockedIntentRow['status'] ?? '') === 'bound') {
                throw new ApiException('package_purchase_intent_already_bound');
            }
            if ((string)($lockedIntentRow['status'] ?? '') !== 'creating'
                || (string)($lockedIntentRow['creating_request_id'] ?? '') !== $requestId) {
                throw new ApiException('package_purchase_intent_creation_claim_lost');
            }

            $existing = $this->findPurchaseByOrderIdentity((int)$order['id'], (string)$order['order_id']);
            if ($existing) {
                if ((int)$existing['uid'] !== $uid) {
                    throw new ApiException('order_already_bound_to_other_user');
                }
                $intentDao->update((int)$lockedIntentRow['id'], [
                    'status' => 'bound',
                    'order_id' => (int)$existing['order_id'],
                    'order_sn' => (string)$existing['order_sn'],
                    'bound_order_id' => (int)$existing['order_id'],
                    'bound_order_sn' => (string)$existing['order_sn'],
                    'purchase_id' => (int)$existing['id'],
                    'last_error_code' => '',
                    'last_error_message' => '',
                    'fail_reason' => '',
                    'update_time' => time(),
                ]);
                return $this->formatPurchase($existing);
            }

            $purchaseRow = $this->buildPurchasePayload($uid, $validation, [
                'id' => (int)$order['id'],
                'order_id' => (string)$order['order_id'],
                'pay_price' => $this->normalizeMoney($order['pay_price'] ?? '0.00'),
                'paid' => (int)($order['paid'] ?? 0),
                'store_id' => (int)($order['store_id'] ?? 0),
            ], (int)$lockedIntentRow['agreement_snapshot_id'], (int)$lockedIntentRow['id'], $data);
            $purchaseRow = $this->savePurchaseResolvingOrderConflict($purchaseDao, $purchaseRow, $uid);
            if (!empty($purchaseRow['_existing'])) {
                $intentDao->update((int)$lockedIntentRow['id'], [
                    'status' => 'bound',
                    'order_id' => (int)$purchaseRow['order_id'],
                    'order_sn' => (string)$purchaseRow['order_sn'],
                    'bound_order_id' => (int)$purchaseRow['order_id'],
                    'bound_order_sn' => (string)$purchaseRow['order_sn'],
                    'purchase_id' => (int)$purchaseRow['id'],
                    'last_error_code' => '',
                    'last_error_message' => '',
                    'fail_reason' => '',
                    'update_time' => time(),
                ]);
                return $this->formatPurchase($purchaseRow);
            }
            $snapshotId = $this->createPurchaseSnapshots($purchaseRow, $validation, $order, $lockedIntentRow);
            $purchaseDao->update((int)$purchaseRow['id'], ['snapshot_id' => $snapshotId, 'update_time' => time()]);
            $purchaseRow['snapshot_id'] = $snapshotId;
            $intentDao->update((int)$lockedIntentRow['id'], [
                'status' => 'bound',
                'order_id' => (int)$order['id'],
                'order_sn' => (string)$order['order_id'],
                'bound_order_id' => (int)$order['id'],
                'bound_order_sn' => (string)$order['order_id'],
                'purchase_id' => (int)$purchaseRow['id'],
                'last_error_code' => '',
                'last_error_message' => '',
                'fail_reason' => '',
                'orphan_order_id' => 0,
                'orphan_order_sn' => '',
                'orphan_close_status' => '',
                'orphan_close_error' => '',
                'update_time' => time(),
            ]);
            $this->recordPackageAudit('package_purchase', (string)$purchaseRow['id'], 'bind_order', [], $purchaseRow, $uid, 'customer', (int)$purchaseRow['store_id'], '', $requestId);
            return $this->formatPurchase($purchaseRow);
        });
    }

    private function markIntentFailed(
        YfthPackagePurchaseIntentDao $intentDao,
        array $intent,
        string $requestId,
        string $errorCode,
        string $message,
        array $order = []
    ): void {
        $safeCode = substr($errorCode ?: 'package_purchase_intent_failed', 0, 64);
        $safeMessage = substr($message ?: $safeCode, 0, 255);
        $update = [
            'status' => 'failed',
            'last_error_code' => $safeCode,
            'last_error_message' => $safeMessage,
            'fail_reason' => $safeMessage,
            'update_time' => time(),
        ];
        if (!empty($order['id'])) {
            $update['orphan_order_id'] = (int)$order['id'];
            $update['orphan_order_sn'] = (string)($order['order_id'] ?? '');
            $update['orphan_close_status'] = 'pending';
            $update['orphan_close_error'] = '';
        }
        $updated = $intentDao->search([])
            ->where('id', (int)$intent['id'])
            ->where('status', 'creating')
            ->where('creating_request_id', $requestId)
            ->update($update);
        if ($updated) {
            $this->recordPackageAudit('package_purchase_intent', (string)$intent['id'], 'create_order_failed', $intent, $update, (int)$intent['uid'], 'customer', (int)$intent['store_id'], $safeCode, $requestId);
        }
    }

    private function compensateUnboundPackageOrder(
        YfthPackagePurchaseIntentDao $intentDao,
        array $intent,
        array $order,
        int $uid,
        string $requestId,
        string $reason,
        int $operatorUid = 0
    ): array {
        $orderId = (int)($order['id'] ?? 0);
        $orderSn = (string)($order['order_id'] ?? '');
        if ($orderId <= 0 || $orderSn === '') {
            return ['status' => 'skipped', 'reason' => 'missing_order_identity'];
        }
        if ($this->findPurchaseByOrderIdentity($orderId, $orderSn)) {
            return ['status' => 'skipped', 'reason' => 'order_already_bound'];
        }

        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        try {
            $current = $this->requireRow($orderServices->get($orderId), 'order_not_found');
            if ((int)($current['paid'] ?? 0) === 1) {
                $status = 'pending_manual_paid';
                $error = 'orphan_order_already_paid';
            } elseif ((int)($current['is_cancel'] ?? 0) === 1 || (int)($current['is_del'] ?? 0) === 1) {
                $status = 'cancelled';
                $error = '';
            } else {
                $orderServices->cancelOrder($orderSn, $uid);
                $status = 'cancelled';
                $error = '';
            }
        } catch (\Throwable $e) {
            $status = 'pending_manual';
            $error = substr($e->getMessage(), 0, 255);
        }

        $update = [
            'orphan_order_id' => $orderId,
            'orphan_order_sn' => $orderSn,
            'orphan_close_status' => $status,
            'orphan_close_error' => $error,
            'update_time' => time(),
        ];
        $intentDao->update((int)$intent['id'], $update);
        $this->recordPackageAudit('package_purchase_intent', (string)$intent['id'], 'orphan_order_compensate', $intent, array_merge($update, [
            'reason' => substr($reason, 0, 255),
        ]), $operatorUid ?: $uid, $operatorUid > 0 ? 'admin' : 'system', (int)$intent['store_id'], $status, $requestId);

        return ['status' => $status, 'error' => $error, 'order_id' => $orderId, 'order_sn' => $orderSn];
    }

    public function createPurchase(int $uid, array $data): array
    {
        $validation = $this->validatePurchaseRequest($uid, $data);

        $lastException = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return $this->transaction(function () use ($uid, $data, $validation) {
                    if (!empty($validation['order']['order_id'])) {
                        $existing = $this->findPurchaseByOrderIdentity((int)$validation['order']['order_id'], (string)($validation['order']['order_sn'] ?? ''));
                        if ($existing) {
                            if ((int)$existing['uid'] !== $uid) {
                                throw new ApiException('order_already_bound_to_other_user');
                            }
                            return $this->formatPurchase($existing);
                        }
                    }

                    $agreement = $this->createAgreementSnapshot($uid, $validation, $data);
                    $payload = $this->buildPurchasePayload($uid, $validation, $validation['order'] ?? [], (int)$agreement['id'], 0, $data);
                    $row = $this->savePurchaseResolvingOrderConflict($this->dao, $payload, $uid);
                    if (!empty($row['_existing'])) {
                        return $this->formatPurchase($row);
                    }
                    if ((int)$row['order_id'] > 0) {
                        $snapshotId = $this->createPurchaseSnapshots($row, $validation, [
                            'id' => (int)$row['order_id'],
                            'order_id' => (string)$row['order_sn'],
                            'pay_price' => $row['order_pay_price'],
                        ], []);
                        $this->dao->update((int)$row['id'], ['snapshot_id' => $snapshotId, 'update_time' => time()]);
                        $row['snapshot_id'] = $snapshotId;
                    }
                    $this->recordPackageAudit('package_purchase', (string)$row['id'], 'create', [], $row, $uid, 'customer', (int)$payload['store_id']);
                    return $this->formatPurchase($row);
                });
            } catch (\Throwable $e) {
                if (!$this->isRetryableOrderWriteConflict($e) || empty($validation['order']['order_id'])) {
                    throw $e;
                }
                $lastException = $e;
                $existing = $this->findPurchaseByOrderIdentity((int)$validation['order']['order_id'], (string)($validation['order']['order_sn'] ?? ''));
                if ($existing) {
                    if ((int)$existing['uid'] !== $uid) {
                        throw new ApiException('order_already_bound_to_other_user');
                    }
                    return $this->formatPurchase($existing);
                }
                usleep(50000 * ($attempt + 1));
            }
        }

        throw $lastException ?: new ApiException('package_purchase_retry_exhausted');
    }

    public function scanUnboundPackageIntentOrders(int $limit = 50, bool $close = false, int $operatorUid = 0): array
    {
        $limit = max(1, min($limit, 200));
        /** @var YfthPackagePurchaseIntentDao $intentDao */
        $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
        $rows = $intentDao->search([])
            ->where('orphan_order_id', '>', 0)
            ->whereIn('orphan_close_status', ['', 'pending', 'pending_manual', 'failed', 'cancel_failed'])
            ->order('id asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $result = [
            'scanned' => count($rows),
            'payable_orphans' => 0,
            'closed' => 0,
            'pending_manual' => 0,
            'items' => [],
        ];
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        foreach ($rows as $row) {
            $order = $orderServices->get((int)$row['orphan_order_id']);
            $orderRow = $order ? (is_array($order) ? $order : $order->toArray()) : [];
            $purchase = $this->findPurchaseByOrderIdentity((int)($row['orphan_order_id'] ?? 0), (string)($row['orphan_order_sn'] ?? ''));
            $payable = $orderRow
                && !$purchase
                && (int)($orderRow['paid'] ?? 0) === 0
                && (int)($orderRow['is_cancel'] ?? 0) === 0
                && (int)($orderRow['is_del'] ?? 0) === 0;
            if ($payable) {
                $result['payable_orphans']++;
            }
            $item = [
                'intent_id' => (int)$row['id'],
                'intent_no' => (string)$row['intent_no'],
                'order_id' => (int)($row['orphan_order_id'] ?? 0),
                'order_sn' => (string)($row['orphan_order_sn'] ?? ''),
                'payable' => $payable,
                'has_purchase' => (bool)$purchase,
                'close_status' => (string)($row['orphan_close_status'] ?? ''),
            ];
            if ($close && $payable) {
                $closeResult = $this->compensateUnboundPackageOrder($intentDao, $row, [
                    'id' => (int)$row['orphan_order_id'],
                    'order_id' => (string)$row['orphan_order_sn'],
                ], (int)$row['uid'], 'scan-orphan-orders', 'manual scan close', $operatorUid);
                $item['close_result'] = $closeResult;
                if (($closeResult['status'] ?? '') === 'cancelled') {
                    $result['closed']++;
                } else {
                    $result['pending_manual']++;
                }
            } elseif (!$payable && !$purchase) {
                $result['pending_manual']++;
            }
            $result['items'][] = $item;
        }
        return $result;
    }

    public function purchaseStatus(int $uid, string $purchaseNo): array
    {
        $purchase = $this->dao->getOne(['purchase_no' => $purchaseNo]);
        $row = $this->requireRow($purchase, 'package_purchase_not_found');
        if ((int)$row['uid'] !== $uid) {
            throw new ApiException('package_purchase_forbidden');
        }
        return $this->formatPurchase($row);
    }

    public function agreementRecord(int $uid, string $purchaseNo): array
    {
        $purchase = $this->purchaseStatus($uid, $purchaseNo);
        /** @var YfthPackageAgreementSnapshotDao $agreementDao */
        $agreementDao = app()->make(YfthPackageAgreementSnapshotDao::class);
        $agreement = $agreementDao->get((int)$purchase['agreement_snapshot_id']);
        return $this->requireRow($agreement, 'agreement_snapshot_not_found');
    }

    public function serviceStores(int $templateId): array
    {
        /** @var PackageTemplateServices $templateServices */
        $templateServices = app()->make(PackageTemplateServices::class);
        $templateServices->requirePublishedTemplate($templateId);

        /** @var YfthStoreCapabilityDao $capabilityDao */
        $capabilityDao = app()->make(YfthStoreCapabilityDao::class);
        $rows = $capabilityDao->selectList([
            'capability_code' => 'package_sale',
            'status' => 'active',
        ], '*', 0, 0, 'store_id asc,id desc', [], false)->toArray();

        $stores = [];
        foreach ($rows as $row) {
            $storeId = (int)$row['store_id'];
            if (isset($stores[$storeId])) {
                continue;
            }
            try {
                $store = app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
                $this->assertStoreReadyForPackage($storeId);
                $stores[$storeId] = $store;
            } catch (\Throwable $e) {
                continue;
            }
        }
        return array_values($stores);
    }

    public function validatePurchaseRequest(int $uid, array $data): array
    {
        if ($uid <= 0) {
            throw new ApiException('user_login_required');
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $this->requireRow($userServices->get($uid), 'user_not_found');
        if (trim((string)($user['phone'] ?? '')) === '') {
            throw new ApiException('user_phone_binding_required');
        }

        $templateId = (int)($data['template_id'] ?? 0);
        $storeId = (int)($data['store_id'] ?? 0);
        $productId = (int)($data['product_id'] ?? 0);
        $skuUnique = trim((string)($data['product_attr_unique'] ?? ''));
        if ($templateId <= 0 || $storeId <= 0 || $productId <= 0 || $skuUnique === '') {
            throw new ApiException('template_store_product_and_sku_required');
        }

        /** @var PackageTemplateServices $templateServices */
        $templateServices = app()->make(PackageTemplateServices::class);
        $template = $templateServices->requirePublishedTemplate($templateId);
        $rule = $templateServices->currentRule($templateId);
        $clientRuleVersionId = (int)($data['rule_version_id'] ?? 0);
        if ($clientRuleVersionId > 0 && $clientRuleVersionId !== (int)$rule['id']) {
            throw new ApiException('client_rule_version_mismatch');
        }
        if (isset($data['client_month_count']) && (int)$data['client_month_count'] !== (int)$rule['month_count']) {
            throw new ApiException('client_month_count_mismatch');
        }
        if (isset($data['client_price']) && !$this->moneyEquals($data['client_price'], $rule['package_price'])) {
            throw new ApiException('client_price_mismatch');
        }
        $benefitHash = $templateServices->benefitHash((int)$rule['id']);
        $benefits = $templateServices->ruleBenefits((int)$rule['id']);
        if (!empty($data['client_benefit_hash']) && !hash_equals($benefitHash, (string)$data['client_benefit_hash'])) {
            throw new ApiException('client_benefit_hash_mismatch');
        }

        $binding = $templateServices->activeBinding((int)$template['id'], (int)$rule['id'], $productId, $skuUnique);
        [$product, $sku] = $templateServices->assertProductSkuActive($productId, $skuUnique);
        if (!$this->moneyEquals($sku['price'], $rule['package_price']) || !$this->moneyEquals($binding['sku_price_snapshot'], $rule['package_price'])) {
            throw new ApiException('package_sku_price_mismatch');
        }

        $store = app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        $storeChecks = $this->assertStoreReadyForPackage($storeId);

        $order = [];
        $orderSn = trim((string)($data['order_sn'] ?? ''));
        if ($orderSn !== '') {
            $order = $this->assertOrderMatchesPackage($uid, $orderSn, $storeId, $productId, $skuUnique, $rule);
        }

        if (empty($data['agreement_accepted'])) {
            throw new ApiException('package_agreement_must_be_accepted');
        }

        return [
            'user' => [
                'uid' => $uid,
                'phone_masked' => $this->maskPhone((string)$user['phone']),
            ],
            'template' => [
                'id' => (int)$template['id'],
                'package_code' => $template['package_code'],
                'package_name' => $template['package_name'],
                'package_title' => $template['package_title'],
                'package_type' => $template['package_type'],
                'currency' => $template['currency'],
            ],
            'rule' => [
                'id' => (int)$rule['id'],
                'version_no' => (int)$rule['version_no'],
                'package_price' => $this->normalizeMoney($rule['package_price']),
                'month_count' => (int)$rule['month_count'],
                'benefit_hash' => $benefitHash,
                'agreement_title' => $rule['agreement_title'],
                'agreement_content_summary' => $rule['agreement_content_summary'],
                'agreement_content_hash' => $rule['agreement_content_hash'],
            ],
            'product' => [
                'product_id' => (int)$product['id'],
                'product_name' => (string)$product['store_name'],
                'product_attr_unique' => $skuUnique,
                'sku_name' => (string)($sku['suk'] ?? ''),
                'sku_price' => $this->normalizeMoney($sku['price']),
                'binding_id' => (int)$binding['id'],
            ],
            'store' => $store,
            'store_checks' => $storeChecks,
            'payment_route' => $storeChecks['payment_route'],
            'benefits' => $benefits,
            'order' => $order,
        ];
    }

    private function createAgreementSnapshot(int $uid, array $validation, array $data): array
    {
        /** @var YfthPackageAgreementSnapshotDao $agreementDao */
        $agreementDao = app()->make(YfthPackageAgreementSnapshotDao::class);
        $row = $this->withTimestamps([
            'uid' => $uid,
            'store_id' => (int)$validation['store']['store_id'],
            'template_id' => (int)$validation['template']['id'],
            'rule_version_id' => (int)$validation['rule']['id'],
            'template_version' => (int)$validation['rule']['version_no'],
            'agreement_title' => (string)$validation['rule']['agreement_title'],
            'content_summary' => (string)$validation['rule']['agreement_content_summary'],
            'content_hash' => (string)$validation['rule']['agreement_content_hash'],
            'source' => trim((string)($data['source'] ?? 'mobile')) ?: 'mobile',
            'ip' => app()->request ? app()->request->ip() : '',
            'user_agent' => app()->request ? substr((string)app()->request->header('user-agent', ''), 0, 255) : '',
            'accepted_time' => time(),
        ], true);
        $saved = $agreementDao->save($row);
        $row['id'] = (int)$saved->id;
        return $row;
    }

    private function assertStoreReadyForPackage(int $storeId): array
    {
        /** @var StoreCapabilityServices $capabilityServices */
        $capabilityServices = app()->make(StoreCapabilityServices::class);
        foreach (['package_sale', 'online_payment'] as $capability) {
            if (!$capabilityServices->isAvailable($storeId, $capability)) {
                throw new ApiException('store_capability_missing:' . $capability);
            }
        }

        /** @var StoreSubjectServices $storeSubjectServices */
        $storeSubjectServices = app()->make(StoreSubjectServices::class);
        $subjects = $storeSubjectServices->listActiveByStore($storeId);
        $roles = array_values(array_unique(array_column($subjects, 'subject_role')));
        $subjectIds = [];
        foreach ($subjects as $subject) {
            $subjectIds[(string)$subject['subject_role']] = (int)$subject['subject_id'];
        }
        foreach (['sales', 'payment', 'fulfillment', 'refund'] as $role) {
            if (!in_array($role, $roles, true)) {
                throw new ApiException('store_subject_role_missing:' . $role);
            }
        }

        /** @var StorePaymentRouteServices $routeServices */
        $routeServices = app()->make(StorePaymentRouteServices::class);
        $route = $routeServices->resolveRoute($storeId, 'package_5980');

        return [
            'capabilities' => ['package_sale', 'online_payment'],
            'subject_roles' => $roles,
            'subject_ids' => $subjectIds,
            'subjects' => $subjects,
            'payment_route' => $route,
        ];
    }

    private function assertOrderMatchesPackage(int $uid, string $orderSn, int $storeId, int $productId, string $skuUnique, array $rule): array
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $this->requireRow($orderServices->getOne(['order_id' => $orderSn]), 'order_not_found');
        if ((int)$order['uid'] !== $uid) {
            throw new ApiException('order_user_mismatch');
        }
        if ((int)($order['paid'] ?? 0) !== 0) {
            throw new ApiException('order_already_paid_before_package_binding');
        }
        if ((int)($order['is_del'] ?? 0) !== 0 || (int)($order['is_cancel'] ?? 0) !== 0) {
            throw new ApiException('order_not_payable');
        }
        if ((int)($order['store_id'] ?? 0) > 0 && (int)$order['store_id'] !== $storeId) {
            throw new ApiException('order_store_mismatch');
        }
        if (!$this->moneyEquals($order['pay_price'], $rule['package_price'])) {
            throw new ApiException('order_pay_price_mismatch');
        }

        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfos = $cartInfoServices->getOrderCartInfo((int)$order['id']);
        $matched = false;
        foreach ($cartInfos as $item) {
            $cart = $item['cart_info'] ?? [];
            $cartProductId = (int)($cart['productInfo']['id'] ?? $item['product_id'] ?? 0);
            $cartSkuUnique = (string)($cart['productInfo']['attrInfo']['unique'] ?? $cart['attrInfo']['unique'] ?? $cart['productAttrUnique'] ?? '');
            if ($cartProductId === $productId && $cartSkuUnique === $skuUnique) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new ApiException('order_product_sku_mismatch');
        }

        return [
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['order_id'],
            'pay_price' => $this->normalizeMoney($order['pay_price']),
            'paid' => (int)$order['paid'],
            'store_id' => (int)$order['store_id'],
        ];
    }

    private function fillDefaultBinding(array $data): array
    {
        if ((int)($data['product_id'] ?? 0) > 0 && trim((string)($data['product_attr_unique'] ?? '')) !== '') {
            return $data;
        }
        $templateId = (int)($data['template_id'] ?? 0);
        if ($templateId <= 0) {
            return $data;
        }
        /** @var PackageTemplateServices $templateServices */
        $templateServices = app()->make(PackageTemplateServices::class);
        $rule = $templateServices->currentRule($templateId);
        $binding = $templateServices->firstActiveBinding($templateId, (int)$rule['id']);
        $data['product_id'] = (int)$binding['product_id'];
        $data['product_attr_unique'] = (string)$binding['product_attr_unique'];
        $data['rule_version_id'] = (int)$rule['id'];
        $data['client_price'] = $this->normalizeMoney($rule['package_price']);
        $data['client_month_count'] = (int)$rule['month_count'];
        $data['client_benefit_hash'] = $templateServices->benefitHash((int)$rule['id']);
        return $data;
    }

    private function createCrmebOrderFromSnapshot(int $uid, array $intent, array $validation, array $data): array
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $this->requireRow($userServices->get($uid), 'user_not_found');
        /** @var StoreCartServices $cartServices */
        $cartServices = app()->make(StoreCartServices::class);
        $cartKey = $cartServices->setCart(
            $uid,
            (int)$validation['product']['product_id'],
            1,
            (string)$validation['product']['product_attr_unique'],
            0,
            true
        );

        $shippingType = (int)($data['shipping_type'] ?? 2);
        $addressId = (int)($data['address_id'] ?? 0);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $confirm = $orderServices->getOrderConfirmData($user, $cartKey, true, $addressId, $shippingType, 0);
        $confirmPrice = $this->normalizeMoney($confirm['priceGroup']['totalPrice'] ?? '0.00');
        if (!$this->moneyEquals($confirmPrice, $intent['expected_pay_price'])) {
            throw new ApiException('package_order_price_snapshot_mismatch');
        }

        /** @var StoreOrderCreateServices $createServices */
        $createServices = app()->make(StoreOrderCreateServices::class);
        $payType = trim((string)($data['pay_type'] ?? 'weixin')) ?: 'weixin';
        $realName = trim((string)($data['real_name'] ?? ($user['real_name'] ?? '')));
        $phone = trim((string)($data['phone'] ?? ($user['phone'] ?? '')));
        $order = $createServices->createOrder(
            $uid,
            (string)$confirm['orderKey'],
            $user,
            $addressId,
            $payType,
            false,
            0,
            '',
            0,
            0,
            0,
            0,
            $shippingType,
            $realName,
            $phone,
            (int)$validation['store']['store_id'],
            true,
            0,
            [],
            0,
            0,
            ''
        );
        $order = is_array($order) ? $order : $order->toArray();
        if (!$this->moneyEquals($order['pay_price'] ?? '0.00', $intent['expected_pay_price'])) {
            throw new ApiException('package_order_pay_price_mismatch');
        }
        return $order;
    }

    private function buildPurchasePayload(int $uid, array $validation, array $order, int $agreementSnapshotId, int $intentId, array $data): array
    {
        $orderId = (int)($order['store_order_id'] ?? $order['id'] ?? 0);
        if ($orderId <= 0 && isset($order['order_id']) && is_numeric($order['order_id'])) {
            $orderId = (int)$order['order_id'];
        }
        $orderSn = trim((string)($order['order_sn'] ?? ''));
        if ($orderSn === '' && isset($order['order_id']) && !is_numeric($order['order_id'])) {
            $orderSn = (string)$order['order_id'];
        }
        $purchaseStatus = $orderId > 0 ? 'wait_pay' : 'created';
        return $this->withTimestamps([
            'purchase_no' => $this->makeNo('YFP'),
            'uid' => $uid,
            'store_id' => (int)$validation['store']['store_id'],
            'template_id' => (int)$validation['template']['id'],
            'rule_version_id' => (int)$validation['rule']['id'],
            'product_id' => (int)$validation['product']['product_id'],
            'product_attr_unique' => (string)$validation['product']['product_attr_unique'],
            'order_id' => $orderId,
            'order_sn' => $orderSn,
            'expected_pay_price' => $validation['rule']['package_price'],
            'order_pay_price' => $this->normalizeMoney($order['pay_price'] ?? '0.00'),
            'payment_scene' => 'package_5980',
            'route_snapshot' => $this->jsonEncode($validation['payment_route']),
            'agreement_snapshot_id' => $agreementSnapshotId,
            'validation_snapshot' => $this->jsonEncode($validation),
            'purchase_status' => $purchaseStatus,
            'activation_status' => 'pending',
            'instance_id' => 0,
            'idempotency_key' => $orderId > 0 ? 'package_activate:' . $orderId : '',
            'source' => trim((string)($data['source'] ?? 'mobile')) ?: 'mobile',
            'intent_id' => $intentId,
            'snapshot_id' => 0,
            'order_unique_key' => $orderId > 0 ? (string)$orderId : null,
            'order_sn_unique_key' => $orderSn !== '' ? $orderSn : null,
            'activation_attempt_count' => 0,
            'last_activation_error' => '',
            'activation_retry_at' => 0,
            'manual_retry_count' => 0,
            'last_manual_retry_at' => 0,
            'last_manual_retry_operator' => 0,
            'manual_retry_reason' => '',
            'manual_retry_request_id' => '',
            'manual_retry_result' => '',
        ], true);
    }

    private function createPurchaseSnapshots(array $purchase, array $validation, array $order, array $intent): int
    {
        /** @var YfthPackagePurchaseSnapshotDao $snapshotDao */
        $snapshotDao = app()->make(YfthPackagePurchaseSnapshotDao::class);
        $existing = $snapshotDao->getOne(['purchase_id' => (int)$purchase['id']]);
        if ($existing) {
            return (int)$existing['id'];
        }
        $subjectIds = $validation['store_checks']['subject_ids'] ?? [];
        $route = $validation['payment_route'] ?? [];
        $snapshotPayload = [
            'validation' => $validation,
            'order' => [
                'id' => (int)($order['id'] ?? $purchase['order_id']),
                'order_id' => (string)($order['order_id'] ?? $purchase['order_sn']),
                'pay_price' => $this->normalizeMoney($order['pay_price'] ?? $purchase['order_pay_price']),
            ],
        ];
        $snapshotRow = $this->withTimestamps([
            'purchase_id' => (int)$purchase['id'],
            'intent_id' => (int)($intent['id'] ?? $purchase['intent_id'] ?? 0),
            'uid' => (int)$purchase['uid'],
            'store_id' => (int)$purchase['store_id'],
            'template_id' => (int)$purchase['template_id'],
            'rule_version_id' => (int)$purchase['rule_version_id'],
            'rule_version_no' => (int)$validation['rule']['version_no'],
            'package_code' => (string)$validation['template']['package_code'],
            'package_name' => (string)$validation['template']['package_name'],
            'package_title' => (string)$validation['template']['package_title'],
            'package_type' => (string)($validation['template']['package_type'] ?? 'health_package'),
            'package_price' => $this->normalizeMoney($validation['rule']['package_price']),
            'currency' => (string)($validation['template']['currency'] ?? 'CNY'),
            'month_count' => (int)$validation['rule']['month_count'],
            'product_id' => (int)$validation['product']['product_id'],
            'product_attr_unique' => (string)$validation['product']['product_attr_unique'],
            'product_name' => (string)$validation['product']['product_name'],
            'sku_name' => (string)$validation['product']['sku_name'],
            'sku_price' => $this->normalizeMoney($validation['product']['sku_price']),
            'agreement_snapshot_id' => (int)$purchase['agreement_snapshot_id'],
            'agreement_title' => (string)$validation['rule']['agreement_title'],
            'agreement_hash' => (string)$validation['rule']['agreement_content_hash'],
            'payment_scene' => 'package_5980',
            'route_version_no' => (int)($route['version_no'] ?? 0),
            'route_type' => (string)($route['route_type'] ?? ''),
            'payment_route_ref' => (string)($route['merchant_ref_masked'] ?? $route['merchant_ref'] ?? ''),
            'sales_subject_id' => (int)($subjectIds['sales'] ?? 0),
            'payment_subject_id' => (int)($route['subject_id'] ?? $subjectIds['payment'] ?? 0),
            'fulfillment_subject_id' => (int)($subjectIds['fulfillment'] ?? 0),
            'invoice_subject_id' => (int)($route['invoice_subject_id'] ?? $subjectIds['invoice'] ?? 0),
            'refund_subject_id' => (int)($route['refund_subject_id'] ?? $subjectIds['refund'] ?? 0),
            'available_store_ids' => $this->jsonEncode([(int)$purchase['store_id']]),
            'validation_hash' => hash('sha256', $this->jsonEncode($validation)),
            'order_id' => (int)$purchase['order_id'],
            'order_sn' => (string)$purchase['order_sn'],
            'order_pay_price' => $this->normalizeMoney($purchase['order_pay_price']),
            'paid_time' => (int)($order['pay_time'] ?? 0),
            'snapshot_payload' => $this->jsonEncode($snapshotPayload),
        ], true);
        $snapshot = $snapshotDao->save($snapshotRow);
        $snapshotId = (int)$snapshot->id;

        /** @var YfthPackagePurchaseBenefitSnapshotDao $benefitSnapshotDao */
        $benefitSnapshotDao = app()->make(YfthPackagePurchaseBenefitSnapshotDao::class);
        foreach (($validation['benefits'] ?? []) as $benefit) {
            $monthNo = (int)$benefit['month_no'];
            $periodStartRule = [
                'offset_days' => (int)$benefit['available_offset_days'],
                'relative_to' => 'period_start',
            ];
            $expireRule = [
                'offset_days' => (int)$benefit['expire_offset_days'],
                'relative_to' => (int)$benefit['expire_offset_days'] > 0 ? 'period_start' : 'period_end',
            ];
            $benefitSnapshotDao->save($this->withTimestamps([
                'purchase_id' => (int)$purchase['id'],
                'snapshot_id' => $snapshotId,
                'intent_id' => (int)($intent['id'] ?? $purchase['intent_id'] ?? 0),
                'rule_version_id' => (int)$purchase['rule_version_id'],
                'month_no' => $monthNo,
                'source_rule_id' => (int)$benefit['id'],
                'benefit_template_id' => (int)$benefit['benefit_template_id'],
                'benefit_code' => (string)$benefit['benefit_code'],
                'benefit_name' => (string)$benefit['benefit_name'],
                'benefit_type' => (string)$benefit['benefit_type'],
                'fulfillment_type' => (string)($benefit['fulfillment_type'] ?? ''),
                'unit' => (string)($benefit['unit'] ?? ''),
                'quantity' => $this->normalizeMoney($benefit['quantity']),
                'per_limit' => $this->normalizeMoney($benefit['per_limit'] ?? '0.00'),
                'available_offset_days' => (int)$benefit['available_offset_days'],
                'expire_offset_days' => (int)$benefit['expire_offset_days'],
                'service_capability' => (string)($benefit['service_capability'] ?? ''),
                'product_id' => 0,
                'product_attr_unique' => '',
                'service_ref' => '',
                'fulfillment_rule' => $this->jsonEncode(['type' => (string)($benefit['fulfillment_type'] ?? 'manual')]),
                'open_rule' => $this->jsonEncode($periodStartRule),
                'expire_rule' => $this->jsonEncode($expireRule),
                'available_store_ids' => $this->jsonEncode([(int)$purchase['store_id']]),
                'snapshot_payload' => $this->jsonEncode($benefit),
            ], true));
        }
        return $snapshotId;
    }

    private function findPurchaseByOrderIdentity(int $orderId = 0, string $orderSn = ''): array
    {
        $purchase = null;
        if ($orderId > 0) {
            $purchase = $this->dao->getOne(['order_unique_key' => (string)$orderId]);
            if (!$purchase) {
                $purchase = $this->dao->getOne(['order_id' => $orderId]);
            }
        }
        if (!$purchase && $orderSn !== '') {
            $purchase = $this->dao->getOne(['order_sn_unique_key' => $orderSn]);
            if (!$purchase) {
                $purchase = $this->dao->getOne(['order_sn' => $orderSn]);
            }
        }
        return $purchase ? $purchase->toArray() : [];
    }

    private function savePurchaseResolvingOrderConflict(YfthPackagePurchaseDao $purchaseDao, array $payload, int $uid): array
    {
        try {
            $purchase = $purchaseDao->save($payload);
            return array_merge($payload, ['id' => (int)$purchase->id, '_existing' => false]);
        } catch (\Throwable $e) {
            if (!$this->isOrderUniqueConflict($e)) {
                throw $e;
            }
            $existing = [];
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $existing = $this->findPurchaseByOrderIdentityForUpdate((int)($payload['order_id'] ?? 0), (string)($payload['order_sn'] ?? ''));
                if ($existing) {
                    break;
                }
                usleep(50000);
            }
            if (!$existing) {
                throw $e;
            }
            if ((int)$existing['uid'] !== $uid) {
                throw new ApiException('order_already_bound_to_other_user');
            }
            $existing['_existing'] = true;
            return $existing;
        }
    }

    private function findPurchaseByOrderIdentityForUpdate(int $orderId = 0, string $orderSn = ''): array
    {
        foreach ([
            ['order_unique_key', $orderId > 0 ? (string)$orderId : ''],
            ['order_id', $orderId > 0 ? $orderId : ''],
            ['order_sn_unique_key', $orderSn],
            ['order_sn', $orderSn],
        ] as $condition) {
            [$field, $value] = $condition;
            if ($value === '' || $value === 0) {
                continue;
            }
            $row = $this->dao->search([])->where($field, $value)->lock(true)->find();
            if ($row) {
                return $row->toArray();
            }
        }
        return [];
    }

    private function isOrderUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false
            || strpos($message, '1062') !== false
            || strpos($message, 'uniq_yfth_pkg_purchase_order_key') !== false
            || strpos($message, 'uniq_yfth_pkg_purchase_order_sn_key') !== false
            || (string)$e->getCode() === '23000';
    }

    private function isRetryableOrderWriteConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return $this->isOrderUniqueConflict($e)
            || strpos($message, 'deadlock') !== false
            || strpos($message, '1213') !== false
            || (string)$e->getCode() === '40001';
    }

    private function intentBoundPayload(array $intent): array
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $purchase = $this->requireRow($purchaseDao->get((int)$intent['purchase_id']), 'package_purchase_not_found');
        return [
            'intent' => $this->formatIntent($intent),
            'purchase' => $this->formatPurchase($purchase),
            'order' => [
                'order_id' => (string)$purchase['order_sn'],
                'store_order_id' => (int)$purchase['order_id'],
                'pay_price' => $this->normalizeMoney($purchase['order_pay_price']),
            ],
            'pay' => [
                'order_id' => (string)$purchase['order_sn'],
                'total_price' => $this->normalizeMoney($purchase['order_pay_price']),
            ],
        ];
    }

    private function intentCreatingPayload(array $intent): array
    {
        return [
            'intent' => $this->formatIntent($intent),
            'purchase' => null,
            'order' => null,
            'pay' => null,
            'processing' => true,
            'message' => 'package_order_creating',
        ];
    }

    private function formatIntent(array $row): array
    {
        $row['expected_pay_price'] = $this->normalizeMoney($row['expected_pay_price'] ?? '0.00');
        $row['validation_snapshot'] = $this->jsonDecode($row['validation_snapshot'] ?? '');
        return $row;
    }

    private function formatPurchase(array $row): array
    {
        unset($row['_existing']);
        $row['expected_pay_price'] = $this->normalizeMoney($row['expected_pay_price'] ?? '0.00');
        $row['order_pay_price'] = $this->normalizeMoney($row['order_pay_price'] ?? '0.00');
        $row['route_snapshot'] = $this->jsonDecode($row['route_snapshot'] ?? '');
        $row['validation_snapshot'] = $this->jsonDecode($row['validation_snapshot'] ?? '');
        $row['auto_retry_count'] = 0;
        $row['auto_retry_max_attempts'] = 5;
        $row['auto_retry_exceeded'] = false;
        if ((int)($row['order_id'] ?? 0) > 0) {
            $idem = app()->make(IdempotencyRecordServices::class)->getOne([
                'business_domain' => 'yfth_package',
                'action_type' => 'activate',
                'idempotency_key' => 'package_activate:' . (int)$row['order_id'],
            ]);
            if ($idem) {
                $idemRow = is_array($idem) ? $idem : $idem->toArray();
                $row['auto_retry_count'] = (int)($idemRow['attempt_count'] ?? 0);
                $row['auto_retry_max_attempts'] = (int)($idemRow['max_attempts'] ?? 5) ?: 5;
                $row['auto_retry_exceeded'] = (string)($idemRow['process_status'] ?? '') === 'failed'
                    && $row['auto_retry_count'] >= $row['auto_retry_max_attempts'];
            }
        }
        $row['can_manual_retry'] = (int)($row['instance_id'] ?? 0) === 0
            && in_array((string)($row['activation_status'] ?? ''), ['pending', 'failed'], true)
            && !in_array((string)($row['purchase_status'] ?? ''), ['refunding', 'refunded', 'closed', 'closed_after_partial_refund', 'partial_fulfillment_refunded'], true);
        return $row;
    }

    private function errorCode(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'unknown';
        }
        $pos = strpos($message, ':');
        if ($pos !== false) {
            $message = substr($message, 0, $pos);
        }
        return preg_replace('/[^a-zA-Z0-9_\\-]/', '_', substr($message, 0, 64));
    }
}
