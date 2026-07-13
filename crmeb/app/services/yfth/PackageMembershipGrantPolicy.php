<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

class PackageMembershipGrantPolicy
{
    public const SEMANTICS_EXPLICIT = 'explicit_snapshot';
    public const SEMANTICS_LEGACY_PACKAGE = 'legacy_package_semantics';

    public function forRule($rule): array
    {
        return $this->decision($this->normalizeRow($rule, 'package_rule_membership_grant_invalid'), 'package_rule_membership_grant_invalid');
    }

    public function forSnapshot($snapshot): array
    {
        return $this->decision($this->normalizeRow($snapshot, 'package_snapshot_membership_grant_invalid'), 'package_snapshot_membership_grant_invalid');
    }

    private function normalizeRow($row, string $error): array
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            return $row->toArray();
        }
        throw new ApiException($error);
    }

    private function decision(array $row, string $error): array
    {
        if (!array_key_exists('grants_permanent_membership', $row)
            || $row['grants_permanent_membership'] === null) {
            return [
                'grants_permanent_membership' => true,
                'semantics' => self::SEMANTICS_LEGACY_PACKAGE,
            ];
        }

        $value = $row['grants_permanent_membership'];
        if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
            throw new ApiException($error);
        }

        return [
            'grants_permanent_membership' => (bool)$value,
            'semantics' => self::SEMANTICS_EXPLICIT,
        ];
    }
}
