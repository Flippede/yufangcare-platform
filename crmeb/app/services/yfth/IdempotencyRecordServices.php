<?php

namespace app\services\yfth;

use app\dao\yfth\YfthIdempotencyRecordDao;
use crmeb\exceptions\ApiException;

class IdempotencyRecordServices extends YfthFoundationBaseServices
{
    public function __construct(YfthIdempotencyRecordDao $dao)
    {
        $this->dao = $dao;
    }

    public function begin(string $domain, string $action, string $key, array $payload = [], string $objectId = '', int $ttl = 86400): array
    {
        if ($domain === '' || $action === '' || $key === '') {
            throw new ApiException('idempotency_domain_action_key_required');
        }

        $now = time();
        $requestHash = hash('sha256', json_encode($this->sanitizeState($payload), JSON_UNESCAPED_UNICODE));
        $data = $this->withTimestamps([
            'business_domain' => $domain,
            'action_type' => $action,
            'idempotency_key' => $key,
            'object_id' => $objectId,
            'request_hash' => $requestHash,
            'process_status' => 'processing',
            'result_summary' => '',
            'fail_reason' => '',
            'finish_time' => 0,
            'expire_time' => $now + max(1, $ttl),
        ], true);

        try {
            $record = $this->dao->save($data);
            return $this->beginResult(true, false, $record->toArray());
        } catch (\Throwable $e) {
            if (!$this->isUniqueConflict($e)) {
                throw $e;
            }
        }

        $existing = $this->dao->getOne([
            'business_domain' => $domain,
            'action_type' => $action,
            'idempotency_key' => $key,
        ]);
        if (!$existing) {
            throw new ApiException('idempotency_conflict_record_missing');
        }
        $row = $existing->toArray();
        if (!hash_equals((string)$row['request_hash'], $requestHash)) {
            throw new ApiException('idempotency_key_payload_mismatch');
        }

        if ($row['process_status'] === 'processing' && (int)$row['expire_time'] <= $now) {
            $updated = $this->dao->search([])
                ->where('id', (int)$row['id'])
                ->where('process_status', 'processing')
                ->where('expire_time', '<=', $now)
                ->update([
                    'object_id' => $objectId ?: (string)($row['object_id'] ?? ''),
                    'process_status' => 'processing',
                    'result_summary' => '',
                    'fail_reason' => '',
                    'finish_time' => 0,
                    'expire_time' => $now + max(1, $ttl),
                    'update_time' => $now,
                ]);
            if ($updated) {
                $row = $this->dao->get((int)$row['id'])->toArray();
                return $this->beginResult(true, false, $row, ['recovered_expired_processing' => true]);
            }
        }

        $extra = [];
        if ($row['process_status'] === 'succeeded') {
            $extra['result_summary'] = $this->jsonDecode($row['result_summary'] ?? '');
        }
        if ($row['process_status'] === 'failed') {
            $extra['can_retry'] = true;
            $extra['fail_reason'] = (string)($row['fail_reason'] ?? '');
        }
        return $this->beginResult(false, true, $row, $extra);
    }

    public function complete(int $id, array $summary = []): void
    {
        $this->dao->update($id, [
            'process_status' => 'succeeded',
            'result_summary' => $this->jsonEncode($this->sanitizeState($summary)),
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }

    public function fail(int $id, string $reason): void
    {
        $this->dao->update($id, [
            'process_status' => 'failed',
            'fail_reason' => substr($reason, 0, 255),
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function beginResult(bool $acquired, bool $isReplay, array $record, array $extra = []): array
    {
        return array_merge([
            'acquired' => $acquired,
            'is_replay' => $isReplay,
            'status' => (string)($record['process_status'] ?? ''),
            'record' => $record,
        ], $extra);
    }

    private function isUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if (strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || strpos($message, 'uniq_yfth_idem_key') !== false) {
            return true;
        }
        return (string)$e->getCode() === '23000';
    }
}
