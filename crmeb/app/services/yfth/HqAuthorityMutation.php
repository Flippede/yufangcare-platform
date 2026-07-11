<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

final class HqAuthorityMutation
{
    private $source;
    private $operatorUid;
    private $operatorRoleCode;
    private $reason;
    private $requestId;
    private $idempotencyKey;

    public function __construct(
        HqAuthoritySource $source,
        int $operatorUid,
        string $operatorRoleCode,
        string $reason,
        string $requestId,
        string $idempotencyKey
    ) {
        $operatorRoleCode = trim($operatorRoleCode);
        $idempotencyKey = trim($idempotencyKey);
        if ($operatorUid <= 0 || $operatorRoleCode === '') {
            throw new ApiException('authority_operator_required');
        }
        if ($idempotencyKey === '') {
            throw new ApiException('authority_idempotency_key_required');
        }
        $this->source = $source;
        $this->operatorUid = $operatorUid;
        $this->operatorRoleCode = substr($operatorRoleCode, 0, 64);
        $this->reason = substr(trim($reason), 0, 255);
        $requestId = trim($requestId);
        $this->requestId = strlen($requestId) <= 64 ? $requestId : hash('sha256', $requestId);
        $this->idempotencyKey = $idempotencyKey;
    }

    public function source(): HqAuthoritySource { return $this->source; }
    public function operatorUid(): int { return $this->operatorUid; }
    public function operatorRoleCode(): string { return $this->operatorRoleCode; }
    public function reason(): string { return $this->reason; }
    public function requestId(): string { return $this->requestId; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }

    public function payload(): array
    {
        return [
            'source_type' => $this->source->type(),
            'source_id' => $this->source->id(),
            'operator_uid' => $this->operatorUid,
            'operator_role_code' => $this->operatorRoleCode,
            'reason' => $this->reason,
            'request_id' => $this->requestId,
        ];
    }
}
