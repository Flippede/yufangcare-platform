<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use DateTimeImmutable;
use DateTimeZone;

abstract class ServiceAppointmentBaseServices extends YfthFoundationBaseServices
{
    protected const DOMAIN = 'yfth_service_appointment';
    protected const DEFAULT_TIMEZONE = 'Asia/Shanghai';
    protected const TYPE_CLOSED = 'closed';
    protected const TYPE_EXTRA = 'extra';
    protected const TYPE_CAPACITY_OVERRIDE = 'capacity_override';

    protected function requireRow($row, string $message): array
    {
        if (!$row) {
            throw new ApiException($message);
        }
        return is_array($row) ? $row : $row->toArray();
    }

    protected function recordServiceAudit(
        string $objectType,
        string $objectId,
        string $action,
        array $before = [],
        array $after = [],
        int $operatorUid = 0,
        string $roleCode = 'admin',
        int $storeId = 0,
        string $reason = '',
        string $requestId = ''
    ): void {
        /** @var AuditEventServices $audit */
        $audit = app()->make(AuditEventServices::class);
        $audit->recordSafely(self::DOMAIN, $objectType, $objectId, $action, $before, $after, $operatorUid, $roleCode, $storeId, $reason, $requestId);
    }

    protected function normalizeStatus(string $status, array $allowed = ['active', 'disabled']): string
    {
        $status = trim($status) ?: YfthConstants::STATUS_ACTIVE;
        if (!in_array($status, $allowed, true)) {
            throw new AdminException('invalid_status');
        }
        return $status;
    }

    protected function normalizeBool($value): int
    {
        return (int)((bool)$value);
    }

    protected function boundedInt($value, int $min, int $max, string $message): int
    {
        $value = (int)$value;
        if ($value < $min || $value > $max) {
            throw new AdminException($message);
        }
        return $value;
    }

    protected function normalizeTimezone(string $timezone): string
    {
        $timezone = trim($timezone) ?: self::DEFAULT_TIMEZONE;
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable $e) {
            throw new AdminException('invalid_timezone');
        }
        return $timezone;
    }

    protected function normalizeMinuteRange(array $data): array
    {
        $start = (int)($data['start_minute'] ?? 0);
        $end = (int)($data['end_minute'] ?? 0);
        if ($start < 0 || $start > 1439 || $end < 1 || $end > 1440 || $end <= $start) {
            throw new AdminException('invalid_time_range');
        }
        if ($end === 1440 && $start === 0) {
            return [$start, $end];
        }
        if ($end > 1440) {
            throw new AdminException('cross_day_slots_not_supported_v1');
        }
        return [$start, $end];
    }

    protected function serviceDateToInt($value, string $timezone = self::DEFAULT_TIMEZONE): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^\d{8}$/', $raw)) {
            $date = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $date = $raw;
        } else {
            throw new ApiException('invalid_service_date');
        }

        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($this->normalizeTimezoneForRead($timezone)));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $dt->format('Y-m-d') !== $date) {
            throw new ApiException('invalid_service_date');
        }
        return (int)$dt->format('Ymd');
    }

    protected function serviceDateText(int $serviceDate): string
    {
        $text = (string)$serviceDate;
        if (!preg_match('/^\d{8}$/', $text)) {
            return '';
        }
        return substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2);
    }

    protected function weekdayFromDateInt(int $serviceDate, string $timezone = self::DEFAULT_TIMEZONE): int
    {
        $dateText = $this->serviceDateText($serviceDate);
        $dt = new DateTimeImmutable($dateText . ' 00:00:00', new DateTimeZone($this->normalizeTimezoneForRead($timezone)));
        return (int)$dt->format('N');
    }

    protected function normalizeDateRange($startDate, $endDate, int $maxDays, string $timezone): array
    {
        $timezone = $this->normalizeTimezoneForRead($timezone);
        $start = $startDate ? $this->serviceDateToInt($startDate, $timezone) : (int)(new DateTimeImmutable('today', new DateTimeZone($timezone)))->format('Ymd');
        $end = $endDate ? $this->serviceDateToInt($endDate, $timezone) : $start;
        if ($end < $start) {
            throw new ApiException('invalid_date_range');
        }
        $maxDays = max(1, min($maxDays, 60));
        $dates = [];
        $cursor = new DateTimeImmutable($this->serviceDateText($start) . ' 00:00:00', new DateTimeZone($timezone));
        $last = new DateTimeImmutable($this->serviceDateText($end) . ' 00:00:00', new DateTimeZone($timezone));
        $limit = 0;
        while ($cursor <= $last && $limit < $maxDays) {
            $dates[] = (int)$cursor->format('Ymd');
            $cursor = $cursor->modify('+1 day');
            $limit++;
        }
        return $dates;
    }

    protected function minuteText(int $minute): string
    {
        $minute = max(0, min(1440, $minute));
        if ($minute === 1440) {
            return '24:00';
        }
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    protected function slotTimestamp(int $serviceDate, int $minute, string $timezone): int
    {
        $dateText = $this->serviceDateText($serviceDate);
        $dt = new DateTimeImmutable($dateText . ' 00:00:00', new DateTimeZone($this->normalizeTimezoneForRead($timezone)));
        return $dt->modify('+' . $minute . ' minutes')->getTimestamp();
    }

    protected function isIntervalOverlap(int $start, int $end, int $otherStart, int $otherEnd): bool
    {
        return $start < $otherEnd && $end > $otherStart;
    }

    protected function assertHeadquarterScope(array $adminInfo): void
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
    }

    protected function assertStoreConfigScope(array $adminInfo, int $storeId): void
    {
        app()->make(AdminStoreContextServices::class)->assertStoreWritable($adminInfo, $storeId);
    }

    protected function adminStoreIds(array $adminInfo): array
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($adminInfo);
        return array_values(array_filter(array_unique(array_map('intval', $context['store_ids'] ?? []))));
    }

    private function normalizeTimezoneForRead(string $timezone): string
    {
        $timezone = trim($timezone) ?: self::DEFAULT_TIMEZONE;
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable $e) {
            return self::DEFAULT_TIMEZONE;
        }
        return $timezone;
    }
}
