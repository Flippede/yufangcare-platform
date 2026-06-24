<?php

namespace app\services\yfth;

use app\services\BaseServices;

abstract class YfthFoundationBaseServices extends BaseServices
{
    protected function pageList(array $where = [], string $field = '*', string $order = 'id desc', callable $formatter = null): array
    {
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $list = $this->dao->selectList($where, $field, $page, $limit, $order, [], false)->toArray();
        if ($formatter) {
            $list = array_map($formatter, $list);
        }
        $count = $this->dao->getCount($where);
        return compact('list', 'count');
    }

    protected function cleanWhere(array $where): array
    {
        foreach ($where as $key => $value) {
            if ($value === '' || $value === null) {
                unset($where[$key]);
            }
        }
        return $where;
    }

    protected function withTimestamps(array $data, bool $creating = false): array
    {
        $now = time();
        if ($creating) {
            $data['add_time'] = $data['add_time'] ?? $now;
        }
        $data['update_time'] = $now;
        return $data;
    }

    protected function activeKey(array $parts, string $status): ?string
    {
        if ($status !== YfthConstants::STATUS_ACTIVE) {
            return null;
        }
        return implode(':', array_map('strval', $parts));
    }

    protected function parseTime($value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            return $timestamp ? $timestamp : 0;
        }
        return 0;
    }

    protected function jsonEncode($value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }
        if (is_string($value)) {
            json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $value : json_encode([$value], JSON_UNESCAPED_UNICODE);
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    protected function jsonDecode($value)
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

    protected function applyActiveWindow($query, string $startField = 'effective_time', string $endField = 'expire_time')
    {
        $now = time();
        return $query
            ->where(function ($query) use ($startField, $now) {
                $query->where($startField, '=', 0)->whereOr($startField, '<=', $now);
            })
            ->where(function ($query) use ($endField, $now) {
                $query->where($endField, '=', 0)->whereOr($endField, '>', $now);
            });
    }

    protected function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        if (strlen($phone) <= 7) {
            return substr($phone, 0, 1) . '****';
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    protected function maskRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }
        if (strlen($ref) <= 8) {
            return '****' . substr($ref, -2);
        }
        return substr($ref, 0, 4) . '****' . substr($ref, -4);
    }

    protected function maskCreditCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        if (strlen($code) <= 8) {
            return '****' . substr($code, -2);
        }
        return substr($code, 0, 4) . '********' . substr($code, -4);
    }

    protected function maskVerifyCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        return 'hash:' . substr(hash('sha256', $code), 0, 16) . ':tail:' . substr($code, -4);
    }

    protected function sanitizeState($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $lower = strtolower((string)$key);
            if ($this->isSensitiveStateKey($lower)) {
                $result[$key] = $this->maskSensitiveValue($lower, $item);
                continue;
            }
            if (strpos($lower, 'phone') !== false || strpos($lower, 'mobile') !== false) {
                $result[$key] = is_string($item) ? $this->maskPhone($item) : '[masked]';
                continue;
            }
            $result[$key] = is_array($item) ? $this->sanitizeState($item) : $item;
        }
        return $result;
    }

    private function isSensitiveStateKey(string $key): bool
    {
        if (in_array($key, ['verify_code_hash', 'verify_code_tail', 'credit_code_masked', 'merchant_ref_masked', 'sub_merchant_ref_masked'], true)) {
            return false;
        }
        if ($key === 'key' || substr($key, -4) === '_key') {
            return true;
        }
        foreach ([
            'secret',
            'token',
            'password',
            'verify_code',
            'verification_code',
            'private',
            'api_key',
            'appsecret',
            'certificate_no',
            'cert_no',
            'credit_code',
            'id_card',
            'identity_no',
            'license_no',
            'merchant_ref',
            'sub_merchant_ref',
        ] as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function maskSensitiveValue(string $key, $value)
    {
        if (is_array($value)) {
            return $this->sanitizeState($value);
        }
        if (!is_scalar($value)) {
            return '[redacted]';
        }
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        if (strpos($key, 'verify_code') !== false || strpos($key, 'verification_code') !== false) {
            return $this->maskVerifyCode($value);
        }
        if (strpos($key, 'credit_code') !== false) {
            return $this->maskCreditCode($value);
        }
        if (strpos($key, 'phone') !== false || strpos($key, 'mobile') !== false) {
            return $this->maskPhone($value);
        }
        return '[redacted:' . substr(hash('sha256', $value), 0, 12) . ':tail:' . substr($value, -4) . ']';
    }
}
