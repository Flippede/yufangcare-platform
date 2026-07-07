<?php

namespace app\services\yfth;

use app\Request;
use app\dao\order\StoreOrderCartInfoDao;
use app\dao\order\StoreOrderDao;
use app\dao\yfth\YfthServiceAppointmentDao;
use app\dao\yfth\YfthServiceWriteoffRecordDao;
use crmeb\exceptions\ApiException;

class StoreWorkbenchBusinessAdapterServices extends YfthFoundationBaseServices
{
    private const STORE_ROLES = ['franchisee', 'store_manager', 'store_staff'];
    private const STORE_OPERATE_ROLES = ['franchisee', 'store_manager'];

    public function overview(Request $request): array
    {
        $scope = $this->resolveStoreScope($request);
        $storeId = (int)$scope['context']['store_id'];
        [$dayStart, $dayEnd] = $this->todayRange();
        $today = (int)date('Ymd', $dayStart);

        $appointmentDao = app()->make(YfthServiceAppointmentDao::class);
        $writeoffDao = app()->make(YfthServiceWriteoffRecordDao::class);
        $orderDao = app()->make(StoreOrderDao::class);

        return [
            'context' => $this->formatContext($scope['context']),
            'metrics' => [
                'today_appointments' => (int)$appointmentDao->search([])
                    ->where('store_id', $storeId)->where('service_date', $today)->count(),
                'pending_confirm' => (int)$appointmentDao->search([])
                    ->where('store_id', $storeId)->where('status', ServiceAppointmentBookingServices::STATUS_PENDING)->count(),
                'confirmed_waiting_arrival' => (int)$appointmentDao->search([])
                    ->where('store_id', $storeId)->where('status', ServiceAppointmentBookingServices::STATUS_CONFIRMED)->count(),
                'today_completed' => (int)$appointmentDao->search([])
                    ->where('store_id', $storeId)->where('status', ServiceAppointmentBookingServices::STATUS_COMPLETED)
                    ->whereBetween('completed_at', [$dayStart, $dayEnd])->count(),
                'today_writeoffs' => (int)$writeoffDao->search([])
                    ->where('store_id', $storeId)->where('status', 'succeeded')
                    ->whereBetween('writeoff_time', [$dayStart, $dayEnd])->count(),
                'today_store_orders' => (int)$orderDao->search([])
                    ->where('store_id', $storeId)->where('pid', 0)->where('paid', 1)
                    ->where('refund_status', 0)->where('is_del', 0)->where('is_system_del', 0)
                    ->whereBetween('pay_time', [$dayStart, $dayEnd])->count(),
                'today_store_order_amount' => (string)$orderDao->search([])
                    ->where('store_id', $storeId)->where('pid', 0)->where('paid', 1)
                    ->where('refund_status', 0)->where('is_del', 0)->where('is_system_del', 0)
                    ->whereBetween('pay_time', [$dayStart, $dayEnd])->sum('pay_price'),
                'pending_store_orders' => (int)$orderDao->search([])
                    ->where('store_id', $storeId)->where('pid', 0)->where('paid', 1)
                    ->where('status', 0)->whereIn('refund_status', [0, 3])
                    ->where('is_del', 0)->where('is_system_del', 0)->count(),
            ],
            'permissions' => [
                'can_manage_appointment' => in_array((string)$scope['context']['role_code'], self::STORE_OPERATE_ROLES, true),
                'can_writeoff' => true,
                'can_read_orders' => true,
                'headquarter_exception_writeoff' => false,
            ],
            'server_time' => time(),
        ];
    }

