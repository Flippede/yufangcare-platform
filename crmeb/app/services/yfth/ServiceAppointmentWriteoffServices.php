<?php

namespace app\services\yfth;

use app\dao\yfth\YfthServiceAppointmentDao;
use app\dao\yfth\YfthServiceAppointmentEventDao;
use app\dao\yfth\YfthServiceBenefitLockDao;
use app\dao\yfth\YfthServiceDynamicCodeDao;
use app\dao\yfth\YfthServiceWriteoffRecordDao;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use think\facade\Cache;

class ServiceAppointmentWriteoffServices extends ServiceAppointmentBaseServices
{
    public const CODE_STATUS_ISSUED = 'issued';
    public const CODE_STATUS_USED = 'used';
    public const CODE_STATUS_INVALIDATED = 'invalidated';
    public const CODE_STATUS_EXPIRED = 'expired';
    public const METHOD_QR = 'qr_code';
    public const METHOD_DIGITAL = 'digital_code';
    public const METHOD_EXCEPTION = 'headquarter_exception';
    private const CODE_TTL_SECONDS = 300;
    private const WINDOW_BEFORE_SECONDS = 1800;
    private const WINDOW_AFTER_SECONDS = 7200;
    private const DIGITAL_MAX_ATTEMPTS = 5;
    private const DIGITAL_RATE_LIMIT_TTL = 300;
    private const DIGITAL_GENERATION_MAX_RETRIES = 10;
    private const DIGITAL_SAFE_ERROR = 'digital_code_invalid_or_expired';

    public function __construct(YfthServiceWriteoffRecordDao $dao)
    {
        $this->dao = $dao;
    }

    public function userCodeStatus(int $uid, int $appointmentId): array
    {
        $appointment = $this->appointmentById($appointmentId);
        if ((int)$appointment['uid'] !== $uid) {
            throw new ApiException('appointment_forbidden');
        }
        $code = $this->activeCodeByAppointment($appointmentId);
        return [
            'status' => 'ok',
            'availability' => $this->codeAvailabilityForAppointment($appointment),
            'code' => $this->formatCodeStatus($code),
            'writeoff_result' => $this->writeoffResultForAppointment($appointmentId),
        ];
    }

    public function generateUserCode(int $uid, int $appointmentId, array $data = []): array
    {
        $key = $this->writeKey('generate_code', $uid, $appointmentId, $data);
        return $this->runIdempotent('generate_code', $key, ['uid' => $uid, 'id' => $appointmentId], (string)$appointmentId, function ($requestId) use ($uid, $appointmentId) {
            return $this->transaction(function () use ($uid, $appointmentId, $requestId) {
                $appointment = $this->lockAppointment($appointmentId);
                if ((int)$appointment['uid'] !== $uid) {
                    throw new ApiException('appointment_forbidden');
                }
                $availability = $this->assertCodeAvailable($appointment);
                $benefitLock = $this->activeBenefitLock($appointmentId, true);
                if (!$benefitLock) {
                    throw new ApiException('benefit_lock_not_active');
                }
                $this->invalidateActiveCodes($appointmentId, $requestId);

                [$code, $token, $digital, $now] = $this->createDynamicCodeWithRetry($appointment, $uid, $requestId);

                $codeId = (int)$code->id;
                $this->recordServiceAudit('dynamic_code', (string)$codeId, 'generate', [], [
                    'appointment_id' => $appointmentId,
                    'store_id' => (int)$appointment['store_id'],
                    'expire_time' => $now + self::CODE_TTL_SECONDS,
                ], $uid, 'user', (int)$appointment['store_id'], 'user_generate_writeoff_code', $requestId);

                return [
                    'status' => 'ok',
                    'availability' => $availability,
                    'code' => [
                        'code_id' => $codeId,
                        'qr_token' => $token,
                        'qr_payload' => $token,
                        'digital_code' => $digital,
                        'issued_time' => $now,
                        'expire_time' => $now + self::CODE_TTL_SECONDS,
                        'ttl_seconds' => self::CODE_TTL_SECONDS,
                    ],
                ];
            });
        });
    }

