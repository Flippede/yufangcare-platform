<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;
use think\facade\Log;

class HqAuthorityOperationRunner
{
    private const DOMAIN = 'yfth_hq_authority';
    private $idempotency;

    public function __construct(IdempotencyRecordServices $idempotency)
    {
        $this->idempotency = $idempotency;
    }

    public function run(string $action, HqAuthorityMutation $mutation, array $payload, string $objectId, callable $callback): array
    {
        if (array_key_exists('source_unique_key', $payload)) {
            throw new ApiException('authority_client_source_key_forbidden');
        }
        $payload = array_merge($payload, $mutation->payload());
        $begin = $this->idempotency->begin(self::DOMAIN, $action, $mutation->idempotencyKey(), $payload, $objectId, 600);
        if (!$begin['acquired']) {
            if (($begin['status'] ?? '') === 'succeeded' && is_array($begin['result_summary'] ?? null)) {
                return array_merge($begin['result_summary'], ['idempotent_replay' => true]);
            }
            throw new ApiException('authority_idempotency_request_processing');
        }

        $recordId = (int)$begin['record']['id'];
        $attempt = 0;
        try {
            while (true) {
                $attempt++;
                try {
                    $result = Db::transaction(function () use ($callback, $recordId, $attempt) {
                        $result = $callback($attempt);
                        $result['transaction_attempts'] = $attempt;
                        $this->idempotency->complete($recordId, $result);
                        return $result;
                    });
                    $result['idempotent_replay'] = false;
                    return $result;
                } catch (\Throwable $e) {
                    if ($attempt >= 3 || !$this->isRetryable($e)) {
                        throw $e;
                    }
                    usleep(random_int(30000, 80000) * $attempt);
                }
            }
        } catch (\Throwable $e) {
            try {
                $this->idempotency->fail($recordId, $e->getMessage());
            } catch (\Throwable $failError) {
                Log::error([
                    'msg' => 'yfth_hq_authority_idempotency_fail_write_failed',
                    'record_id' => $recordId,
                    'business_error' => $e->getMessage(),
                    'idempotency_error' => $failError->getMessage(),
                ]);
            }
            throw $e;
        }
    }

    private function isRetryable(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'deadlock') !== false
            || strpos($message, 'lock wait timeout') !== false
            || strpos($message, '1213') !== false
            || strpos($message, '1205') !== false
            || (string)$e->getCode() === '40001';
    }
}
