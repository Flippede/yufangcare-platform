<?php

namespace app\services\yfth;

use app\dao\yfth\YfthServiceProjectDao;
use app\dao\yfth\YfthStoreServiceScheduleRuleDao;
use app\dao\yfth\YfthStoreServiceSpecialDayDao;
use DateTimeImmutable;
use DateTimeZone;

class ServiceAppointmentQueryServices extends ServiceAppointmentBaseServices
{
    public function __construct(YfthServiceProjectDao $dao)
    {
        $this->dao = $dao;
    }

    public function projectList(array $where = []): array
    {
        /** @var ServiceProjectServices $projectServices */
        $projectServices = app()->make(ServiceProjectServices::class);
        return $projectServices->publicList($where);
    }

    public function projectDetail(int $projectId): array
    {
        $project = $this->projectAvailability($projectId);
        if ($project['status'] !== 'ok') {
            return ['status' => 'unavailable', 'reason' => $project['reason']];
        }
        return ['status' => 'ok', 'project' => $project['project']];
    }

    public function serviceStores(int $projectId): array
    {
        $contextProject = $this->projectAvailability($projectId);
        if ($contextProject['status'] !== 'ok') {
            return ['status' => 'unavailable', 'reason' => $contextProject['reason'], 'list' => []];
        }
        /** @var StoreServiceAppointmentServices $storeServiceServices */
        $storeServiceServices = app()->make(StoreServiceAppointmentServices::class);
        /** @var StoreAccessServices $storeAccess */
        $storeAccess = app()->make(StoreAccessServices::class);
        /** @var StoreCapabilityServices $capabilityServices */
        $capabilityServices = app()->make(StoreCapabilityServices::class);
        $list = [];
        foreach ($storeServiceServices->activeBindingsForProject($projectId) as $binding) {
            try {
                $store = $storeAccess->assertStoreActive((int)$binding['store_id']);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$capabilityServices->isAvailable((int)$binding['store_id'], 'reservation_service')) {
                continue;
            }
            $list[] = [
                'store_service_id' => (int)$binding['id'],
                'store_id' => (int)$binding['store_id'],
                'store_name' => (string)$store['store_name'],
                'service_project_id' => (int)$binding['service_project_id'],
                'service_alias' => (string)$binding['service_alias'],
                'duration_minutes' => (int)$binding['duration_minutes'],
                'requires_confirmation' => (int)$binding['requires_confirmation'],
                'default_capacity' => (int)$binding['default_capacity'],
                'timezone' => (string)$binding['timezone'],
            ];
        }
        return ['status' => 'ok', 'list' => $list, 'count' => count($list)];
    }

    public function availableDates(int $projectId, array $where): array
    {
        $context = $this->availableContext($projectId, (int)($where['store_id'] ?? 0));
        if ($context['status'] !== 'ok') {
            return ['status' => 'unavailable', 'reason' => $context['reason'], 'dates' => []];
        }
        $binding = $context['binding'];
        $dates = $this->normalizeDateRange($where['start_date'] ?? '', $where['end_date'] ?? '', (int)$binding['advance_max_days'], (string)$binding['timezone']);
        $result = [];
        foreach ($dates as $date) {
            $day = $this->slotsForBinding($binding, $date, false);
            $result[] = [
                'date' => $this->serviceDateText($date),
                'service_date' => $date,
                'available' => $day['status'] === 'ok' && !empty($day['slots']) ? 1 : 0,
                'reason' => $day['reason'] ?? '',
                'slot_count' => count($day['slots'] ?? []),
                'total_capacity' => (int)($day['total_capacity'] ?? 0),
                'remaining_capacity' => (int)($day['remaining_capacity'] ?? 0),
            ];
        }
        return [
            'status' => 'ok',
            'store_id' => (int)$binding['store_id'],
            'service_project_id' => $projectId,
            'dates' => $result,
        ];
    }

    public function daySlots(int $projectId, array $where): array
    {
        $context = $this->availableContext($projectId, (int)($where['store_id'] ?? 0));
        if ($context['status'] !== 'ok') {
            return ['status' => 'unavailable', 'reason' => $context['reason'], 'slots' => []];
        }
        $binding = $context['binding'];
        $date = $this->serviceDateToInt($where['date'] ?? '', (string)$binding['timezone']);
        $day = $this->slotsForBinding($binding, $date, false);
        $day['store'] = $context['store'];
        $day['project'] = $context['project'];
        return $day;
    }