    public function codeAvailabilityForAppointment(array $appointment): array
    {
        $now = time();
        $window = $this->checkInWindow($appointment);
        $reason = '';
        $available = true;
        if ((string)$appointment['status'] !== ServiceAppointmentBookingServices::STATUS_CONFIRMED) {
            $available = false;
            $reason = 'appointment_status_not_confirmed';
        } elseif ((int)($appointment['writeoff_id'] ?? 0) > 0 || (int)($appointment['completed_at'] ?? 0) > 0) {
            $available = false;
            $reason = 'appointment_already_written_off';
        } elseif ($now < $window['begin_time']) {
            $available = false;
            $reason = 'check_in_window_not_started';
        } elseif ($now > $window['end_time']) {
            $available = false;
            $reason = 'check_in_window_expired';
        }
        return [
            'can_generate' => $available,
            'reason' => $reason,
            'server_time' => $now,
            'window_begin_time' => $window['begin_time'],
            'window_end_time' => $window['end_time'],
            'window_before_seconds' => self::WINDOW_BEFORE_SECONDS,
            'window_after_seconds' => self::WINDOW_AFTER_SECONDS,
        ];
    }

    public function precheckByToken(string $token, array $adminInfo = []): array
    {
        $code = $this->requireActiveCodeByToken($token, false);
        return $this->precheckCode($code, $adminInfo);
    }

    public function precheckByDigital(string $digitalCode, array $adminInfo = []): array
    {
        $scope = $this->resolveDigitalWriteoffScope($adminInfo);
        $rateKey = $this->digitalRateLimitKey($scope);
        $this->assertDigitalRateAllowed($rateKey);
        try {
            $code = $this->requireScopedDigitalCode($digitalCode, $scope, false, false);
            return $this->precheckCode($code, $adminInfo);
        } catch (\Throwable $e) {
            $this->recordDigitalFailure($rateKey);
            throw $this->safeDigitalException($e);
        }
    }

    public function writeoffByToken(string $token, array $adminInfo = [], array $data = []): array
    {
        $adminId = (int)($adminInfo['id'] ?? 0);
        $key = $this->writeKey('writeoff_token', $adminId, 0, $data + ['token_hash' => $this->hashSecret($token)]);
        return $this->runIdempotent('writeoff_token', $key, ['admin_id' => $adminId], '', function ($requestId) use ($token, $adminInfo, $key) {
            return $this->performCodeWriteoff($token, '', self::METHOD_QR, $adminInfo, $key, $requestId);
        });
    }

    public function writeoffByDigital(string $digitalCode, array $adminInfo = [], array $data = []): array
    {
        $adminId = (int)($adminInfo['id'] ?? 0);
        $scope = $this->resolveDigitalWriteoffScope($adminInfo);
        $rateKey = $this->digitalRateLimitKey($scope);
        $this->assertDigitalRateAllowed($rateKey);
        $key = $this->writeKey('writeoff_digital', $adminId, 0, $data + ['digital_hash' => $this->hashSecret($digitalCode)]);
        try {
            $result = $this->runIdempotent('writeoff_digital', $key, ['admin_id' => $adminId], '', function ($requestId) use ($digitalCode, $adminInfo, $key, $scope) {
                return $this->performCodeWriteoff('', $digitalCode, self::METHOD_DIGITAL, $adminInfo, $key, $requestId, $scope);
            });
            $this->resetDigitalFailures($rateKey);
            return $result;
        } catch (\Throwable $e) {
            $this->recordDigitalFailure($rateKey);
            throw $this->safeDigitalException($e);
        }
    }

    public function exceptionWriteoff(int $appointmentId, array $adminInfo = [], string $reason = '', array $data = []): array
    {
        $adminId = (int)($adminInfo['id'] ?? 0);
        $reason = $this->normalizeExceptionReason($reason);
        $key = $this->writeKey('writeoff_exception', $adminId, $appointmentId, $data + ['reason' => $reason]);
        return $this->runIdempotent('writeoff_exception', $key, ['admin_id' => $adminId, 'appointment_id' => $appointmentId], (string)$appointmentId, function ($requestId) use ($appointmentId, $adminInfo, $key, $reason) {
            return $this->transaction(function () use ($appointmentId, $adminInfo, $key, $reason, $requestId) {
                $this->assertHeadquarterWriteoff($adminInfo);
                $appointment = $this->lockAppointment($appointmentId);
                return $this->completeWriteoff($appointment, [], self::METHOD_EXCEPTION, $adminInfo, $key, $requestId, $reason);
            });
        });
    }

