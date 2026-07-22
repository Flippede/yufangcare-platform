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
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_CONFIRMATION = 'pending_customer_confirmation';
    public const STATUS_PENDING_STORE_REVIEW = 'pending_store_review';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ACTIVATED = 'activated';
    public const STATUS_CANCELLED = 'cancelled';
    public const SCENE_CUSTOMER_IDENTITY = 'customer_identity';
    private const CODE_ISSUED = 'issued';
    private const CODE_USED = 'used';
    private const CODE_REPLACED = 'replaced';
    private const IDENTITY_TTL = 300;
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

    public function applyByCustomer(int $uid, array $data): array
    {
        $this->assertUser($uid);
        if (app()->make(PackageMembershipServices::class)->effectiveMembership($uid)['is_member']) {
            throw new ApiException('permanent_membership_already_exists');
        }
        $requestedStoreId = (int)($data['store_id'] ?? 0);
        $storeId = app()->make(PackageMembershipReferralServices::class)
            ->requireAuthoritativeStoreForPurchase($uid, $requestedStoreId);
        $amountCents = $this->currentPackageAmountCents();
        $key = $this->idempotencyKey($data);
        return Db::transaction(function () use ($uid, $storeId, $amountCents, $key) {
            $existing = $this->row($this->dao->search([])
                ->where('target_uid', $uid)
                ->whereIn('status', [self::STATUS_PENDING_STORE_REVIEW, self::STATUS_DRAFT])
                ->lock(true)->order('id desc')->find());
            if ($existing) {
                return $this->customerEnrollmentDto($existing);
            }
            $now = time();
            $row = $this->dao->save([
                'enrollment_no' => $this->makeNo('YPE'),
                'store_id' => $storeId,
                'target_uid' => $uid,
                'status' => self::STATUS_PENDING_STORE_REVIEW,
                'amount_cents' => $amountCents,
                'payment_status' => 'offline_pending',
                'target_bound_at' => $now,
                'payment_confirmed_at' => 0,
                'activated_member_id' => 0,
                'activated_at' => 0,
                'created_by_type' => 'customer_application',
                'created_by_id' => $uid,
                'created_by_role' => 'customer',
                'active_target_key' => 'uid:' . $uid,
                'request_id' => substr(hash('sha256', $key), 0, 64),
                'add_time' => $now,
                'update_time' => $now,
            ])->toArray();
            $this->audit->recordSafely(
                self::DOMAIN,
                'membership_enrollment',
                (string)$row['id'],
                'customer_apply',
                [],
                $this->operatorEnrollmentDto($row),
                $uid,
                'customer',
                $storeId,
                'offline_membership_application',
                $row['request_id']
            );
            return $this->customerEnrollmentDto($row);
        });
    }

    public function approveForStore(Request $request, int $enrollmentId, array $data): array
    {
        $context = $this->storeContext($request);
        return Db::transaction(function () use ($context, $enrollmentId, $data) {
            return $this->activateOfflineEnrollment(
                $enrollmentId,
                (int)$context['store_id'],
                (int)$context['uid'],
                (string)$context['role_code'],
                $this->idempotencyKey($data)
            );
        });
    }

    public function rejectForStore(Request $request, int $enrollmentId, array $data): array
    {
        $context = $this->storeContext($request);
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException('membership_reject_reason_required');
        }
        return Db::transaction(function () use ($context, $enrollmentId, $reason, $data) {
            $row = $this->lockEnrollment($enrollmentId);
            $this->assertEnrollmentStore($row, (int)$context['store_id']);
            if ((string)$row['status'] === self::STATUS_REJECTED) {
                return $this->operatorEnrollmentDto($row);
            }
            if ((string)$row['status'] !== self::STATUS_PENDING_STORE_REVIEW) {
                throw new ApiException('membership_application_status_invalid');
            }
            $update = [
                'status' => self::STATUS_REJECTED,
                'payment_status' => 'rejected',
                'active_target_key' => null,
                'request_id' => substr(hash('sha256', $this->idempotencyKey($data)), 0, 64),
                'update_time' => time(),
            ];
            $this->dao->update($enrollmentId, $update);
            $after = array_merge($row, $update);
            $this->audit->recordSafely(
                self::DOMAIN,
                'membership_enrollment',
                (string)$enrollmentId,
                'store_reject',
                $this->operatorEnrollmentDto($row),
                $this->operatorEnrollmentDto($after),
                (int)$context['uid'],
                (string)$context['role_code'],
                (int)$context['store_id'],
                mb_substr($reason, 0, 255),
                $update['request_id']
            );
            return $this->operatorEnrollmentDto($after);
        });
    }

    public function activateIdentityForStore(Request $request, string $token, array $data): array
    {
        $context = $this->storeContext($request);
        $token = trim($token);
        if ($token === '') {
            throw new ApiException('customer_identity_code_required');
        }
        return Db::transaction(function () use ($context, $token, $data) {
            $code = $this->lockCode($token);
            $this->assertCode($code, self::SCENE_CUSTOMER_IDENTITY, 0, (int)($code['target_uid'] ?? 0), 0);
            $uid = (int)$code['target_uid'];
            $this->assertUser($uid);
            $storeId = (int)$context['store_id'];
            $this->assertAuthoritativeStore($uid, $storeId);
            if (app()->make(PackageMembershipServices::class)->effectiveMembership($uid)['is_member']) {
                throw new ApiException('permanent_membership_already_exists');
            }
            $existing = $this->row($this->dao->search([])
                ->where('target_uid', $uid)
                ->whereIn('status', [self::STATUS_PENDING_STORE_REVIEW, self::STATUS_DRAFT])
                ->lock(true)->order('id desc')->find());
            if (!$existing) {
                $now = time();
                $amountCents = $this->currentPackageAmountCents();
                $existing = $this->dao->save([
                    'enrollment_no' => $this->makeNo('YPE'),
                    'store_id' => $storeId,
                    'target_uid' => $uid,
                    'status' => self::STATUS_PENDING_STORE_REVIEW,
                    'amount_cents' => $amountCents,
                    'payment_status' => 'offline_pending',
                    'target_bound_at' => $now,
                    'payment_confirmed_at' => 0,
                    'activated_member_id' => 0,
                    'activated_at' => 0,
                    'created_by_type' => 'store_identity_scan',
                    'created_by_id' => (int)$context['uid'],
                    'created_by_role' => (string)$context['role_code'],
                    'active_target_key' => 'uid:' . $uid,
                    'request_id' => substr(hash('sha256', $this->idempotencyKey($data)), 0, 64),
                    'add_time' => $now,
                    'update_time' => $now,
                ])->toArray();
            }
            $this->codeDao->update((int)$code['id'], [
                'status' => self::CODE_USED,
                'store_id' => $storeId,
                'used_by_uid' => (int)$context['uid'],
                'used_by_role' => (string)$context['role_code'],
                'used_time' => time(),
                'active_key' => null,
                'update_time' => time(),
            ]);
            return $this->activateOfflineEnrollment(
                (int)$existing['id'],
                $storeId,
                (int)$context['uid'],
                (string)$context['role_code'],
                $this->idempotencyKey($data)
            );
        });
    }

    public function me(int $uid): array
    {
        $effective = app()->make(PackageMembershipServices::class)->effectiveMembership($uid);
        $pending = $this->row($this->dao->search([])->where('target_uid', $uid)
            ->whereIn('status', [self::STATUS_PENDING_STORE_REVIEW, self::STATUS_PENDING_CONFIRMATION])
            ->order('id desc')->find());
        return [
            'membership' => !empty($effective['is_member']) ? (array)$effective['member'] : null,
            'pending_enrollment' => $pending ? $this->customerEnrollmentDto($pending) : null,
            'is_permanent_member' => (bool)($effective['is_member'] ?? false),
            'has_referral_qualification' => (bool)($effective['is_member'] ?? false),
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
        if (!empty($where['store_id'])) $query = $query->where('store_id', (int)$where['store_id']);
        if (!empty($where['uid'])) $query = $query->where('uid', (int)$where['uid']);
        if (!empty($where['status'])) $query = $query->where('status', trim((string)$where['status']));
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

    private function activateOfflineEnrollment(
        int $enrollmentId,
        int $storeId,
        int $operatorUid,
        string $operatorRole,
        string $idempotencyKey
    ): array {
        $row = $this->lockEnrollment($enrollmentId);
        $this->assertEnrollmentStore($row, $storeId);
        if ((string)$row['status'] === self::STATUS_ACTIVATED) {
            return [
                'changed' => false,
                'idempotent' => true,
                'enrollment' => $this->operatorEnrollmentDto($row),
            ];
        }
        if (!in_array((string)$row['status'], [self::STATUS_PENDING_STORE_REVIEW, self::STATUS_DRAFT], true)
            || (int)$row['target_uid'] <= 0) {
            throw new ApiException('membership_application_status_invalid');
        }
        $uid = (int)$row['target_uid'];
        $amountCents = (int)$row['amount_cents'];
        if ($amountCents <= 0) {
            throw new ApiException('membership_application_price_snapshot_invalid');
        }
        $this->assertAuthoritativeStore($uid, $storeId);
        if (app()->make(PackageMembershipServices::class)->effectiveMembership($uid)['is_member']) {
            throw new ApiException('permanent_membership_already_exists');
        }

        $requestId = 'offline-membership-' . substr(hash('sha256', $idempotencyKey), 0, 40);
        $referral = app()->make(HqActiveReferralServices::class);
        $lockContext = $referral->membershipLockContext($uid);
        $lockedCurrents = (array)$lockContext['locked_currents'];
        $current = (array)$lockedCurrents[$uid];
        if ((string)$current['status'] !== 'active' || (int)$current['store_id'] !== $storeId) {
            throw new ApiException('offline_membership_store_attribution_mismatch');
        }
        $source = HqAuthoritySource::fromTrusted('offline_membership_activation', $enrollmentId);
        $mutation = new HqAuthorityMutation(
            $source,
            $operatorUid,
            $operatorRole,
            'offline_membership_activated',
            $requestId,
            $idempotencyKey
        );

        $commission = [];
        if ((int)$lockContext['relation_id'] > 0) {
            $payload = [
                'activation_type' => 'offline_enrollment',
                'enrollment_id' => $enrollmentId,
                'instance_id' => 0,
                'order_id' => 0,
                'amount_cent' => $amountCents,
                'relation' => [
                    'id' => (int)$lockContext['relation_id'],
                    'referrer_uid' => (int)$lockContext['referrer_uid'],
                    'referred_uid' => $uid,
                    'store_id' => $storeId,
                ],
            ];
            $commission = app()->make(AutomaticCommissionServices::class)->consumePackageActivation($payload);
            $referral->closeForMembershipWithLockedCurrentsInTransaction(
                $uid,
                $storeId,
                $mutation,
                $lockContext,
                $lockedCurrents
            );
        }

        $membership = app()->make(PackageMembershipServices::class)->grantOfflineInTransaction(
            $uid,
            $storeId,
            $enrollmentId,
            $amountCents,
            $operatorUid,
            $operatorRole,
            $requestId
        );
        $membershipId = (int)Db::name('yfth_permanent_membership')->where('uid', $uid)->value('id');
        $now = time();
        $update = [
            'status' => self::STATUS_ACTIVATED,
            'payment_status' => 'offline_confirmed',
            'payment_confirmed_at' => $now,
            'activated_member_id' => $membershipId,
            'activated_at' => $now,
            'active_target_key' => null,
            'request_id' => $requestId,
            'update_time' => $now,
        ];
        $this->dao->update($enrollmentId, $update);
        $after = array_merge($row, $update);
        $this->audit->recordSafely(
            self::DOMAIN,
            'membership_enrollment',
            (string)$enrollmentId,
            'store_activate',
            $this->operatorEnrollmentDto($row),
            $this->operatorEnrollmentDto($after),
            $operatorUid,
            $operatorRole,
            $storeId,
            'offline_membership_activated',
            $requestId
        );
        return [
            'changed' => true,
            'idempotent' => false,
            'membership' => (array)$membership['member'],
            'commission' => $commission,
            'enrollment' => $this->operatorEnrollmentDto($after),
        ];
    }

    private function assertAuthoritativeStore(int $uid, int $storeId): void
    {
        try {
            $store = app()->make(UserRelationshipAuthorityServices::class)->requirePurchaseStore($uid, $storeId);
        } catch (\Throwable $e) {
            throw new ApiException('offline_membership_store_attribution_mismatch');
        }
        if ((int)($store['store_id'] ?? 0) !== $storeId) {
            throw new ApiException('offline_membership_store_attribution_mismatch');
        }
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

    private function currentPackageAmountCents(): int
    {
        $rule = app()->make(PackageTemplateServices::class)->managedMemberRule();
        $price = trim((string)($rule['package_price'] ?? ''));
        if ($price === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $price)) {
            throw new ApiException('managed_member_package_price_invalid');
        }
        [$whole, $decimal] = array_pad(explode('.', $price, 2), 2, '');
        $amountCents = ((int)$whole * 100) + (int)str_pad(substr($decimal, 0, 2), 2, '0');
        if ($amountCents <= 0) {
            throw new ApiException('managed_member_package_price_invalid');
        }
        return $amountCents;
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
        if (!empty($where['store_id'])) $query = $query->where('store_id', is_array($where['store_id']) ? 'in' : '=', $where['store_id']);
        if (!empty($where['status'])) $query = $query->where('status', $where['status']);
        if (!empty($where['target_uid'])) $query = $query->where('target_uid', (int)$where['target_uid']);
        [$page, $limit, $default] = $this->getPageValue();
        $limit = $limit ?: $default;
        $count = (clone $query)->count();
        $list = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return ['list' => array_map([$this, 'operatorEnrollmentDto'], $list), 'count' => $count];
    }

    private function storeContext(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        if (!in_array((string)$context['role_code'], ['store_manager', 'store_staff'], true)) throw new ApiException('membership_store_operator_forbidden');
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
