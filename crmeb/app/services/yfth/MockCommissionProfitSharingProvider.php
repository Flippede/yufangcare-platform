<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

/**
 * Isolated-test-only provider. It creates requests but never fabricates a
 * completed result: tests still deliver a signed callback to exercise the
 * same trusted callback path used by production adapters.
 */
class MockCommissionProfitSharingProvider implements CommissionProfitSharingProviderInterface
{
    public function registerReceiver(array $receiver): array
    {
        return ['receiver_ref' => 'MOCK-R-' . substr(hash('sha256', (string)($receiver['receiver_account_masked'] ?? '')), 0, 20)];
    }

    public function createSettlement(array $batch): array
    {
        return [
            'wechat_batch_no' => 'MOCK-B-' . substr(hash('sha256', (string)$batch['batch_no']), 0, 24),
            'wechat_detail_no' => 'MOCK-D-' . substr(hash('sha256', (string)$batch['batch_no']), 0, 24),
            'status' => 'processing',
        ];
    }

    public function querySettlement(array $batch): array
    {
        return ['status' => (string)($batch['status'] ?? 'processing')];
    }

    public function createReturn(array $return, array $batch): array
    {
        return [
            'wechat_return_no' => 'MOCK-R-' . substr(hash('sha256', (string)$return['return_no']), 0, 24),
            'status' => 'processing',
        ];
    }

    public function queryReturn(array $return): array
    {
        return ['status' => (string)($return['status'] ?? 'processing')];
    }

    public function verifyCallback(array $headers, string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) throw new ApiException('commission_callback_payload_invalid');
        $signature = strtolower(trim((string)($headers['x-yfth-mock-signature'] ?? $headers['X-Yfth-Mock-Signature'] ?? '')));
        $timestamp = trim((string)($headers['x-yfth-mock-timestamp'] ?? $headers['X-Yfth-Mock-Timestamp'] ?? ''));
        $nonce = trim((string)($headers['x-yfth-mock-nonce'] ?? $headers['X-Yfth-Mock-Nonce'] ?? ''));
        $certificate = trim((string)($headers['x-yfth-mock-certificate'] ?? $headers['X-Yfth-Mock-Certificate'] ?? ''));
        $secret = trim((string)getenv('YFTH_COMMISSION_TEST_CALLBACK_SECRET'));
        if ($secret === '' || $timestamp === '' || !ctype_digit($timestamp) || $nonce === '' || $certificate !== 'YFTH-ISOLATED-TEST') {
            throw new ApiException('commission_callback_header_invalid');
        }
        if (abs(time() - (int)$timestamp) > 300) {
            throw new ApiException('commission_callback_timestamp_expired');
        }
        $signedPayload = $timestamp . "\n" . $nonce . "\n" . $rawBody;
        if (!hash_equals(hash_hmac('sha256', $signedPayload, $secret), $signature)) {
            throw new ApiException('commission_callback_signature_invalid');
        }
        $status = strtolower(trim((string)($payload['status'] ?? '')));
        $type = strtolower(trim((string)($payload['type'] ?? 'settlement')));
        if (!in_array($status, ['success', 'failed'], true) || !in_array($type, ['settlement', 'return'], true)
            || trim((string)($payload['event_id'] ?? '')) === '' || (int)($payload['amount_cent'] ?? 0) < 0) {
            throw new ApiException('commission_callback_payload_invalid');
        }
        return [
            'event_id' => substr((string)$payload['event_id'], 0, 96),
            'type' => $type,
            'status' => $status,
            'batch_no' => substr((string)($payload['batch_no'] ?? ''), 0, 96),
            'return_no' => substr((string)($payload['return_no'] ?? ''), 0, 96),
            'amount_cent' => (int)$payload['amount_cent'],
            'receiver_account_masked' => substr((string)($payload['receiver_account_masked'] ?? ''), 0, 128),
            'message' => substr((string)($payload['message'] ?? ''), 0, 255),
            'raw' => $payload,
        ];
    }
}