    public function adminList(array $where, array $adminInfo = []): array
    {
        $filter = $this->cleanWhere([
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'appointment_id' => (int)($where['appointment_id'] ?? 0) ?: '',
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
            'writeoff_method' => $where['writeoff_method'] ?? '',
        ]);
        $filter = app()->make(AdminStoreContextServices::class)->applyStoreFilter($filter, $adminInfo);
        return $this->pageList($filter, '*', 'id desc', function ($row) {
            return $this->formatWriteoffRecord($row, true);
        });
    }

    public function adminDetail(int $id, array $adminInfo = []): array
    {
        $row = $this->requireRow($this->dao->get($id), 'writeoff_record_not_found');
        $this->assertAdminStoreReadable($adminInfo, (int)$row['store_id']);
        return ['status' => 'ok', 'record' => $this->formatWriteoffRecord($row, true)];
    }

    public function writeoffResultForAppointment(int $appointmentId): array
    {
        $record = $this->dao->getOne(['appointment_id' => $appointmentId, 'status' => 'succeeded']);
        if (!$record) {
            return ['status' => 'none'];
        }
        return ['status' => 'written_off', 'record' => $this->formatWriteoffRecord(is_array($record) ? $record : $record->toArray(), false)];
    }

    private function precheckCode(array $code, array $adminInfo): array
    {
        $appointment = $this->appointmentById((int)$code['appointment_id']);
        $this->assertAdminCanWriteoff($adminInfo, (int)$appointment['store_id'], false);
        $this->assertCodeUsable($code);
        $this->assertAppointmentReadyForWriteoff($appointment, true);
        return [
            'status' => 'ok',
            'appointment' => $this->formatAppointmentSummary($appointment),
            'code' => $this->formatCodeStatus($code),
        ];
    }

    private function performCodeWriteoff(string $token, string $digitalCode, string $method, array $adminInfo, string $idempotencyKey, string $requestId, array $digitalScope = []): array
    {
        return $this->transaction(function () use ($token, $digitalCode, $method, $adminInfo, $idempotencyKey, $requestId, $digitalScope) {
            $code = $method === self::METHOD_QR
                ? $this->requireActiveCodeByToken($token, true)
                : $this->requireScopedDigitalCode($digitalCode, $digitalScope, true, true);
            $appointment = $this->lockAppointment((int)$code['appointment_id']);
            return $this->completeWriteoff($appointment, $code, $method, $adminInfo, $idempotencyKey, $requestId, 'service_writeoff');
        });
    }