    public function slotsForBinding(array $binding, int $serviceDate, bool $ignoreAdvance = false): array
    {
        /** @var YfthStoreServiceScheduleRuleDao $ruleDao */
        $ruleDao = app()->make(YfthStoreServiceScheduleRuleDao::class);
        /** @var YfthStoreServiceSpecialDayDao $specialDao */
        $specialDao = app()->make(YfthStoreServiceSpecialDayDao::class);
        $timezone = (string)($binding['timezone'] ?? self::DEFAULT_TIMEZONE);
        $weekday = $this->weekdayFromDateInt($serviceDate, $timezone);
        $specialRows = $specialDao->selectList([
            'store_service_id' => (int)$binding['id'],
            'service_date' => $serviceDate,
            'status' => YfthConstants::STATUS_ACTIVE,
        ], '*', 0, 0, 'start_minute asc,id asc', [], false)->toArray();

        foreach ($specialRows as $row) {
            if ((string)$row['date_type'] === self::TYPE_CLOSED) {
                return $this->emptyDay($binding, $serviceDate, 'special_day_closed');
            }
        }

        $slots = [];
        $ruleRows = $ruleDao->selectList([
            'store_service_id' => (int)$binding['id'],
            'weekday' => $weekday,
            'status' => YfthConstants::STATUS_ACTIVE,
        ], '*', 0, 0, 'start_minute asc,id asc', [], false)->toArray();
        foreach ($ruleRows as $rule) {
            $slots = array_merge($slots, $this->generateSlots(
                $binding,
                $serviceDate,
                (int)$rule['start_minute'],
                (int)$rule['end_minute'],
                (int)$rule['slot_capacity'],
                (int)$rule['slot_interval_minutes'],
                'weekly_rule',
                (int)$rule['id'],
                $ignoreAdvance
            ));
        }

        foreach ($specialRows as $row) {
            if ((string)$row['date_type'] !== self::TYPE_EXTRA) {
                continue;
            }
            $slots = array_merge($slots, $this->generateSlots(
                $binding,
                $serviceDate,
                (int)$row['start_minute'],
                (int)$row['end_minute'],
                (int)$row['slot_capacity'],
                (int)$binding['duration_minutes'],
                'special_extra',
                (int)$row['id'],
                $ignoreAdvance
            ));
        }

        $slots = $this->dedupeSlots($slots);
        $slots = $this->applyCapacityOverrides($slots, $specialRows);
        usort($slots, function ($a, $b) {
            return $a['start_minute'] <=> $b['start_minute'];
        });
        $total = array_sum(array_map('intval', array_column($slots, 'capacity')));
        $reason = '';
        if (!$slots) {
            $reason = $ruleRows || $this->hasSpecialExtra($specialRows) ? 'no_slot_in_advance_window' : 'no_schedule';
        }
        return [
            'status' => $slots ? 'ok' : 'empty',
            'reason' => $reason,
            'slot_generation_mode' => 'rule_realtime_with_special_day_overlay',
            'store_service_id' => (int)$binding['id'],
            'store_id' => (int)$binding['store_id'],
            'service_project_id' => (int)$binding['service_project_id'],
            'date' => $this->serviceDateText($serviceDate),
            'service_date' => $serviceDate,
            'timezone' => $timezone,
            'slots' => $slots,
            'total_capacity' => $total,
            'occupied_count' => 0,
            'locked_count' => 0,
            'remaining_capacity' => $total,
        ];
    }

