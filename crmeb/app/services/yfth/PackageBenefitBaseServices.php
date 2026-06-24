<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

abstract class PackageBenefitBaseServices extends YfthFoundationBaseServices
{
    protected const DOMAIN = 'yfth_package_benefit';

    protected function normalizeMoney($value): string
    {
        return number_format((float)$value, 2, '.', '');
    }

    protected function moneyEquals($left, $right): bool
    {
        return bccomp($this->normalizeMoney($left), $this->normalizeMoney($right), 2) === 0;
    }

    protected function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    protected function summaryText(string $content, int $limit = 200): string
    {
        $content = trim(strip_tags($content));
        if (function_exists('mb_substr')) {
            return mb_substr($content, 0, $limit, 'UTF-8');
        }
        return substr($content, 0, $limit);
    }

    protected function statusMachine(): PackageBenefitStateMachine
    {
        return app()->make(PackageBenefitStateMachine::class);
    }

    protected function assertTransition(string $machine, string $from, string $to): void
    {
        $this->statusMachine()->assertTransition($machine, $from, $to);
    }

    protected function recordPackageAudit(
        string $objectType,
        string $objectId,
        string $action,
        array $before = [],
        array $after = [],
        int $operatorUid = 0,
        string $roleCode = '',
        int $storeId = 0,
        string $reason = '',
        string $requestId = ''
    ): void {
        /** @var AuditEventServices $audit */
        $audit = app()->make(AuditEventServices::class);
        $audit->recordSafely(self::DOMAIN, $objectType, $objectId, $action, $before, $after, $operatorUid, $roleCode, $storeId, $reason, $requestId);
    }

    protected function requireRow($row, string $message): array
    {
        if (!$row) {
            throw new ApiException($message);
        }
        return is_array($row) ? $row : $row->toArray();
    }
}