    private function completeWriteoff(array $appointment, array $code, string $method, array $adminInfo, string $idempotencyKey, string $requestId, string $reason): array
    {
        $adminId = (int)($adminInfo['id'] ?? 0);
        $roleCode = $this->assertAdminCanWriteoff($adminInfo, (int)$appointment['store_id'], $method === self::METHOD_EXCEPTION);
        $existing = $this->dao->getOne(['appointment_id' => (int)$appointment['id'], 'status' => 'succeeded']);
        if ($existing || (string)$appointment['status'] === ServiceAppointmentBookingServices::STATUS_COMPLETED) {
            return [
                'status' => 'already_written_off',
                'record' => $existing ? $this->formatWriteoffRecord(is_array($existing) ? $existing : $existing->toArray(), true) : null,
            ];
        }

        if ($method !== self::METHOD_EXCEPTION) {
            if (!$code || (int)$code['appointment_id'] !== (int)$appointment['id']) {
                throw new AdminException('dynamic_code_appointment_mismatch');
            }
            $this->assertCodeUsable($code);
        }

        $this->assertAppointmentReadyForWriteoff($appointment, $method !== self::METHOD_EXCEPTION);
        $benefitLock = $this->activeBenefitLock((int)$appointment['id'], true);
        if (!$benefitLock || (string)$benefitLock['status'] !== 'locked') {
            throw new AdminException('benefit_lock_not_active');
        }

        $now = time();
        $record = $this->dao->save($this->withTimestamps([
            'writeoff_no' => $this->makeNo('SW'),
            'appointment_id' => (int)$appointment['id'],
            'uid' => (int)$appointment['uid'],
            'store_id' => (int)$appointment['store_id'],
            'service_project_id' => (int)$appointment['service_project_id'],
            'package_instance_id' => (int)$appointment['package_instance_id'],
            'benefit_plan_id' => (int)$appointment['benefit_plan_id'],
            'benefit_period_id' => (int)$appointment['benefit_period_id'],
            'benefit_item_id' => (int)$appointment['benefit_item_id'],
            'benefit_lock_id' => (int)$benefitLock['id'],
            'dynamic_code_id' => (int)($code['id'] ?? 0),
            'writeoff_method' => $method,
            'operator_type' => 'admin',
            'operator_id' => $adminId,
            'operator_role_code' => $roleCode,
            'before_appointment_status' => (string)$appointment['status'],
            'after_appointment_status' => ServiceAppointmentBookingServices::STATUS_COMPLETED,
            'before_benefit_status' => (string)$benefitLock['status'],
            'after_benefit_status' => 'consumed',
            'writeoff_time' => $now,
            'status' => 'succeeded',
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'request_id' => $requestId,
            'active_key' => (string)$appointment['id'],
        ], true));
        $writeoffId = (int)$record->id;

        $consumption = app()->make(ServiceBenefitConsumptionServices::class)
            ->consumeForServiceWriteoff($appointment, $benefitLock, $writeoffId, $adminId, $roleCode, (int)$appointment['store_id'], $requestId);

        app()->make(YfthServiceBenefitLockDao::class)->update((int)$benefitLock['id'], [
            'status' => 'consumed',
            'consume_status' => 'consumed',
            'consumed_time' => $now,
            'consume_reason' => $reason,
            'writeoff_id' => $writeoffId,
            'active_key' => null,
            'update_time' => $now,
        ]);

        $appointmentDao = app()->make(YfthServiceAppointmentDao::class);
        $appointmentDao->update((int)$appointment['id'], [
            'status' => ServiceAppointmentBookingServices::STATUS_COMPLETED,
            'check_in_at' => $now,
            'writeoff_at' => $now,
            'completed_at' => $now,
            'writeoff_id' => $writeoffId,
            'writeoff_store_id' => (int)$appointment['store_id'],
            'writeoff_operator_id' => $adminId,
            'writeoff_operator_type' => 'admin',
            'writeoff_method' => $method,
            'request_id' => $requestId,
            'update_time' => $now,
        ]);

        if ($code) {
            app()->make(YfthServiceDynamicCodeDao::class)->update((int)$code['id'], [
                'status' => self::CODE_STATUS_USED,
                'used_time' => $now,
                'used_admin_id' => $adminId,
                'used_role_code' => $roleCode,
                'used_writeoff_id' => $writeoffId,
                'active_key' => null,
                'digital_active_key' => null,
                'update_time' => $now,
            ]);
        }

        $after = $this->appointmentById((int)$appointment['id']);
        $snapshot = [
            'appointment' => $this->formatAppointmentSummary($after),
            'benefit_item_before' => $this->sanitizeState($consumption['before_item']),
            'benefit_item_after' => $this->sanitizeState($consumption['after_item']),
            'code_id' => (int)($code['id'] ?? 0),
        ];
        $this->dao->update($writeoffId, ['snapshot' => $this->jsonEncode($snapshot), 'update_time' => $now]);

        $this->recordEvent((int)$appointment['id'], 'checked_in', (string)$appointment['status'], (string)$appointment['status'], 'admin', $adminId, (int)$appointment['store_id'], $appointment, $after, $reason, $requestId);
        $this->recordEvent((int)$appointment['id'], 'benefit_written_off', (string)$appointment['status'], (string)$appointment['status'], 'admin', $adminId, (int)$appointment['store_id'], $appointment, $after, $reason, $requestId);
        $this->recordEvent((int)$appointment['id'], 'completed', (string)$appointment['status'], ServiceAppointmentBookingServices::STATUS_COMPLETED, 'admin', $adminId, (int)$appointment['store_id'], $appointment, $after, $reason, $requestId);
        $this->recordServiceAudit('appointment', (string)$appointment['id'], 'writeoff', $appointment, $after, $adminId, $roleCode, (int)$appointment['store_id'], $reason, $requestId);
        $this->recordServiceAudit('writeoff_record', (string)$writeoffId, 'create', [], $snapshot, $adminId, $roleCode, (int)$appointment['store_id'], $reason, $requestId);

        return [
            'status' => 'ok',
            'appointment' => $this->formatAppointmentSummary($after),
            'record' => $this->formatWriteoffRecord($this->requireRow($this->dao->get($writeoffId), 'writeoff_record_not_found'), true),
        ];
    }

