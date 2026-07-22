<?php

namespace app\services\yfth;

use app\dao\order\StoreOrderDao;
use app\dao\yfth\YfthPackageAgreementSnapshotDao;
use app\dao\yfth\YfthPackageOrderAttemptDao;
use app\dao\yfth\YfthPackagePurchaseBenefitSnapshotDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use app\dao\yfth\YfthPackagePurchaseIntentDao;
use app\dao\yfth\YfthPackagePurchaseSnapshotDao;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\user\UserServices;
use crmeb\exceptions\ApiException;

class PackagePurchaseServices extends PackageBenefitBaseServices
{
    private const CREATING_TIMEOUT_SECONDS = 300;

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
            $order = $this->createCrmebOrderFromSnapshot($uid, $intentRow, $validation, $data, $requestId);
        } catch (\Throwable $e) {
            $this->markIntentFailed($intentDao, $intentRow, $requestId, $this->errorCode($e->getMessage()), $e->getMessage());
            throw $e;
        }

        try {
            $purchase = $this->bindCreatedOrderToIntent($intentDao, $intentRow, $validation, $order, $uid, $data, $requestId);
            // The order starts life through CRMEB, but this binding makes it an
            // explicit YFTH package source before any payment/activation event.
            app()->make(YfthCommissionOrderSourceServices::class)->mark((int)$order['id'], 'package');
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
                if (!$this->isCreatingTimedOut($row)) {
                    return ['state' => 'creating', 'intent' => $row];
                }
                $row = $this->recoverTimedOutCreatingIntent($intentDao, $row);
                if ((int)($row['purchase_id'] ?? 0) > 0 || (string)($row['status'] ?? '') === 'bound') {
                    return ['state' => 'bound', 'intent' => $row];
                }
                $status = (string)($row['status'] ?? '');
                if ($status === 'orphan_paid_pending') {
                    throw new ApiException('package_purchase_intent_orphan_paid_pending');
                }
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
            $attemptId = (int)($order['_yfth_attempt_id'] ?? 0);
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
                $this->markAttemptBound($attemptId, $existing);
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
                $this->markAttemptBound($attemptId, $purchaseRow);
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
            $this->markAttemptBound($attemptId, $purchaseRow);
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
            $this->markAttemptFailedFromOrder($order, $safeCode, $safeMessage);
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
        $attemptId = (int)($order['_yfth_attempt_id'] ?? 0);
        $orderId = (int)($order['id'] ?? 0);
        $orderSn = (string)($order['order_id'] ?? $order['order_sn'] ?? '');
        if ($orderId <= 0 || $orderSn === '') {
            return ['status' => 'skipped', 'reason' => 'missing_order_identity'];
        }
        $existingPurchase = $this->findPurchaseByOrderIdentity($orderId, $orderSn);
        if ($existingPurchase) {
            $this->markAttemptBound($attemptId, $existingPurchase);
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

        $intentStatus = $status === 'pending_manual_paid' ? 'orphan_paid_pending' : 'failed';
        $update = [
            'status' => $intentStatus,
            'orphan_order_id' => $orderId,
            'orphan_order_sn' => $orderSn,
            'orphan_close_status' => $status,
            'orphan_close_error' => $error,
            'last_error_code' => $status ?: 'orphan_order_compensate',
            'last_error_message' => $error ?: $status,
            'fail_reason' => $error ?: $status,
            'update_time' => time(),
        ];
        $intentAlreadyBound = (int)($intent['purchase_id'] ?? 0) > 0 || (string)($intent['status'] ?? '') === 'bound';
        if (!$intentAlreadyBound) {
            $intentDao->update((int)$intent['id'], $update);
        }
        $this->markAttemptOrphanStatus($attemptId, $status, $orderId, $orderSn, $error);
        $this->recordPackageAudit('package_purchase_intent', (string)$intent['id'], 'orphan_order_compensate', $intent, array_merge($update, [
            'reason' => substr($reason, 0, 255),
            'intent_already_bound' => $intentAlreadyBound,
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

    public function scanUnboundPackageIntentOrders(int $limit = 50, bool $closeUnpaid = false, bool $recoverPaid = false, int $operatorUid = 0): array
    {
        $limit = max(1, min($limit, 200));
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $attemptRows = $attemptDao->search([])
            ->whereNotIn('status', ['bound', 'recovered', 'orphan_closed'])
            ->order('id asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $result = [
            'dry_run' => !$closeUnpaid && !$recoverPaid,
            'scanned' => 0,
            'attempts' => count($attemptRows),
            'legacy_intents' => 0,
            'creating_timeout' => 0,
            'payable_orphans' => 0,
            'paid_orphans' => 0,
            'closed' => 0,
            'recovered' => 0,
            'pending_manual' => 0,
            'items' => [],
        ];

        foreach ($attemptRows as $row) {
            $item = $this->scanAttemptRow($row, $closeUnpaid, $recoverPaid, $operatorUid);
            $this->mergeScanCounters($result, $item);
            $result['items'][] = $item;
            $result['scanned']++;
        }

        /** @var YfthPackagePurchaseIntentDao $intentDao */
        $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
        $remaining = max(0, $limit - count($attemptRows));
        if ($remaining > 0) {
            $legacyRows = $intentDao->search([])
                ->where('orphan_order_id', '>', 0)
                ->whereIn('orphan_close_status', ['', 'pending', 'pending_manual', 'failed', 'cancel_failed'])
                ->order('id asc')
                ->limit($remaining)
                ->select()
                ->toArray();
            $result['legacy_intents'] = count($legacyRows);
            foreach ($legacyRows as $row) {
                $item = $this->scanLegacyIntentOrphan($intentDao, $row, $closeUnpaid, $operatorUid);
                $this->mergeScanCounters($result, $item);
                $result['items'][] = $item;
                $result['scanned']++;
            }
        }

        return $result;
    }

    private function scanAttemptRow(array $attempt, bool $closeUnpaid, bool $recoverPaid, int $operatorUid): array
    {
        $order = $this->findOrderForAttempt($attempt, $closeUnpaid || $recoverPaid);
        $timedOut = (int)($attempt['timeout_at'] ?? 0) > 0 && (int)$attempt['timeout_at'] <= time();
        $item = [
            'source' => 'attempt',
            'attempt_id' => (int)$attempt['id'],
            'intent_id' => (int)$attempt['intent_id'],
            'intent_no' => (string)$attempt['intent_no'],
            'request_id' => (string)$attempt['request_id'],
            'status' => (string)$attempt['status'],
            'timed_out' => $timedOut,
            'action' => 'dry_run',
        ];
        if ($timedOut) {
            $item['creating_timeout'] = true;
        }
        if (!$order) {
            $item['result'] = $timedOut ? 'timeout_no_order' : 'order_not_found_yet';
            if (($closeUnpaid || $recoverPaid) && $timedOut) {
                $this->markAttemptFailed((int)$attempt['id'], 'order_creation_timeout_no_order', 'order_creation_timeout_no_order');
                $item['action'] = 'marked_failed';
            }
            return $item;
        }

        $item['order_id'] = (int)$order['id'];
        $item['order_sn'] = (string)$order['order_id'];
        $item['order_sn_masked'] = $this->maskToken((string)$order['order_id']);
        $purchase = $this->findPurchaseByOrderIdentity((int)$order['id'], (string)$order['order_id']);
        if ($purchase) {
            if ($closeUnpaid || $recoverPaid) {
                $this->markAttemptBound((int)$attempt['id'], $purchase);
                $item['action'] = 'marked_bound';
            }
            $item['has_purchase'] = true;
            $item['result'] = 'already_bound';
            return $item;
        }

        $isPaid = (int)($order['paid'] ?? 0) === 1;
        $isClosed = (int)($order['is_cancel'] ?? 0) === 1 || (int)($order['is_del'] ?? 0) === 1;
        if (!$timedOut && !$isPaid) {
            $item['result'] = 'creating_not_timeout';
            return $item;
        }
        if ($isPaid) {
            $item['paid_orphan'] = true;
            $item['result'] = 'paid_orphan_pending';
            if ($recoverPaid) {
                $this->markPaidOrderMissingPurchaseForRecovery($order, 'scan_orphan_orders');
                $item['recover_result'] = $this->recoverPaidOrderAttempt((int)$attempt['id'], $operatorUid, 'scan recover paid orphan');
                $item['action'] = 'recover_paid';
                if (!empty($item['recover_result']['purchase_id'])) {
                    $item['recovered'] = true;
                } elseif (!empty($item['recover_result']['skipped'])) {
                    $item['pending_manual'] = true;
                }
            }
            return $item;
        }
        if ($isClosed) {
            if ($closeUnpaid) {
                $this->markAttemptOrphanStatus((int)$attempt['id'], 'cancelled', (int)$order['id'], (string)$order['order_id'], '');
                $item['action'] = 'marked_closed';
            }
            $item['closed'] = true;
            $item['result'] = 'order_already_closed';
            return $item;
        }

        $item['payable_orphan'] = true;
        $item['result'] = 'unpaid_orphan_payable';
        if ($closeUnpaid) {
            /** @var YfthPackagePurchaseIntentDao $intentDao */
            $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
            $intent = $intentDao->get((int)$attempt['intent_id']);
            $intentRow = $intent ? $intent->toArray() : [];
            if ($intentRow) {
                $order['_yfth_attempt_id'] = (int)$attempt['id'];
                $closeResult = $this->compensateUnboundPackageOrder($intentDao, $intentRow, $order, (int)$attempt['uid'], (string)$attempt['request_id'], 'scan close unpaid orphan', $operatorUid);
                $item['close_result'] = $closeResult;
                $item['action'] = 'close_unpaid';
                if (($closeResult['status'] ?? '') === 'cancelled') {
                    $item['closed'] = true;
                } else {
                    $item['pending_manual'] = true;
                }
            } else {
                $item['pending_manual'] = true;
                $item['error'] = 'intent_not_found';
            }
        }
        return $item;
    }

    private function scanLegacyIntentOrphan(YfthPackagePurchaseIntentDao $intentDao, array $row, bool $closeUnpaid, int $operatorUid): array
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $orderServices->get((int)$row['orphan_order_id']);
        $orderRow = $order ? (is_array($order) ? $order : $order->toArray()) : [];
        $purchase = $this->findPurchaseByOrderIdentity((int)($row['orphan_order_id'] ?? 0), (string)($row['orphan_order_sn'] ?? ''));
        $payable = $orderRow
            && !$purchase
            && (int)($orderRow['paid'] ?? 0) === 0
            && (int)($orderRow['is_cancel'] ?? 0) === 0
            && (int)($orderRow['is_del'] ?? 0) === 0;
        $item = [
            'source' => 'legacy_intent_orphan',
            'intent_id' => (int)$row['id'],
            'intent_no' => (string)$row['intent_no'],
            'order_id' => (int)($row['orphan_order_id'] ?? 0),
            'order_sn_masked' => $this->maskToken((string)($row['orphan_order_sn'] ?? '')),
            'payable_orphan' => $payable,
            'has_purchase' => (bool)$purchase,
            'close_status' => (string)($row['orphan_close_status'] ?? ''),
            'action' => 'dry_run',
        ];
        if ($closeUnpaid && $payable) {
            $closeResult = $this->compensateUnboundPackageOrder($intentDao, $row, [
                'id' => (int)$row['orphan_order_id'],
                'order_id' => (string)$row['orphan_order_sn'],
            ], (int)$row['uid'], 'scan-orphan-orders', 'manual scan close', $operatorUid);
            $item['close_result'] = $closeResult;
            $item['action'] = 'close_unpaid';
            if (($closeResult['status'] ?? '') === 'cancelled') {
                $item['closed'] = true;
            } else {
                $item['pending_manual'] = true;
            }
        } elseif (!$payable && !$purchase) {
            $item['pending_manual'] = true;
        }
        return $item;
    }

    private function mergeScanCounters(array &$result, array $item): void
    {
        if (!empty($item['creating_timeout'])) {
            $result['creating_timeout']++;
        }
        if (!empty($item['payable_orphan'])) {
            $result['payable_orphans']++;
        }
        if (!empty($item['paid_orphan'])) {
            $result['paid_orphans']++;
        }
        if (!empty($item['closed'])) {
            $result['closed']++;
        }
        if (!empty($item['recovered'])) {
            $result['recovered']++;
        }
        if (!empty($item['pending_manual'])) {
            $result['pending_manual']++;
        }
    }

    public function recoverPaidOrderAttempt(int $attemptId, int $operatorUid = 0, string $reason = 'recover paid orphan'): array
    {
        $requestId = $this->makeNo('YFORP');
        $purchase = $this->transaction(function () use ($attemptId, $operatorUid, $reason, $requestId) {
            /** @var YfthPackageOrderAttemptDao $attemptDao */
            $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
            /** @var YfthPackagePurchaseIntentDao $intentDao */
            $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
            /** @var YfthPackagePurchaseDao $purchaseDao */
            $purchaseDao = app()->make(YfthPackagePurchaseDao::class);

            $attempt = $attemptDao->search([])->where('id', $attemptId)->lock(true)->find();
            $attemptRow = $this->requireRow($attempt, 'package_order_attempt_not_found');
            $order = $this->findOrderForAttempt($attemptRow, true);
            if (!$order) {
                throw new ApiException('package_order_attempt_order_not_found');
            }
            if ((int)($order['paid'] ?? 0) !== 1) {
                throw new ApiException('package_order_attempt_order_not_paid');
            }
            if ((int)($order['is_cancel'] ?? 0) !== 0 || (int)($order['is_del'] ?? 0) !== 0) {
                throw new ApiException('package_order_attempt_order_closed');
            }

            $existing = $this->findPurchaseByOrderIdentity((int)$order['id'], (string)$order['order_id']);
            if ($existing) {
                $this->markAttemptBound($attemptId, $existing);
                return $this->formatPurchase($existing);
            }

            $intent = $intentDao->search([])->where('id', (int)$attemptRow['intent_id'])->lock(true)->find();
            $intentRow = $this->requireRow($intent, 'package_purchase_intent_not_found');
            if ((int)($intentRow['purchase_id'] ?? 0) > 0 || (string)($intentRow['status'] ?? '') === 'bound') {
                $attemptDao->update($attemptId, [
                    'recovery_status' => 'pending_manual',
                    'recovery_error' => 'package_order_attempt_intent_already_bound',
                    'update_time' => time(),
                ]);
                $this->recordPackageAudit('package_order_attempt', (string)$attemptId, 'recover_paid_orphan_skipped', $attemptRow, [
                    'intent_id' => (int)$intentRow['id'],
                    'bound_purchase_id' => (int)($intentRow['purchase_id'] ?? 0),
                    'order_id' => (int)$order['id'],
                    'order_sn_masked' => $this->maskToken((string)$order['order_id']),
                    'reason' => 'package_order_attempt_intent_already_bound',
                ], $operatorUid, $operatorUid > 0 ? 'admin' : 'system', (int)$intentRow['store_id'], $reason, $requestId);
                return [
                    'id' => 0,
                    'order_id' => (int)$order['id'],
                    'order_sn' => (string)$order['order_id'],
                    'skipped' => true,
                    'reason' => 'package_order_attempt_intent_already_bound',
                ];
            }
            if ((int)$intentRow['uid'] !== (int)$attemptRow['uid'] || (int)$order['uid'] !== (int)$intentRow['uid']) {
                throw new ApiException('package_order_attempt_user_mismatch');
            }
            $validation = $this->jsonDecode($intentRow['validation_snapshot'] ?? '');
            if (!$validation) {
                throw new ApiException('package_purchase_intent_snapshot_missing');
            }
            $this->assertRecoveredOrderMatchesSnapshot($order, $intentRow, $validation);

            $payload = $this->buildPurchasePayload((int)$intentRow['uid'], $validation, [
                'id' => (int)$order['id'],
                'order_id' => (string)$order['order_id'],
                'pay_price' => $this->normalizeMoney($order['pay_price'] ?? '0.00'),
                'paid' => 1,
                'pay_time' => (int)($order['pay_time'] ?? 0),
                'store_id' => (int)($order['store_id'] ?? 0),
            ], (int)$intentRow['agreement_snapshot_id'], (int)$intentRow['id'], ['source' => 'orphan_recovery']);
            $purchaseRow = $this->savePurchaseResolvingOrderConflict($purchaseDao, $payload, (int)$intentRow['uid']);
            if (empty($purchaseRow['_existing'])) {
                $snapshotId = $this->createPurchaseSnapshots($purchaseRow, $validation, $order, $intentRow);
                $purchaseDao->update((int)$purchaseRow['id'], ['snapshot_id' => $snapshotId, 'update_time' => time()]);
                $purchaseRow['snapshot_id'] = $snapshotId;
            }
            $intentUpdate = [
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
            ];
            $intentDao->update((int)$intentRow['id'], $intentUpdate);
            $attemptDao->update($attemptId, [
                'status' => 'recovered',
                'recovery_status' => 'recovered',
                'order_id' => (int)$order['id'],
                'order_sn' => (string)$order['order_id'],
                'order_paid' => 1,
                'last_error_code' => '',
                'last_error_message' => '',
                'recovery_error' => '',
                'update_time' => time(),
            ]);
            $this->recordPackageAudit('package_order_attempt', (string)$attemptId, 'recover_paid_orphan_bind', $attemptRow, [
                'purchase_id' => (int)$purchaseRow['id'],
                'intent_id' => (int)$intentRow['id'],
                'order_id' => (int)$order['id'],
                'order_sn_masked' => $this->maskToken((string)$order['order_id']),
                'reason' => substr($reason, 0, 255),
            ], $operatorUid, $operatorUid > 0 ? 'admin' : 'system', (int)$intentRow['store_id'], $reason, $requestId);
            return $this->formatPurchase($purchaseRow);
        });

        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        if (!empty($purchase['skipped'])) {
            return [
                'attempt_id' => $attemptId,
                'order_id' => (int)$purchase['order_id'],
                'order_sn_masked' => $this->maskToken((string)$purchase['order_sn']),
                'skipped' => true,
                'reason' => (string)($purchase['reason'] ?? 'package_order_attempt_skipped'),
            ];
        }
        $order = $this->requireRow($orderServices->get((int)$purchase['order_id']), 'order_not_found');
        try {
            /** @var PackageActivationServices $activationServices */
            $activationServices = app()->make(PackageActivationServices::class);
            $activation = $operatorUid > 0
                ? $activationServices->manualActivateByPaidOrder(is_array($order) ? $order : $order->toArray(), $operatorUid, $reason, $requestId)
                : $activationServices->activateByPaidOrder(is_array($order) ? $order : $order->toArray());
        } catch (\Throwable $e) {
            /** @var YfthPackageOrderAttemptDao $attemptDao */
            $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
            $attemptDao->update($attemptId, [
                'status' => 'recovery_failed',
                'recovery_status' => 'failed',
                'recovery_error' => substr($e->getMessage(), 0, 255),
                'update_time' => time(),
            ]);
            throw $e;
        }

        return [
            'attempt_id' => $attemptId,
            'purchase_id' => (int)$purchase['id'],
            'order_id' => (int)$purchase['order_id'],
            'order_sn_masked' => $this->maskToken((string)$purchase['order_sn']),
            'activation' => $activation,
        ];
    }

    private function assertRecoveredOrderMatchesSnapshot(array $order, array $intent, array $validation): void
    {
        if ((int)$order['uid'] !== (int)$intent['uid']) {
            throw new ApiException('package_recovery_order_user_mismatch');
        }
        if ((int)($order['store_id'] ?? 0) > 0 && (int)$order['store_id'] !== (int)$intent['store_id']) {
            throw new ApiException('package_recovery_order_store_mismatch');
        }
        if (!$this->moneyEquals($order['pay_price'] ?? '0.00', $intent['expected_pay_price'])) {
            throw new ApiException('package_recovery_order_price_mismatch');
        }
        if (!$this->moneyEquals($order['pay_price'] ?? '0.00', $validation['rule']['package_price'] ?? '0.00')) {
            throw new ApiException('package_recovery_rule_price_mismatch');
        }
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $productId = (int)$validation['product']['product_id'];
        $skuUnique = (string)$validation['product']['product_attr_unique'];
        $matched = false;
        foreach ($cartInfoServices->getOrderCartInfo((int)$order['id']) as $item) {
            $cart = $item['cart_info'] ?? [];
            $cartProductId = (int)($cart['productInfo']['id'] ?? $item['product_id'] ?? 0);
            $cartSkuUnique = (string)($cart['productInfo']['attrInfo']['unique'] ?? $cart['attrInfo']['unique'] ?? $cart['productAttrUnique'] ?? '');
            if ($cartProductId === $productId && $cartSkuUnique === $skuUnique) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new ApiException('package_recovery_order_sku_mismatch');
        }
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
        $storeId = app()->make(PackageMembershipReferralServices::class)->requireAuthoritativeStoreForPurchase(
            $uid,
            (int)($data['store_id'] ?? 0)
        );
        $productId = (int)($data['product_id'] ?? 0);
        $skuUnique = trim((string)($data['product_attr_unique'] ?? ''));
        if ($templateId <= 0 || $storeId <= 0 || $productId <= 0 || $skuUnique === '') {
            throw new ApiException('template_store_product_and_sku_required');
        }

        /** @var PackageTemplateServices $templateServices */
        $templateServices = app()->make(PackageTemplateServices::class);
        $template = $templateServices->requirePublishedTemplate($templateId);
        $rule = $templateServices->currentRule($templateId);
        $rule['grants_permanent_membership'] = (int)app()->make(PackageMembershipGrantPolicy::class)
            ->forRule($rule)['grants_permanent_membership'];
        app()->make(PackageMembershipReferralServices::class)->assertMembershipGrantRule($uid, $rule);
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
                'grants_permanent_membership' => (int)($rule['grants_permanent_membership'] ?? 0),
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

    private function createCrmebOrderFromSnapshot(int $uid, array $intent, array $validation, array $data, string $requestId): array
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
        $orderKey = (string)$confirm['orderKey'];
        $attempt = $this->createOrderAttempt($intent, $validation, $uid, $requestId, $orderKey);

        /** @var StoreOrderCreateServices $createServices */
        $createServices = app()->make(StoreOrderCreateServices::class);
        $payType = trim((string)($data['pay_type'] ?? 'weixin')) ?: 'weixin';
        $realName = trim((string)($data['real_name'] ?? ($user['real_name'] ?? '')));
        $phone = trim((string)($data['phone'] ?? ($user['phone'] ?? '')));
        try {
            $order = $createServices->createOrder(
                $uid,
                $orderKey,
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
        } catch (\Throwable $e) {
            $this->markAttemptFailed((int)$attempt['id'], 'crmeb_order_create_failed', $e->getMessage());
            throw $e;
        }
        $order = is_array($order) ? $order : $order->toArray();
        $this->markAttemptOrderCreated((int)$attempt['id'], $order);
        if (!$this->moneyEquals($order['pay_price'] ?? '0.00', $intent['expected_pay_price'])) {
            throw new ApiException('package_order_pay_price_mismatch');
        }
        $order['_yfth_attempt_id'] = (int)$attempt['id'];
        $order['_yfth_attempt_no'] = (string)$attempt['attempt_no'];
        $order['_yfth_order_key'] = $orderKey;
        return $order;
    }

    private function createOrderAttempt(array $intent, array $validation, int $uid, string $requestId, string $orderKey): array
    {
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $row = $this->withTimestamps([
            'attempt_no' => $this->makeNo('YFATT'),
            'intent_id' => (int)$intent['id'],
            'intent_no' => (string)$intent['intent_no'],
            'uid' => $uid,
            'store_id' => (int)$validation['store']['store_id'],
            'request_id' => $requestId,
            'product_id' => (int)$validation['product']['product_id'],
            'product_attr_unique' => (string)$validation['product']['product_attr_unique'],
            'order_key' => $orderKey,
            'source_token_hash' => hash('sha256', $orderKey),
            'status' => 'creating',
            'recovery_status' => '',
            'order_id' => 0,
            'order_sn' => '',
            'order_paid' => 0,
            'timeout_at' => time() + self::CREATING_TIMEOUT_SECONDS,
            'recoverable_at' => 0,
            'last_error_code' => '',
            'last_error_message' => '',
            'recovery_error' => '',
        ], true);
        $saved = $attemptDao->save($row);
        $row['id'] = (int)$saved->id;
        $audit = $row;
        $audit['order_key'] = $this->maskToken($orderKey);
        $this->recordPackageAudit('package_order_attempt', (string)$row['id'], 'create', [], $audit, $uid, 'customer', (int)$row['store_id'], '', $requestId);
        return $row;
    }

    private function markAttemptOrderCreated(int $attemptId, array $order): void
    {
        if ($attemptId <= 0) {
            return;
        }
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $attemptDao->update($attemptId, [
            'status' => 'order_created',
            'order_id' => (int)($order['id'] ?? 0),
            'order_sn' => (string)($order['order_id'] ?? ''),
            'order_paid' => (int)($order['paid'] ?? 0),
            'recoverable_at' => time(),
            'update_time' => time(),
        ]);
    }

    private function markAttemptFailed(int $attemptId, string $errorCode, string $message): void
    {
        if ($attemptId <= 0) {
            return;
        }
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $attemptDao->update($attemptId, [
            'status' => 'failed',
            'last_error_code' => substr($errorCode, 0, 64),
            'last_error_message' => substr($message, 0, 255),
            'recovery_error' => substr($message, 0, 255),
            'update_time' => time(),
        ]);
    }

    private function markAttemptFailedFromOrder(array $order, string $errorCode, string $message): void
    {
        $attemptId = (int)($order['_yfth_attempt_id'] ?? 0);
        if ($attemptId > 0) {
            $this->markAttemptFailed($attemptId, $errorCode, $message);
        }
    }

    private function markAttemptBound(int $attemptId, array $purchase): void
    {
        if ($attemptId <= 0) {
            return;
        }
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $attemptDao->update($attemptId, [
            'status' => 'bound',
            'recovery_status' => 'bound',
            'order_id' => (int)($purchase['order_id'] ?? 0),
            'order_sn' => (string)($purchase['order_sn'] ?? ''),
            'last_error_code' => '',
            'last_error_message' => '',
            'recovery_error' => '',
            'update_time' => time(),
        ]);
    }

    private function markAttemptOrphanStatus(int $attemptId, string $closeStatus, int $orderId, string $orderSn, string $error): void
    {
        if ($attemptId <= 0) {
            return;
        }
        $status = 'orphan_unpaid';
        $recoveryStatus = '';
        if ($closeStatus === 'pending_manual_paid') {
            $status = 'orphan_paid_pending';
        } elseif ($closeStatus === 'cancelled') {
            $status = 'orphan_closed';
            $recoveryStatus = 'closed';
        } elseif ($closeStatus === 'pending_manual') {
            $recoveryStatus = 'failed';
        }
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $attemptDao->update($attemptId, [
            'status' => $status,
            'recovery_status' => $recoveryStatus,
            'order_id' => $orderId,
            'order_sn' => $orderSn,
            'order_paid' => $closeStatus === 'pending_manual_paid' ? 1 : 0,
            'last_error_code' => $closeStatus,
            'last_error_message' => substr($error ?: $closeStatus, 0, 255),
            'recovery_error' => substr($error, 0, 255),
            'update_time' => time(),
        ]);
    }

    private function isCreatingTimedOut(array $intent): bool
    {
        $startedAt = (int)($intent['creating_started_at'] ?? 0);
        return $startedAt > 0 && $startedAt + self::CREATING_TIMEOUT_SECONDS <= time();
    }

    private function recoverTimedOutCreatingIntent(YfthPackagePurchaseIntentDao $intentDao, array $intent): array
    {
        $attempt = $this->latestAttemptForIntent((int)$intent['id'], (string)($intent['creating_request_id'] ?? ''));
        $order = $attempt ? $this->findOrderForAttempt($attempt) : [];
        $requestId = (string)($intent['creating_request_id'] ?? '');
        if (!$order) {
            $update = [
                'status' => 'failed',
                'last_error_code' => 'order_creation_timeout_no_order',
                'last_error_message' => 'order_creation_timeout_no_order',
                'fail_reason' => 'order_creation_timeout_no_order',
                'update_time' => time(),
            ];
            $intentDao->update((int)$intent['id'], $update);
            if ($attempt) {
                $this->markAttemptFailed((int)$attempt['id'], 'order_creation_timeout_no_order', 'order_creation_timeout_no_order');
            }
            $this->recordPackageAudit('package_purchase_intent', (string)$intent['id'], 'creating_timeout_no_order', $intent, $update, (int)$intent['uid'], 'system', (int)$intent['store_id'], 'order_creation_timeout_no_order', $requestId);
            return array_merge($intent, $update);
        }

        $order['_yfth_attempt_id'] = (int)($attempt['id'] ?? 0);
        $existing = $this->findPurchaseByOrderIdentity((int)$order['id'], (string)$order['order_id']);
        if ($existing) {
            $update = [
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
            ];
            $intentDao->update((int)$intent['id'], $update);
            $this->markAttemptBound((int)($attempt['id'] ?? 0), $existing);
            return array_merge($intent, $update);
        }

        if ((int)($order['paid'] ?? 0) === 1) {
            $this->markPaidOrderMissingPurchaseForRecovery($order, 'creating_timeout');
            return array_merge($intent, [
                'status' => 'orphan_paid_pending',
                'orphan_order_id' => (int)$order['id'],
                'orphan_order_sn' => (string)$order['order_id'],
                'orphan_close_status' => 'pending_manual_paid',
                'last_error_code' => 'orphan_order_already_paid',
            ]);
        }

        if ((int)($order['is_cancel'] ?? 0) === 1 || (int)($order['is_del'] ?? 0) === 1) {
            $update = [
                'status' => 'failed',
                'orphan_order_id' => (int)$order['id'],
                'orphan_order_sn' => (string)$order['order_id'],
                'orphan_close_status' => 'cancelled',
                'orphan_close_error' => '',
                'last_error_code' => 'orphan_order_closed',
                'last_error_message' => 'orphan_order_closed',
                'fail_reason' => 'orphan_order_closed',
                'update_time' => time(),
            ];
            $intentDao->update((int)$intent['id'], $update);
            $this->markAttemptOrphanStatus((int)($attempt['id'] ?? 0), 'cancelled', (int)$order['id'], (string)$order['order_id'], '');
            return array_merge($intent, $update);
        }

        $this->compensateUnboundPackageOrder($intentDao, $intent, $order, (int)$intent['uid'], $requestId, 'creating_timeout_unpaid_orphan');
        $latest = $intentDao->get((int)$intent['id']);
        return $latest ? $latest->toArray() : $intent;
    }

    private function latestAttemptForIntent(int $intentId, string $requestId = ''): array
    {
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $query = $attemptDao->search([])->where('intent_id', $intentId);
        if ($requestId !== '') {
            $query->where('request_id', $requestId);
        }
        $row = $query->order('id desc')->find();
        return $row ? $row->toArray() : [];
    }

    private function findOrderForAttempt(array $attempt, bool $syncAttempt = true): array
    {
        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);
        $order = null;
        if ((int)($attempt['order_id'] ?? 0) > 0) {
            $order = $orderDao->get((int)$attempt['order_id']);
        }
        if (!$order && (string)($attempt['order_sn'] ?? '') !== '') {
            $order = $orderDao->getOne(['order_id' => (string)$attempt['order_sn']]);
        }
        if (!$order && (string)($attempt['order_key'] ?? '') !== '') {
            $order = $orderDao->getOne(['unique' => (string)$attempt['order_key']]);
        }
        if ($syncAttempt && $order && ((int)($attempt['order_id'] ?? 0) <= 0 || (string)($attempt['order_sn'] ?? '') === '')) {
            /** @var YfthPackageOrderAttemptDao $attemptDao */
            $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
            $attemptDao->update((int)$attempt['id'], [
                'order_id' => (int)$order['id'],
                'order_sn' => (string)$order['order_id'],
                'order_paid' => (int)$order['paid'],
                'update_time' => time(),
            ]);
        }
        return $order ? $order->toArray() : [];
    }

    public function locatePackageOrderAttempt(array $orderInfo): array
    {
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $orderId = (int)($orderInfo['id'] ?? $orderInfo['store_order_id'] ?? 0);
        $orderSn = (string)($orderInfo['order_id'] ?? $orderInfo['order_sn'] ?? '');
        $orderKey = (string)($orderInfo['unique'] ?? $orderInfo['_yfth_order_key'] ?? '');
        $attempt = null;
        if ($orderId > 0) {
            $attempt = $attemptDao->getOne(['order_id' => $orderId]);
        }
        if (!$attempt && $orderSn !== '') {
            $attempt = $attemptDao->getOne(['order_sn' => $orderSn]);
        }
        if (!$attempt && $orderKey !== '') {
            $attempt = $attemptDao->getOne(['order_key' => $orderKey]);
        }
        if (!$attempt && ($orderId > 0 || $orderSn !== '')) {
            $order = $this->fullOrderFromOrderInfo($orderInfo);
            if ($order && (string)($order['unique'] ?? '') !== '') {
                $attempt = $attemptDao->getOne(['order_key' => (string)$order['unique']]);
            }
        }
        return $attempt ? $attempt->toArray() : [];
    }

    public function markPaidOrderMissingPurchaseForRecovery(array $orderInfo, string $source = 'listener'): array
    {
        $order = $this->fullOrderFromOrderInfo($orderInfo);
        if (!$order || (int)($order['paid'] ?? 0) !== 1) {
            return ['tracked' => false, 'reason' => 'order_not_paid_or_missing'];
        }
        $attempt = $this->locatePackageOrderAttempt($order);
        if (!$attempt) {
            return ['tracked' => false, 'reason' => 'package_order_attempt_not_found'];
        }
        /** @var YfthPackagePurchaseIntentDao $intentDao */
        $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
        $intent = $intentDao->get((int)$attempt['intent_id']);
        $intentRow = $intent ? $intent->toArray() : [];
        $now = time();
        /** @var YfthPackageOrderAttemptDao $attemptDao */
        $attemptDao = app()->make(YfthPackageOrderAttemptDao::class);
        $attemptDao->update((int)$attempt['id'], [
            'status' => 'orphan_paid_pending',
            'recovery_status' => 'pending',
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['order_id'],
            'order_paid' => 1,
            'last_error_code' => 'package_order_missing_purchase',
            'last_error_message' => 'package_order_missing_purchase',
            'recoverable_at' => $now,
            'update_time' => $now,
        ]);
        $intentAlreadyBound = $intentRow && ((int)($intentRow['purchase_id'] ?? 0) > 0 || (string)($intentRow['status'] ?? '') === 'bound');
        if ($intentRow && !$intentAlreadyBound) {
            $intentDao->update((int)$intentRow['id'], [
                'status' => 'orphan_paid_pending',
                'orphan_order_id' => (int)$order['id'],
                'orphan_order_sn' => (string)$order['order_id'],
                'orphan_close_status' => 'pending_manual_paid',
                'orphan_close_error' => 'package_order_missing_purchase',
                'last_error_code' => 'package_order_missing_purchase',
                'last_error_message' => 'package_order_missing_purchase',
                'fail_reason' => 'package_order_missing_purchase',
                'update_time' => $now,
            ]);
        }
        $payload = [
            'attempt_id' => (int)$attempt['id'],
            'intent_id' => (int)($attempt['intent_id'] ?? 0),
            'request_id' => (string)($attempt['request_id'] ?? ''),
            'order_id' => (int)$order['id'],
            'order_sn_masked' => $this->maskToken((string)$order['order_id']),
            'source' => $source,
            'intent_already_bound' => $intentAlreadyBound,
        ];
        $this->recordPackageAudit('package_order_attempt', (string)$attempt['id'], 'paid_order_missing_purchase', $attempt, $payload, 0, 'system', (int)$order['store_id'], 'package_order_missing_purchase', (string)($attempt['request_id'] ?? ''));
        return array_merge(['tracked' => true], $payload);
    }

    private function fullOrderFromOrderInfo(array $orderInfo): array
    {
        /** @var StoreOrderDao $orderDao */
        $orderDao = app()->make(StoreOrderDao::class);
        $orderId = (int)($orderInfo['id'] ?? $orderInfo['store_order_id'] ?? 0);
        $orderSn = (string)($orderInfo['order_id'] ?? $orderInfo['order_sn'] ?? '');
        $order = null;
        if ($orderId > 0) {
            $order = $orderDao->get($orderId);
        }
        if (!$order && $orderSn !== '') {
            $order = $orderDao->getOne(['order_id' => $orderSn]);
        }
        if ($order) {
            return $order->toArray();
        }
        return $orderInfo;
    }

    private function maskToken(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 8) {
            return substr($value, 0, 2) . '***';
        }
        return substr($value, 0, 4) . '***' . substr($value, -4);
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
            'grants_permanent_membership' => (int)($validation['rule']['grants_permanent_membership'] ?? 0),
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
