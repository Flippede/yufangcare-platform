<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use DateTime;

class HqAuthorityReadParameterServices
{
    private const UINT_MAX = '4294967295';
    private const PAGE_MAX = 1000000;
    private const LIMIT_MAX = 50;
    private const ATTRIBUTION_STATUSES = ['active', 'paused', 'unassigned', 'closed'];
    private const REFERRAL_STATUSES = ['active', 'paused', 'closed', 'invalid'];
    private const STORE_STATUSES = ['active', 'paused'];
    private const STORE_ROLES = ['franchisee', 'store_manager', 'store_staff', 'service_mentor', 'customer'];

    public function positiveId($value, string $name): int
    {
        return $this->positiveInteger($value, $name, (int)self::UINT_MAX);
    }

    public function storeFilters(array $input): array
    {
        $this->rejectSort($input);
        $result = $this->page($input);
        $result['status'] = $this->optionalEnum($input, 'status', self::STORE_STATUSES);
        $result['keyword'] = $this->optionalString($input, 'keyword', 50);
        if (array_key_exists('store_id', $input)) {
            $result['requested_store_id'] = $this->positiveId($input['store_id'], 'store_id');
        }
        if (array_key_exists('role_code', $input)) {
            $result['requested_role_code'] = $this->enumValue($input['role_code'], 'role_code', self::STORE_ROLES);
        }
        return $result;
    }

    public function attributionFilters(array $input): array
    {
        $this->rejectSort($input);
        $result = $this->page($input);
        foreach (['uid', 'store_id'] as $name) {
            if (array_key_exists($name, $input)) {
                $result[$name] = $this->positiveId($input[$name], $name);
            }
        }
        $result['status'] = $this->optionalEnum($input, 'status', self::ATTRIBUTION_STATUSES);
        return $this->dates($input, $result);
    }

    public function referralFilters(array $input): array
    {
        $this->rejectSort($input);
        $result = $this->page($input);
        foreach (['store_id', 'referrer_uid', 'referred_uid'] as $name) {
            if (array_key_exists($name, $input)) {
                $result[$name] = $this->positiveId($input[$name], $name);
            }
        }
        $result['status'] = $this->optionalEnum($input, 'status', self::REFERRAL_STATUSES);
        return $this->dates($input, $result);
    }

    private function page(array $input): array
    {
        return [
            'page' => array_key_exists('page', $input)
                ? $this->positiveInteger($input['page'], 'page', self::PAGE_MAX) : 1,
            'limit' => array_key_exists('limit', $input)
                ? $this->positiveInteger($input['limit'], 'limit', self::LIMIT_MAX) : 20,
        ];
    }

    private function dates(array $input, array $result): array
    {
        foreach (['start_date', 'end_date'] as $name) {
            if (array_key_exists($name, $input)) {
                $result[$name] = $this->date($input[$name], $name);
            }
        }
        if (isset($result['start_date'], $result['end_date'])) {
            $start = DateTime::createFromFormat('!Y-m-d', $result['start_date']);
            $end = DateTime::createFromFormat('!Y-m-d', $result['end_date']);
            $days = (int)$start->diff($end)->format('%r%a');
            if ($days < 0 || $days > 366) {
                throw new ApiException('authority_date_range_invalid');
            }
        }
        return $result;
    }

    private function positiveInteger($value, string $name, int $max): int
    {
        if (is_int($value)) {
            if ($value <= 0 || $value > $max) {
                throw new ApiException('authority_' . $name . '_invalid');
            }
            return $value;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new ApiException('authority_' . $name . '_invalid');
        }
        $normalized = ltrim($value, '0');
        $maximum = (string)$max;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new ApiException('authority_' . $name . '_invalid');
        }
        return (int)$normalized;
    }

    private function date($value, string $name): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new ApiException('authority_' . $name . '_invalid');
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        if (!$date || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ApiException('authority_' . $name . '_invalid');
        }
        return $value;
    }

    private function optionalEnum(array $input, string $name, array $allowed): string
    {
        if (!array_key_exists($name, $input) || $input[$name] === '') {
            return '';
        }
        return $this->enumValue($input[$name], $name, $allowed);
    }

    private function enumValue($value, string $name, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ApiException('authority_' . $name . '_invalid');
        }
        return $value;
    }

    private function optionalString(array $input, string $name, int $maxLength): string
    {
        if (!array_key_exists($name, $input) || $input[$name] === '') {
            return '';
        }
        if (!is_string($input[$name]) || mb_strlen($input[$name]) > $maxLength) {
            throw new ApiException('authority_' . $name . '_invalid');
        }
        return trim($input[$name]);
    }

    private function rejectSort(array $input): void
    {
        foreach (['sort', 'sort_by', 'order', 'order_by'] as $name) {
            if (array_key_exists($name, $input)) {
                throw new ApiException('authority_sort_invalid');
            }
        }
    }
}