    private function assertAppointmentReadyForWriteoff(array $appointment, bool $requireWindow): void
    {
        app()->make(StoreAccessServices::class)->assertStoreActive((int)$appointment['store_id']);
        if ((string)$appointment['status'] !== ServiceAppointmentBookingServices::STATUS_CONFIRMED) {
            throw new AdminException('appointment_status_not_confirmed');
        }
        if ((int)($appointment['writeoff_id'] ?? 0) > 0 || (int)($appointment['completed_at'] ?? 0) > 0) {
            throw new AdminException('appointment_already_written_off');
        }
        if ($requireWindow) {
            $now = time();
            $window = $this->checkInWindow($appointment);
            if ($now < $window['begin_time']) {
                throw new AdminException('check_in_window_not_started');
            }
            if ($now > $window['end_time']) {
                throw new AdminException('check_in_window_expired');
            }
        }
    }

    private function assertCodeAvailable(array $appointment): array
    {
        $availability = $this->codeAvailabilityForAppointment($appointment);
        if (!$availability['can_generate']) {
            throw new ApiException($availability['reason'] ?: 'dynamic_code_unavailable');
        }
        return $availability;
    }

    private function assertCodeUsable(array $code): void
    {
        if ((string)$code['status'] !== self::CODE_STATUS_ISSUED || (string)($code['active_key'] ?? '') === '') {
            throw new AdminException('dynamic_code_not_active');
        }
        if ((int)$code['expire_time'] <= time()) {
            app()->make(YfthServiceDynamicCodeDao::class)->update((int)$code['id'], [
                'status' => self::CODE_STATUS_EXPIRED,
                'active_key' => null,
                'digital_active_key' => null,
                'update_time' => time(),
            ]);
            throw new AdminException('dynamic_code_expired');
        }
    }

    private function activeBenefitLock(int $appointmentId, bool $lock = false): array
    {
        $query = app()->make(YfthServiceBenefitLockDao::class)->search([])
            ->where('appointment_id', $appointmentId)
            ->where('status', 'locked');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }

    private function activeCodeByAppointment(int $appointmentId): array
    {
        $code = app()->make(YfthServiceDynamicCodeDao::class)->search([])
            ->where('appointment_id', $appointmentId)
            ->where('status', self::CODE_STATUS_ISSUED)
            ->where('active_key', (string)$appointmentId)
            ->order('id desc')
            ->find();
        return $code ? (is_array($code) ? $code : $code->toArray()) : [];
    }