    public function appointmentList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request);
        $where['store_id'] = (int)$scope['context']['store_id'];
        $result = app()->make(ServiceAppointmentBookingServices::class)->storeOperatorList($where, $scope['operator_info']);
        $result['list'] = array_map(function ($row) use ($scope) {
            return $this->formatStoreAppointment($row, false, (string)$scope['context']['role_code']);
        }, $result['list'] ?? []);
        return $result;
    }

    public function appointmentDetail(Request $request, int $appointmentId): array
    {
        $scope = $this->resolveStoreScope($request);
        $result = app()->make(ServiceAppointmentBookingServices::class)->storeOperatorDetail($appointmentId, $scope['operator_info']);
        $appointment = $this->formatStoreAppointment($result, true, (string)$scope['context']['role_code']);
        $appointment['events'] = array_map(function ($event) {
            return [
                'event_type' => (string)($event['event_type'] ?? ''),
                'from_status' => (string)($event['from_status'] ?? ''),
                'to_status' => (string)($event['to_status'] ?? ''),
                'reason' => (string)($event['reason'] ?? ''),
                'add_time' => (int)($event['add_time'] ?? 0),
            ];
        }, $result['events'] ?? []);
        $appointment['writeoff_result'] = $result['writeoff_result'] ?? ['status' => 'none'];
        return ['appointment' => $appointment];
    }

    public function confirmAppointment(Request $request, int $appointmentId, string $reason, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        $this->assertAppointmentOperateRole((string)$scope['context']['role_code']);
        return app()->make(ServiceAppointmentBookingServices::class)
            ->confirmByStoreOperator($appointmentId, $reason, $scope['operator_info'], $data);
    }

    public function rejectAppointment(Request $request, int $appointmentId, string $reason, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        $this->assertAppointmentOperateRole((string)$scope['context']['role_code']);
        return app()->make(ServiceAppointmentBookingServices::class)
            ->rejectByStoreOperator($appointmentId, $reason, $scope['operator_info'], $data);
    }

    public function cancelAppointment(Request $request, int $appointmentId, string $reason, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        $this->assertAppointmentOperateRole((string)$scope['context']['role_code']);
        return app()->make(ServiceAppointmentBookingServices::class)
            ->cancelByStoreOperator($appointmentId, $reason, $scope['operator_info'], $data);
    }

    public function writeoffPrecheck(Request $request, string $qrToken, string $digitalCode): array
    {
        $scope = $this->resolveStoreScope($request);
        $services = app()->make(ServiceAppointmentWriteoffServices::class);
        if (trim($qrToken) !== '') {
            return $services->precheckByStoreToken($qrToken, $scope['operator_info']);
        }
        return $services->precheckByStoreDigital($digitalCode, $scope['operator_info']);
    }

    public function writeoffByToken(Request $request, string $qrToken, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        return app()->make(ServiceAppointmentWriteoffServices::class)
            ->writeoffByStoreToken($qrToken, $scope['operator_info'], $data);
    }

    public function writeoffByDigital(Request $request, string $digitalCode, array $data): array
    {
        $scope = $this->resolveStoreScope($request);
        return app()->make(ServiceAppointmentWriteoffServices::class)
            ->writeoffByStoreDigital($digitalCode, $scope['operator_info'], $data);
    }

    public function writeoffList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request);
        $where['store_id'] = (int)$scope['context']['store_id'];
        $result = app()->make(ServiceAppointmentWriteoffServices::class)->storeOperatorList($where, $scope['operator_info']);
        $result['list'] = array_map(function ($row) {
            return $this->formatStoreWriteoffRecord($row);
        }, $result['list'] ?? []);
        return $result;
    }

    public function writeoffDetail(Request $request, int $id): array
    {
        $scope = $this->resolveStoreScope($request);
        $result = app()->make(ServiceAppointmentWriteoffServices::class)->storeOperatorDetail($id, $scope['operator_info']);
        return ['record' => $this->formatStoreWriteoffRecord($result['record'] ?? [])];
    }

    public function writeoffResult(Request $request, int $appointmentId): array
    {
        $this->resolveStoreScope($request);
        return app()->make(ServiceAppointmentWriteoffServices::class)->writeoffResultForAppointment($appointmentId);
    }

    public function orderList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request);
        $storeId = (int)$scope['context']['store_id'];
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;

        $buildQuery = function () use ($storeId, $where) {
            $filter = [
                'store_id' => $storeId,
                'pid' => 0,
                'is_del' => 0,
                'is_system_del' => 0,
            ];
            if (($where['status'] ?? '') !== '') {
                $filter['status'] = $where['status'];
            }
            $query = app()->make(StoreOrderDao::class)->search($filter)
                ->where('store_id', $storeId)
                ->where('pid', 0)
                ->where('is_del', 0)
                ->where('is_system_del', 0);
            $orderSn = trim((string)($where['order_sn'] ?? ''));
            if ($orderSn !== '') {
                $query->whereLike('order_id', '%' . $orderSn . '%');
            }
            [$start, $end] = $this->dateRange($where['start_date'] ?? ($where['date'] ?? ''), $where['end_date'] ?? ($where['date'] ?? ''));
            if ($start && $end) {
                $query->whereBetween('add_time', [$start, $end]);
            }
            return $query;
        };

        $count = (int)$buildQuery()->count();
        $list = $buildQuery()
            ->field('id,order_id,uid,store_id,real_name,user_phone,total_num,total_price,total_postage,pay_price,pay_postage,paid,pay_type,status,refund_status,shipping_type,delivery_type,add_time,pay_time')
            ->page($page, $limit)
            ->order('add_time desc,id desc')
            ->select()
            ->toArray();

        return [
            'list' => array_map(function ($row) {
                return $this->formatStoreOrder($row, false);
            }, $list),
            'count' => $count,
        ];
    }

    public function orderDetail(Request $request, int $id): array
    {
        $scope = $this->resolveStoreScope($request);
        $storeId = (int)$scope['context']['store_id'];
        $row = app()->make(StoreOrderDao::class)->search([])
            ->where('id', $id)
            ->where('store_id', $storeId)
            ->where('pid', 0)
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->field('id,order_id,uid,store_id,real_name,user_phone,user_address,total_num,total_price,total_postage,pay_price,pay_postage,paid,pay_type,status,refund_status,shipping_type,delivery_type,add_time,pay_time')
            ->find();
        if (!$row) {
            throw new ApiException('store_order_not_found');
        }
        $order = $this->formatStoreOrder(is_array($row) ? $row : $row->toArray(), true);
        $order['items'] = $this->orderItems((int)$order['id']);
        return ['order' => $order];
    }

    private function resolveStoreScope(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $roleCode = (string)($context['role_code'] ?? '');
        if (!in_array($roleCode, self::STORE_ROLES, true)) {
            throw new ApiException('store_workbench_role_forbidden');
        }
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new ApiException('store_id_required_for_store_workbench');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        return [
            'context' => $context,
            'operator_info' => $this->storeOperatorInfo($context),
        ];
    }

    private function storeOperatorInfo(array $context): array
    {
        $uid = (int)($context['uid'] ?? 0);
        $storeId = (int)($context['store_id'] ?? 0);
        $roleCode = (string)($context['role_code'] ?? '');
        return [
            'yfth_operator_context' => [
                'operator_type' => AdminStoreContextServices::OPERATOR_USER_STORE_ROLE,
                'operator_uid' => $uid,
                'role_code' => $roleCode,
                'store_id' => $storeId,
                'authorized_store_ids' => [$storeId],
                'primary_role_code' => $roleCode,
                'permission_scope' => (array)($context['permission_scope'] ?? []),
                'allowed_actions' => $roleCode === 'store_staff'
                    ? ['appointment.read', 'writeoff.execute', 'order.read']
                    : ['appointment.read', 'appointment.confirm', 'appointment.reject', 'appointment.cancel', 'writeoff.execute', 'order.read'],
                'source' => 'yfth_user_token_store_workbench',
            ],
        ];
    }

    private function assertAppointmentOperateRole(string $roleCode): void
    {
        if (!in_array($roleCode, self::STORE_OPERATE_ROLES, true)) {
            throw new ApiException('store_staff_can_read_appointment_only');
        }
    }

    private function formatContext(array $context): array
    {
        return [
            'uid' => (int)($context['uid'] ?? 0),
            'role_code' => (string)($context['role_code'] ?? ''),
            'role_name' => (string)($context['role_name'] ?? ''),
            'store_id' => (int)($context['store_id'] ?? 0),
            'store_name' => (string)($context['store_name'] ?? ''),
            'store_status' => (string)($context['store_status'] ?? ''),
            'capabilities' => array_values((array)($context['capabilities'] ?? [])),
            'business_context_source' => (string)($context['business_context_source'] ?? ''),
        ];
    }

    private function formatStoreAppointment(array $row, bool $detail, string $roleCode): array
    {
        $service = $this->jsonDecode($row['service_snapshot'] ?? '');
        $benefit = $this->jsonDecode($row['benefit_snapshot'] ?? '');
        $status = (string)($row['status'] ?? '');
        $canOperate = in_array($roleCode, self::STORE_OPERATE_ROLES, true);
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'appointment_no' => (string)($row['appointment_no'] ?? ''),
            'uid' => (int)($row['uid'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'service_project_id' => (int)($row['service_project_id'] ?? 0),
            'service_name' => (string)($service['project']['service_name'] ?? $service['project']['name'] ?? ''),
            'benefit_name' => (string)($benefit['benefit_name'] ?? ''),
            'status' => $status,
            'status_text' => $this->appointmentStatusText($status),
            'confirm_mode' => (string)($row['confirm_mode'] ?? ''),
            'date_text' => (string)($row['date_text'] ?? $this->serviceDateText((int)($row['service_date'] ?? 0))),
            'start_time_text' => (string)($row['start_time_text'] ?? $this->minuteText((int)($row['start_minute'] ?? 0))),
            'end_time_text' => (string)($row['end_time_text'] ?? $this->minuteText((int)($row['end_minute'] ?? 0))),
            'actions' => [
                'can_confirm' => $canOperate && $status === ServiceAppointmentBookingServices::STATUS_PENDING,
                'can_reject' => $canOperate && $status === ServiceAppointmentBookingServices::STATUS_PENDING,
                'can_cancel' => $canOperate && in_array($status, [ServiceAppointmentBookingServices::STATUS_PENDING, ServiceAppointmentBookingServices::STATUS_CONFIRMED], true),
                'can_writeoff' => $status === ServiceAppointmentBookingServices::STATUS_CONFIRMED,
            ],
        ];
        if ($detail) {
            $payload['user_note'] = (string)($row['user_note'] ?? '');
            $payload['cancel_reason'] = (string)($row['cancel_reason'] ?? '');
            $payload['reject_reason'] = (string)($row['reject_reason'] ?? '');
            $payload['check_in_at'] = (int)($row['check_in_at'] ?? 0);
            $payload['writeoff_at'] = (int)($row['writeoff_at'] ?? 0);
            $payload['completed_at'] = (int)($row['completed_at'] ?? 0);
            $payload['writeoff_method'] = (string)($row['writeoff_method'] ?? '');
        }
        return $payload;
    }

    private function formatStoreWriteoffRecord(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'writeoff_no' => (string)($row['writeoff_no'] ?? ''),
            'appointment_id' => (int)($row['appointment_id'] ?? 0),
            'uid' => (int)($row['uid'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'service_project_id' => (int)($row['service_project_id'] ?? 0),
            'writeoff_method' => (string)($row['writeoff_method'] ?? ''),
            'operator_type' => (string)($row['operator_type'] ?? ''),
            'operator_role_code' => (string)($row['operator_role_code'] ?? ''),
            'writeoff_time' => (int)($row['writeoff_time'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
        ];
    }

    private function formatStoreOrder(array $row, bool $detail): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'order_id' => (string)($row['order_id'] ?? ''),
            'uid' => (int)($row['uid'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'real_name_masked' => $this->maskName((string)($row['real_name'] ?? '')),
            'user_phone_masked' => $this->maskPhone((string)($row['user_phone'] ?? '')),
            'total_num' => (int)($row['total_num'] ?? 0),
            'total_price' => (string)($row['total_price'] ?? '0'),
            'pay_price' => (string)($row['pay_price'] ?? '0'),
            'pay_postage' => (string)($row['pay_postage'] ?? '0'),
            'paid' => (int)($row['paid'] ?? 0),
            'pay_type' => (string)($row['pay_type'] ?? ''),
            'status' => (int)($row['status'] ?? 0),
            'refund_status' => (int)($row['refund_status'] ?? 0),
            'status_text' => $this->orderStatusText($row),
            'shipping_type' => (int)($row['shipping_type'] ?? 0),
            'delivery_type' => (string)($row['delivery_type'] ?? ''),
            'add_time' => (int)($row['add_time'] ?? 0),
            'pay_time' => (int)($row['pay_time'] ?? 0),
        ];
        if ($detail) {
            $payload['user_address_masked'] = $this->maskAddress((string)($row['user_address'] ?? ''));
        }
        return $payload;
    }

    private function orderItems(int $orderId): array
    {
        $rows = app()->make(StoreOrderCartInfoDao::class)
            ->getCartInfoList(['oid' => $orderId], ['cart_num', 'refund_num', 'cart_info']);
        return array_map(function ($row, $index) {
            $cart = is_string($row['cart_info'] ?? null) ? json_decode($row['cart_info'], true) : ($row['cart_info'] ?? []);
            $product = $cart['productInfo'] ?? [];
            $attr = $product['attrInfo'] ?? [];
            return [
                'item_key' => 'order_item_' . (int)$index,
                'product_name' => (string)($product['store_name'] ?? ''),
                'image' => (string)($attr['image'] ?? $product['image'] ?? ''),
                'sku' => (string)($attr['suk'] ?? ''),
                'cart_num' => (int)($row['cart_num'] ?? $cart['cart_num'] ?? 0),
                'refund_num' => (int)($row['refund_num'] ?? 0),
                'true_price' => (string)($cart['truePrice'] ?? $attr['price'] ?? $product['price'] ?? '0'),
            ];
        }, $rows, array_keys($rows));
    }

    private function appointmentStatusText(string $status): string
    {
        $map = [
            ServiceAppointmentBookingServices::STATUS_PENDING => '待确认',
            ServiceAppointmentBookingServices::STATUS_CONFIRMED => '待到店',
            ServiceAppointmentBookingServices::STATUS_REJECTED => '已拒绝',
            ServiceAppointmentBookingServices::STATUS_CANCELLED => '已取消',
            ServiceAppointmentBookingServices::STATUS_COMPLETED => '已完成',
        ];
        return $map[$status] ?? $status;
    }

    private function orderStatusText(array $row): string
    {
        if ((int)($row['refund_status'] ?? 0) === 1) {
            return '退款中';
        }
        if ((int)($row['refund_status'] ?? 0) === 2) {
            return '已退款';
        }
        if ((int)($row['paid'] ?? 0) !== 1) {
            return '待支付';
        }
        $map = [
            0 => '待发货/待核销',
            1 => '待收货',
            2 => '待评价',
            3 => '已完成',
            4 => '部分发货',
        ];
        return $map[(int)($row['status'] ?? 0)] ?? '处理中';
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

    private function todayRange(): array
    {
        $start = strtotime(date('Y-m-d 00:00:00'));
        return [$start, $start + 86399];
    }

    private function dateRange($startDate, $endDate): array
    {
        $startDate = trim((string)$startDate);
        $endDate = trim((string)$endDate);
        if ($startDate === '' && $endDate === '') {
            return [0, 0];
        }
        $start = strtotime(($startDate ?: $endDate) . ' 00:00:00');
        $end = strtotime(($endDate ?: $startDate) . ' 23:59:59');
        if (!$start || !$end || $end < $start) {
            throw new ApiException('invalid_order_date_range');
        }
        return [$start, $end];
    }
}
