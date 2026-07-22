<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthBenefitTemplateDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthServiceAppointmentDao;
use app\dao\yfth\YfthServiceAppointmentEventDao;
use app\dao\yfth\YfthServiceAppointmentSlotDao;
use app\dao\yfth\YfthServiceBenefitLockDao;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class ServiceAppointmentBookingServices extends ServiceAppointmentBaseServices
{
    public const STATUS_PENDING = 'pending_confirm';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SIGNED_IN = 'signed_in';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_SHOW = 'no_show';

    public function __construct(YfthServiceAppointmentDao $dao)
    {
        $this->dao = $dao;
    }

    public function availableBenefits(int $uid, array $where = []): array
    {
        if ($uid <= 0) {
            throw new ApiException('user_login_required');
        }
        $projectId = (int)($where['service_project_id'] ?? 0);
        $project = $projectId > 0 ? app()->make(ServiceProjectServices::class)->requireActiveProject($projectId) : [];
        $allowedTemplateIds = $project ? $this->projectBenefitTemplateIds($project) : [];

        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        $query = $itemDao->search([])
            ->where('uid', $uid)
            ->where('benefit_type', 'service')
            ->where('status', 'available')
            ->where('quantity_available', '>', 0)
            ->where(function ($query) {
                $query->where('expire_time', '=', 0)->whereOr('expire_time', '>', time());
            });
        if ($allowedTemplateIds) {
            $query->whereIn('benefit_template_id', $allowedTemplateIds);
        }
        if (!empty($where['package_instance_id'])) {
            $query->where('package_instance_id', (int)$where['package_instance_id']);
        }
        $rows = $query->order('expire_time asc,id asc')->select()->toArray();
        $rows = array_values(array_filter($rows, function ($row) {
            return !$this->activeBenefitLockExists((int)$row['id']);
        }));
        return ['status' => 'ok', 'list' => array_map([$this, 'formatBenefitItem'], $rows), 'count' => count($rows)];
    }

    public function createAppointment(int $uid, array $data): array
    {
        if ($uid <= 0) {
            throw new ApiException('user_login_required');
        }
        $key = $this->writeKey('create', $uid, 0, $data);
        return $this->runIdempotent('create', $key, ['uid' => $uid, 'data' => $data], '', function ($requestId) use ($uid, $data, $key) {
            return $this->transaction(function () use ($uid, $data, $key, $requestId) {
                $context = $this->bookingContext($uid, $data, true);
                $slot = $this->getOrCreateSlotLocked($context['binding'], $context['slot_data']);
                $this->assertSlotCanHold($slot);
                $benefit = $this->lockBenefitItem($uid, (int)$data['benefit_item_id'], $context['project']);
                $status = (int)$context['binding']['requires_confirmation'] ? self::STATUS_PENDING : self::STATUS_CONFIRMED;
                $this->increaseSlot($slot, $status);

                $now = time();
                $appointment = $this->dao->save($this->withTimestamps([
                    'appointment_no' => $this->makeNo('SA'),
                    'uid' => $uid,
                    'store_id' => (int)$context['binding']['store_id'],
                    'store_service_id' => (int)$context['binding']['id'],
                    'service_project_id' => (int)$context['binding']['service_project_id'],
                    'slot_id' => (int)$slot['id'],
                    'package_instance_id' => (int)$benefit['package_instance_id'],
                    'benefit_plan_id' => (int)$benefit['plan_id'],
                    'benefit_period_id' => (int)$benefit['period_id'],
                    'benefit_item_id' => (int)$benefit['id'],
                    'service_date' => (int)$context['slot_data']['service_date'],
                    'start_minute' => (int)$context['slot_data']['start_minute'],
                    'end_minute' => (int)$context['slot_data']['end_minute'],
                    'start_time' => (int)$context['slot_data']['start_timestamp'],
                    'end_time' => (int)$context['slot_data']['end_timestamp'],
                    'duration_minutes' => (int)$context['binding']['duration_minutes'],
                    'status' => $status,
                    'confirm_mode' => (int)$context['binding']['requires_confirmation'] ? 'manual' : 'auto',
                    'source_type' => 'package_5980_benefit',
                    'user_note' => trim((string)($data['user_note'] ?? '')),
                    'confirm_time' => $status === self::STATUS_CONFIRMED ? $now : 0,
                    'idempotency_key' => $key,
                    'request_id' => $requestId,
                    'store_snapshot' => $this->jsonEncode($context['store']),
                    'service_snapshot' => $this->jsonEncode([
                        'project' => $context['project'],
                        'binding' => $context['binding'],
                        'slot' => $context['slot_data'],
                    ]),
                    'benefit_snapshot' => $this->jsonEncode($this->formatBenefitItem($benefit)),
                ], true));
                $appointmentId = (int)$appointment->id;
                $this->createBenefitLock($uid, $appointmentId, $benefit);
                $after = $this->appointmentById($appointmentId);
                $this->recordEvent($appointmentId, 'create', '', $status, 'user', $uid, (int)$after['store_id'], [], $after, 'user_create', $requestId);
                $this->recordServiceAudit('appointment', (string)$appointmentId, 'create', [], $after, $uid, 'user', (int)$after['store_id'], 'user_create', $requestId);
                return ['status' => 'ok', 'appointment' => $this->formatAppointment($after)];
            });
        });
    }

    public function userList(int $uid, array $where = []): array
    {
        if ($uid <= 0) {
            throw new ApiException('user_login_required');
        }
        $filter = $this->cleanWhere([
            'uid' => $uid,
            'status' => $where['status'] ?? '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
        ]);
        return $this->pageList($filter, '*', 'service_date desc,start_minute desc,id desc', function ($row) {
            return $this->formatPublicAppointment($row, false);
        });
    }

    public function userDetail(int $uid, int $appointmentId): array
    {
        $row = $this->appointmentById($appointmentId);
        if ((int)$row['uid'] !== $uid) {
            throw new ApiException('appointment_forbidden');
        }
        return $this->publicDetailPayload($row);
    }

    public function cancelByUser(int $uid, int $appointmentId, string $reason, array $data = []): array
    {
        $key = $this->writeKey('user_cancel', $uid, $appointmentId, $data + ['reason' => $reason]);
        return $this->runIdempotent('user_cancel', $key, ['uid' => $uid, 'id' => $appointmentId, 'reason' => $reason], (string)$appointmentId, function ($requestId) use ($uid, $appointmentId, $reason) {
            return $this->transaction(function () use ($uid, $appointmentId, $reason, $requestId) {
                $row = $this->lockAppointment($appointmentId);
                if ((int)$row['uid'] !== $uid) {
                    throw new ApiException('appointment_forbidden');
                }
                $this->assertUserCancelable($row);
                return $this->closeAppointment($row, self::STATUS_CANCELLED, 'user_cancel', 'user', $uid, $reason, $requestId);
            });
        });
    }

    public function rescheduleSlots(int $uid, int $appointmentId, array $where): array
    {
        $row = $this->appointmentById($appointmentId);
        if ((int)$row['uid'] !== $uid) {
            throw new ApiException('appointment_forbidden');
        }
        if (!in_array((string)$row['status'], [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)) {
            return ['status' => 'unavailable', 'reason' => 'appointment_status_not_reschedulable', 'slots' => []];
        }
        return app()->make(ServiceAppointmentQueryServices::class)->daySlots((int)$row['service_project_id'], [
            'store_id' => (int)$row['store_id'],
            'date' => $where['date'] ?? $this->serviceDateText((int)$row['service_date']),
        ]);
    }

    public function rescheduleByUser(int $uid, int $appointmentId, array $data): array
    {
        $key = $this->writeKey('user_reschedule', $uid, $appointmentId, $data);
        return $this->runIdempotent('user_reschedule', $key, ['uid' => $uid, 'id' => $appointmentId, 'data' => $data], (string)$appointmentId, function ($requestId) use ($uid, $appointmentId, $data) {
            return $this->runWithDeadlockRetry(function () use ($uid, $appointmentId, $data, $requestId) {
                return $this->transaction(function () use ($uid, $appointmentId, $data, $requestId) {
                $row = $this->lockAppointment($appointmentId);
                if ((int)$row['uid'] !== $uid) {
                    throw new ApiException('appointment_forbidden');
                }
                if (!in_array((string)$row['status'], [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)) {
                    throw new ApiException('appointment_status_not_reschedulable');
                }
                $context = $this->bookingContext($uid, [
                    'store_id' => (int)$row['store_id'],
                    'service_project_id' => (int)$row['service_project_id'],
                    'benefit_item_id' => (int)$row['benefit_item_id'],
                    'date' => $data['date'] ?? '',
                    'start_minute' => $data['start_minute'] ?? 0,
                ], false);
                $newSlotId = $this->ensureSlotExists($context['binding'], $context['slot_data']);
                if ((int)$newSlotId === (int)$row['slot_id']) {
                    return ['status' => 'ok', 'appointment' => $this->formatAppointment($row), 'unchanged' => true];
                }
                [$oldSlot, $newSlot] = $this->lockSlotPairById((int)$row['slot_id'], $newSlotId);
                $this->refreshSlotCapacityIfIdle($newSlot, $context['slot_data']);
                $this->assertSlotCanHold($newSlot);
                $this->increaseSlot($newSlot, (string)$row['status']);
                $this->decreaseSlot($oldSlot, (string)$row['status']);
                $before = $row;
                $update = [
                    'slot_id' => (int)$newSlot['id'],
                    'service_date' => (int)$context['slot_data']['service_date'],
                    'start_minute' => (int)$context['slot_data']['start_minute'],
                    'end_minute' => (int)$context['slot_data']['end_minute'],
                    'start_time' => (int)$context['slot_data']['start_timestamp'],
                    'end_time' => (int)$context['slot_data']['end_timestamp'],
                    'reschedule_count' => (int)$row['reschedule_count'] + 1,
                    'request_id' => $requestId,
                    'service_snapshot' => $this->jsonEncode([
                        'project' => $context['project'],
                        'binding' => $context['binding'],
                        'slot' => $context['slot_data'],
                    ]),
                    'update_time' => time(),
                ];
                $this->dao->update((int)$row['id'], $update);
                $after = $this->appointmentById((int)$row['id']);
                $this->recordEvent((int)$row['id'], 'reschedule', (string)$before['status'], (string)$after['status'], 'user', $uid, (int)$row['store_id'], $before, $after, trim((string)($data['reason'] ?? 'user_reschedule')), $requestId);
                $this->recordServiceAudit('appointment', (string)$row['id'], 'reschedule', $before, $after, $uid, 'user', (int)$row['store_id'], trim((string)($data['reason'] ?? 'user_reschedule')), $requestId);
                return ['status' => 'ok', 'appointment' => $this->formatAppointment($after)];
                });
            });
        });
    }

    public function adminList(array $where, array $adminInfo = []): array
    {
        return $this->operatorList($where, $adminInfo);
    }

    public function storeOperatorList(array $where, array $operatorInfo = []): array
    {
        return $this->operatorList($where, $operatorInfo);
    }

    private function operatorList(array $where, array $operatorInfo = []): array
    {
        $filter = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'service_project_id' => (int)($where['service_project_id'] ?? 0) ?: '',
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
            'service_date' => ($where['service_date'] ?? '') ? $this->serviceDateToInt($where['service_date']) : '',
        ]);
        $filter = app()->make(AdminStoreContextServices::class)->applyStoreFilter($filter, $operatorInfo);
        return $this->pageList($filter, '*', 'service_date desc,start_minute desc,id desc', function ($row) {
            return $this->formatAppointment($row);
        });
    }

    public function adminDetail(int $appointmentId, array $adminInfo = []): array
    {
        return $this->operatorDetail($appointmentId, $adminInfo);
    }

    public function storeOperatorDetail(int $appointmentId, array $operatorInfo = []): array
    {
        return $this->operatorDetail($appointmentId, $operatorInfo);
    }

    private function operatorDetail(int $appointmentId, array $operatorInfo = []): array
    {
        $row = $this->appointmentById($appointmentId);
        $this->assertAdminStoreReadable($operatorInfo, (int)$row['store_id']);
        return $this->detailPayload($row);
    }

    public function confirmByAdmin(int $appointmentId, string $reason, int $adminId, array $adminInfo = [], array $data = []): array
    {
        return $this->confirmByOperator($appointmentId, $reason, $adminId, $adminInfo, $data, 'admin_confirm');
    }

    public function confirmByStoreOperator(int $appointmentId, string $reason, array $operatorInfo = [], array $data = []): array
    {
        return $this->confirmByOperator($appointmentId, $reason, $this->operatorId($operatorInfo), $operatorInfo, $data, 'store_confirm');
    }

    private function confirmByOperator(int $appointmentId, string $reason, int $operatorId, array $operatorInfo = [], array $data = [], string $action = 'admin_confirm'): array
    {
        $this->assertAdminCanOperate($operatorInfo, 0);
        $key = $this->writeKey($action, $operatorId, $appointmentId, $data + ['reason' => $reason]);
        return $this->runIdempotent($action, $key, ['operator_id' => $operatorId, 'id' => $appointmentId, 'reason' => $reason], (string)$appointmentId, function ($requestId) use ($appointmentId, $reason, $operatorId, $operatorInfo, $action) {
            return $this->transaction(function () use ($appointmentId, $reason, $operatorId, $operatorInfo, $requestId, $action) {
                $row = $this->lockAppointment($appointmentId);
                $this->assertAdminCanOperate($operatorInfo, (int)$row['store_id']);
                if ((string)$row['status'] !== self::STATUS_PENDING) {
                    throw new AdminException('appointment_status_not_pending_confirm');
                }
                $slot = $this->lockSlotById((int)$row['slot_id']);
                if ((int)$slot['locked_count'] <= 0) {
                    throw new AdminException('slot_locked_count_invalid');
                }
                $this->updateSlotCounts($slot, -1, 1);
                $before = $row;
                $this->dao->update((int)$row['id'], [
                    'status' => self::STATUS_CONFIRMED,
                    'confirm_time' => time(),
                    'confirm_operator_id' => $operatorId,
                    'request_id' => $requestId,
                    'update_time' => time(),
                ]);
                $after = $this->appointmentById((int)$row['id']);
                $operatorType = $this->operatorType($operatorInfo);
                $eventReason = $reason ?: $action;
                $this->recordEvent((int)$row['id'], 'confirm', self::STATUS_PENDING, self::STATUS_CONFIRMED, $operatorType, $operatorId, (int)$row['store_id'], $before, $after, $eventReason, $requestId);
                $this->recordServiceAudit('appointment', (string)$row['id'], 'confirm', $before, $after, $operatorId, $this->operatorRole($operatorInfo), (int)$row['store_id'], $eventReason, $requestId);
                return ['status' => 'ok', 'appointment' => $this->formatAppointment($after)];
            });
        });
    }

    public function rejectByAdmin(int $appointmentId, string $reason, int $adminId, array $adminInfo = [], array $data = []): array
    {
        return $this->rejectByOperator($appointmentId, $reason, $adminId, $adminInfo, $data, 'admin_reject');
    }

    public function rejectByStoreOperator(int $appointmentId, string $reason, array $operatorInfo = [], array $data = []): array
    {
        return $this->rejectByOperator($appointmentId, $reason, $this->operatorId($operatorInfo), $operatorInfo, $data, 'store_reject');
    }

    private function rejectByOperator(int $appointmentId, string $reason, int $operatorId, array $operatorInfo = [], array $data = [], string $action = 'admin_reject'): array
    {
        $this->assertAdminCanOperate($operatorInfo, 0);
        $key = $this->writeKey($action, $operatorId, $appointmentId, $data + ['reason' => $reason]);
        return $this->runIdempotent($action, $key, ['operator_id' => $operatorId, 'id' => $appointmentId, 'reason' => $reason], (string)$appointmentId, function ($requestId) use ($appointmentId, $reason, $operatorId, $operatorInfo, $action) {
            return $this->transaction(function () use ($appointmentId, $reason, $operatorId, $operatorInfo, $requestId, $action) {
                $row = $this->lockAppointment($appointmentId);
                $this->assertAdminCanOperate($operatorInfo, (int)$row['store_id']);
                if ((string)$row['status'] !== self::STATUS_PENDING) {
                    throw new AdminException('appointment_status_not_pending_confirm');
                }
                return $this->closeAppointment($row, self::STATUS_REJECTED, $action, $this->operatorType($operatorInfo), $operatorId, $reason ?: $action, $requestId, $operatorInfo);
            });
        });
    }

    public function cancelByAdmin(int $appointmentId, string $reason, int $adminId, array $adminInfo = [], array $data = []): array
    {
        return $this->cancelByOperator($appointmentId, $reason, $adminId, $adminInfo, $data, 'admin_cancel');
    }

    public function cancelByStoreOperator(int $appointmentId, string $reason, array $operatorInfo = [], array $data = []): array
    {
        return $this->cancelByOperator($appointmentId, $reason, $this->operatorId($operatorInfo), $operatorInfo, $data, 'store_cancel');
    }

    private function cancelByOperator(int $appointmentId, string $reason, int $operatorId, array $operatorInfo = [], array $data = [], string $action = 'admin_cancel'): array
    {
        $this->assertAdminCanOperate($operatorInfo, 0);
        $key = $this->writeKey($action, $operatorId, $appointmentId, $data + ['reason' => $reason]);
        return $this->runIdempotent($action, $key, ['operator_id' => $operatorId, 'id' => $appointmentId, 'reason' => $reason], (string)$appointmentId, function ($requestId) use ($appointmentId, $reason, $operatorId, $operatorInfo, $action) {
            return $this->transaction(function () use ($appointmentId, $reason, $operatorId, $operatorInfo, $requestId, $action) {
                $row = $this->lockAppointment($appointmentId);
                $this->assertAdminCanOperate($operatorInfo, (int)$row['store_id']);
                if (!in_array((string)$row['status'], [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)) {
                    throw new AdminException('appointment_status_not_cancelable');
                }
                return $this->closeAppointment($row, self::STATUS_CANCELLED, $action, $this->operatorType($operatorInfo), $operatorId, $reason ?: $action, $requestId, $operatorInfo);
            });
        });
    }

    private function bookingContext(int $uid, array $data, bool $requireBenefitUnlocked): array
    {
        $projectId = (int)($data['service_project_id'] ?? 0);
        $storeId = (int)($data['store_id'] ?? 0);
        if ($projectId <= 0 || $storeId <= 0) {
            throw new ApiException('store_and_service_project_required');
        }
        /** @var ServiceProjectServices $projectServices */
        $projectServices = app()->make(ServiceProjectServices::class);
        $project = $projectServices->requireActiveProject($projectId);
        if ((int)$project['allow_benefit'] !== 1 || (string)$project['required_benefit_type'] !== 'service') {
            throw new ApiException('service_project_requires_service_benefit');
        }
        $store = app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        if (!app()->make(StoreCapabilityServices::class)->isAvailable($storeId, 'reservation_service')) {
            throw new ApiException('store_capability_unavailable');
        }
        $binding = app()->make(StoreServiceAppointmentServices::class)->activeBinding($storeId, $projectId);
        $date = $this->serviceDateToInt($data['date'] ?? ($data['service_date'] ?? ''), (string)$binding['timezone']);
        $startMinute = (int)($data['start_minute'] ?? -1);
        $day = app()->make(ServiceAppointmentQueryServices::class)->slotsForBinding($binding, $date, false);
        if ($day['status'] !== 'ok') {
            throw new ApiException($day['reason'] ?: 'slot_not_available');
        }
        $slotData = null;
        foreach ($day['slots'] as $slot) {
            if ((int)$slot['start_minute'] === $startMinute) {
                $slotData = $slot;
                break;
            }
        }
        if (!$slotData || (string)$slotData['status'] !== 'available') {
            throw new ApiException('slot_not_available');
        }
        $slotData['service_date'] = $date;
        if ($requireBenefitUnlocked && $this->activeBenefitLockExists((int)($data['benefit_item_id'] ?? 0))) {
            throw new ApiException('benefit_item_already_locked');
        }
        return compact('project', 'store', 'binding', 'slotData') + ['slot_data' => $slotData];
    }

    private function lockBenefitItem(int $uid, int $benefitItemId, array $project): array
    {
        if ($benefitItemId <= 0) {
            throw new ApiException('benefit_item_required');
        }
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        $item = $itemDao->search([])->where('id', $benefitItemId)->lock(true)->find();
        $item = $this->requireRow($item, 'benefit_item_not_found');
        if ((int)$item['uid'] !== $uid) {
            throw new ApiException('benefit_item_forbidden');
        }
        if ((string)$item['benefit_type'] !== 'service') {
            throw new ApiException('only_service_benefit_can_book_service');
        }
        if ((string)$item['status'] !== 'available' || (float)$item['quantity_available'] <= 0 || (float)$item['quantity_used'] >= (float)$item['quantity_total']) {
            throw new ApiException('benefit_item_not_available');
        }
        $now = time();
        if ((int)$item['available_time'] > $now || ((int)$item['expire_time'] > 0 && (int)$item['expire_time'] <= $now)) {
            throw new ApiException('benefit_item_not_in_available_window');
        }
        if ($this->activeBenefitLockExists($benefitItemId)) {
            throw new ApiException('benefit_item_already_locked');
        }
        $allowedTemplateIds = $this->projectBenefitTemplateIds($project);
        if ($allowedTemplateIds && !in_array((int)$item['benefit_template_id'], $allowedTemplateIds, true)) {
            throw new ApiException('benefit_item_not_allowed_for_service_project');
        }
        $this->assertBenefitParentsActive($item);
        return $item;
    }

    private function assertBenefitParentsActive(array $item): void
    {
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);
        $instance = $this->requireRow($instanceDao->get((int)$item['package_instance_id']), 'package_instance_not_found');
        $plan = $this->requireRow($planDao->get((int)$item['plan_id']), 'benefit_plan_not_found');
        $period = $this->requireRow($periodDao->get((int)$item['period_id']), 'benefit_period_not_found');
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

    private function getOrCreateSlotLocked(array $binding, array $slotData): array
    {
        /** @var YfthServiceAppointmentSlotDao $slotDao */
        $slotDao = app()->make(YfthServiceAppointmentSlotDao::class);
        $slotKey = $this->slotKey((int)$binding['id'], (int)$slotData['service_date'], (int)$slotData['start_minute'], (int)$slotData['end_minute']);
        $slot = $slotDao->search([])->where('slot_key', $slotKey)->lock(true)->find();
        if (!$slot) {
            try {
                $slotDao->save($this->withTimestamps([
                    'store_id' => (int)$binding['store_id'],
                    'store_service_id' => (int)$binding['id'],
                    'service_project_id' => (int)$binding['service_project_id'],
                    'service_date' => (int)$slotData['service_date'],
                    'start_minute' => (int)$slotData['start_minute'],
                    'end_minute' => (int)$slotData['end_minute'],
                    'start_time' => (int)$slotData['start_timestamp'],
                    'end_time' => (int)$slotData['end_timestamp'],
                    'capacity' => (int)$slotData['capacity'],
                    'locked_count' => 0,
                    'occupied_count' => 0,
                    'status' => 'available',
                    'slot_key' => $slotKey,
                ], true));
            } catch (\Throwable $e) {
                if (!$this->isUniqueConflict($e)) {
                    throw $e;
                }
            }
            $slot = $slotDao->search([])->where('slot_key', $slotKey)->lock(true)->find();
        }
        $row = $this->requireRow($slot, 'appointment_slot_not_found');
        $capacity = (int)$slotData['capacity'];
        if ((int)$row['capacity'] !== $capacity && (int)$row['locked_count'] === 0 && (int)$row['occupied_count'] === 0) {
            $slotDao->update((int)$row['id'], ['capacity' => $capacity, 'status' => $capacity > 0 ? 'available' : 'closed', 'update_time' => time()]);
            $row = $slotDao->search([])->where('id', (int)$row['id'])->lock(true)->find()->toArray();
        }
        return $row;
    }

    private function ensureSlotExists(array $binding, array $slotData): int
    {
        /** @var YfthServiceAppointmentSlotDao $slotDao */
        $slotDao = app()->make(YfthServiceAppointmentSlotDao::class);
        $slotKey = $this->slotKey((int)$binding['id'], (int)$slotData['service_date'], (int)$slotData['start_minute'], (int)$slotData['end_minute']);
        $slot = $slotDao->search([])->where('slot_key', $slotKey)->find();
        if ($slot) {
            return (int)$slot['id'];
        }
        try {
            $created = $slotDao->save($this->withTimestamps([
                'store_id' => (int)$binding['store_id'],
                'store_service_id' => (int)$binding['id'],
                'service_project_id' => (int)$binding['service_project_id'],
                'service_date' => (int)$slotData['service_date'],
                'start_minute' => (int)$slotData['start_minute'],
                'end_minute' => (int)$slotData['end_minute'],
                'start_time' => (int)$slotData['start_timestamp'],
                'end_time' => (int)$slotData['end_timestamp'],
                'capacity' => (int)$slotData['capacity'],
                'locked_count' => 0,
                'occupied_count' => 0,
                'status' => (int)$slotData['capacity'] > 0 ? 'available' : 'closed',
                'slot_key' => $slotKey,
            ], true));
            return (int)$created->id;
        } catch (\Throwable $e) {
            if (!$this->isUniqueConflict($e)) {
                throw $e;
            }
            $slot = $slotDao->search([])->where('slot_key', $slotKey)->find();
            return (int)$this->requireRow($slot, 'appointment_slot_not_found')['id'];
        }
    }

    private function lockSlotPairById(int $oldSlotId, int $newSlotId): array
    {
        $ids = [$oldSlotId, $newSlotId];
        sort($ids, SORT_NUMERIC);
        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = $this->lockSlotById($id);
        }
        return [$locked[$oldSlotId], $locked[$newSlotId]];
    }

    private function refreshSlotCapacityIfIdle(array &$slot, array $slotData): void
    {
        $capacity = (int)$slotData['capacity'];
        if ((int)$slot['capacity'] === $capacity || (int)$slot['locked_count'] !== 0 || (int)$slot['occupied_count'] !== 0) {
            return;
        }
        app()->make(YfthServiceAppointmentSlotDao::class)->update((int)$slot['id'], [
            'capacity' => $capacity,
            'status' => $capacity > 0 ? 'available' : 'closed',
            'update_time' => time(),
        ]);
        $slot = $this->lockSlotById((int)$slot['id']);
    }

    private function assertSlotCanHold(array $slot): void
    {
        if ((string)$slot['status'] === 'closed') {
            throw new ApiException('slot_closed');
        }
        if ((int)$slot['locked_count'] + (int)$slot['occupied_count'] >= (int)$slot['capacity']) {
            throw new ApiException('slot_capacity_full');
        }
    }

    private function increaseSlot(array $slot, string $appointmentStatus): void
    {
        $this->updateSlotCounts($slot, $appointmentStatus === self::STATUS_PENDING ? 1 : 0, $appointmentStatus === self::STATUS_CONFIRMED ? 1 : 0);
    }

    private function decreaseSlot(array $slot, string $appointmentStatus): void
    {
        $this->updateSlotCounts($slot, $appointmentStatus === self::STATUS_PENDING ? -1 : 0, $appointmentStatus === self::STATUS_CONFIRMED ? -1 : 0);
    }

    private function updateSlotCounts(array $slot, int $lockedDelta, int $occupiedDelta): void
    {
        $locked = max(0, (int)$slot['locked_count'] + $lockedDelta);
        $occupied = max(0, (int)$slot['occupied_count'] + $occupiedDelta);
        $status = ($locked + $occupied) >= (int)$slot['capacity'] ? 'full' : 'available';
        app()->make(YfthServiceAppointmentSlotDao::class)->update((int)$slot['id'], [
            'locked_count' => $locked,
            'occupied_count' => $occupied,
            'status' => $status,
            'update_time' => time(),
        ]);
    }

    private function closeAppointment(array $row, string $toStatus, string $event, string $operatorType, int $operatorId, string $reason, string $requestId, array $adminInfo = []): array
    {
        $fromStatus = (string)$row['status'];
        $slot = $this->lockSlotById((int)$row['slot_id']);
        $this->decreaseSlot($slot, $fromStatus);
        $this->releaseBenefitLock((int)$row['id'], $event);
        $update = [
            'status' => $toStatus,
            'request_id' => $requestId,
            'update_time' => time(),
        ];
        if ($toStatus === self::STATUS_CANCELLED) {
            $update += [
                'cancel_source' => $operatorType,
                'cancel_reason' => $reason,
                'cancel_time' => time(),
                'cancel_operator_id' => $operatorId,
            ];
        }
        if ($toStatus === self::STATUS_REJECTED) {
            $update += [
                'reject_reason' => $reason,
                'reject_time' => time(),
                'reject_operator_id' => $operatorId,
            ];
        }
        $before = $row;
        $this->dao->update((int)$row['id'], $update);
        $after = $this->appointmentById((int)$row['id']);
        $role = $operatorType === 'user' ? 'user' : $this->operatorRole($adminInfo);
        $this->recordEvent((int)$row['id'], $event, $fromStatus, $toStatus, $operatorType, $operatorId, (int)$row['store_id'], $before, $after, $reason, $requestId);
        $this->recordServiceAudit('appointment', (string)$row['id'], $event, $before, $after, $operatorId, $role, (int)$row['store_id'], $reason, $requestId);
        return ['status' => 'ok', 'appointment' => $this->formatAppointment($after)];
    }

    private function createBenefitLock(int $uid, int $appointmentId, array $benefit): void
    {
        app()->make(YfthServiceBenefitLockDao::class)->save($this->withTimestamps([
            'uid' => $uid,
            'appointment_id' => $appointmentId,
            'package_instance_id' => (int)$benefit['package_instance_id'],
            'benefit_plan_id' => (int)$benefit['plan_id'],
            'benefit_period_id' => (int)$benefit['period_id'],
            'benefit_item_id' => (int)$benefit['id'],
            'status' => 'locked',
            'consume_status' => 'none',
            'locked_time' => time(),
            'released_time' => 0,
            'release_reason' => '',
            'active_key' => (string)$benefit['id'],
        ], true));
    }

    private function releaseBenefitLock(int $appointmentId, string $reason): void
    {
        /** @var YfthServiceBenefitLockDao $lockDao */
        $lockDao = app()->make(YfthServiceBenefitLockDao::class);
        $lock = $lockDao->search([])->where('appointment_id', $appointmentId)->where('status', 'locked')->lock(true)->find();
        if (!$lock) {
            return;
        }
        $lockDao->update((int)$lock['id'], [
            'status' => 'released',
            'released_time' => time(),
            'release_reason' => $reason,
            'active_key' => null,
            'update_time' => time(),
        ]);
    }

    private function activeBenefitLockExists(int $benefitItemId): bool
    {
        if ($benefitItemId <= 0) {
            return false;
        }
        return app()->make(YfthServiceBenefitLockDao::class)->getCount(['benefit_item_id' => $benefitItemId, 'status' => 'locked']) > 0;
    }

    private function lockAppointment(int $appointmentId): array
    {
        return $this->requireRow($this->dao->search([])->where('id', $appointmentId)->lock(true)->find(), 'appointment_not_found');
    }

    private function appointmentById(int $appointmentId): array
    {
        return $this->requireRow($this->dao->get($appointmentId), 'appointment_not_found');
    }

    private function lockSlotById(int $slotId): array
    {
        return $this->requireRow(app()->make(YfthServiceAppointmentSlotDao::class)->search([])->where('id', $slotId)->lock(true)->find(), 'appointment_slot_not_found');
    }

    private function detailPayload(array $row): array
    {
        $payload = $this->formatAppointment($row);
        $payload['events'] = app()->make(YfthServiceAppointmentEventDao::class)->selectList(['appointment_id' => (int)$row['id']], '*', 0, 0, 'id asc', [], false)->toArray();
        $payload['benefit_lock'] = app()->make(YfthServiceBenefitLockDao::class)->getOne(['appointment_id' => (int)$row['id']]);
        if ($payload['benefit_lock'] && !is_array($payload['benefit_lock'])) {
            $payload['benefit_lock'] = $payload['benefit_lock']->toArray();
        }
        $payload['writeoff_result'] = app()->make(ServiceAppointmentWriteoffServices::class)->writeoffResultForAppointment((int)$row['id']);
        return $payload;
    }

    private function publicDetailPayload(array $row): array
    {
        $payload = $this->formatPublicAppointment($row, true);
        $events = app()->make(YfthServiceAppointmentEventDao::class)->selectList(['appointment_id' => (int)$row['id']], '*', 0, 0, 'id asc', [], false)->toArray();
        $payload['timeline'] = array_map(function ($event) {
            return [
                'event_type' => (string)$event['event_type'],
                'from_status' => (string)$event['from_status'],
                'to_status' => (string)$event['to_status'],
                'reason' => (string)$event['reason'],
                'add_time' => (int)$event['add_time'],
            ];
        }, $events);
        return $payload;
    }

    public function formatAppointment(array $row): array
    {
        $row['date_text'] = $this->serviceDateText((int)$row['service_date']);
        $row['start_time_text'] = $this->minuteText((int)$row['start_minute']);
        $row['end_time_text'] = $this->minuteText((int)$row['end_minute']);
        $row['store_snapshot'] = $this->jsonDecode($row['store_snapshot'] ?? '');
        $row['service_snapshot'] = $this->jsonDecode($row['service_snapshot'] ?? '');
        $row['benefit_snapshot'] = $this->jsonDecode($row['benefit_snapshot'] ?? '');
        return $row;
    }

    private function formatPublicAppointment(array $row, bool $detail): array
    {
        $store = $this->jsonDecode($row['store_snapshot'] ?? '');
        $service = $this->jsonDecode($row['service_snapshot'] ?? '');
        $benefit = $this->jsonDecode($row['benefit_snapshot'] ?? '');
        $status = (string)$row['status'];
        $writeoff = app()->make(ServiceAppointmentWriteoffServices::class);
        $payload = [
            'id' => (int)$row['id'],
            'appointment_no' => (string)$row['appointment_no'],
            'status' => $status,
            'store' => [
                'id' => (int)$row['store_id'],
                'name' => (string)($store['name'] ?? $store['store_name'] ?? ''),
            ],
            'service_project' => [
                'id' => (int)$row['service_project_id'],
                'name' => (string)($service['project']['service_name'] ?? $service['project']['name'] ?? ''),
            ],
            'schedule' => [
                'date' => $this->serviceDateText((int)$row['service_date']),
                'start_time' => $this->minuteText((int)$row['start_minute']),
                'end_time' => $this->minuteText((int)$row['end_minute']),
                'start_timestamp' => (int)$row['start_time'],
                'end_timestamp' => (int)$row['end_time'],
            ],
            'benefit' => [
                'name' => (string)($benefit['benefit_name'] ?? ''),
                'type' => (string)($benefit['benefit_type'] ?? 'service'),
            ],
            'actions' => [
                'can_cancel' => in_array($status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true),
                'can_reschedule' => in_array($status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true),
                'can_generate_dynamic_code' => false,
            ],
            'dynamic_code' => $writeoff->codeAvailabilityForAppointment($row),
            'writeoff_result' => $writeoff->writeoffResultForAppointment((int)$row['id']),
        ];
        $payload['actions']['can_generate_dynamic_code'] = (bool)$payload['dynamic_code']['can_generate'];
        if ($detail) {
            $payload['user_note'] = (string)($row['user_note'] ?? '');
            $payload['confirm_mode'] = (string)($row['confirm_mode'] ?? '');
            $payload['cancel_reason'] = (string)($row['cancel_reason'] ?? '');
            $payload['reject_reason'] = (string)($row['reject_reason'] ?? '');
            $payload['check_in_at'] = (int)($row['check_in_at'] ?? 0);
            $payload['writeoff_at'] = (int)($row['writeoff_at'] ?? 0);
            $payload['completed_at'] = (int)($row['completed_at'] ?? 0);
        }
        return $payload;
    }

    private function recordEvent(int $appointmentId, string $eventType, string $fromStatus, string $toStatus, string $operatorType, int $operatorId, int $storeId, array $before, array $after, string $reason, string $requestId): void
    {
        app()->make(YfthServiceAppointmentEventDao::class)->save($this->withTimestamps([
            'appointment_id' => $appointmentId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'store_id' => $storeId,
            'old_service_date' => (int)($before['service_date'] ?? 0),
            'old_start_minute' => (int)($before['start_minute'] ?? 0),
            'old_end_minute' => (int)($before['end_minute'] ?? 0),
            'new_service_date' => (int)($after['service_date'] ?? 0),
            'new_start_minute' => (int)($after['start_minute'] ?? 0),
            'new_end_minute' => (int)($after['end_minute'] ?? 0),
            'reason' => $reason,
            'request_id' => $requestId,
        ], true));
    }

    private function runIdempotent(string $action, string $key, array $payload, string $objectId, callable $callback): array
    {
        /** @var IdempotencyRecordServices $idempotency */
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin(self::DOMAIN, $action, $key, $payload, $objectId, 600);
        if (!$begin['acquired']) {
            if (($begin['status'] ?? '') === 'succeeded') {
                return is_array($begin['result_summary'] ?? null) ? $begin['result_summary'] : ['status' => 'ok', 'replayed' => true];
            }
            if (!empty($begin['can_retry'])) {
                $begin = $idempotency->tryReacquire($begin['record'], 600);
            }
            if (!$begin['acquired']) {
                throw new ApiException('idempotency_request_processing');
            }
        }
        $recordId = (int)$begin['record']['id'];
        $requestId = substr(hash('sha256', self::DOMAIN . ':' . $action . ':' . $key), 0, 32);
        try {
            $result = $callback($requestId);
            $idempotency->complete($recordId, $result);
            return $result;
        } catch (\Throwable $e) {
            $idempotency->fail($recordId, $e->getMessage());
            throw $e;
        }
    }

    private function runWithDeadlockRetry(callable $callback, int $maxAttempts = 3): array
    {
        $attempt = 0;
        beginning:
        $attempt++;
        try {
            return $callback();
        } catch (\Throwable $e) {
            if ($attempt >= $maxAttempts || !$this->isDeadlockOrLockTimeout($e)) {
                throw $e;
            }
            usleep(50000 * $attempt);
            goto beginning;
        }
    }

    private function isDeadlockOrLockTimeout(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'deadlock') !== false
            || strpos($message, 'lock wait timeout') !== false
            || strpos($message, '1213') !== false
            || strpos($message, '1205') !== false;
    }

    private function writeKey(string $action, int $operatorId, int $objectId, array $data): string
    {
        $explicit = trim((string)($data['idempotency_key'] ?? ''));
        if ($explicit !== '') {
            return $action . ':' . $operatorId . ':' . $explicit;
        }
        unset($data['idempotency_key']);
        ksort($data);
        return $action . ':' . $operatorId . ':' . $objectId . ':' . hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function assertUserCancelable(array $row): void
    {
        if (!in_array((string)$row['status'], [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)) {
            throw new ApiException('appointment_status_not_cancelable');
        }
        $snapshot = $this->jsonDecode($row['service_snapshot'] ?? '');
        $binding = $snapshot['binding'] ?? [];
        $deadlineMinutes = (int)($binding['cancel_deadline_minutes'] ?? 0);
        if ($deadlineMinutes > 0 && ((int)$row['start_time'] - time()) < $deadlineMinutes * 60) {
            throw new ApiException('appointment_cancel_deadline_passed');
        }
    }

    private function assertAdminStoreReadable(array $adminInfo, int $storeId): void
    {
        app()->make(AdminStoreContextServices::class)->applyStoreFilter(['store_id' => $storeId], $adminInfo);
    }

    private function assertAdminCanOperate(array $adminInfo, int $storeId): void
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($adminInfo);
        if ($context['is_super_admin'] || $context['is_headquarter_admin']) {
            return;
        }
        if ($storeId <= 0) {
            return;
        }
        $roles = $context['store_scope_roles'][$storeId] ?? [];
        if (in_array('store_manager', $roles, true)) {
            return;
        }
        if (in_array('store_staff', $roles, true) || ($context['is_store_staff'] ?? false)) {
            throw new AdminException('store_staff_cannot_operate_service_appointment');
        }
        throw new AdminException('store_scope_forbidden');
    }

    private function adminRole(array $adminInfo): string
    {
        return $this->operatorRole($adminInfo);
    }

    private function operatorRole(array $operatorInfo): string
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($operatorInfo);
        return (string)($context['primary_role_code'] ?? 'admin');
    }

    private function operatorType(array $operatorInfo): string
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($operatorInfo);
        return (string)($context['operator_type'] ?? AdminStoreContextServices::OPERATOR_ADMIN);
    }

    private function operatorId(array $operatorInfo): int
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($operatorInfo);
        if ((string)($context['operator_type'] ?? '') === AdminStoreContextServices::OPERATOR_USER_STORE_ROLE) {
            return (int)($context['operator_uid'] ?? 0);
        }
        return (int)($context['admin_id'] ?? ($operatorInfo['id'] ?? 0));
    }

    private function projectBenefitTemplateIds(array $project): array
    {
        $value = trim((string)($project['required_benefit_template_ids'] ?? ''));
        if ($value === '' && isset($project['required_benefit_template_id_list'])) {
            return array_values(array_filter(array_map('intval', (array)$project['required_benefit_template_id_list'])));
        }
        return $value === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    private function formatBenefitItem(array $row): array
    {
        /** @var YfthBenefitTemplateDao $templateDao */
        $templateDao = app()->make(YfthBenefitTemplateDao::class);
        $template = !empty($row['benefit_template_id']) ? $templateDao->get((int)$row['benefit_template_id']) : null;
        $row['template_status'] = $template ? (string)$template['status'] : '';
        $row['template_unit'] = $template ? (string)$template['unit'] : '';
        return $row;
    }

    private function slotKey(int $storeServiceId, int $serviceDate, int $startMinute, int $endMinute): string
    {
        return implode(':', [$storeServiceId, $serviceDate, $startMinute, $endMinute]);
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function isUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000';
    }
}