    private function requireActiveCodeByToken(string $token, bool $lock): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new AdminException('dynamic_token_required');
        }
        $query = app()->make(YfthServiceDynamicCodeDao::class)->search([])->where('token_hash', $this->hashSecret($token));
        if ($lock) {
            $query->lock(true);
        }
        $code = $query->order('id desc')->find();
        $row = $this->requireRow($code, 'dynamic_code_invalid');
        return $row;
    }

    private function invalidateActiveCodes(int $appointmentId, string $requestId): void
    {
        $rows = app()->make(YfthServiceDynamicCodeDao::class)->search([])
            ->where('appointment_id', $appointmentId)
            ->where('status', self::CODE_STATUS_ISSUED)
            ->where('active_key', (string)$appointmentId)
            ->lock(true)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            app()->make(YfthServiceDynamicCodeDao::class)->update((int)$row['id'], [
                'status' => self::CODE_STATUS_INVALIDATED,
                'invalidated_time' => time(),
                'active_key' => null,
                'digital_active_key' => null,
                'request_id' => $requestId,
                'update_time' => time(),
            ]);
        }
    }

    private function createDynamicCodeWithRetry(array $appointment, int $uid, string $requestId): array
    {
        $storeId = (int)$appointment['store_id'];
        $appointmentId = (int)$appointment['id'];
        $dao = app()->make(YfthServiceDynamicCodeDao::class);
        for ($i = 0; $i < self::DIGITAL_GENERATION_MAX_RETRIES; $i++) {
            $token = 'yfthsvc_' . bin2hex(random_bytes(24));
            $digital = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = $this->hashSecret($digital);
            $now = time();
            try {
                $code = $dao->save($this->withTimestamps([
                    'appointment_id' => $appointmentId,
                    'uid' => $uid,
                    'store_id' => $storeId,
                    'token_hash' => $this->hashSecret($token),
                    'digital_code_hash' => $hash,
                    'status' => self::CODE_STATUS_ISSUED,
                    'issued_time' => $now,
                    'expire_time' => $now + self::CODE_TTL_SECONDS,
                    'max_attempts' => self::DIGITAL_MAX_ATTEMPTS,
                    'request_id' => $requestId,
                    'active_key' => (string)$appointmentId,
                    'digital_active_key' => $this->digitalActiveKey($storeId, $hash),
                ], true));
                return [$code, $token, $digital, $now];
            } catch (\Throwable $e) {
                if (!$this->isDuplicateKeyException($e)) {
                    throw $e;
                }
            }
        }
        throw new ApiException('dynamic_code_generation_failed');
    }

    private function requireScopedDigitalCode(string $digitalCode, array $scope, bool $lock, bool $includeUsedForCompletedReplay): array
    {
        $digitalCode = trim($digitalCode);
        if (!preg_match('/^\d{6}$/', $digitalCode)) {
            throw new AdminException(self::DIGITAL_SAFE_ERROR);
        }
        $storeIds = array_values(array_filter(array_map('intval', $scope['store_ids'] ?? [])));
        if (!$storeIds) {
            throw new AdminException(self::DIGITAL_SAFE_ERROR);
        }
        $hash = $this->hashSecret($digitalCode);
        $query = app()->make(YfthServiceDynamicCodeDao::class)->search([])
            ->where('digital_code_hash', $hash)
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', $includeUsedForCompletedReplay ? [self::CODE_STATUS_ISSUED, self::CODE_STATUS_USED] : [self::CODE_STATUS_ISSUED]);
        if ($lock) {
            $query->lock(true);
        }
        $rows = $query->order('id desc')->select()->toArray();
        $active = array_values(array_filter($rows, function ($row) {
            return (string)$row['status'] === self::CODE_STATUS_ISSUED
                && (string)($row['active_key'] ?? '') !== ''
                && (string)($row['digital_active_key'] ?? '') !== ''
                && (int)$row['expire_time'] > time();
        }));
        if (count($active) === 1) {
            return $active[0];
        }
        $used = array_values(array_filter($rows, function ($row) {
            return (string)$row['status'] === self::CODE_STATUS_USED;
        }));
        if ($includeUsedForCompletedReplay && !$active && count($rows) === 1 && count($used) === 1) {
            return $used[0];
        }
        throw new AdminException(self::DIGITAL_SAFE_ERROR);
    }

    private function resolveDigitalWriteoffScope(array $adminInfo): array
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($adminInfo);
        $storeIds = [];
        foreach ((array)($context['store_scope_roles'] ?? []) as $storeId => $roles) {
            if (array_intersect((array)$roles, ['store_manager', 'franchisee', 'store_staff'])) {
                $storeIds[] = (int)$storeId;
            }
        }
        $storeIds = array_values(array_unique(array_filter($storeIds)));
        if (!$storeIds) {
            if (!empty($context['is_headquarter_admin']) || !empty($context['is_super_admin'])) {
                throw new AdminException('headquarter_exception_writeoff_required');
            }
            throw new AdminException('store_scope_forbidden');
        }
        return [
            'admin_id' => (int)($context['admin_id'] ?? ($adminInfo['id'] ?? 0)),
            'store_ids' => $storeIds,
            'primary_role_code' => (string)($context['primary_role_code'] ?? ''),
        ];
    }

    private function digitalRateLimitKey(array $scope): string
    {
        $storeKey = implode('-', array_values(array_filter(array_map('intval', $scope['store_ids'] ?? []))));
        $ip = '';
        try {
            $ip = (string)request()->ip();
        } catch (\Throwable $e) {
            $ip = 'cli';
        }
        return 'yfth:writeoff:digital_attempt:' . (int)($scope['admin_id'] ?? 0) . ':' . $storeKey . ':' . hash('sha256', $ip);
    }

    private function assertDigitalRateAllowed(string $key): void
    {
        if ((int)Cache::get($key, 0) >= self::DIGITAL_MAX_ATTEMPTS) {
            throw new AdminException(self::DIGITAL_SAFE_ERROR);
        }
    }

    private function recordDigitalFailure(string $key): void
    {
        try {
            $count = (int)Cache::inc($key);
            if ($count <= 1) {
                Cache::set($key, 1, self::DIGITAL_RATE_LIMIT_TTL);
            }
        } catch (\Throwable $e) {
            $count = (int)Cache::get($key, 0) + 1;
            Cache::set($key, $count, self::DIGITAL_RATE_LIMIT_TTL);
        }
    }

    private function resetDigitalFailures(string $key): void
    {
        try {
            Cache::delete($key);
        } catch (\Throwable $e) {
        }
    }

    private function safeDigitalException(\Throwable $e): AdminException
    {
        if ($e instanceof AdminException && in_array($e->getMessage(), ['store_scope_forbidden', 'headquarter_exception_writeoff_required'], true)) {
            return $e;
        }
        return new AdminException(self::DIGITAL_SAFE_ERROR);
    }

    private function normalizeExceptionReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) < 2) {
            throw new AdminException('exception_writeoff_reason_required');
        }
        if (function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') > 200 : strlen($reason) > 200) {
            throw new AdminException('exception_writeoff_reason_too_long');
        }
        return $reason;
    }

    private function digitalActiveKey(int $storeId, string $digitalHash): string
    {
        return $storeId . ':' . $digitalHash;
    }

    private function isDuplicateKeyException(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return strpos($message, 'Duplicate entry') !== false || strpos($message, '1062') !== false;
    }

    private function assertAdminCanWriteoff(array $adminInfo, int $storeId, bool $allowHeadquarter): string
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($adminInfo);
        if ($context['is_super_admin'] || ($allowHeadquarter && $context['is_headquarter_admin'])) {
            return (string)($context['primary_role_code'] ?: 'super_admin');
        }
        if ($storeId <= 0) {
            throw new AdminException('store_id_required');
        }
        $roles = $context['store_scope_roles'][$storeId] ?? [];
        if (array_intersect($roles, ['store_manager', 'franchisee', 'store_staff'])) {
            return (string)($roles[0] ?? $context['primary_role_code'] ?? 'store_staff');
        }
        if ($context['is_headquarter_admin'] && !$allowHeadquarter) {
            throw new AdminException('headquarter_exception_writeoff_required');
        }
        throw new AdminException('store_scope_forbidden');
    }

    private function assertHeadquarterWriteoff(array $adminInfo): void
    {
        $context = app()->make(AdminStoreContextServices::class)->resolve($adminInfo);
        if ($context['is_super_admin'] || $context['is_headquarter_admin']) {
            return;
        }
        throw new AdminException('headquarter_permission_required');
    }

    private function assertAdminStoreReadable(array $adminInfo, int $storeId): void
    {
        app()->make(AdminStoreContextServices::class)->applyStoreFilter(['store_id' => $storeId], $adminInfo);
    }

    private function appointmentById(int $appointmentId): array
    {
        return $this->requireRow(app()->make(YfthServiceAppointmentDao::class)->get($appointmentId), 'appointment_not_found');
    }

    private function lockAppointment(int $appointmentId): array
    {
        return $this->requireRow(app()->make(YfthServiceAppointmentDao::class)->search([])->where('id', $appointmentId)->lock(true)->find(), 'appointment_not_found');
    }

    private function checkInWindow(array $appointment): array
    {
        return [
            'begin_time' => max(0, (int)$appointment['start_time'] - self::WINDOW_BEFORE_SECONDS),
            'end_time' => (int)$appointment['end_time'] + self::WINDOW_AFTER_SECONDS,
        ];
    }

    private function formatCodeStatus(array $code): array
    {
        if (!$code) {
            return ['status' => 'none'];
        }
        return [
            'status' => (string)$code['status'],
            'code_id' => (int)$code['id'],
            'appointment_id' => (int)$code['appointment_id'],
            'issued_time' => (int)$code['issued_time'],
            'expire_time' => (int)$code['expire_time'],
            'used_time' => (int)$code['used_time'],
            'ttl_remaining' => max(0, (int)$code['expire_time'] - time()),
            'attempt_count' => (int)$code['attempt_count'],
            'max_attempts' => (int)$code['max_attempts'],
        ];
    }

    private function formatAppointmentSummary(array $row): array
    {
        $service = $this->jsonDecode($row['service_snapshot'] ?? '');
        $store = $this->jsonDecode($row['store_snapshot'] ?? '');
        $benefit = $this->jsonDecode($row['benefit_snapshot'] ?? '');
        return [
            'id' => (int)$row['id'],
            'appointment_no' => (string)$row['appointment_no'],
            'uid' => (int)$row['uid'],
            'store_id' => (int)$row['store_id'],
            'store_name' => (string)($store['name'] ?? $store['store_name'] ?? ''),
            'service_project_id' => (int)$row['service_project_id'],
            'service_name' => (string)($service['project']['service_name'] ?? $service['project']['name'] ?? $service['project_name'] ?? ''),
            'benefit_name' => (string)($benefit['benefit_name'] ?? ''),
            'date_text' => $this->serviceDateText((int)$row['service_date']),
            'start_time_text' => $this->minuteText((int)$row['start_minute']),
            'end_time_text' => $this->minuteText((int)$row['end_minute']),
            'status' => (string)$row['status'],
            'check_in_at' => (int)($row['check_in_at'] ?? 0),
            'writeoff_at' => (int)($row['writeoff_at'] ?? 0),
            'completed_at' => (int)($row['completed_at'] ?? 0),
            'writeoff_method' => (string)($row['writeoff_method'] ?? ''),
        ];
    }

    private function formatWriteoffRecord(array $row, bool $adminView): array
    {
        $data = [
            'id' => (int)$row['id'],
            'writeoff_no' => (string)$row['writeoff_no'],
            'appointment_id' => (int)$row['appointment_id'],
            'uid' => (int)$row['uid'],
            'store_id' => (int)$row['store_id'],
            'service_project_id' => (int)$row['service_project_id'],
            'writeoff_method' => (string)$row['writeoff_method'],
            'operator_role_code' => (string)$row['operator_role_code'],
            'writeoff_time' => (int)$row['writeoff_time'],
            'status' => (string)$row['status'],
            'reason' => (string)($row['reason'] ?? ''),
        ];
        if ($adminView) {
            $data['operator_id'] = (int)$row['operator_id'];
            $data['dynamic_code_id'] = (int)$row['dynamic_code_id'];
            $data['benefit_lock_id'] = (int)$row['benefit_lock_id'];
            $data['snapshot'] = $this->jsonDecode($row['snapshot'] ?? '');
        }
        return $data;
    }

    private function recordEvent(int $appointmentId, string $eventType, string $fromStatus, string $toStatus, string $operatorType, int $operatorId, int $storeId, array $before, array $after, string $reason, string $requestId): void
    {
        app()->make(YfthServiceAppointmentEventDao::class)->save($this->withTimestamps([
            'appointment_id' => $appointmentId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'store_id' => $storeId,
            'old_service_date' => (int)($before['service_date'] ?? 0),
            'old_start_minute' => (int)($before['start_minute'] ?? 0),
            'old_end_minute' => (int)($before['end_minute'] ?? 0),
            'new_service_date' => (int)($after['service_date'] ?? 0),
            'new_start_minute' => (int)($after['start_minute'] ?? 0),
            'new_end_minute' => (int)($after['end_minute'] ?? 0),
            'reason' => $reason,
            'request_id' => $requestId,
        ], true));
    }

    private function runIdempotent(string $action, string $key, array $payload, string $objectId, callable $callback): array
    {
        /** @var IdempotencyRecordServices $idempotency */
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin(self::DOMAIN, $action, $key, $payload, $objectId, 600);
        if (!$begin['acquired']) {
            if (($begin['status'] ?? '') === 'succeeded') {
                return is_array($begin['result_summary'] ?? null) ? $begin['result_summary'] : ['status' => 'ok', 'replayed' => true];
            }
            if (!empty($begin['can_retry'])) {
                $begin = $idempotency->tryReacquire($begin['record'], 600);
            }
            if (!$begin['acquired']) {
                throw new ApiException('idempotency_request_processing');
            }
        }
        $recordId = (int)$begin['record']['id'];
        try {
            $result = $callback(substr(hash('sha256', self::DOMAIN . ':' . $action . ':' . $key), 0, 32));
            $idempotency->complete($recordId, $result);
            return $result;
        } catch (\Throwable $e) {
            $idempotency->fail($recordId, $e->getMessage());
            throw $e;
        }
    }

    private function writeKey(string $action, int $operatorId, int $objectId, array $data): string
    {
        $explicit = trim((string)($data['idempotency_key'] ?? ''));
        if ($explicit !== '') {
            return $action . ':' . $operatorId . ':' . $explicit;
        }
        unset($data['idempotency_key']);
        ksort($data);
        return $action . ':' . $operatorId . ':' . $objectId . ':' . hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function hashSecret(string $value): string
    {
        return hash('sha256', $value);
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
}
