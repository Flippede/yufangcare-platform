<?php

namespace app\services\yfth;

use app\dao\yfth\YfthStoreServiceScheduleRuleDao;
use app\dao\yfth\YfthStoreServiceSpecialDayDao;
use crmeb\exceptions\AdminException;

class StoreServiceScheduleServices extends ServiceAppointmentBaseServices
{
    public function __construct(YfthStoreServiceScheduleRuleDao $dao)
    {
        $this->dao = $dao;
    }

    public function scheduleRuleList(array $where, array $adminInfo = []): array
    {
        $where = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'service_project_id' => (int)($where['service_project_id'] ?? 0) ?: '',
            'store_service_id' => (int)($where['store_service_id'] ?? 0) ?: '',
            'weekday' => (int)($where['weekday'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
        ]);
        $where = $this->applyAdminStoreFilter($where, $adminInfo);
        return $this->pageList($where, '*', 'weekday asc,start_minute asc,id asc', function ($row) {
            return $this->formatScheduleRuleRow($row);
        });
    }

    public function saveScheduleRule(array $data, int $operatorUid = 0, array $adminInfo = [])
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeScheduleRule($data, $id, $before ? $before->toArray() : [], $operatorUid, $adminInfo);
        return $this->transaction(function () use ($id, $data, $before, $operatorUid) {
            $this->assertScheduleNotOverlapping($data, $id);
            $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
            $objectId = $id ?: (int)$result->id;
            $after = $id ? $this->dao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
            $this->recordServiceAudit('schedule_rule', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin', (int)$data['store_id'], (string)($data['close_reason'] ?? ''));
            return $result;
        });
    }

    public function disableScheduleRule(int $ruleId, string $reason, int $operatorUid = 0, array $adminInfo = []): void
    {
        $before = $this->requireRow($this->dao->get($ruleId), 'schedule_rule_not_found');
        $this->assertStoreConfigScope($adminInfo, (int)$before['store_id']);
        if ((string)$before['status'] === YfthConstants::STATUS_DISABLED) {
            return;
        }
        $data = [
            'status' => YfthConstants::STATUS_DISABLED,
            'active_key' => null,
            'disabled_uid' => $operatorUid,
            'disabled_time' => time(),
            'updated_uid' => $operatorUid,
            'close_reason' => trim($reason) ?: 'admin_disabled',
            'update_time' => time(),
        ];
        $this->dao->update($ruleId, $data);
        $after = $this->dao->get($ruleId)->toArray();
        $this->recordServiceAudit('schedule_rule', (string)$ruleId, 'disable', $before, $after, $operatorUid, 'admin', (int)$before['store_id'], $data['close_reason']);
    }

