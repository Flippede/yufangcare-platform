<?php

namespace app\services\yfth;

use app\Request;
use app\dao\user\UserDao;
use app\dao\yfth\YfthBusinessDynamicCodeDao;
use app\dao\yfth\YfthMembershipRewardCandidateDao;
use app\dao\yfth\YfthPermanentMembershipDao;
use app\dao\yfth\YfthPermanentMembershipEnrollmentDao;
use app\dao\yfth\YfthPermanentMembershipEventDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class PermanentMembershipServices extends YfthFoundationBaseServices
{
    public const AMOUNT_CENTS = 980000;
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_CONFIRMATION = 'pending_customer_confirmation';
    public const STATUS_ACTIVATED = 'activated';
    public const STATUS_CANCELLED = 'cancelled';
    public const SCENE_CUSTOMER_IDENTITY = 'customer_identity';
    public const SCENE_MEMBERSHIP_CONFIRMATION = 'membership_confirmation';
    private const CODE_ISSUED = 'issued';
    private const CODE_USED = 'used';
    private const CODE_REPLACED = 'replaced';
    private const IDENTITY_TTL = 300;
    private const CONFIRMATION_TTL = 900;
    private const DOMAIN = 'yfth_permanent_membership';

    private $membershipDao;
    private $eventDao;
    private $codeDao;
    private $candidateDao;
    private $userDao;
    private $runner;
    private $attribution;
    private $referral;
    private $audit;

    public function __construct(
        YfthPermanentMembershipEnrollmentDao $dao,
        YfthPermanentMembershipDao $membershipDao,
        YfthPermanentMembershipEventDao $eventDao,
        YfthBusinessDynamicCodeDao $codeDao,
        YfthMembershipRewardCandidateDao $candidateDao,
        UserDao $userDao,
        HqAuthorityOperationRunner $runner,
        HqCustomerAttributionServices $attribution,
        HqActiveReferralServices $referral,
        AuditEventServices $audit
    ) {
        $this->dao = $dao;
        $this->membershipDao = $membershipDao;
        $this->eventDao = $eventDao;
        $this->codeDao = $codeDao;
        $this->candidateDao = $candidateDao;
        $this->userDao = $userDao;
        $this->runner = $runner;
        $this->attribution = $attribution;
        $this->referral = $referral;
        $this->audit = $audit;
    }

    public function generateCustomerIdentityCode(int $uid): array
    {
        $this->assertUser($uid);
        return Db::transaction(function () use ($uid) {
            $this->invalidateCodes(self::SCENE_CUSTOMER_IDENTITY, 0, $uid);
            return $this->issueCode(self::SCENE_CUSTOMER_IDENTITY, 0, $uid, 0, $uid, 'customer', self::IDENTITY_TTL);
        });
    }

    public function createForStore(Request $request, array $data): array
    {
        $context = $this->storeContext($request);
        return $this->createEnrollment((int)$context['store_id'], (int)$context['uid'], 'store_user', (string)$context['role_code'], $data);
    }

    public function createForAdmin(int $storeId, int $adminId, array $adminInfo, array $data): array
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        return $this->createEnrollment($storeId, $adminId, 'admin', 'headquarter_operator', $data);
    }

    public function bindForStore(Request $request, int $enrollmentId, string $token, array $data): array
    {
        $context = $this->storeContext($request);
        return $this->bindCustomer($enrollmentId, (int)$context['store_id'], (int)$context['uid'], (string)$context['role_code'], $token, $data);
    }

    public function bindForAdmin(int $enrollmentId, string $token, int $adminId, array $adminInfo, array $data): array
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        $row = $this->requireEnrollment($enrollmentId);
        return $this->bindCustomer($enrollmentId, (int)$row['store_id'], $adminId, 'headquarter_operator', $token, $data);
    }

    public function confirmPaymentForStore(Request $request, int $enrollmentId, array $data): array
    {
        $context = $this->storeContext($request);
        return $this->confirmPayment($enrollmentId, (int)$context['store_id'], (int)$context['uid'], (string)$context['role_code'], $data);
    }

    public function confirmPaymentForAdmin(int $enrollmentId, int $adminId, array $adminInfo, array $data): array
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        $row = $this->requireEnrollment($enrollmentId);
        return $this->confirmPayment($enrollmentId, (int)$row['store_id'], $adminId, 'headquarter_operator', $data);
    }

    public function confirmationCodeForStore(Request $request, int $enrollmentId): array
    {
        $context = $this->storeContext($request);
        return $this->generateConfirmationCode($enrollmentId, (int)$context['store_id'], (int)$context['uid'], (string)$context['role_code']);
    }

    public function confirmationCodeForAdmin(int $enrollmentId, int $adminId, array $adminInfo): array
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        $row = $this->requireEnrollment($enrollmentId);
        return $this->generateConfirmationCode($enrollmentId, (int)$row['store_id'], $adminId, 'headquarter_operator');
    }

    public function confirmByCustomer(int $uid, string $token, array $data): array
    {
        $token = trim($token);
        if ($token === '') throw new ApiException('membership_confirmation_code_required');
        $key = $this->idempotencyKey($data);
        $codeSnapshot = $this->row($this->codeDao->getOne(['token_hash' => $this->hashToken($token)]));
        if (!$codeSnapshot || (string)$codeSnapshot['scene'] !== self::SCENE_MEMBERSHIP_CONFIRMATION) {
            throw new ApiException('membership_confirmation_code_invalid');
        }
        $enrollmentId = (int)$codeSnapshot['enrollment_id'];
        $mutation = $this->mutation($enrollmentId, $uid, 'customer', $key, 'customer_membership_confirmation');
        return $this->runner->run('permanent_membership_activate', $mutation, [
            'enrollment_id' => $enrollmentId,
            'target_uid' => $uid,
            'confirmation_code_hash' => $this->hashToken($token),
        ], 'enrollment:' . $enrollmentId, function () use ($uid, $token, $enrollmentId, $mutation) {
            $enrollment = $this->lockEnrollment($enrollmentId);
            if ((int)$enrollment['target_uid'] !== $uid) throw new ApiException('membership_confirmation_customer_mismatch');
            if ((string)$enrollment['status'] === self::STATUS_ACTIVATED) {
                return $this->activationResult($enrollment, $this->membershipForUid($uid, true), false);
            }
            if ((string)$enrollment['status'] !== self::STATUS_PENDING_CONFIRMATION
                || (string)$enrollment['payment_status'] !== 'confirmed'
                || (int)$enrollment['payment_confirmed_at'] <= 0) {
                throw new ApiException('membership_enrollment_not_ready');
            }

            $code = $this->lockCode($token);
            $this->assertCode($code, self::SCENE_MEMBERSHIP_CONFIRMATION, $enrollmentId, $uid, (int)$enrollment['store_id']);
            $existing = $this->membershipForUid($uid, true);
            if ($existing) {
                if ((int)$existing['enrollment_id'] !== $enrollmentId) throw new ApiException('permanent_membership_already_exists');
                return $this->activationResult($enrollment, $existing, false);
            }

            $attribution = $this->attribution->assignFirstInTransaction($uid, (int)$enrollment['store_id'], $mutation);
            $referral = $this->referral->closeForMembershipInTransaction($uid, (int)$enrollment['store_id'], $mutation);
            $now = time();
            try {
                $member = $this->membershipDao->save([
                    'membership_no' => $this->makeNo('YPM'), 'uid' => $uid,
                    'store_id' => (int)$enrollment['store_id'], 'enrollment_id' => $enrollmentId,
                    'status' => 'active', 'amount_cents' => self::AMOUNT_CENTS, 'authority_version' => 1,
                    'source_type' => HqAuthoritySourceCanonicalizer::PERMANENT_MEMBERSHIP_SOURCE,
                    'source_id' => (string)$enrollmentId, 'activated_at' => $now,
                    'request_id' => $mutation->requestId(), 'add_time' => $now, 'update_time' => $now,
                ])->toArray();
            } catch (\Throwable $e) {
                if ($this->isUniqueConflict($e)) throw new ApiException('permanent_membership_unique_conflict');
                throw $e;
            }
            $this->eventDao->save([
                'event_no' => $this->makeNo('YME'), 'membership_id' => (int)$member['id'],
                'membership_no' => (string)$member['membership_no'], 'uid' => $uid,
                'store_id' => (int)$member['store_id'], 'authority_version' => 1,
                'event_type' => 'membership_activated', 'source_type' => HqAuthoritySourceCanonicalizer::PERMANENT_MEMBERSHIP_SOURCE,
                'source_id' => (string)$enrollmentId, 'operator_uid' => $uid,
                'operator_role_code' => 'customer', 'request_id' => $mutation->requestId(), 'add_time' => $now,
            ]);
            $this->candidateDao->save([
                'candidate_no' => $this->makeNo('YRC'), 'business_type' => 'permanent_membership_activated',
                'membership_id' => (int)$member['id'], 'enrollment_id' => $enrollmentId,
                'store_id' => (int)$member['store_id'], 'target_uid' => $uid,
                'source_type' => HqAuthoritySourceCanonicalizer::PERMANENT_MEMBERSHIP_SOURCE,
                'source_id' => (string)$enrollmentId, 'status' => 'pending',
                'unique_key' => 'membership:' . (int)$member['id'], 'add_time' => $now, 'update_time' => $now,
            ]);
            $this->codeDao->update((int)$code['id'], [
                'status' => self::CODE_USED, 'used_by_uid' => $uid, 'used_by_role' => 'customer',
                'used_time' => $now, 'active_key' => null, 'request_id' => $mutation->requestId(), 'update_time' => $now,
            ]);
            $this->dao->update($enrollmentId, [
                'status' => self::STATUS_ACTIVATED, 'activated_member_id' => (int)$member['id'],
                'activated_at' => $now, 'request_id' => $mutation->requestId(), 'update_time' => $now,
            ]);
            $after = array_merge($enrollment, ['status' => self::STATUS_ACTIVATED, 'activated_member_id' => (int)$member['id'], 'activated_at' => $now]);
            $this->audit->recordSafely(self::DOMAIN, 'permanent_membership', (string)$member['id'], 'activate', [], [
                'membership_id' => (int)$member['id'], 'uid' => $uid, 'store_id' => (int)$member['store_id'],
                'attribution_changed' => (bool)($attribution['changed'] ?? false),
                'referral_closed' => (bool)($referral['changed'] ?? false),
            ], $uid, 'customer', (int)$member['store_id'], 'customer_confirmed_offline_membership', $mutation->requestId());
            return $this->activationResult($after, $member, true);
        });
    }

    public function me(int $uid): array
    {
        $member = $this->membershipForUid($uid, false);
        $pending = $this->row($this->dao->search([])->where('target_uid', $uid)
            ->where('status', self::STATUS_PENDING_CONFIRMATION)->order('id desc')->find());
        return [
            'membership' => $member ? $this->memberDto($member) : null,
            'pending_enrollment' => $pending ? $this->customerEnrollmentDto($pending) : null,
            'is_permanent_member' => (bool)$member,
            'has_referral_qualification' => (bool)$member,
        ];
    }

    public function storeList(Request $request, array $where): array
    {
        $context = $this->storeContext($request);
        return $this->listEnrollments(['store_id' => (int)$context['store_id'], 'status' => trim((string)($where['status'] ?? ''))]);
    }

    public function storeDetail(Request $request, int $id): array
    {
        $context = $this->storeContext($request);
        $row = $this->requireEnrollment($id);
        if ((int)$row['store_id'] !== (int)$context['store_id']) throw new ApiException('membership_enrollment_store_forbidden');
        return $this->operatorEnrollmentDto($row);
    }

    public function adminList(array $where, array $adminInfo): array
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        return $this->listEnrollments(['store_id' => (int)($where['store_id'] ?? 0), 'status' => trim((string)($where['status'] ?? '')), 'target_uid' => (int)($where['target_uid'] ?? 0)]);
    }

    public function adminMembers(array $where, array $adminInfo): array
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        $query = $this->membershipDao->search([]);
        if (!empty($where['store_id'])) $query->where('store_id', (int)$where['store_id']);
        if (!empty($where['uid'])) $query->where('uid', (int)$where['uid']);
        [$page, $limit, $default] = $this->getPageValue();
        $limit = $limit ?: $default;
        $count = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return ['list' => array_map([$this, 'operatorMemberDto'], $list), 'count' => $count];
    }

    public function adminDetail(int $id, array $adminInfo): array
    {
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        return $this->operatorEnrollmentDto($this->requireEnrollment($id));
    }

    private function createEnrollment(int $storeId, int $operatorId, string $operatorType, string $role, array $data): array
    {
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        $key = $this->idempotencyKey($data);
        $mutation = $this->mutation($storeId, max(1, $operatorId), $role, $key, 'offline_membership_enrollment_create');
        return $this->runner->run('permanent_membership_enrollment_create', $mutation, ['store_id' => $storeId, 'operator_type' => $operatorType], 'store:' . $storeId, function () use ($storeId, $operatorId, $operatorType, $role, $mutation) {
            $now = time();
            $row = $this->dao->save([
                'enrollment_no' => $this->makeNo('YPE'), 'store_id' => $storeId, 'target_uid' => 0,
                'status' => self::STATUS_DRAFT, 'amount_cents' => self::AMOUNT_CENTS,
                'payment_status' => 'pending', 'target_bound_at' => 0, 'payment_confirmed_at' => 0,
                'activated_member_id' => 0, 'activated_at' => 0, 'created_by_type' => $operatorType,
                'created_by_id' => $operatorId, 'created_by_role' => $role, 'active_target_key' => null,
                'request_id' => $mutation->requestId(), 'add_time' => $now, 'update_time' => $now,
            ])->toArray();
            $this->audit->recordSafely(self::DOMAIN, 'membership_enrollment', (string)$row['id'], 'create', [], $this->operatorEnrollmentDto($row), $operatorId, $role, $storeId, 'offline_membership_enrollment_create', $mutation->requestId());
            return $this->operatorEnrollmentDto($row);
        });
    }

    private function bindCustomer(int $id, int $storeId, int $operatorId, string $role, string $token, array $data): array
    {
        $token = trim($token);
        if ($token === '') throw new ApiException('customer_identity_code_required');
        $key = $this->idempotencyKey($data);
        $mutation = $this->mutation($id, max(1, $operatorId), $role, $key, 'customer_identity_code_bind');
        return $this->runner->run('permanent_membership_customer_bind', $mutation, ['enrollment_id' => $id, 'store_id' => $storeId, 'identity_code_hash' => $this->hashToken($token)], 'enrollment:' . $id, function () use ($id, $storeId, $operatorId, $role, $token, $mutation) {
            $row = $this->lockEnrollment($id);
            $this->assertEnrollmentStore($row, $storeId);
            if ((int)$row['target_uid'] > 0) return $this->operatorEnrollmentDto($row);
            if ((string)$row['status'] !== self::STATUS_DRAFT) throw new ApiException('membership_enrollment_bind_status_invalid');
            $code = $this->lockCode($token);
            $this->assertCode($code, self::SCENE_CUSTOMER_IDENTITY, 0, (int)$code['target_uid'], 0);
            $targetUid = (int)$code['target_uid'];
            $this->assertUser($targetUid);
            if ($this->membershipForUid($targetUid, true)) throw new ApiException('permanent_membership_already_exists');
            $now = time();
            try {
                $this->dao->update($id, ['target_uid' => $targetUid, 'target_bound_at' => $now, 'active_target_key' => 'uid:' . $targetUid, 'request_id' => $mutation->requestId(), 'update_time' => $now]);
            } catch (\Throwable $e) {
                if ($this->isUniqueConflict($e)) throw new ApiException('membership_customer_already_bound');
                throw $e;
            }
            $this->codeDao->update((int)$code['id'], ['status' => self::CODE_USED, 'store_id' => $storeId, 'used_by_uid' => $operatorId, 'used_by_role' => $role, 'used_time' => $now, 'active_key' => null, 'request_id' => $mutation->requestId(), 'update_time' => $now]);
            $after = array_merge($row, ['target_uid' => $targetUid, 'target_bound_at' => $now, 'active_target_key' => 'uid:' . $targetUid]);
            $this->audit->recordSafely(self::DOMAIN, 'membership_enrollment', (string)$id, 'bind_customer', $row, $after, $operatorId, $role, $storeId, 'customer_identity_code_bind', $mutation->requestId());
            return $this->operatorEnrollmentDto($after);
        });
    }

    private function confirmPayment(int $id, int $storeId, int $operatorId, string $role, array $data): array
    {
        $key = $this->idempotencyKey($data);
        $mutation = $this->mutation($id, max(1, $operatorId), $role, $key, 'offline_payment_9800_confirmed');
        return $this->runner->run('permanent_membership_payment_confirm', $mutation, ['enrollment_id' => $id, 'store_id' => $storeId, 'amount_cents' => self::AMOUNT_CENTS], 'enrollment:' . $id, function () use ($id, $storeId, $operatorId, $role, $mutation) {
            $row = $this->lockEnrollment($id);
            $this->assertEnrollmentStore($row, $storeId);
            if ((string)$row['payment_status'] === 'confirmed') return $this->operatorEnrollmentDto($row);
            if ((string)$row['status'] !== self::STATUS_DRAFT || (int)$row['target_uid'] <= 0) throw new ApiException('membership_customer_not_bound');
            $now = time();
            $update = ['payment_status' => 'confirmed', 'payment_confirmed_at' => $now, 'status' => self::STATUS_PENDING_CONFIRMATION, 'request_id' => $mutation->requestId(), 'update_time' => $now];
            $this->dao->update($id, $update);
            $after = array_merge($row, $update);
            $this->audit->recordSafely(self::DOMAIN, 'membership_enrollment', (string)$id, 'confirm_offline_payment', $row, $after, $operatorId, $role, $storeId, 'offline_payment_9800_confirmed', $mutation->requestId());
            return $this->operatorEnrollmentDto($after);
        });
    }

    private function generateConfirmationCode(int $id, int $storeId, int $operatorId, string $role): array
    {
        return Db::transaction(function () use ($id, $storeId, $operatorId, $role) {
            $row = $this->lockEnrollment($id);
            $this->assertEnrollmentStore($row, $storeId);
            if ((string)$row['status'] !== self::STATUS_PENDING_CONFIRMATION || (string)$row['payment_status'] !== 'confirmed' || (int)$row['target_uid'] <= 0) {
                throw new ApiException('membership_enrollment_not_ready');
            }
            $this->invalidateCodes(self::SCENE_MEMBERSHIP_CONFIRMATION, $id, (int)$row['target_uid']);
            return $this->issueCode(self::SCENE_MEMBERSHIP_CONFIRMATION, $id, (int)$row['target_uid'], $storeId, $operatorId, $role, self::CONFIRMATION_TTL);
        });
    }

    private function issueCode(string $scene, int $enrollmentId, int $targetUid, int $storeId, int $issuer, string $role, int $ttl): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = 'yfthpm_' . bin2hex(random_bytes(24));
            $now = time();
            try {
                $row = $this->codeDao->save([
                    'code_no' => $this->makeNo('YBC'), 'scene' => $scene, 'enrollment_id' => $enrollmentId,
                    'target_uid' => $targetUid, 'store_id' => $storeId, 'token_hash' => $this->hashToken($token),
                    'status' => self::CODE_ISSUED, 'issued_by_uid' => $issuer, 'issued_by_role' => $role,
                    'used_by_uid' => 0, 'used_by_role' => '', 'issued_time' => $now, 'expire_time' => $now + $ttl,
                    'used_time' => 0, 'invalidated_time' => 0, 'active_key' => $this->codeActiveKey($scene, $enrollmentId, $targetUid),
                    'request_id' => '', 'add_time' => $now, 'update_time' => $now,
                ])->toArray();
                return ['scene' => $scene, 'token' => $token, 'qr_payload' => $token, 'issued_time' => $now, 'expire_time' => $now + $ttl, 'ttl_seconds' => $ttl, 'code_id' => (int)$row['id']];
            } catch (\Throwable $e) {
                if (!$this->isUniqueConflict($e)) throw $e;
            }
        }
        throw new ApiException('business_dynamic_code_generation_failed');
    }

    private function invalidateCodes(string $scene, int $enrollmentId, int $targetUid): void
    {
        $query = $this->codeDao->search([])->where('scene', $scene)->where('status', self::CODE_ISSUED);
        $query = $scene === self::SCENE_CUSTOMER_IDENTITY ? $query->where('target_uid', $targetUid) : $query->where('enrollment_id', $enrollmentId);
        $rows = $query->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            $this->codeDao->update((int)$row['id'], ['status' => self::CODE_REPLACED, 'active_key' => null, 'invalidated_time' => time(), 'update_time' => time()]);
        }
    }

    private function assertCode(array $code, string $scene, int $enrollmentId, int $targetUid, int $storeId): void
    {
        if (!$code || (string)$code['scene'] !== $scene || (string)$code['status'] !== self::CODE_ISSUED || (string)($code['active_key'] ?? '') === '' || (int)$code['expire_time'] <= time()) {
            throw new ApiException($scene === self::SCENE_CUSTOMER_IDENTITY ? 'customer_identity_code_invalid' : 'membership_confirmation_code_invalid');
        }
        if ($enrollmentId > 0 && (int)$code['enrollment_id'] !== $enrollmentId) throw new ApiException('membership_confirmation_code_invalid');
        if ((int)$code['target_uid'] !== $targetUid) throw new ApiException('business_dynamic_code_customer_mismatch');
        if ($storeId > 0 && (int)$code['store_id'] !== $storeId) throw new ApiException('business_dynamic_code_store_mismatch');
    }

    private function listEnrollments(array $where): array
    {
        $query = $this->dao->search([]);
        if (!empty($where['store_id'])) $query->where('store_id', is_array($where['store_id']) ? 'in' : '=', $where['store_id']);
        if (!empty($where['status'])) $query->where('status', $where['status']);
        if (!empty($where['target_uid'])) $query->where('target_uid', (int)$where['target_uid']);
        [$page, $limit, $default] = $this->getPageValue();
        $limit = $limit ?: $default;
        $count = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return ['list' => array_map([$this, 'operatorEnrollmentDto'], $list), 'count' => $count];
    }

    private function storeContext(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        if (!in_array((string)$context['role_code'], ['franchisee', 'store_manager'], true)) throw new ApiException('membership_store_operator_forbidden');
        return $context;
    }

    private function mutation(int $sourceId, int $operatorUid, string $role, string $key, string $reason): HqAuthorityMutation
    {
        return new HqAuthorityMutation(
            HqAuthoritySource::fromTrusted(HqAuthoritySourceCanonicalizer::PERMANENT_MEMBERSHIP_SOURCE, $sourceId),
            $operatorUid, $role, $reason, 'pm-' . hash('sha256', $key), $key
        );
    }

    private function idempotencyKey(array $data): string
    {
        $key = trim((string)($data['idempotency_key'] ?? $data['client_operation_key'] ?? ''));
        if ($key === '' || strlen($key) > 191) throw new ApiException('membership_idempotency_key_required');
        return $key;
    }

    private function lockEnrollment(int $id): array
    {
        $row = $this->row($this->dao->search([])->where('id', $id)->lock(true)->find());
        if (!$row) throw new ApiException('membership_enrollment_not_found');
        return $row;
    }

    private function requireEnrollment(int $id): array
    {
        $row = $this->row($this->dao->get($id));
        if (!$row) throw new ApiException('membership_enrollment_not_found');
        return $row;
    }

    private function lockCode(string $token): array
    {
        return $this->row($this->codeDao->search([])->where('token_hash', $this->hashToken(trim($token)))->lock(true)->find());
    }

    private function membershipForUid(int $uid, bool $lock): array
    {
        $query = $this->membershipDao->search([])->where('uid', $uid);
        if ($lock) $query->lock(true);
        return $this->row($query->find());
    }

    private function assertEnrollmentStore(array $row, int $storeId): void
    {
        if ($storeId <= 0 || (int)$row['store_id'] !== $storeId) throw new ApiException('membership_enrollment_store_forbidden');
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
    }

    private function assertUser(int $uid): void
    {
        if ($uid <= 0 || !$this->userDao->getOne(['uid' => $uid, 'is_del' => 0])) throw new ApiException('membership_user_not_found');
    }

    private function activationResult(array $enrollment, array $member, bool $changed): array
    {
        if (!$member) throw new ApiException('permanent_membership_result_missing');
        return ['status' => 'activated', 'changed' => $changed, 'membership' => $this->memberDto($member), 'enrollment' => $this->customerEnrollmentDto($enrollment), 'has_referral_qualification' => true];
    }

    public function memberDto(array $row): array
    {
        return ['membership_id' => (int)$row['id'], 'membership_no' => (string)$row['membership_no'], 'status' => (string)$row['status'], 'store_id' => (int)$row['store_id'], 'activated_at' => (int)$row['activated_at'], 'permanent' => true];
    }

    public function operatorMemberDto(array $row): array
    {
        return array_merge($this->memberDto($row), ['uid' => (int)$row['uid'], 'enrollment_id' => (int)$row['enrollment_id']]);
    }

    public function operatorEnrollmentDto(array $row): array
    {
        return ['id'=>(int)$row['id'],'enrollment_no'=>(string)$row['enrollment_no'],'store_id'=>(int)$row['store_id'],'target_uid'=>(int)$row['target_uid'],'status'=>(string)$row['status'],'amount_cents'=>(int)$row['amount_cents'],'payment_status'=>(string)$row['payment_status'],'target_bound_at'=>(int)$row['target_bound_at'],'payment_confirmed_at'=>(int)$row['payment_confirmed_at'],'activated_member_id'=>(int)$row['activated_member_id'],'activated_at'=>(int)$row['activated_at'],'add_time'=>(int)$row['add_time'],'update_time'=>(int)$row['update_time']];
    }

    private function customerEnrollmentDto(array $row): array
    {
        return ['id'=>(int)$row['id'],'enrollment_no'=>(string)$row['enrollment_no'],'store_id'=>(int)$row['store_id'],'status'=>(string)$row['status'],'amount_cents'=>(int)$row['amount_cents'],'payment_status'=>(string)$row['payment_status'],'payment_confirmed_at'=>(int)$row['payment_confirmed_at'],'activated_at'=>(int)$row['activated_at']];
    }

    private function codeActiveKey(string $scene, int $enrollmentId, int $targetUid): string
    {
        return $scene . ':' . ($scene === self::SCENE_CUSTOMER_IDENTITY ? 'uid:' . $targetUid : 'enrollment:' . $enrollmentId);
    }

    private function hashToken(string $token): string { return hash('sha256', $token); }
    private function makeNo(string $prefix): string { return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6))); }
    private function row($row): array { return $row ? (is_array($row) ? $row : $row->toArray()) : []; }
    private function isUniqueConflict(\Throwable $e): bool { $m = strtolower($e->getMessage()); return strpos($m, 'duplicate') !== false || strpos($m, '1062') !== false || (string)$e->getCode() === '23000'; }
}
