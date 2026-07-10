<?php

namespace app\services\yfth;

use app\Request;
use app\dao\yfth\YfthBenefitFulfillmentDao;
use app\dao\yfth\YfthBenefitFulfillmentEventDao;
use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthPackageInstanceDao;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class MonthlyBenefitFulfillmentServices extends PackageBenefitBaseServices
{
    private const FULFILLMENT_DOMAIN = 'yfth_monthly_benefit_fulfillment';

    private const STATUS_PENDING = 'pending_confirm';
    private const STATUS_CONFIRMED = 'confirmed';
    private const STATUS_PREPARING = 'preparing';
    private const STATUS_SHIPPED = 'shipped';
    private const STATUS_PICKED_UP = 'picked_up';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CANCELLED = 'cancelled';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_EXCEPTION = 'exception';

    private const METHOD_EXPRESS = 'express_delivery';
    private const METHOD_PICKUP = 'self_pickup';

    private const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_REJECTED,
    ];

    public function __construct(YfthBenefitFulfillmentDao $dao)
    {
        $this->dao = $dao;
    }

    public function current(Request $request, array $where = []): array
    {
        $this->assertUserReadonlyPayload($where);
        $uid = (int)$request->uid();
        $instanceId = (int)($where['package_instance_id'] ?? 0);
        /** @var BenefitPeriodServices $periodServices */
        $periodServices = app()->make(BenefitPeriodServices::class);
        $items = $periodServices->currentMonthBenefits($uid, $instanceId);
        $itemIds = array_values(array_filter(array_map(function ($item) {
            return (int)($item['id'] ?? 0);
        }, $items)));

        $fulfillments = [];
        if ($itemIds) {
            $rows = $this->dao->search([])
                ->whereIn('benefit_item_id', $itemIds)
                ->order('id desc')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $itemId = (int)$row['benefit_item_id'];
                if (!isset($fulfillments[$itemId])) {
                    $fulfillments[$itemId] = $this->formatFulfillment($row, 'user_list');
                }
            }
        }

        $productItems = [];
        $serviceItems = [];
        foreach ($items as $item) {
            $payload = $this->formatBenefitItem($item);
            $payload['fulfillment'] = $fulfillments[(int)$item['id']] ?? null;
            if ((string)($item['benefit_type'] ?? '') === 'product') {
                $payload['claimable'] = empty($payload['fulfillment'])
                    || in_array((string)($payload['fulfillment']['status'] ?? ''), [self::STATUS_CANCELLED, self::STATUS_REJECTED], true);
                $productItems[] = $payload;
            } else {
                $serviceItems[] = $payload;
            }
        }

        return [
            'product_items' => $productItems,
            'service_items' => $serviceItems,
            'server_time' => time(),
            'boundary' => [
                'product_fulfillment_uses_yfth_tables' => true,
                'does_not_create_crmeb_order' => true,
                'does_not_touch_crmeb_stock' => true,
            ],
        ];
    }

    public function history(Request $request, array $where = []): array
    {
        $this->assertUserReadonlyPayload($where);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = $this->dao->search([])->where('uid', (int)$request->uid());
        if (!empty($where['status'])) {
            $query->where('status', (string)$where['status']);
        }
        $count = (int)(clone $query)->count();
        $list = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map(function ($row) {
                return $this->formatFulfillment($row, 'user_list');
            }, $list),
            'count' => $count,
        ];
    }

    public function detailForUser(Request $request, int $id): array
    {
        $row = $this->requireRow($this->dao->search([])->where('id', $id)->where('uid', (int)$request->uid())->find(), 'fulfillment_not_found');
        return [
            'fulfillment' => $this->formatFulfillment($row, 'user_detail'),
            'events' => $this->eventsFor($id, 'user'),
        ];
    }

    public function claim(Request $request, array $data): array
    {
        $this->assertClaimPayload($data);
        $uid = (int)$request->uid();
        $benefitItemId = (int)($data['benefit_item_id'] ?? 0);
        if ($benefitItemId <= 0) {
            throw new ApiException('benefit_item_id_required');
        }
        $method = (string)($data['fulfillment_method'] ?? self::METHOD_EXPRESS);
        if (!in_array($method, [self::METHOD_EXPRESS, self::METHOD_PICKUP], true)) {
            throw new ApiException('invalid_fulfillment_method');
        }
        $idempotencyKey = $this->normalizeRequiredClientKey($data['idempotency_key'] ?? ($data['client_operation_key'] ?? ''), 'claim');
        $idempotencyPayload = [
            'uid' => $uid,
            'benefit_item_id' => $benefitItemId,
            'fulfillment_method' => $method,
            'address_id' => (int)($data['address_id'] ?? 0),
            'pickup_store_id' => (int)($data['pickup_store_id'] ?? 0),
        ];

        /** @var IdempotencyRecordServices $idempotency */
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin(self::FULFILLMENT_DOMAIN, 'claim', $idempotencyKey, $idempotencyPayload, 'benefit_item:' . $benefitItemId);
        if (!$begin['acquired']) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                return ['fulfillment' => $this->formatFulfillment($existing, 'user_detail'), 'idempotent_replay' => true];
            }
            throw new ApiException('idempotent_result_not_ready');
        }

        try {
            $result = $this->transaction(function () use ($uid, $data, $method, $benefitItemId, $idempotencyKey) {
                $rows = $this->lockBenefitRows($benefitItemId);
                $item = $rows['item'];
                if ((int)$item['uid'] !== $uid) {
                    throw new ApiException('benefit_item_not_found');
                }
                $this->assertProductBenefitClaimable($rows);

                $existing = $this->findActiveByItemId($benefitItemId, true);
                if ($existing) {
                    return $this->formatFulfillment($existing, 'user_detail');
                }

                $delivery = $this->resolveDeliverySnapshot($uid, $method, $data);
                $now = time();
                $payload = [
                    'fulfillment_no' => $this->makeNo('YFMB'),
                    'uid' => $uid,
                    'store_id' => (int)$item['store_id'],
                    'package_instance_id' => (int)$item['package_instance_id'],
                    'benefit_plan_id' => (int)$item['plan_id'],
                    'benefit_period_id' => (int)$item['period_id'],
                    'benefit_item_id' => (int)$item['id'],
                    'benefit_template_id' => (int)$item['benefit_template_id'],
                    'month_no' => (int)$item['month_no'],
                    'period_code' => (string)($rows['period']['period_code'] ?? ''),
                    'benefit_code' => (string)$item['benefit_code'],
                    'benefit_name' => (string)$item['benefit_name'],
                    'fulfillment_type' => 'product',
                    'fulfillment_method' => $method,
                    'status' => self::STATUS_PENDING,
                    'quantity_total' => $this->normalizeMoney($item['quantity_total']),
                    'product_id' => (int)$delivery['product_snapshot']['product_id'],
                    'sku_unique' => (string)$delivery['product_snapshot']['sku_unique'],
                    'product_snapshot' => $this->jsonEncode($this->sanitizeState($delivery['product_snapshot'])),
                    'benefit_snapshot' => $this->jsonEncode($this->sanitizeState($this->benefitSnapshot($item, $rows))),
                    'recipient_name_masked' => (string)$delivery['recipient_name_masked'],
                    'recipient_phone_masked' => (string)$delivery['recipient_phone_masked'],
                    'address_snapshot' => $this->jsonEncode($this->sanitizeState($delivery['address_snapshot'])),
                    'pickup_store_id' => (int)$delivery['pickup_store_id'],
                    'pickup_store_snapshot' => $this->jsonEncode($this->sanitizeState($delivery['pickup_store_snapshot'])),
                    'claim_time' => $now,
                    'operator_type' => 'user',
                    'operator_uid' => $uid,
                    'operator_role_code' => 'customer',
                    'reason' => 'user_claim',
                    'idempotency_key' => $idempotencyKey,
                    'active_key' => $this->activeFulfillmentKey((int)$item['id']),
                ];
                $payload = $this->withTimestamps($payload, true);
                $created = $this->dao->save($payload)->toArray();
                app()->make(YfthBenefitItemDao::class)->update((int)$item['id'], [
                    'fulfillment_status' => 'claimed',
                    'update_time' => $now,
                ]);
                $after = $this->requireRow($this->dao->get((int)$created['id']), 'fulfillment_not_found');
                $this->appendEvent($after, 'claim', '', self::STATUS_PENDING, 'user', $uid, 'customer', (int)$item['store_id'], 'user_claim', $idempotencyKey, [], $after);
                $this->recordAudit('benefit_fulfillment', (string)$after['id'], 'claim', [], $after, $uid, 'customer', (int)$item['store_id'], 'user_claim', $idempotencyKey);
                return $this->formatFulfillment($after, 'user_detail');
            });
            $idempotency->complete((int)$begin['record']['id'], ['fulfillment_id' => (int)$result['id'], 'status' => (string)$result['status']]);
            return ['fulfillment' => $result, 'idempotent_replay' => false];
        } catch (\Throwable $e) {
            $idempotency->fail((int)$begin['record']['id'], $e->getMessage());
            throw $e;
        }
    }

    public function cancelByUser(Request $request, int $id, array $data): array
    {
        $uid = (int)$request->uid();
        $idempotencyKey = $this->normalizeClientKey($data['idempotency_key'] ?? ($data['client_operation_key'] ?? ''), 'user_cancel:' . $id . ':' . $uid);
        return ['fulfillment' => $this->transition($id, [self::STATUS_PENDING, self::STATUS_CONFIRMED], self::STATUS_CANCELLED, [
            'operator_type' => 'user',
            'operator_uid' => $uid,
            'operator_role_code' => 'customer',
            'reason' => (string)($data['reason'] ?? 'user_cancel'),
            'idempotency_key' => $idempotencyKey,
            'user_uid_required' => $uid,
            'release_benefit' => true,
        ])];
    }

    public function adminList(array $where, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = $this->dao->search([]);
        foreach (['status', 'fulfillment_method'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (string)$where[$field]);
            }
        }
        foreach (['uid', 'store_id', 'pickup_store_id', 'benefit_item_id'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (int)$where[$field]);
            }
        }
        if (!empty($where['fulfillment_no'])) {
            $query->whereLike('fulfillment_no', '%' . trim((string)$where['fulfillment_no']) . '%');
        }
        $count = (int)(clone $query)->count();
        $list = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map(function ($row) {
                return $this->formatFulfillment($row, 'admin_list');
            }, $list),
            'count' => $count,
        ];
    }

    public function adminDetail(int $id, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $row = $this->requireRow($this->dao->get($id), 'fulfillment_not_found');
        return [
            'fulfillment' => $this->formatFulfillment($row, 'admin_detail'),
            'events' => $this->eventsFor($id, 'admin'),
        ];
    }

    public function adminConfirm(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return ['fulfillment' => $this->transition($id, [self::STATUS_PENDING], self::STATUS_CONFIRMED, $this->adminOperator($adminId, $data, 'confirm', $id))];
    }

    public function adminReject(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return ['fulfillment' => $this->transition($id, [self::STATUS_PENDING, self::STATUS_CONFIRMED], self::STATUS_REJECTED, array_merge($this->adminOperator($adminId, $data, 'reject', $id), ['release_benefit' => true]))];
    }

    public function adminPrepare(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return ['fulfillment' => $this->transition($id, [self::STATUS_CONFIRMED], self::STATUS_PREPARING, $this->adminOperator($adminId, $data, 'prepare', $id))];
    }

    public function adminShip(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $deliveryNo = trim((string)($data['delivery_no'] ?? ''));
        return ['fulfillment' => $this->transition($id, [self::STATUS_PREPARING], self::STATUS_SHIPPED, array_merge($this->adminOperator($adminId, $data, 'ship', $id), [
            'delivery_company' => trim((string)($data['delivery_company'] ?? '')),
            'delivery_no' => $deliveryNo,
        ]))];
    }

    public function adminComplete(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return ['fulfillment' => $this->transition($id, [self::STATUS_SHIPPED, self::STATUS_PICKED_UP], self::STATUS_COMPLETED, $this->adminOperator($adminId, $data, 'complete', $id))];
    }

    public function adminException(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return ['fulfillment' => $this->transition($id, [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_PREPARING, self::STATUS_SHIPPED], self::STATUS_EXCEPTION, $this->adminOperator($adminId, $data, 'exception', $id))];
    }

    public function adminCancel(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return ['fulfillment' => $this->transition($id, [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_PREPARING, self::STATUS_EXCEPTION], self::STATUS_CANCELLED, array_merge($this->adminOperator($adminId, $data, 'cancel', $id), ['release_benefit' => true]))];
    }

    public function storePickupList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = $this->dao->search([])
            ->where('fulfillment_method', self::METHOD_PICKUP)
            ->where('pickup_store_id', (int)$scope['context']['store_id']);
        if (!empty($where['status'])) {
            $query->where('status', (string)$where['status']);
        } else {
            $query->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_PREPARING, self::STATUS_COMPLETED]);
        }
        $count = (int)(clone $query)->count();
        $list = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map(function ($row) {
                return $this->formatFulfillment($row, 'store_list');
            }, $list),
            'count' => $count,
        ];
    }

    public function storePickupDetail(Request $request, int $id): array
    {
        $scope = $this->resolveStoreScope($request);
        $row = $this->requireRow($this->dao->search([])
            ->where('id', $id)
            ->where('fulfillment_method', self::METHOD_PICKUP)
            ->where('pickup_store_id', (int)$scope['context']['store_id'])
            ->find(), 'fulfillment_not_found');
        return [
            'fulfillment' => $this->formatFulfillment($row, 'store_detail'),
            'events' => $this->eventsFor($id, 'store'),
        ];
    }

    public function storePickupConfirm(Request $request, int $id, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        $context = $scope['context'];
        $idempotencyKey = $this->normalizeClientKey($data['idempotency_key'] ?? ($data['client_operation_key'] ?? ''), 'store_pickup:' . $id . ':' . (int)$context['store_id']);
        return ['fulfillment' => $this->transition($id, [self::STATUS_PREPARING], self::STATUS_COMPLETED, [
            'operator_type' => 'user_store_role',
            'operator_uid' => (int)$context['uid'],
            'operator_role_code' => (string)$context['role_code'],
            'store_id_required' => (int)$context['store_id'],
            'pickup_store_required' => (int)$context['store_id'],
            'reason' => (string)($data['reason'] ?? 'store_pickup_confirm'),
            'idempotency_key' => $idempotencyKey,
            'event_type' => 'pickup_confirm',
            'allow_pickup_direct_complete' => true,
        ])];
    }

    private function transition(int $id, array $fromStatuses, string $toStatus, array $operator): array
    {
        $idempotencyKey = (string)($operator['idempotency_key'] ?? $this->normalizeClientKey('', 'transition:' . $id . ':' . $toStatus));
        $eventType = (string)($operator['event_type'] ?? $this->eventTypeForStatus($toStatus));
        $idempotencyPayload = [
            'fulfillment_id' => $id,
            'to_status' => $toStatus,
            'operator_type' => (string)($operator['operator_type'] ?? ''),
            'operator_uid' => (int)($operator['operator_uid'] ?? 0),
            'reason' => (string)($operator['reason'] ?? ''),
            'delivery_company' => (string)($operator['delivery_company'] ?? ''),
            'delivery_no' => (string)($operator['delivery_no'] ?? ''),
        ];
        /** @var IdempotencyRecordServices $idempotency */
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin(self::FULFILLMENT_DOMAIN, $eventType, $idempotencyKey, $idempotencyPayload, 'fulfillment:' . $id);
        if (!$begin['acquired']) {
            $row = $this->requireRow($this->dao->get($id), 'fulfillment_not_found');
            $this->assertTransitionScope($row, $operator);
            if ((string)$row['status'] === $toStatus) {
                return $this->formatFulfillment($row, $this->formatScopeByOperator($operator));
            }
            throw new ApiException('idempotent_result_not_ready');
        }

        try {
            $result = $this->transaction(function () use ($id, $fromStatuses, $toStatus, $operator, $idempotencyKey, $eventType) {
            $row = $this->requireRow($this->dao->search([])->where('id', $id)->lock(true)->find(), 'fulfillment_not_found');
            $this->assertTransitionScope($row, $operator);
            if ((string)$row['status'] === $toStatus) {
                return $this->formatFulfillment($row, $this->formatScopeByOperator($operator));
            }
            if (!in_array((string)$row['status'], $fromStatuses, true)) {
                throw new ApiException('invalid_fulfillment_status_transition');
            }
            if ($toStatus === self::STATUS_SHIPPED) {
                if ((string)$row['fulfillment_method'] !== self::METHOD_EXPRESS) {
                    throw new ApiException('monthly_benefit_ship_only_express_delivery');
                }
                if (trim((string)($operator['delivery_company'] ?? '')) === '') {
                    throw new ApiException('monthly_benefit_delivery_company_required');
                }
                if (trim((string)($operator['delivery_no'] ?? '')) === '') {
                    throw new ApiException('monthly_benefit_delivery_no_required');
                }
            }
            if ($toStatus === self::STATUS_COMPLETED) {
                $this->assertCompletionPath($row, $operator);
            }

            $now = time();
            $update = [
                'status' => $toStatus,
                'operator_type' => (string)($operator['operator_type'] ?? ''),
                'operator_uid' => (int)($operator['operator_uid'] ?? 0),
                'operator_role_code' => (string)($operator['operator_role_code'] ?? ''),
                'reason' => (string)($operator['reason'] ?? ''),
                'update_time' => $now,
            ];
            if (in_array($toStatus, [self::STATUS_CANCELLED, self::STATUS_REJECTED], true)) {
                $update['active_key'] = null;
                $update[$toStatus === self::STATUS_CANCELLED ? 'cancelled_time' : 'cancelled_time'] = $now;
            }
            if ($toStatus === self::STATUS_CONFIRMED) {
                $update['confirmed_time'] = $now;
            } elseif ($toStatus === self::STATUS_PREPARING) {
                $update['prepared_time'] = $now;
            } elseif ($toStatus === self::STATUS_SHIPPED) {
                $update['shipped_time'] = $now;
                $update['delivery_company'] = (string)($operator['delivery_company'] ?? '');
                $update['delivery_no_masked'] = $this->maskRef((string)($operator['delivery_no'] ?? ''));
                $update['delivery_snapshot'] = $this->jsonEncode($this->sanitizeState([
                    'delivery_company' => (string)($operator['delivery_company'] ?? ''),
                    'delivery_no' => (string)($operator['delivery_no'] ?? ''),
                ]));
            } elseif ($toStatus === self::STATUS_COMPLETED) {
                if ((string)$row['fulfillment_method'] === self::METHOD_PICKUP) {
                    $update['picked_up_time'] = $now;
                }
                $update['completed_time'] = $now;
                $this->consumeProductBenefit($row, $operator, $idempotencyKey);
            } elseif ($toStatus === self::STATUS_EXCEPTION) {
                $update['exception_time'] = $now;
            }

            $this->dao->update((int)$row['id'], $update);
            if (!empty($operator['release_benefit'])) {
                app()->make(YfthBenefitItemDao::class)->update((int)$row['benefit_item_id'], [
                    'fulfillment_status' => 'none',
                    'update_time' => $now,
                ]);
            }
            $after = $this->requireRow($this->dao->get((int)$row['id']), 'fulfillment_not_found');
            $this->appendEvent($after, $eventType, (string)$row['status'], $toStatus, (string)$operator['operator_type'], (int)$operator['operator_uid'], (string)$operator['operator_role_code'], (int)($after['pickup_store_id'] ?: $after['store_id']), (string)($operator['reason'] ?? ''), $idempotencyKey, $row, $after);
            $this->recordAudit('benefit_fulfillment', (string)$after['id'], $eventType, $row, $after, (int)$operator['operator_uid'], (string)$operator['operator_role_code'], (int)($after['pickup_store_id'] ?: $after['store_id']), (string)($operator['reason'] ?? ''), $idempotencyKey);
            return $this->formatFulfillment($after, $this->formatScopeByOperator($operator));
            });
            $idempotency->complete((int)$begin['record']['id'], ['fulfillment_id' => (int)$result['id'], 'status' => (string)$result['status']]);
            return $result;
        } catch (\Throwable $e) {
            $idempotency->fail((int)$begin['record']['id'], $e->getMessage());
            throw $e;
        }
    }

    private function assertTransitionScope(array $row, array $operator): void
    {
        if (!empty($operator['user_uid_required']) && (int)$row['uid'] !== (int)$operator['user_uid_required']) {
            throw new ApiException('fulfillment_not_found');
        }
        if (!empty($operator['store_id_required']) && (int)$row['pickup_store_id'] !== (int)$operator['store_id_required']) {
            throw new ApiException('fulfillment_store_scope_forbidden');
        }
        if (!empty($operator['pickup_store_required']) && (int)$row['pickup_store_id'] !== (int)$operator['pickup_store_required']) {
            throw new ApiException('fulfillment_pickup_store_forbidden');
        }
    }

    private function assertCompletionPath(array $row, array $operator): void
    {
        $method = (string)$row['fulfillment_method'];
        $status = (string)$row['status'];
        if ($method === self::METHOD_EXPRESS) {
            if ($status !== self::STATUS_SHIPPED) {
                throw new ApiException('monthly_benefit_complete_requires_shipped');
            }
            return;
        }
        if ($method === self::METHOD_PICKUP) {
            if ($status === self::STATUS_PICKED_UP) {
                return;
            }
            if (!empty($operator['allow_pickup_direct_complete']) && $status === self::STATUS_PREPARING) {
                return;
            }
            throw new ApiException('monthly_benefit_pickup_complete_requires_pickup_confirm');
        }
        throw new ApiException('monthly_benefit_unknown_fulfillment_method');
    }

    private function consumeProductBenefit(array $fulfillment, array $operator, string $requestId): void
    {
        $rows = $this->lockBenefitRows((int)$fulfillment['benefit_item_id']);
        $item = $rows['item'];
        if ((string)$item['status'] === 'used' && (string)$item['fulfillment_status'] === 'product_fulfilled') {
            return;
        }
        $this->assertProductBenefitClaimable($rows, true);
        $quantityTotal = $this->normalizeMoney($item['quantity_total']);
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        $itemDao->update((int)$item['id'], [
            'status' => 'used',
            'fulfillment_status' => 'product_fulfilled',
            'quantity_available' => '0.00',
            'quantity_used' => $quantityTotal,
            'update_time' => time(),
        ]);
        $periodDao->update((int)$rows['period']['id'], [
            'fulfilled_item_count' => (int)$rows['period']['fulfilled_item_count'] + 1,
            'update_time' => time(),
        ]);
        $instanceDao->update((int)$rows['instance']['id'], [
            'fulfilled_count' => (int)$rows['instance']['fulfilled_count'] + 1,
            'update_time' => time(),
        ]);
        $after = $this->requireRow($itemDao->get((int)$item['id']), 'benefit_item_not_found');
        $this->recordPackageAudit('benefit_item', (string)$item['id'], 'product_fulfillment_complete', $item, $after, (int)$operator['operator_uid'], (string)$operator['operator_role_code'], (int)$fulfillment['store_id'], 'fulfillment:' . (int)$fulfillment['id'], $this->auditRequestId($requestId));
    }

    private function assertProductBenefitClaimable(array $rows, bool $allowClaimed = false): void
    {
        $item = $rows['item'];
        $instance = $rows['instance'];
        $plan = $rows['plan'];
        $period = $rows['period'];
        if ((string)$item['benefit_type'] !== 'product') {
            throw new ApiException('only_product_benefit_can_claim');
        }
        if ((string)$item['status'] !== 'available' || (float)$item['quantity_available'] <= 0) {
            throw new ApiException('benefit_item_not_available');
        }
        if (!$allowClaimed && !in_array((string)($item['fulfillment_status'] ?? ''), ['', 'none', 'unclaimed'], true)) {
            throw new ApiException('benefit_item_already_claimed');
        }
        if ((int)$item['expire_time'] > 0 && (int)$item['expire_time'] < time()) {
            throw new ApiException('benefit_item_expired');
        }
        if ((string)$instance['status'] !== 'active' || !in_array((string)($instance['refund_status'] ?? 'none'), ['', 'none'], true)) {
            throw new ApiException('package_instance_not_active');
        }
        if ((string)$plan['status'] !== 'active') {
            throw new ApiException('benefit_plan_not_active');
        }
        if ((string)$period['status'] !== 'available') {
            throw new ApiException('benefit_period_not_available');
        }
    }

    private function lockBenefitRows(int $benefitItemId): array
    {
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);

        $item = $this->requireRow($itemDao->search([])->where('id', $benefitItemId)->lock(true)->find(), 'benefit_item_not_found');
        $instance = $this->requireRow($instanceDao->search([])->where('id', (int)$item['package_instance_id'])->lock(true)->find(), 'package_instance_not_found');
        $plan = $this->requireRow($planDao->search([])->where('id', (int)$item['plan_id'])->lock(true)->find(), 'benefit_plan_not_found');
        $period = $this->requireRow($periodDao->search([])->where('id', (int)$item['period_id'])->lock(true)->find(), 'benefit_period_not_found');
        foreach ([
            [(int)$item['uid'], (int)$instance['uid'], 'benefit_item_instance_uid_mismatch'],
            [(int)$item['plan_id'], (int)$plan['id'], 'benefit_item_plan_mismatch'],
            [(int)$item['period_id'], (int)$period['id'], 'benefit_item_period_mismatch'],
            [(int)$plan['package_instance_id'], (int)$instance['id'], 'benefit_plan_instance_mismatch'],
            [(int)$period['package_instance_id'], (int)$instance['id'], 'benefit_period_instance_mismatch'],
        ] as $pair) {
            if ($pair[0] !== $pair[1]) {
                throw new ApiException($pair[2]);
            }
        }
        return compact('item', 'instance', 'plan', 'period');
    }

    private function resolveDeliverySnapshot(int $uid, string $method, array $data): array
    {
        $productSnapshot = [
            'product_id' => 0,
            'sku_unique' => '',
            'source' => 'benefit_item_snapshot',
        ];
        if ($method === self::METHOD_EXPRESS) {
            $addressId = (int)($data['address_id'] ?? 0);
            if ($addressId <= 0) {
                throw new ApiException('address_id_required');
            }
            $address = Db::name('user_address')
                ->where('id', $addressId)
                ->where('uid', $uid)
                ->where('is_del', 0)
                ->find();
            if (!$address) {
                throw new ApiException('address_not_found');
            }
            return [
                'product_snapshot' => $productSnapshot,
                'recipient_name_masked' => $this->maskName((string)($address['real_name'] ?? '')),
                'recipient_phone_masked' => $this->maskPhone((string)($address['phone'] ?? '')),
                'address_snapshot' => [
                    'address_id' => (int)$address['id'],
                    'real_name_masked' => $this->maskName((string)($address['real_name'] ?? '')),
                    'phone_masked' => $this->maskPhone((string)($address['phone'] ?? '')),
                    'province' => (string)($address['province'] ?? ''),
                    'city' => (string)($address['city'] ?? ''),
                    'district' => (string)($address['district'] ?? ''),
                    'detail_masked' => $this->maskAddress((string)($address['detail'] ?? '')),
                ],
                'pickup_store_id' => 0,
                'pickup_store_snapshot' => [],
            ];
        }

        $pickupStoreId = (int)($data['pickup_store_id'] ?? 0);
        if ($pickupStoreId <= 0) {
            throw new ApiException('pickup_store_id_required');
        }
        $store = app()->make(StoreAccessServices::class)->assertStoreActive($pickupStoreId);
        return [
            'product_snapshot' => $productSnapshot,
            'recipient_name_masked' => '',
            'recipient_phone_masked' => '',
            'address_snapshot' => [],
            'pickup_store_id' => $pickupStoreId,
            'pickup_store_snapshot' => $store,
        ];
    }

    private function benefitSnapshot(array $item, array $rows): array
    {
        return [
            'benefit_item_id' => (int)$item['id'],
            'benefit_code' => (string)$item['benefit_code'],
            'benefit_name' => (string)$item['benefit_name'],
            'benefit_type' => (string)$item['benefit_type'],
            'quantity_total' => (string)$item['quantity_total'],
            'month_no' => (int)$item['month_no'],
            'period_code' => (string)($rows['period']['period_code'] ?? ''),
            'package_instance_id' => (int)$item['package_instance_id'],
        ];
    }

    private function adminOperator(int $adminId, array $data, string $action, int $fulfillmentId = 0): array
    {
        return [
            'operator_type' => 'admin',
            'operator_uid' => $adminId,
            'operator_role_code' => 'headquarter_operator',
            'reason' => (string)($data['reason'] ?? $action),
            'idempotency_key' => $this->normalizeClientKey($data['idempotency_key'] ?? ($data['client_operation_key'] ?? ''), 'admin_' . $action . ':' . $fulfillmentId . ':' . $adminId),
        ];
    }

    private function assertHeadquarterAdmin(array $adminInfo): void
    {
        try {
            app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage() ?: 'headquarter_permission_required');
        }
    }

    private function resolveStoreScope(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $roleCode = (string)($context['role_code'] ?? '');
        if (!in_array($roleCode, ['franchisee', 'store_manager', 'store_staff'], true)) {
            throw new ApiException('store_workbench_role_forbidden');
        }
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new ApiException('store_id_required_for_store_workbench');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        return ['context' => $context];
    }

    private function appendEvent(array $fulfillment, string $type, string $from, string $to, string $operatorType, int $operatorUid, string $role, int $storeId, string $reason, string $idempotencyKey, array $before, array $after): void
    {
        app()->make(YfthBenefitFulfillmentEventDao::class)->save([
            'fulfillment_id' => (int)$fulfillment['id'],
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'operator_type' => $operatorType,
            'operator_uid' => $operatorUid,
            'operator_role_code' => $role,
            'store_id' => $storeId,
            'reason' => $reason,
            'before_state' => $this->jsonEncode($this->sanitizeState($before)),
            'after_state' => $this->jsonEncode($this->sanitizeState($after)),
            'idempotency_key' => $idempotencyKey,
            'create_time' => time(),
        ]);
    }

    private function recordAudit(string $objectType, string $objectId, string $action, array $before, array $after, int $operatorUid, string $roleCode, int $storeId, string $reason, string $requestId): void
    {
        app()->make(AuditEventServices::class)->recordSafely(self::FULFILLMENT_DOMAIN, $objectType, $objectId, $action, $before, $after, $operatorUid, $roleCode, $storeId, $reason, $this->auditRequestId($requestId));
    }

    private function auditRequestId(string $requestId): string
    {
        return strlen($requestId) <= 64
            ? $requestId
            : hash('sha256', self::FULFILLMENT_DOMAIN . ':' . $requestId);
    }

    private function eventsFor(int $id, string $scope): array
    {
        $rows = app()->make(YfthBenefitFulfillmentEventDao::class)
            ->selectList(['fulfillment_id' => $id], '*', 0, 0, 'id asc', [], false)
            ->toArray();
        return array_map(function ($row) use ($scope) {
            $payload = [
                'event_type' => (string)$row['event_type'],
                'from_status' => (string)$row['from_status'],
                'to_status' => (string)$row['to_status'],
                'reason' => (string)$row['reason'],
                'create_time' => (int)$row['create_time'],
            ];
            if ($scope !== 'user') {
                $payload['operator_type'] = (string)$row['operator_type'];
                $payload['operator_role_code'] = (string)$row['operator_role_code'];
            }
            return $payload;
        }, $rows);
    }

    private function formatBenefitItem(array $item): array
    {
        return [
            'id' => (int)$item['id'],
            'package_instance_id' => (int)$item['package_instance_id'],
            'benefit_plan_id' => (int)$item['plan_id'],
            'benefit_period_id' => (int)$item['period_id'],
            'store_id' => (int)$item['store_id'],
            'month_no' => (int)$item['month_no'],
            'benefit_code' => (string)$item['benefit_code'],
            'benefit_name' => (string)$item['benefit_name'],
            'benefit_type' => (string)$item['benefit_type'],
            'quantity_total' => (string)$item['quantity_total'],
            'quantity_available' => (string)$item['quantity_available'],
            'status' => (string)$item['status'],
            'fulfillment_status' => (string)($item['fulfillment_status'] ?? ''),
            'available_time' => (int)$item['available_time'],
            'expire_time' => (int)$item['expire_time'],
        ];
    }

    private function formatFulfillment(array $row, string $scope): array
    {
        $payload = [
            'id' => (int)$row['id'],
            'fulfillment_no' => (string)$row['fulfillment_no'],
            'uid' => (int)$row['uid'],
            'store_id' => (int)$row['store_id'],
            'package_instance_id' => (int)$row['package_instance_id'],
            'benefit_period_id' => (int)$row['benefit_period_id'],
            'benefit_item_id' => (int)$row['benefit_item_id'],
            'month_no' => (int)$row['month_no'],
            'period_code' => (string)$row['period_code'],
            'benefit_code' => (string)$row['benefit_code'],
            'benefit_name' => (string)$row['benefit_name'],
            'fulfillment_type' => (string)$row['fulfillment_type'],
            'fulfillment_method' => (string)$row['fulfillment_method'],
            'status' => (string)$row['status'],
            'quantity_total' => (string)$row['quantity_total'],
            'recipient_name_masked' => (string)$row['recipient_name_masked'],
            'recipient_phone_masked' => (string)$row['recipient_phone_masked'],
            'pickup_store_id' => (int)$row['pickup_store_id'],
            'delivery_company' => (string)$row['delivery_company'],
            'delivery_no_masked' => (string)$row['delivery_no_masked'],
            'claim_time' => (int)$row['claim_time'],
            'confirmed_time' => (int)$row['confirmed_time'],
            'prepared_time' => (int)$row['prepared_time'],
            'shipped_time' => (int)$row['shipped_time'],
            'picked_up_time' => (int)$row['picked_up_time'],
            'completed_time' => (int)$row['completed_time'],
            'cancelled_time' => (int)$row['cancelled_time'],
            'exception_time' => (int)$row['exception_time'],
            'reason' => (string)$row['reason'],
            'create_time' => (int)$row['create_time'],
        ];
        if ($scope !== 'user_list') {
            $payload['address_snapshot'] = $this->jsonDecode($row['address_snapshot'] ?? '');
            $payload['pickup_store_snapshot'] = $this->jsonDecode($row['pickup_store_snapshot'] ?? '');
        }
        if (strpos($scope, 'admin') === 0) {
            $payload['operator_type'] = (string)$row['operator_type'];
            $payload['operator_uid'] = (int)$row['operator_uid'];
            $payload['operator_role_code'] = (string)$row['operator_role_code'];
            $payload['product_snapshot'] = $this->jsonDecode($row['product_snapshot'] ?? '');
            $payload['benefit_snapshot'] = $this->jsonDecode($row['benefit_snapshot'] ?? '');
        }
        return $payload;
    }

    private function formatScopeByOperator(array $operator): string
    {
        return ((string)($operator['operator_type'] ?? '') === 'admin') ? 'admin_detail' : 'user_detail';
    }

    private function eventTypeForStatus(string $status): string
    {
        return [
            self::STATUS_CONFIRMED => 'confirm',
            self::STATUS_PREPARING => 'prepare',
            self::STATUS_SHIPPED => 'ship',
            self::STATUS_COMPLETED => 'complete',
            self::STATUS_CANCELLED => 'cancel',
            self::STATUS_REJECTED => 'reject',
            self::STATUS_EXCEPTION => 'exception',
        ][$status] ?? $status;
    }

    private function findByIdempotencyKey(string $key): ?array
    {
        $row = $this->dao->search([])->where('idempotency_key', $key)->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    private function findActiveByItemId(int $itemId, bool $lock = false): ?array
    {
        $query = $this->dao->search([])->where('active_key', $this->activeFulfillmentKey($itemId));
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    private function activeFulfillmentKey(int $itemId): string
    {
        return 'benefit_item:' . $itemId;
    }

    private function normalizeClientKey($value, string $fallback): string
    {
        $value = trim((string)$value);
        if ($value !== '') {
            return 'monthly_benefit:' . hash('sha256', $value);
        }
        return 'monthly_benefit:auto:' . hash('sha256', $fallback);
    }

    private function normalizeRequiredClientKey($value, string $action): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            throw new ApiException('monthly_benefit_idempotency_key_required:' . $action);
        }
        return 'monthly_benefit:' . hash('sha256', $value);
    }

    private function assertClaimPayload(array $data): void
    {
        foreach (['uid', 'owner_uid', 'store_id', 'package_instance_id', 'benefit_plan_id', 'benefit_period_id', 'status', 'product_snapshot', 'quantity_used', 'active_key'] as $field) {
            if (array_key_exists($field, $data)) {
                throw new ApiException('monthly_benefit_claim_field_forbidden:' . $field);
            }
        }
    }

    private function assertUserReadonlyPayload(array $where): void
    {
        foreach (['uid', 'owner_uid', 'store_id', 'operator_uid', 'idempotency_key', 'active_key'] as $field) {
            if (array_key_exists($field, $where)) {
                throw new ApiException('monthly_benefit_user_field_forbidden:' . $field);
            }
        }
    }

    private function maskName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 1, 'UTF-8') . '*';
        }
        return substr($name, 0, 1) . '*';
    }

    private function maskAddress(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($address, 'UTF-8') > 8) {
            return mb_substr($address, 0, 6, 'UTF-8') . '***' . mb_substr($address, -2, 2, 'UTF-8');
        }
        return '***';
    }
}