    public function specialDayList(array $where, array $adminInfo = []): array
    {
        /** @var YfthStoreServiceSpecialDayDao $specialDao */
        $specialDao = app()->make(YfthStoreServiceSpecialDayDao::class);
        $where = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'service_project_id' => (int)($where['service_project_id'] ?? 0) ?: '',
            'store_service_id' => (int)($where['store_service_id'] ?? 0) ?: '',
            'service_date' => ($where['service_date'] ?? '') ? $this->serviceDateToInt($where['service_date']) : '',
            'date_type' => $where['date_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        $where = $this->applyAdminStoreFilter($where, $adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $list = $specialDao->selectList($where, '*', $page, $limit, 'service_date asc,start_minute asc,id asc', [], false)->toArray();
        return [
            'list' => array_map(function ($row) {
                return $this->formatSpecialDayRow($row);
            }, $list),
            'count' => $specialDao->getCount($where),
        ];
    }

    public function saveSpecialDay(array $data, int $operatorUid = 0, array $adminInfo = [])
    {
        /** @var YfthStoreServiceSpecialDayDao $specialDao */
        $specialDao = app()->make(YfthStoreServiceSpecialDayDao::class);
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $specialDao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeSpecialDay($data, $id, $before ? $before->toArray() : [], $operatorUid, $adminInfo);
        return $this->transaction(function () use ($specialDao, $id, $data, $before, $operatorUid) {
            $this->assertSpecialDayCompatible($data, $id);
            $result = $id ? $specialDao->update($id, $data) : $specialDao->save($data);
            $objectId = $id ?: (int)$result->id;
            $after = $id ? $specialDao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
            $this->recordServiceAudit('special_day', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin', (int)$data['store_id'], (string)($data['reason'] ?? ''));
            return $result;
        });
    }

    public function disableSpecialDay(int $specialDayId, string $reason, int $operatorUid = 0, array $adminInfo = []): void
    {
        /** @var YfthStoreServiceSpecialDayDao $specialDao */
        $specialDao = app()->make(YfthStoreServiceSpecialDayDao::class);
        $before = $this->requireRow($specialDao->get($specialDayId), 'special_day_not_found');
        $this->assertStoreConfigScope($adminInfo, (int)$before['store_id']);
        if ((string)$before['status'] === YfthConstants::STATUS_DISABLED) {
            return;
        }
        $data = [
            'status' => YfthConstants::STATUS_DISABLED,
            'active_key' => null,
            'disabled_uid' => $operatorUid,
            'disabled_time' => time(),
            'updated_uid' => $operatorUid,
            'close_reason' => trim($reason) ?: 'admin_disabled',
            'update_time' => time(),
        ];
        $specialDao->update($specialDayId, $data);
        $after = $specialDao->get($specialDayId)->toArray();
        $this->recordServiceAudit('special_day', (string)$specialDayId, 'disable', $before, $after, $operatorUid, 'admin', (int)$before['store_id'], $data['close_reason']);
    }

    public function previewSlots(array $where, array $adminInfo = []): array
    {
        /** @var StoreServiceAppointmentServices $storeServiceServices */
        $storeServiceServices = app()->make(StoreServiceAppointmentServices::class);
        $binding = !empty($where['store_service_id'])
            ? $storeServiceServices->bindingById((int)$where['store_service_id'], true)
            : $storeServiceServices->activeBinding((int)($where['store_id'] ?? 0), (int)($where['service_project_id'] ?? 0));
        $this->assertStoreConfigScope($adminInfo, (int)$binding['store_id']);
        /** @var ServiceAppointmentQueryServices $queryServices */
        $queryServices = app()->make(ServiceAppointmentQueryServices::class);
        $dates = $this->normalizeDateRange($where['start_date'] ?? ($where['date'] ?? ''), $where['end_date'] ?? ($where['date'] ?? ''), 31, (string)$binding['timezone']);
        $days = [];
        foreach ($dates as $date) {
            $days[] = $queryServices->slotsForBinding($binding, $date, true);
        }
        return [
            'status' => 'ok',
            'store_service_id' => (int)$binding['id'],
            'store_id' => (int)$binding['store_id'],
            'service_project_id' => (int)$binding['service_project_id'],
            'days' => $days,
        ];
    }

    private function normalizeScheduleRule(array $data, int $id, array $before, int $operatorUid, array $adminInfo): array
    {
        $data['store_service_id'] = (int)($data['store_service_id'] ?? ($before['store_service_id'] ?? 0));
        if ($data['store_service_id'] <= 0) {
            throw new AdminException('store_service_id_required');
        }
        /** @var StoreServiceAppointmentServices $storeServiceServices */
        $storeServiceServices = app()->make(StoreServiceAppointmentServices::class);
        $binding = $storeServiceServices->bindingById($data['store_service_id'], false);
        $this->assertStoreConfigScope($adminInfo, (int)$binding['store_id']);
        $data['status'] = $this->normalizeStatus((string)($data['status'] ?? ($before['status'] ?? YfthConstants::STATUS_ACTIVE)));
        if ($data['status'] === YfthConstants::STATUS_ACTIVE && ((string)$binding['status'] !== YfthConstants::STATUS_ACTIVE || (int)$binding['appointment_enabled'] !== 1)) {
            throw new AdminException('active_schedule_requires_active_store_service');
        }
        $data['store_id'] = (int)$binding['store_id'];
        $data['service_project_id'] = (int)$binding['service_project_id'];
        $data['weekday'] = $this->boundedInt($data['weekday'] ?? 0, 1, 7, 'invalid_weekday');
        [$data['start_minute'], $data['end_minute']] = $this->normalizeMinuteRange($data);
        $data['slot_capacity'] = $this->boundedInt($data['slot_capacity'] ?? ($binding['default_capacity'] ?? 1), 1, 999, 'invalid_slot_capacity');
        $data['slot_interval_minutes'] = (int)($data['slot_interval_minutes'] ?? 0);
        if ($data['slot_interval_minutes'] === 0) {
            $data['slot_interval_minutes'] = (int)$binding['duration_minutes'];
        }
        $data['slot_interval_minutes'] = $this->boundedInt($data['slot_interval_minutes'], 5, 480, 'invalid_slot_interval_minutes');
        if ($data['slot_interval_minutes'] < (int)$binding['duration_minutes']) {
            throw new AdminException('slot_interval_cannot_be_shorter_than_duration');
        }
        if (($data['end_minute'] - $data['start_minute']) < (int)$binding['duration_minutes']) {
            throw new AdminException('schedule_window_shorter_than_service_duration');
        }
        $data['active_key'] = $this->activeKey([$data['store_service_id'], $data['weekday'], $data['start_minute'], $data['end_minute']], $data['status']);
        $data['updated_uid'] = $operatorUid;
        if ($id === 0) {
            $data['created_uid'] = $operatorUid;
        }
        if ($data['status'] === YfthConstants::STATUS_ACTIVE) {
            $data['disabled_uid'] = 0;
            $data['disabled_time'] = 0;
            $data['close_reason'] = '';
        }
        return $this->withTimestamps($data, $id === 0);
    }

    private function normalizeSpecialDay(array $data, int $id, array $before, int $operatorUid, array $adminInfo): array
    {
        $data['store_service_id'] = (int)($data['store_service_id'] ?? ($before['store_service_id'] ?? 0));
        if ($data['store_service_id'] <= 0) {
            throw new AdminException('store_service_id_required');
        }
        /** @var StoreServiceAppointmentServices $storeServiceServices */
        $storeServiceServices = app()->make(StoreServiceAppointmentServices::class);
        $binding = $storeServiceServices->bindingById($data['store_service_id'], false);
        $this->assertStoreConfigScope($adminInfo, (int)$binding['store_id']);
        $data['status'] = $this->normalizeStatus((string)($data['status'] ?? ($before['status'] ?? YfthConstants::STATUS_ACTIVE)));
        if ($data['status'] === YfthConstants::STATUS_ACTIVE && ((string)$binding['status'] !== YfthConstants::STATUS_ACTIVE || (int)$binding['appointment_enabled'] !== 1)) {
            throw new AdminException('active_special_day_requires_active_store_service');
        }
        $data['store_id'] = (int)$binding['store_id'];
        $data['service_project_id'] = (int)$binding['service_project_id'];
        $data['service_date'] = $this->serviceDateToInt($data['service_date'] ?? ($before['service_date'] ?? ''), (string)$binding['timezone']);
        $data['date_type'] = trim((string)($data['date_type'] ?? self::TYPE_CLOSED)) ?: self::TYPE_CLOSED;
        if (!in_array($data['date_type'], [self::TYPE_CLOSED, self::TYPE_EXTRA, self::TYPE_CAPACITY_OVERRIDE], true)) {
            throw new AdminException('invalid_special_day_type');
        }
        if ($data['date_type'] === self::TYPE_CLOSED) {
            $data['start_minute'] = 0;
            $data['end_minute'] = 1440;
            $data['slot_capacity'] = 0;
        } else {
            [$data['start_minute'], $data['end_minute']] = $this->normalizeMinuteRange($data);
            $data['slot_capacity'] = $this->boundedInt($data['slot_capacity'] ?? ($binding['default_capacity'] ?? 1), 1, 999, 'invalid_slot_capacity');
            if ($data['date_type'] === self::TYPE_EXTRA && ($data['end_minute'] - $data['start_minute']) < (int)$binding['duration_minutes']) {
                throw new AdminException('special_window_shorter_than_service_duration');
            }
        }
        $data['reason'] = trim((string)($data['reason'] ?? ''));
        $data['active_key'] = $this->activeKey([$data['store_service_id'], $data['service_date'], $data['date_type'], $data['start_minute'], $data['end_minute']], $data['status']);
        $data['updated_uid'] = $operatorUid;
        if ($id === 0) {
            $data['created_uid'] = $operatorUid;
        }
        if ($data['status'] === YfthConstants::STATUS_ACTIVE) {
            $data['disabled_uid'] = 0;
            $data['disabled_time'] = 0;
            $data['close_reason'] = '';
        }
        return $this->withTimestamps($data, $id === 0);
    }

    private function assertScheduleNotOverlapping(array $data, int $id): void
    {
        if ((string)$data['status'] !== YfthConstants::STATUS_ACTIVE) {
            return;
        }
        $rows = $this->dao->selectList([
            'store_service_id' => (int)$data['store_service_id'],
            'weekday' => (int)$data['weekday'],
            'status' => YfthConstants::STATUS_ACTIVE,
        ], '*', 0, 0, 'id asc', [], false)->toArray();
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                continue;
            }
            if ($this->isIntervalOverlap((int)$data['start_minute'], (int)$data['end_minute'], (int)$row['start_minute'], (int)$row['end_minute'])) {
                throw new AdminException('schedule_rule_overlap');
            }
        }
    }

