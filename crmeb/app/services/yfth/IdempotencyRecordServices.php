<?php

namespace app\services\yfth;

use app\dao\yfth\YfthIdempotencyRecordDao;

class IdempotencyRecordServices extends YfthFoundationBaseServices
{
    public function __construct(YfthIdempotencyRecordDao $dao)
    {
        $this->dao = $dao;
    }

    public function begin(string $domain, string $action, string $key, array $payload = [], string $objectId = '', int $ttl = 86400): array
    {
        $requestHash = hash('sha256', json_encode($this->sanitizeState($payload), JSON_UNESCAPED_UNICODE));
        $existing = $this->dao->getOne([
            'business_domain' => $domain,
            'action_type' => $action,
            'idempotency_key' => $key,
        ]);
        if ($existing) {
            return ['is_replay' => true, 'record' => $existing->toArray()];
        }
        $record = $this->dao->save($this->withTimestamps([
            'business_domain' => $domain,
            'action_type' => $action,
            'idempotency_key' => $key,
            'object_id' => $objectId,
            'request_hash' => $requestHash,
            'process_status' => 'processing',
            'result_summary' => '',
            'fail_reason' => '',
            'finish_time' => 0,
            'expire_time' => time() + $ttl,
        ], true));
        return ['is_replay' => false, 'record' => $record->toArray()];
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
            'fail_reason' => $reason,
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }
}