    private function availableContext(int $projectId, int $storeId): array
    {
        $project = $this->projectAvailability($projectId);
        if ($project['status'] !== 'ok') {
            return $project;
        }
        if ($storeId <= 0) {
            return ['status' => 'unavailable', 'reason' => 'store_id_required'];
        }
        /** @var StoreAccessServices $storeAccess */
        $storeAccess = app()->make(StoreAccessServices::class);
        try {
            $store = $storeAccess->assertStoreActive($storeId);
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'reason' => $e->getMessage() ?: 'store_not_active'];
        }
        /** @var StoreServiceAppointmentServices $storeServiceServices */
        $storeServiceServices = app()->make(StoreServiceAppointmentServices::class);
        try {
            $binding = $storeServiceServices->activeBinding($storeId, $projectId);
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'reason' => 'store_service_not_available'];
        }
        /** @var StoreCapabilityServices $capabilityServices */
        $capabilityServices = app()->make(StoreCapabilityServices::class);
        if (!$capabilityServices->isAvailable($storeId, 'reservation_service')) {
            return ['status' => 'unavailable', 'reason' => 'store_capability_unavailable'];
        }
        return [
            'status' => 'ok',
            'project' => $project['project'],
            'store' => $store,
            'binding' => $binding,
        ];
    }

    private function projectAvailability(int $projectId): array
    {
        $project = $this->dao->get($projectId);
        if (!$project) {
            return ['status' => 'unavailable', 'reason' => 'service_project_not_found'];
        }
        $project = $project->toArray();
        if ((string)$project['status'] !== YfthConstants::STATUS_ACTIVE) {
            return ['status' => 'unavailable', 'reason' => 'service_project_not_active'];
        }
        /** @var ServiceProjectServices $projectServices */
        $projectServices = app()->make(ServiceProjectServices::class);
        return ['status' => 'ok', 'project' => $projectServices->publicProjectRow($project)];
    }

    private function generateSlots(array $binding, int $serviceDate, int $start, int $end, int $capacity, int $interval, string $sourceType, int $sourceId, bool $ignoreAdvance): array
    {
        $duration = (int)$binding['duration_minutes'];
        $interval = $interval > 0 ? $interval : $duration;
        $capacity = $capacity > 0 ? $capacity : (int)$binding['default_capacity'];
        $timezone = (string)$binding['timezone'];
        $slots = [];
        for ($minute = $start; $minute + $duration <= $end; $minute += $interval) {
            $slotStart = $this->slotTimestamp($serviceDate, $minute, $timezone);
            if (!$ignoreAdvance && !$this->slotInAdvanceWindow($slotStart, $binding, $timezone)) {
                continue;
            }
            $slots[] = [
                'slot_key' => $serviceDate . ':' . $minute . ':' . ($minute + $duration),
                'start_minute' => $minute,
                'end_minute' => $minute + $duration,
                'start_time' => $this->minuteText($minute),
                'end_time' => $this->minuteText($minute + $duration),
                'start_timestamp' => $slotStart,
                'end_timestamp' => $this->slotTimestamp($serviceDate, $minute + $duration, $timezone),
                'capacity' => $capacity,
                'occupied_count' => 0,
                'locked_count' => 0,
                'remaining_capacity' => $capacity,
                'status' => $capacity > 0 ? 'available' : 'closed',
                'capacity_source' => $sourceType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];
        }
        return $slots;
    }

    private function slotInAdvanceWindow(int $slotStart, array $binding, string $timezone): bool
    {
        $tz = new DateTimeZone($timezone);
        $now = new DateTimeImmutable('now', $tz);
        $earliest = $now->modify('+' . (int)$binding['advance_min_minutes'] . ' minutes')->getTimestamp();
        $latest = $now->modify('+' . (int)$binding['advance_max_days'] . ' days')->setTime(23, 59, 59)->getTimestamp();
        return $slotStart >= $earliest && $slotStart <= $latest;
    }

    private function dedupeSlots(array $slots): array
    {
        $map = [];
        foreach ($slots as $slot) {
            $key = (string)$slot['slot_key'];
            if (!isset($map[$key])) {
                $map[$key] = $slot;
            }
        }
        return array_values($map);
    }

    private function applyCapacityOverrides(array $slots, array $specialRows): array
    {
        $overrides = array_values(array_filter($specialRows, function ($row) {
            return (string)$row['date_type'] === self::TYPE_CAPACITY_OVERRIDE;
        }));
        if (!$overrides) {
            return $slots;
        }
        foreach ($slots as &$slot) {
            foreach ($overrides as $override) {
                if (!$this->isIntervalOverlap((int)$slot['start_minute'], (int)$slot['end_minute'], (int)$override['start_minute'], (int)$override['end_minute'])) {
                    continue;
                }
                $capacity = (int)$override['slot_capacity'];
                $slot['capacity'] = $capacity;
                $slot['remaining_capacity'] = $capacity;
                $slot['capacity_source'] = 'special_capacity_override';
                $slot['capacity_override_id'] = (int)$override['id'];
                $slot['status'] = $capacity > 0 ? 'available' : 'closed';
            }
        }
        unset($slot);
        return $slots;
    }

    private function hasSpecialExtra(array $specialRows): bool
    {
        foreach ($specialRows as $row) {
            if ((string)$row['date_type'] === self::TYPE_EXTRA) {
                return true;
            }
        }
        return false;
    }

    private function emptyDay(array $binding, int $serviceDate, string $reason): array
    {
        return [
            'status' => 'empty',
            'reason' => $reason,
            'slot_generation_mode' => 'rule_realtime_with_special_day_overlay',
            'store_service_id' => (int)$binding['id'],
            'store_id' => (int)$binding['store_id'],
            'service_project_id' => (int)$binding['service_project_id'],
            'date' => $this->serviceDateText($serviceDate),
            'service_date' => $serviceDate,
            'timezone' => (string)$binding['timezone'],
            'slots' => [],
            'total_capacity' => 0,
            'occupied_count' => 0,
            'locked_count' => 0,
            'remaining_capacity' => 0,
        ];
    }
}