    private function assertSpecialDayCompatible(array $data, int $id): void
    {
        if ((string)$data['status'] !== YfthConstants::STATUS_ACTIVE) {
            return;
        }
        /** @var YfthStoreServiceSpecialDayDao $specialDao */
        $specialDao = app()->make(YfthStoreServiceSpecialDayDao::class);
        $rows = $specialDao->selectList([
            'store_service_id' => (int)$data['store_service_id'],
            'service_date' => (int)$data['service_date'],
            'status' => YfthConstants::STATUS_ACTIVE,
        ], '*', 0, 0, 'id asc', [], false)->toArray();
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                continue;
            }
            if ((string)$data['date_type'] === self::TYPE_CLOSED || (string)$row['date_type'] === self::TYPE_CLOSED) {
                throw new AdminException('special_day_closed_conflict');
            }
            if ($this->isIntervalOverlap((int)$data['start_minute'], (int)$data['end_minute'], (int)$row['start_minute'], (int)$row['end_minute'])) {
                throw new AdminException('special_day_time_overlap');
            }
        }
        if ((string)$data['date_type'] === self::TYPE_EXTRA) {
            $this->assertExtraDoesNotOverlapWeeklyRule($data);
        }
    }

    private function assertExtraDoesNotOverlapWeeklyRule(array $data): void
    {
        /** @var StoreServiceAppointmentServices $storeServiceServices */
        $storeServiceServices = app()->make(StoreServiceAppointmentServices::class);
        $binding = $storeServiceServices->bindingById((int)$data['store_service_id'], false);
        $weekday = $this->weekdayFromDateInt((int)$data['service_date'], (string)$binding['timezone']);
        $rows = $this->dao->selectList([
            'store_service_id' => (int)$data['store_service_id'],
            'weekday' => $weekday,
            'status' => YfthConstants::STATUS_ACTIVE,
        ], '*', 0, 0, 'id asc', [], false)->toArray();
        foreach ($rows as $row) {
            if ($this->isIntervalOverlap((int)$data['start_minute'], (int)$data['end_minute'], (int)$row['start_minute'], (int)$row['end_minute'])) {
                throw new AdminException('extra_special_day_overlaps_weekly_schedule');
            }
        }
    }

    private function applyAdminStoreFilter(array $where, array $adminInfo): array
    {
        $storeIds = $this->adminStoreIds($adminInfo);
        if (!$storeIds) {
            return $where;
        }
        if (!empty($where['store_id']) && !in_array((int)$where['store_id'], $storeIds, true)) {
            throw new AdminException('store_scope_forbidden');
        }
        if (empty($where['store_id'])) {
            $where['store_id'] = $storeIds;
        }
        return $where;
    }

    private function formatScheduleRuleRow(array $row): array
    {
        $row['start_time_text'] = $this->minuteText((int)$row['start_minute']);
        $row['end_time_text'] = $this->minuteText((int)$row['end_minute']);
        return $row;
    }

    private function formatSpecialDayRow(array $row): array
    {
        $row['service_date_text'] = $this->serviceDateText((int)$row['service_date']);
        $row['start_time_text'] = $this->minuteText((int)$row['start_minute']);
        $row['end_time_text'] = $this->minuteText((int)$row['end_minute']);
        return $row;
    }
}
