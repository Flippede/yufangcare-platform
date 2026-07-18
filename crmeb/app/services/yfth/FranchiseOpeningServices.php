<?php

namespace app\services\yfth;

use app\Request;
use app\dao\yfth\YfthFranchiseApplicationDao;
use app\dao\yfth\YfthFranchiseContractDao;
use app\dao\yfth\YfthFranchiseIdentityGrantDao;
use app\dao\yfth\YfthFranchisePaymentProofDao;
use app\dao\yfth\YfthFranchisePreparationTaskDao;
use app\dao\yfth\YfthFranchisePreparationTaskRecordDao;
use app\dao\yfth\YfthFranchiseStoreProfileDao;
use app\dao\yfth\YfthStoreCapabilityDao;
use app\dao\yfth\YfthStoreOpeningAcceptanceDao;
use app\dao\yfth\YfthStoreOpeningAcceptanceItemDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class FranchiseOpeningServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_franchise_opening';
    private const CONTRACT_STATUSES = ['draft', 'pending_user_confirm', 'user_confirmed', 'hq_confirmed', 'signed'];
    private const PAYMENT_STATUSES = ['pending_upload', 'uploaded', 'rejected', 'finance_confirmed'];
    private const PROFILE_STATUSES = ['draft', 'submitted', 'verified', 'bound'];
    private const TASK_STATUSES = ['pending', 'in_progress', 'submitted', 'approved', 'rejected'];
    private const ACCEPTANCE_STATUSES = ['draft', 'submitted', 'reviewing', 'passed', 'rejected', 'recheck_required'];
    private const OPENING_CAPABILITIES = ['store_purchase', 'retail_sale', 'package_sale', 'reservation_service', 'order_writeoff'];

    private const TASK_TEMPLATES = [
        ['code' => 'subject_info', 'name' => 'Subject information', 'required' => 1, 'owner' => 'applicant'],
        ['code' => 'store_address', 'name' => 'Store address', 'required' => 1, 'owner' => 'applicant'],
        ['code' => 'decoration_signboard', 'name' => 'Decoration and signboard', 'required' => 1, 'owner' => 'applicant'],
        ['code' => 'qualification_submit', 'name' => 'Qualification submission', 'required' => 1, 'owner' => 'applicant'],
        ['code' => 'public_account_material', 'name' => 'Headquarters payment account and public-account materials', 'required' => 1, 'owner' => 'applicant'],
        ['code' => 'payment_route_submit', 'name' => 'Payment merchant materials', 'required' => 1, 'owner' => 'applicant'],
        // Supply-chain purchase is linked after a formal store exists; it must not make pre-opening acceptance impossible.
        ['code' => 'first_purchase', 'name' => 'First purchase proof', 'required' => 0, 'owner' => 'applicant'],
        ['code' => 'training_complete', 'name' => 'Training completion', 'required' => 1, 'owner' => 'applicant'],
        ['code' => 'opening_material', 'name' => 'Opening materials', 'required' => 1, 'owner' => 'applicant'],
        ['code' => 'acceptance_apply', 'name' => 'Acceptance application', 'required' => 1, 'owner' => 'applicant'],
    ];

    private const ACCEPTANCE_ITEMS = [
        ['code' => 'store_profile', 'name' => 'Store profile verified'],
        ['code' => 'decoration', 'name' => 'Decoration and signboard checked'],
        ['code' => 'qualification', 'name' => 'Qualification materials checked'],
        ['code' => 'training', 'name' => 'Training complete'],
        ['code' => 'first_purchase', 'name' => 'First purchase stocked'],
    ];

    public function __construct(YfthFranchiseContractDao $dao)
    {
        $this->dao = $dao;
    }

    public function myOpening(Request $request): array
    {
        $uid = $this->requestUid($request);
        $application = app()->make(YfthFranchiseApplicationDao::class)->search([])
            ->where('applicant_uid', $uid)
            ->whereIn('status', ['pending_contract', 'signed', 'preparing', 'opened'])
            ->order('id desc')
            ->find();
        if (!$application) {
            return [
                'application' => [],
                'contract' => [],
                'payment' => [],
                'store_profile' => [],
                'tasks' => [],
                'acceptance' => [],
                'identity_grants' => [],
            ];
        }
        return $this->openingSummary($this->rowArray($application), false);
    }

    public function userContractDetail(Request $request, int $id): array
    {
        $contract = $this->requireContract($id);
        $this->assertApplicantOwns((int)$contract['application_id'], $this->requestUid($request));
        return ['contract' => $this->formatContract($contract, false)];
    }

    public function userConfirmContract(Request $request, int $id): array
    {
        $uid = $this->requestUid($request);
        return Db::transaction(function () use ($id, $uid) {
            $before = $this->lockContract($id);
            $this->assertApplicantOwns((int)$before['application_id'], $uid);
            if ((string)$before['status'] !== 'pending_user_confirm') {
                throw new ApiException('franchise_contract_user_confirm_status_invalid');
            }
            $after = $before;
            $after['status'] = 'user_confirmed';
            $after['update_time'] = time();
            $this->dao->update($id, [
                'status' => $after['status'],
                'update_time' => $after['update_time'],
            ]);
            $this->audit('franchise_contract', $id, 'contract_user_confirm', $before, $after, $uid, 'customer', 0, 'user_confirm_contract');
            return ['contract' => $this->formatContract($after, false)];
        });
    }

    public function userUploadPaymentProof(Request $request, int $id, array $data): array
    {
        $this->assertNoForbiddenUserFields($data);
        $uid = $this->requestUid($request);
        return Db::transaction(function () use ($id, $uid, $data) {
            $before = $this->lockPayment($id);
            $this->assertApplicantOwns((int)$before['application_id'], $uid);
            if (!in_array((string)$before['status'], ['pending_upload', 'rejected', 'uploaded'], true)) {
                throw new ApiException('franchise_payment_upload_status_invalid');
            }
            $contract = $this->requireContract((int)$before['contract_id']);
            if ((string)$contract['status'] !== 'signed') {
                throw new ApiException('franchise_payment_contract_not_signed');
            }
            $attachments = $this->normalizeAttachmentIds($data['attachment_ids'] ?? ($data['proof_attachment_id'] ?? ''));
            if ($attachments === '') {
                throw new ApiException('franchise_payment_proof_required');
            }
            $after = $before;
            $after['attachment_ids'] = $attachments;
            $after['amount_snapshot'] = $this->normalizeAmount($data['amount_snapshot'] ?? $before['amount_snapshot']);
            $after['status'] = 'uploaded';
            $after['reject_reason'] = '';
            $after['update_time'] = time();
            app()->make(YfthFranchisePaymentProofDao::class)->update($id, [
                'attachment_ids' => $after['attachment_ids'],
                'amount_snapshot' => $after['amount_snapshot'],
                'status' => $after['status'],
                'reject_reason' => '',
                'update_time' => $after['update_time'],
            ]);
            $this->audit('franchise_payment_proof', $id, 'payment_proof_upload', $before, $after, $uid, 'customer', 0, 'user_upload_payment_proof');
            return ['payment' => $this->formatPayment($after, false)];
        });
    }

    public function userTaskList(Request $request): array
    {
        $uid = $this->requestUid($request);
        $application = $this->requireLatestOpeningApplication($uid);
        return ['list' => $this->tasksForApplication((int)$application['id'])];
    }

    public function userSubmitTask(Request $request, int $id, array $data): array
    {
        $this->assertNoForbiddenUserFields($data);
        $uid = $this->requestUid($request);
        return Db::transaction(function () use ($id, $uid, $data) {
            $before = $this->lockTask($id);
            $this->assertApplicantOwns((int)$before['application_id'], $uid);
            if (!in_array((string)$before['status'], ['pending', 'in_progress', 'rejected'], true)) {
                throw new ApiException('franchise_task_submit_status_invalid');
            }
            $this->validateFirstPurchaseTask($before, $data);
            $attachments = $this->normalizeAttachmentIds($data['attachment_ids'] ?? '');
            $content = trim((string)($data['content'] ?? ''));
            if ($attachments === '' && $content === '') {
                throw new ApiException('franchise_task_evidence_required');
            }
            $now = time();
            $after = $before;
            $after['status'] = 'submitted';
            $after['purchase_order_id'] = (int)($data['purchase_order_id'] ?? $before['purchase_order_id']);
            $after['reject_reason'] = '';
            $after['update_time'] = $now;
            app()->make(YfthFranchisePreparationTaskDao::class)->update($id, [
                'status' => 'submitted',
                'purchase_order_id' => $after['purchase_order_id'],
                'reject_reason' => '',
                'update_time' => $now,
            ]);
            $this->taskRecord($id, (int)$before['application_id'], 'applicant', $uid, 'task_submit', $content, $attachments);
            $this->audit('franchise_preparation_task', $id, 'task_submit', $before, $after, $uid, 'customer', 0, 'user_submit_task');
            return ['task' => $this->formatTask($after, false)];
        });
    }

    public function userAcceptance(Request $request): array
    {
        $uid = $this->requestUid($request);
        $application = $this->requireLatestOpeningApplication($uid);
        $acceptance = $this->latestAcceptance((int)$application['id']);
        if (!$acceptance) {
            return ['acceptance' => $this->pendingAcceptanceDto((int)$application['id'])];
        }
        return ['acceptance' => $this->formatAcceptance($acceptance, false)];
    }

    public function userSubmitAcceptance(Request $request, array $data): array
    {
        $this->assertNoForbiddenUserFields($data);
        $uid = $this->requestUid($request);
        $application = $this->requireLatestOpeningApplication($uid);
        return Db::transaction(function () use ($application, $uid, $data) {
            $gate = $this->assertAcceptanceSubmitReady((int)$application['id']);
            $before = $this->ensureAcceptanceForSubmit((int)$application['id'], $gate['contract'], $gate['profile']);
            $this->assertApplicantOwns((int)$before['application_id'], $uid);
            if (!in_array((string)$before['status'], ['draft', 'rejected', 'recheck_required'], true)) {
                throw new ApiException('franchise_acceptance_submit_status_invalid');
            }
            $now = time();
            $after = $before;
            $after['status'] = 'submitted';
            $after['reject_reason'] = '';
            $after['update_time'] = $now;
            app()->make(YfthStoreOpeningAcceptanceDao::class)->update((int)$before['id'], [
                'status' => 'submitted',
                'reject_reason' => '',
                'update_time' => $now,
            ]);
            $this->ensureAcceptanceItems((int)$before['id']);
            $this->audit('store_opening_acceptance', (int)$before['id'], 'acceptance_submit', $before, $after, $uid, 'customer', (int)$before['system_store_id'], trim((string)($data['reason'] ?? '')));
            return ['acceptance' => $this->formatAcceptance($after, false)];
        });
    }

    public function adminContractList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = $this->dao->search([]);
        $status = $this->normalizeStatus((string)($where['status'] ?? ''), self::CONTRACT_STATUSES, '');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $applicationId = (int)($where['application_id'] ?? 0);
        if ($applicationId > 0) {
            $query->where('application_id', $applicationId);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map(function ($row) {
                return $this->formatContract($row, true);
            }, $rows),
            'count' => $count,
        ];
    }

    public function adminContractDetail(int $id, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $contract = $this->requireContract($id);
        $application = $this->requireApplication((int)$contract['application_id']);
        return array_merge($this->openingSummary($application, true), ['contract' => $this->formatContract($contract, true)]);
    }

    public function adminCreateContract(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $applicationId = (int)($data['application_id'] ?? 0);
        return Db::transaction(function () use ($applicationId, $data, $adminId) {
            $application = $this->lockApplication($applicationId);
            if ((string)$application['status'] !== 'pending_contract') {
                throw new ApiException('franchise_contract_application_status_invalid');
            }
            if ($this->dao->getOne(['application_id' => $applicationId])) {
                throw new ApiException('franchise_contract_application_exists');
            }
            $now = time();
            $contract = $this->dao->save([
                'application_id' => $applicationId,
                'applicant_uid' => (int)$application['applicant_uid'],
                'contract_no' => $this->makeNo('FC'),
                'status' => 'pending_user_confirm',
                'amount_snapshot' => $this->normalizeAmount($data['amount_snapshot'] ?? 0),
                'attachment_ids' => $this->normalizeAttachmentIds($data['attachment_ids'] ?? ''),
                'signed_time' => 0,
                'operator_uid' => $adminId,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $contract = $this->rowArray($contract);
            $this->audit('franchise_contract', (int)$contract['id'], 'contract_create', [], $contract, $adminId, 'headquarter_admin', 0, 'admin_create_contract');
            return ['contract' => $this->formatContract($contract, true)];
        });
    }

    public function adminConfirmContract(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $action = trim((string)($data['action'] ?? 'hq_confirm'));
        return Db::transaction(function () use ($id, $action, $adminId) {
            $before = $this->lockContract($id);
            $after = $before;
            $now = time();
            if ($action === 'hq_confirm') {
                if ((string)$before['status'] !== 'user_confirmed') {
                    throw new ApiException('franchise_contract_hq_confirm_status_invalid');
                }
                $after['status'] = 'hq_confirmed';
                $auditAction = 'contract_hq_confirm';
            } elseif ($action === 'sign') {
                if ((string)$before['status'] !== 'hq_confirmed') {
                    throw new ApiException('franchise_contract_sign_status_invalid');
                }
                $after['status'] = 'signed';
                $after['signed_time'] = $now;
                $auditAction = 'contract_signed';
            } else {
                throw new ApiException('franchise_contract_action_invalid');
            }
            $after['operator_uid'] = $adminId;
            $after['update_time'] = $now;
            $this->dao->update($id, [
                'status' => $after['status'],
                'signed_time' => $after['signed_time'],
                'operator_uid' => $adminId,
                'update_time' => $now,
            ]);
            if ($after['status'] === 'signed') {
                $this->advanceApplication((int)$before['application_id'], 'signed');
                $this->ensurePaymentProof((int)$before['application_id'], $id, (string)$before['amount_snapshot']);
            }
            $this->audit('franchise_contract', $id, $auditAction, $before, $after, $adminId, 'headquarter_admin', 0, $auditAction);
            return ['contract' => $this->formatContract($after, true)];
        });
    }

    public function adminPaymentList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = app()->make(YfthFranchisePaymentProofDao::class)->search([]);
        $status = $this->normalizeStatus((string)($where['status'] ?? ''), self::PAYMENT_STATUSES, '');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map(function ($row) {
                return $this->formatPayment($row, true);
            }, $rows),
            'count' => $count,
        ];
    }

    public function adminConfirmPayment(int $id, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $adminId) {
            $before = $this->lockPayment($id);
            if ((string)$before['status'] !== 'uploaded') {
                throw new ApiException('franchise_payment_confirm_status_invalid');
            }
            $contract = $this->requireContract((int)$before['contract_id']);
            if ((string)$contract['status'] !== 'signed') {
                throw new ApiException('franchise_payment_contract_not_signed');
            }
            $now = time();
            $after = $before;
            $after['status'] = 'finance_confirmed';
            $after['finance_uid'] = $adminId;
            $after['finance_time'] = $now;
            $after['reject_reason'] = '';
            $after['update_time'] = $now;
            app()->make(YfthFranchisePaymentProofDao::class)->update($id, [
                'status' => $after['status'],
                'finance_uid' => $adminId,
                'finance_time' => $now,
                'reject_reason' => '',
                'update_time' => $now,
            ]);
            $this->ensureStoreProfile((int)$before['application_id'], (int)$before['contract_id']);
            $this->ensurePreparationTasks((int)$before['application_id']);
            app()->make(FranchisePartnerServices::class)->freezeRecruitSource((int)$before['application_id'], $adminId);
            $this->advanceApplication((int)$before['application_id'], 'preparing');
            $this->audit('franchise_payment_proof', $id, 'payment_finance_confirm', $before, $after, $adminId, 'headquarter_finance', 0, 'finance_confirm_payment');
            return ['payment' => $this->formatPayment($after, true)];
        });
    }

    public function adminRejectPayment(int $id, string $reason, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $reason, $adminId) {
            $before = $this->lockPayment($id);
            if ((string)$before['status'] !== 'uploaded') {
                throw new ApiException('franchise_payment_reject_status_invalid');
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw new ApiException('franchise_payment_reject_reason_required');
            }
            $after = $before;
            $after['status'] = 'rejected';
            $after['reject_reason'] = $reason;
            $after['update_time'] = time();
            app()->make(YfthFranchisePaymentProofDao::class)->update($id, [
                'status' => 'rejected',
                'reject_reason' => $reason,
                'update_time' => $after['update_time'],
            ]);
            $this->audit('franchise_payment_proof', $id, 'payment_reject', $before, $after, $adminId, 'headquarter_finance', 0, $reason);
            return ['payment' => $this->formatPayment($after, true)];
        });
    }

    public function adminProfileDetail(int $applicationId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $profile = $this->ensureStoreProfile($applicationId, (int)($this->latestContract($applicationId)['id'] ?? 0));
        return ['store_profile' => $this->formatProfile($profile, true)];
    }

    public function adminSaveProfile(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($data, $adminId) {
            $applicationId = (int)($data['application_id'] ?? 0);
            $profile = $this->ensureStoreProfile($applicationId, (int)($this->latestContract($applicationId)['id'] ?? 0));
            $before = $profile;
            $payload = $this->normalizeProfilePayload($data, $profile);
            $payload['status'] = $payload['status'] === 'draft' ? 'submitted' : $payload['status'];
            $payload['update_time'] = time();
            app()->make(YfthFranchiseStoreProfileDao::class)->update((int)$profile['id'], $payload);
            $after = array_merge($profile, $payload);
            $this->audit('franchise_store_profile', (int)$profile['id'], 'profile_save', $before, $after, $adminId, 'headquarter_operator', (int)$after['system_store_id'], 'admin_save_profile');
            return ['store_profile' => $this->formatProfile($after, true)];
        });
    }

    public function adminBindStore(int $profileId, int $systemStoreId, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($profileId, $systemStoreId, $adminId) {
            $before = $this->requireProfile($profileId);
            app()->make(StoreAccessServices::class)->assertStoreActive($systemStoreId);
            $after = $before;
            $after['system_store_id'] = $systemStoreId;
            $after['status'] = 'bound';
            $after['update_time'] = time();
            app()->make(YfthFranchiseStoreProfileDao::class)->update($profileId, [
                'system_store_id' => $systemStoreId,
                'status' => 'bound',
                'update_time' => $after['update_time'],
            ]);
            $this->syncAcceptanceStore((int)$before['application_id'], $systemStoreId);
            $this->audit('franchise_store_profile', $profileId, 'profile_bind_store', $before, $after, $adminId, 'headquarter_operator', $systemStoreId, 'admin_bind_existing_store');
            return ['store_profile' => $this->formatProfile($after, true)];
        });
    }

    public function adminCreateAndBindStore(int $profileId, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($profileId, $data, $adminId) {
            $profileHint = $this->requireProfile($profileId);
            $applicationId = (int)$profileHint['application_id'];
            $application = $this->lockApplication($applicationId);
            $profile = Db::name('yfth_franchise_store_profile')->where('id', $profileId)->lock(true)->find();
            if (!$profile || (int)$profile['application_id'] !== $applicationId) {
                throw new ApiException('franchise_store_profile_not_found');
            }
            $acceptance = $this->latestAcceptance($applicationId);
            $contract = $this->latestContract($applicationId);
            $payment = $this->latestPayment($applicationId);
            if (!$contract || (string)$contract['status'] !== 'signed') {
                throw new ApiException('franchise_store_create_contract_not_signed');
            }
            if (!$payment || (string)$payment['status'] !== 'finance_confirmed') {
                throw new ApiException('franchise_store_create_payment_not_confirmed');
            }
            if (!$acceptance || (string)$acceptance['status'] !== 'passed') {
                throw new ApiException('franchise_store_create_acceptance_not_passed');
            }
            if (!in_array((string)$profile['status'], ['verified', 'bound'], true)) {
                throw new ApiException('franchise_store_profile_not_verified');
            }
            if ((int)$profile['system_store_id'] > 0) {
                app()->make(StoreAccessServices::class)->assertStoreActive((int)$profile['system_store_id']);
                return ['store_profile' => $this->formatProfile($profile, true), 'created' => false];
            }
            $phone = preg_replace('/\s+/', '', (string)($application['phone'] ?? ''));
            if ($phone === '') {
                throw new ApiException('franchise_store_phone_required');
            }
            $conflict = Db::name('system_store')->where('phone', $phone)->where('is_del', 0)->find();
            if ($conflict) {
                throw new ApiException('franchise_store_phone_conflict');
            }
            $storeId = (int)Db::name('system_store')->insertGetId([
                'name' => (string)$profile['store_name'],
                'introduction' => '御方通和正式加盟门店，来源申请 ' . (string)$application['application_no'],
                'phone' => $phone,
                'address' => trim(implode(' ', array_filter([
                    (string)$profile['province'], (string)$profile['city'], (string)$profile['district'],
                ]))),
                'detailed_address' => (string)$profile['address'],
                'image' => trim((string)($data['image'] ?? '')),
                'oblong_image' => trim((string)($data['oblong_image'] ?? '')),
                'latitude' => trim((string)($data['latitude'] ?? '')),
                'longitude' => trim((string)($data['longitude'] ?? '')),
                'valid_time' => trim((string)($data['valid_time'] ?? '09:00 - 18:00')),
                'day_time' => trim((string)($data['day_time'] ?? '周一至周日')),
                'add_time' => time(),
                'is_show' => 1,
                'is_del' => 0,
            ]);
            if ($storeId <= 0) {
                throw new ApiException('franchise_store_create_failed');
            }
            $after = $profile;
            $after['system_store_id'] = $storeId;
            $after['status'] = 'bound';
            $after['update_time'] = time();
            app()->make(YfthFranchiseStoreProfileDao::class)->update($profileId, [
                'system_store_id' => $storeId,
                'status' => 'bound',
                'update_time' => $after['update_time'],
            ]);
            $this->syncAcceptanceStore($applicationId, $storeId);
            $this->audit('franchise_store_profile', $profileId, 'formal_store_create', $profile, $after, $adminId, 'headquarter_opening_operator', $storeId, trim((string)($data['reason'] ?? 'formal_opening')));
            return ['store_profile' => $this->formatProfile($after, true), 'created' => true];
        });
    }

    public function adminTaskList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $query = app()->make(YfthFranchisePreparationTaskDao::class)->search([]);
        $applicationId = (int)($where['application_id'] ?? 0);
        if ($applicationId > 0) {
            $query->where('application_id', $applicationId);
        }
        $status = $this->normalizeStatus((string)($where['status'] ?? ''), self::TASK_STATUSES, '');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $rows = $query->order('id asc')->select()->toArray();
        return ['list' => array_map(function ($row) {
            return $this->formatTask($row, true);
        }, $rows)];
    }

    public function adminReviewTask(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $action = trim((string)($data['action'] ?? 'approve'));
        return Db::transaction(function () use ($id, $data, $adminId, $action) {
            $before = $this->lockTask($id);
            if ((string)$before['status'] !== 'submitted') {
                throw new ApiException('franchise_task_review_status_invalid');
            }
            $now = time();
            $after = $before;
            if ($action === 'approve') {
                $after['status'] = 'approved';
                $after['reject_reason'] = '';
                $auditAction = 'task_approve';
            } elseif ($action === 'reject') {
                $reason = trim((string)($data['reject_reason'] ?? ''));
                if ($reason === '') {
                    throw new ApiException('franchise_task_reject_reason_required');
                }
                $after['status'] = 'rejected';
                $after['reject_reason'] = $reason;
                $auditAction = 'task_reject';
            } else {
                throw new ApiException('franchise_task_action_invalid');
            }
            $after['verified_uid'] = $adminId;
            $after['verified_time'] = $now;
            $after['update_time'] = $now;
            app()->make(YfthFranchisePreparationTaskDao::class)->update($id, [
                'status' => $after['status'],
                'verified_uid' => $adminId,
                'verified_time' => $now,
                'reject_reason' => $after['reject_reason'],
                'update_time' => $now,
            ]);
            $this->taskRecord($id, (int)$before['application_id'], 'headquarters', $adminId, $auditAction, trim((string)($data['content'] ?? '')), '');
            $this->audit('franchise_preparation_task', $id, $auditAction, $before, $after, $adminId, 'headquarter_operator', 0, $after['reject_reason']);
            return ['task' => $this->formatTask($after, true)];
        });
    }

    public function adminAcceptanceList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $query = app()->make(YfthStoreOpeningAcceptanceDao::class)->search([]);
        $status = $this->normalizeStatus((string)($where['status'] ?? ''), self::ACCEPTANCE_STATUSES, '');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $rows = $query->order('id desc')->select()->toArray();
        return ['list' => array_map(function ($row) {
            return $this->formatAcceptance($row, true);
        }, $rows)];
    }

    public function adminAcceptanceDetail(int $id, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $acceptance = $this->requireAcceptance($id);
        return ['acceptance' => $this->formatAcceptance($acceptance, true)];
    }

    public function adminReviewAcceptance(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $action = trim((string)($data['action'] ?? 'pass'));
        return Db::transaction(function () use ($id, $data, $adminId, $action) {
            $before = $this->lockAcceptance($id);
            if (!in_array((string)$before['status'], ['submitted', 'reviewing', 'recheck_required'], true)) {
                throw new ApiException('franchise_acceptance_review_status_invalid');
            }
            $now = time();
            $after = $before;
            if ($action === 'reviewing') {
                $after['status'] = 'reviewing';
                $auditAction = 'acceptance_reviewing';
            } elseif ($action === 'pass') {
                $this->assertAcceptancePassReady((int)$before['application_id']);
                $after['status'] = 'passed';
                $auditAction = 'acceptance_pass';
                $this->markAcceptanceItemsPassed($id, $adminId);
            } elseif ($action === 'reject') {
                $reason = trim((string)($data['reject_reason'] ?? ''));
                if ($reason === '') {
                    throw new ApiException('franchise_acceptance_reject_reason_required');
                }
                $after['status'] = 'rejected';
                $after['reject_reason'] = $reason;
                $auditAction = 'acceptance_reject';
            } else {
                throw new ApiException('franchise_acceptance_action_invalid');
            }
            $after['reviewer_uid'] = $adminId;
            $after['review_time'] = $now;
            $after['update_time'] = $now;
            app()->make(YfthStoreOpeningAcceptanceDao::class)->update($id, [
                'status' => $after['status'],
                'reviewer_uid' => $adminId,
                'review_time' => $now,
                'reject_reason' => $after['reject_reason'],
                'update_time' => $now,
            ]);
            $this->audit('store_opening_acceptance', $id, $auditAction, $before, $after, $adminId, 'headquarter_operator', (int)$before['system_store_id'], $after['reject_reason']);
            return ['acceptance' => $this->formatAcceptance($after, true)];
        });
    }

    public function adminGrantIdentity(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $applicationId = (int)($data['application_id'] ?? 0);
        $role = trim((string)($data['role_code'] ?? 'county_partner'));
        if (!in_array($role, ['county_partner', 'store_manager', 'all'], true)) {
            throw new ApiException('franchise_identity_grant_role_invalid');
        }
        // Partner ownership is mandatory and independent from the optional manager permission.
        $grantManager = in_array($role, ['store_manager', 'all'], true);
        $roles = $grantManager ? ['store_manager'] : [];
        $result = Db::transaction(function () use ($applicationId, $roles, $adminId, $data) {
            $application = $this->lockApplication($applicationId);
            $this->ensurePreparationTasks($applicationId);
            $contract = $this->latestContract($applicationId);
            $payment = $this->latestPayment($applicationId);
            $profile = $this->latestProfile($applicationId);
            $acceptance = $this->latestAcceptance($applicationId);
            if (!$contract || (string)$contract['status'] !== 'signed') {
                throw new ApiException('franchise_identity_contract_not_signed');
            }
            if (!$payment || (string)$payment['status'] !== 'finance_confirmed') {
                throw new ApiException('franchise_identity_payment_not_confirmed');
            }
            if (!$this->allRequiredTasksApprovedStrict($applicationId)) {
                throw new ApiException('franchise_identity_tasks_not_approved');
            }
            if (!$acceptance || (string)$acceptance['status'] !== 'passed') {
                throw new ApiException('franchise_identity_acceptance_not_passed');
            }
            $storeId = (int)($profile['system_store_id'] ?? 0);
            if ($storeId <= 0 || (string)($profile['status'] ?? '') !== 'bound') {
                throw new ApiException('franchise_identity_store_not_bound');
            }
            app()->make(StoreAccessServices::class)->assertStoreActive($storeId);

            $grants = [$this->activatePartnerIdentityGrant($application, $acceptance, $storeId, $adminId, trim((string)($data['reason'] ?? '')))];
            foreach ($roles as $currentRole) {
                $grants[] = $this->activateStoreRoleGrant($application, $acceptance, $storeId, $currentRole, $adminId, trim((string)($data['reason'] ?? '')));
            }
            $partner = app()->make(FranchisePartnerServices::class)->finalizeOpeningInTransaction(
                $application,
                $storeId,
                0,
                $adminId
            );
            $this->enableOpeningCapabilities($storeId, $adminId);
            $this->advanceApplication($applicationId, 'opened');
            return ['grants' => array_map([$this, 'formatGrant'], $grants), 'partner' => $partner];
        });
        $eventId = (int)($result['partner']['reward_event_id'] ?? 0);
        if ($eventId > 0) {
            try {
                app()->make(UnifiedRewardOrchestratorServices::class)->process($eventId, 'franchise-opening');
            } catch (\Throwable $e) {
                $this->audit('franchise_application', $applicationId, 'reward_event_deferred', [], [
                    'reward_event_id' => $eventId, 'error' => substr($e->getMessage(), 0, 255),
                ], $adminId, 'headquarter_operator', 0, 'reward_event_deferred');
            }
        }
        return $result;
    }

    private function activatePartnerIdentityGrant(array $application, array $acceptance, int $storeId, int $adminId, string $reason): array
    {
        $uid = (int)$application['applicant_uid'];
        $grantDao = app()->make(YfthFranchiseIdentityGrantDao::class);
        $activeKey = $uid . ':' . $storeId . ':county_partner';
        $existing = $this->rowArray($grantDao->getOne(['active_key' => $activeKey]));
        if ($existing && (string)$existing['status'] === 'active') return $existing;
        $now = time();
        $row = [
            'application_id' => (int)$application['id'], 'target_uid' => $uid,
            'store_id' => $storeId, 'acceptance_id' => (int)$acceptance['id'],
            'role_code' => 'county_partner', 'store_role_id' => 0, 'status' => 'active',
            'grant_time' => $now, 'revoke_time' => 0, 'grant_uid' => $adminId, 'revoke_uid' => 0,
            'reason' => $reason ?: 'formal_franchise_opening', 'active_key' => $activeKey,
            'create_time' => $now, 'update_time' => $now,
        ];
        $saved = $grantDao->save($row);
        $row['id'] = (int)$saved->id;
        $this->audit('franchise_identity_grant', (int)$row['id'], 'partner_grant', [], $row, $adminId, 'headquarter_operator', $storeId, $row['reason']);
        return $row;
    }

    private function activateStoreRoleGrant(array $application, array $acceptance, int $storeId, string $roleCode, int $adminId, string $reason): array
    {
        $uid = (int)$application['applicant_uid'];
        $grantDao = app()->make(YfthFranchiseIdentityGrantDao::class);
        $activeKey = $uid . ':' . $storeId . ':' . $roleCode;
        $existing = $this->rowArray($grantDao->getOne(['active_key' => $activeKey]));
        if ($existing && (string)$existing['status'] === 'active') {
            return $existing;
        }
        $storeRole = app()->make(UserStoreRoleServices::class)->getActiveRole($uid, $storeId, $roleCode);
        if (!$storeRole) {
            $storeRole = app()->make(UserStoreRoleServices::class)->saveRole([
                'uid' => $uid,
                'store_id' => $storeId,
                'role_code' => $roleCode,
                'permission_scope' => ['source' => 'franchise_opening'],
                'status' => YfthConstants::STATUS_ACTIVE,
                'start_time' => time(),
                'end_time' => 0,
                'inviter_uid' => 0,
                'creator_uid' => $adminId,
            ]);
        }
        $storeRole = $this->rowArray($storeRole);
        $now = time();
        $grant = $grantDao->save([
            'application_id' => (int)$application['id'],
            'acceptance_id' => (int)$acceptance['id'],
            'target_uid' => $uid,
            'store_id' => $storeId,
            'role_code' => $roleCode,
            'store_role_id' => (int)$storeRole['id'],
            'status' => 'active',
            'grant_uid' => $adminId,
            'grant_time' => $now,
            'revoke_uid' => 0,
            'revoke_time' => 0,
            'reason' => $reason,
            'active_key' => $activeKey,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $grant = $this->rowArray($grant);
        $this->audit('franchise_identity_grant', (int)$grant['id'], 'identity_grant', [], $grant, $adminId, 'headquarter_operator', $storeId, $reason ?: 'admin_identity_grant');
        return $grant;
    }

    private function enableOpeningCapabilities(int $storeId, int $adminId): void
    {
        $dao = app()->make(YfthStoreCapabilityDao::class);
        foreach (self::OPENING_CAPABILITIES as $code) {
            $activeKey = $storeId . ':' . $code;
            if ($dao->getOne(['active_key' => $activeKey])) {
                continue;
            }
            $now = time();
            $row = $dao->save([
                'store_id' => $storeId,
                'capability_code' => $code,
                'source_qualification_id' => 0,
                'source_authorization' => 'franchise_opening',
                'status' => YfthConstants::STATUS_ACTIVE,
                'effective_time' => $now,
                'expire_time' => 0,
                'close_reason' => '',
                'active_key' => $activeKey,
                'add_time' => $now,
                'update_time' => $now,
            ]);
            $row = $this->rowArray($row);
            $this->audit('store_capability', (int)$row['id'], 'opening_capability_enable', [], $row, $adminId, 'headquarter_operator', $storeId, 'franchise_opening');
        }
    }

    private function openingSummary(array $application, bool $admin): array
    {
        $applicationId = (int)$application['id'];
        return [
            'application' => $this->formatApplication($application, $admin),
            'contract' => $this->formatContract($this->latestContract($applicationId), $admin),
            'payment' => $this->formatPayment($this->latestPayment($applicationId), $admin),
            'store_profile' => $this->formatProfile($this->latestProfile($applicationId), $admin),
            'tasks' => $this->tasksForApplication($applicationId, $admin),
            'acceptance' => $this->formatAcceptance($this->latestAcceptance($applicationId), $admin),
            'identity_grants' => $this->grantsForApplication($applicationId),
        ];
    }

    private function ensurePaymentProof(int $applicationId, int $contractId, string $amount): array
    {
        $dao = app()->make(YfthFranchisePaymentProofDao::class);
        $existing = $this->rowArray($dao->getOne(['application_id' => $applicationId]));
        if ($existing) {
            return $existing;
        }
        $now = time();
        return $this->rowArray($dao->save([
            'application_id' => $applicationId,
            'contract_id' => $contractId,
            'amount_snapshot' => $amount,
            'attachment_ids' => '',
            'status' => 'pending_upload',
            'finance_uid' => 0,
            'finance_time' => 0,
            'reject_reason' => '',
            'create_time' => $now,
            'update_time' => $now,
        ]));
    }

    private function ensureStoreProfile(int $applicationId, int $contractId): array
    {
        $dao = app()->make(YfthFranchiseStoreProfileDao::class);
        $existing = $this->rowArray($dao->getOne(['application_id' => $applicationId]));
        if ($existing) {
            return $existing;
        }
        $application = $this->requireApplication($applicationId);
        $now = time();
        return $this->rowArray($dao->save([
            'application_id' => $applicationId,
            'contract_id' => $contractId,
            'intended_store_type' => '',
            'store_name' => (string)($application['city'] ?? '') . ' YFTH Store',
            'province' => '',
            'city' => (string)($application['city'] ?? ''),
            'district' => (string)($application['region'] ?? ''),
            'address' => (string)($application['intention_area'] ?? ''),
            'business_subject_id' => 0,
            'system_store_id' => 0,
            'status' => 'draft',
            'create_time' => $now,
            'update_time' => $now,
        ]));
    }

    private function ensurePreparationTasks(int $applicationId): void
    {
        $profile = $this->ensureStoreProfile($applicationId, (int)($this->latestContract($applicationId)['id'] ?? 0));
        $dao = app()->make(YfthFranchisePreparationTaskDao::class);
        $now = time();
        foreach (self::TASK_TEMPLATES as $template) {
            if ($dao->getOne(['application_id' => $applicationId, 'task_code' => $template['code']])) {
                continue;
            }
            try {
                $dao->save([
                    'application_id' => $applicationId,
                    'store_profile_id' => (int)$profile['id'],
                    'task_code' => $template['code'],
                    'task_name' => $template['name'],
                    'required_flag' => (int)$template['required'],
                    'owner_type' => $template['owner'],
                    'status' => 'pending',
                    'purchase_order_id' => 0,
                    'verified_uid' => 0,
                    'verified_time' => 0,
                    'reject_reason' => '',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (\Throwable $e) {
                if (!$dao->getOne(['application_id' => $applicationId, 'task_code' => $template['code']])) {
                    throw $e;
                }
            }
        }
    }

    private function ensureAcceptanceForSubmit(int $applicationId, array $contract, array $profile): array
    {
        $dao = app()->make(YfthStoreOpeningAcceptanceDao::class);
        $existing = $this->rowArray($dao->getOne(['application_id' => $applicationId]));
        if ($existing) {
            return $existing;
        }
        $now = time();
        $row = $this->rowArray($dao->save([
            'application_id' => $applicationId,
            'contract_id' => (int)($contract['id'] ?? 0),
            'store_profile_id' => (int)$profile['id'],
            'system_store_id' => (int)$profile['system_store_id'],
            'status' => 'draft',
            'reviewer_uid' => 0,
            'review_time' => 0,
            'reject_reason' => '',
            'create_time' => $now,
            'update_time' => $now,
        ]));
        $this->ensureAcceptanceItems((int)$row['id']);
        return $row;
    }

    private function assertAcceptanceSubmitReady(int $applicationId): array
    {
        $this->ensurePreparationTasks($applicationId);
        $application = $this->requireApplication($applicationId);
        if ((string)$application['status'] !== 'preparing') {
            throw new ApiException('franchise_acceptance_application_not_preparing');
        }
        $contract = $this->latestContract($applicationId);
        if (!$contract || (string)$contract['status'] !== 'signed') {
            throw new ApiException('franchise_acceptance_contract_not_signed');
        }
        $payment = $this->latestPayment($applicationId);
        if (!$payment || (string)$payment['status'] !== 'finance_confirmed') {
            throw new ApiException('franchise_acceptance_payment_not_confirmed');
        }
        $profile = $this->latestProfile($applicationId);
        if (!$profile) {
            throw new ApiException('franchise_acceptance_store_profile_required');
        }
        if (!$this->allRequiredTasksApprovedStrict($applicationId)) {
            throw new ApiException('franchise_required_tasks_not_approved');
        }
        return [
            'application' => $application,
            'contract' => $contract,
            'payment' => $payment,
            'profile' => $profile,
        ];
    }

    private function assertAcceptancePassReady(int $applicationId): array
    {
        $gate = $this->assertAcceptanceSubmitReady($applicationId);
        $profile = $gate['profile'];
        if (!in_array((string)($profile['status'] ?? ''), ['verified', 'bound'], true)) {
            throw new ApiException('franchise_acceptance_store_profile_not_verified');
        }
        $storeId = (int)($profile['system_store_id'] ?? 0);
        if ($storeId > 0) {
            app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        }
        return $gate;
    }

    private function ensureAcceptanceItems(int $acceptanceId): void
    {
        $dao = app()->make(YfthStoreOpeningAcceptanceItemDao::class);
        foreach (self::ACCEPTANCE_ITEMS as $item) {
            if ($dao->getOne(['acceptance_id' => $acceptanceId, 'item_code' => $item['code']])) {
                continue;
            }
            $dao->save([
                'acceptance_id' => $acceptanceId,
                'item_code' => $item['code'],
                'item_name' => $item['name'],
                'result' => 'pending',
                'evidence_attachment_ids' => '',
                'reviewer_uid' => 0,
                'review_time' => 0,
                'remark' => '',
            ]);
        }
    }

    private function markAcceptanceItemsPassed(int $acceptanceId, int $adminId): void
    {
        $this->ensureAcceptanceItems($acceptanceId);
        app()->make(YfthStoreOpeningAcceptanceItemDao::class)->search([])
            ->where('acceptance_id', $acceptanceId)
            ->update([
                'result' => 'pass',
                'reviewer_uid' => $adminId,
                'review_time' => time(),
            ]);
    }

    private function validateFirstPurchaseTask(array $task, array $data): void
    {
        if ((string)$task['task_code'] !== 'first_purchase') {
            return;
        }
        $purchaseOrderId = (int)($data['purchase_order_id'] ?? $task['purchase_order_id']);
        if ($purchaseOrderId <= 0) {
            throw new ApiException('franchise_first_purchase_order_required');
        }
        $profile = $this->latestProfile((int)$task['application_id']);
        $storeId = (int)($profile['system_store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new ApiException('franchise_first_purchase_store_not_bound');
        }
        $order = Db::name('yfth_purchase_order')->where('id', $purchaseOrderId)->where('store_id', $storeId)->find();
        if (!$order || (string)$order['status'] !== 'stocked') {
            throw new ApiException('franchise_first_purchase_not_stocked');
        }
    }

    private function expectedRequiredTaskCodes(): array
    {
        $codes = [];
        foreach (self::TASK_TEMPLATES as $template) {
            if ((int)$template['required'] === 1) {
                $codes[] = (string)$template['code'];
            }
        }
        return $codes;
    }

    private function requiredTasksGeneratedForApplication(int $applicationId): array
    {
        return app()->make(YfthFranchisePreparationTaskDao::class)->search([])
            ->where('application_id', $applicationId)
            ->where('required_flag', 1)
            ->select()
            ->toArray();
    }

    private function allRequiredTasksApprovedStrict(int $applicationId): bool
    {
        $expectedCodes = $this->expectedRequiredTaskCodes();
        if (!$expectedCodes) {
            return false;
        }
        $rows = $this->requiredTasksGeneratedForApplication($applicationId);
        if (count($rows) !== count($expectedCodes)) {
            return false;
        }
        $seen = [];
        foreach ($rows as $row) {
            $code = (string)($row['task_code'] ?? '');
            if (!in_array($code, $expectedCodes, true) || isset($seen[$code])) {
                return false;
            }
            if ((string)($row['status'] ?? '') !== 'approved') {
                return false;
            }
            if ($code === 'first_purchase') {
                $this->validateFirstPurchaseTask($row, []);
            }
            $seen[$code] = true;
        }
        foreach ($expectedCodes as $code) {
            if (!isset($seen[$code])) {
                return false;
            }
        }
        return true;
    }

    private function advanceApplication(int $applicationId, string $targetStatus): void
    {
        $application = $this->requireApplication($applicationId);
        $current = (string)$application['status'];
        $allowed = [
            'pending_contract' => ['signed'],
            'signed' => ['preparing'],
            'preparing' => ['opened'],
        ];
        if ($current === $targetStatus) {
            return;
        }
        if (!in_array($targetStatus, $allowed[$current] ?? [], true)) {
            throw new ApiException('franchise_application_opening_transition_forbidden');
        }
        app()->make(YfthFranchiseApplicationDao::class)->update($applicationId, [
            'status' => $targetStatus,
            'update_time' => time(),
        ]);
        $after = $application;
        $after['status'] = $targetStatus;
        $this->audit('franchise_application', $applicationId, 'opening_status_change', $application, $after, 0, 'system', 0, 'franchise_opening');
    }

    private function taskRecord(int $taskId, int $applicationId, string $operatorType, int $operatorUid, string $action, string $content, string $attachments): void
    {
        app()->make(YfthFranchisePreparationTaskRecordDao::class)->save([
            'task_id' => $taskId,
            'application_id' => $applicationId,
            'operator_type' => $operatorType,
            'operator_uid' => $operatorUid,
            'action' => $action,
            'content' => $content,
            'attachment_ids' => $attachments,
            'create_time' => time(),
        ]);
    }

    private function assertHeadquarterAdmin(array $adminInfo): void
    {
        if (!$adminInfo || (int)($adminInfo['id'] ?? 0) <= 0) {
            throw new ApiException('headquarter_admin_required');
        }
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
    }

    private function assertApplicantOwns(int $applicationId, int $uid): void
    {
        $application = $this->requireApplication($applicationId);
        if ((int)$application['applicant_uid'] !== $uid) {
            throw new ApiException('franchise_opening_not_found');
        }
    }

    private function assertNoForbiddenUserFields(array $data): void
    {
        foreach (['uid', 'applicant_uid', 'status', 'store_id', 'system_store_id', 'finance_uid', 'verified_uid', 'reviewer_uid', 'grant_uid'] as $field) {
            if (array_key_exists($field, $data)) {
                throw new ApiException('franchise_opening_user_field_forbidden');
            }
        }
    }

    private function requestUid(Request $request): int
    {
        $uid = (int)$request->uid();
        if ($uid <= 0) {
            throw new ApiException('user_not_login');
        }
        return $uid;
    }

    private function requireLatestOpeningApplication(int $uid): array
    {
        $row = app()->make(YfthFranchiseApplicationDao::class)->search([])
            ->where('applicant_uid', $uid)
            ->whereIn('status', ['pending_contract', 'signed', 'preparing', 'opened'])
            ->order('id desc')
            ->find();
        if (!$row) {
            throw new ApiException('franchise_opening_not_found');
        }
        return $this->rowArray($row);
    }

    private function requireApplication(int $id): array
    {
        $row = app()->make(YfthFranchiseApplicationDao::class)->get($id);
        if (!$row) {
            throw new ApiException('franchise_application_not_found');
        }
        return $this->rowArray($row);
    }

    private function lockApplication(int $id): array
    {
        $row = Db::name('yfth_franchise_application')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('franchise_application_not_found');
        }
        return $row;
    }

    private function requireContract(int $id): array
    {
        $row = $this->dao->get($id);
        if (!$row) {
            throw new ApiException('franchise_contract_not_found');
        }
        return $this->rowArray($row);
    }

    private function lockContract(int $id): array
    {
        $row = Db::name('yfth_franchise_contract')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('franchise_contract_not_found');
        }
        return $row;
    }

    private function lockPayment(int $id): array
    {
        $row = Db::name('yfth_franchise_payment_proof')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('franchise_payment_not_found');
        }
        return $row;
    }

    private function lockTask(int $id): array
    {
        $row = Db::name('yfth_franchise_preparation_task')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('franchise_task_not_found');
        }
        return $row;
    }

    private function requireProfile(int $id): array
    {
        $row = app()->make(YfthFranchiseStoreProfileDao::class)->get($id);
        if (!$row) {
            throw new ApiException('franchise_store_profile_not_found');
        }
        return $this->rowArray($row);
    }

    private function requireAcceptance(int $id): array
    {
        $row = app()->make(YfthStoreOpeningAcceptanceDao::class)->get($id);
        if (!$row) {
            throw new ApiException('franchise_acceptance_not_found');
        }
        return $this->rowArray($row);
    }

    private function lockAcceptance(int $id): array
    {
        $row = Db::name('yfth_store_opening_acceptance')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('franchise_acceptance_not_found');
        }
        return $row;
    }

    private function latestContract(int $applicationId): array
    {
        return $this->rowArray($this->dao->getOne(['application_id' => $applicationId]));
    }

    private function latestPayment(int $applicationId): array
    {
        return $this->rowArray(app()->make(YfthFranchisePaymentProofDao::class)->getOne(['application_id' => $applicationId]));
    }

    private function latestProfile(int $applicationId): array
    {
        return $this->rowArray(app()->make(YfthFranchiseStoreProfileDao::class)->getOne(['application_id' => $applicationId]));
    }

    private function latestAcceptance(int $applicationId): array
    {
        return $this->rowArray(app()->make(YfthStoreOpeningAcceptanceDao::class)->getOne(['application_id' => $applicationId]));
    }

    private function tasksForApplication(int $applicationId, bool $admin = false): array
    {
        $rows = app()->make(YfthFranchisePreparationTaskDao::class)->search([])
            ->where('application_id', $applicationId)
            ->order('id asc')
            ->select()
            ->toArray();
        return array_map(function ($row) use ($admin) {
            return $this->formatTask($row, $admin);
        }, $rows);
    }

    private function grantsForApplication(int $applicationId): array
    {
        $rows = app()->make(YfthFranchiseIdentityGrantDao::class)->search([])
            ->where('application_id', $applicationId)
            ->order('id asc')
            ->select()
            ->toArray();
        return array_map([$this, 'formatGrant'], $rows);
    }

    private function normalizeProfilePayload(array $data, array $before): array
    {
        $payload = [
            'intended_store_type' => trim((string)($data['intended_store_type'] ?? $before['intended_store_type'] ?? '')),
            'store_name' => trim((string)($data['store_name'] ?? $before['store_name'] ?? '')),
            'province' => trim((string)($data['province'] ?? $before['province'] ?? '')),
            'city' => trim((string)($data['city'] ?? $before['city'] ?? '')),
            'district' => trim((string)($data['district'] ?? $before['district'] ?? '')),
            'address' => trim((string)($data['address'] ?? $before['address'] ?? '')),
            'business_subject_id' => (int)($data['business_subject_id'] ?? $before['business_subject_id'] ?? 0),
            'status' => $this->normalizeStatus((string)($data['status'] ?? $before['status'] ?? 'draft'), self::PROFILE_STATUSES, 'draft'),
        ];
        if ($payload['store_name'] === '') {
            throw new ApiException('franchise_store_profile_name_required');
        }
        return $payload;
    }

    private function syncAcceptanceStore(int $applicationId, int $systemStoreId): void
    {
        $acceptance = $this->latestAcceptance($applicationId);
        if (!$acceptance) {
            return;
        }
        app()->make(YfthStoreOpeningAcceptanceDao::class)->update((int)$acceptance['id'], [
            'system_store_id' => $systemStoreId,
            'update_time' => time(),
        ]);
    }

    private function formatApplication(array $row, bool $admin): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'application_no' => (string)($row['application_no'] ?? ''),
            'applicant_uid' => $admin ? (int)($row['applicant_uid'] ?? 0) : 0,
            'name' => (string)($row['name'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
            'city' => (string)($row['city'] ?? ''),
            'region' => (string)($row['region'] ?? ''),
            'intention_area' => (string)($row['intention_area'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->statusText((string)($row['status'] ?? '')),
        ];
    }

    private function formatContract(array $row = [], bool $admin = false): array
    {
        if (!$row) {
            return [];
        }
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'application_id' => (int)($row['application_id'] ?? 0),
            'contract_no' => (string)($row['contract_no'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->statusText((string)($row['status'] ?? '')),
            'amount_snapshot' => (string)($row['amount_snapshot'] ?? '0.00'),
            'attachment_ids' => $this->jsonDecode((string)($row['attachment_ids'] ?? '')),
            'signed_time' => (int)($row['signed_time'] ?? 0),
        ];
        if ($admin) {
            $payload['applicant_uid'] = (int)($row['applicant_uid'] ?? 0);
            $payload['operator_uid'] = (int)($row['operator_uid'] ?? 0);
            $payload['create_time'] = (int)($row['create_time'] ?? 0);
            $payload['update_time'] = (int)($row['update_time'] ?? 0);
        }
        return $payload;
    }

    private function formatPayment(array $row = [], bool $admin = false): array
    {
        if (!$row) {
            return [];
        }
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'application_id' => (int)($row['application_id'] ?? 0),
            'contract_id' => (int)($row['contract_id'] ?? 0),
            'amount_snapshot' => (string)($row['amount_snapshot'] ?? '0.00'),
            'attachment_ids' => $this->jsonDecode((string)($row['attachment_ids'] ?? '')),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->statusText((string)($row['status'] ?? '')),
            'reject_reason' => (string)($row['reject_reason'] ?? ''),
            'finance_time' => (int)($row['finance_time'] ?? 0),
        ];
        if ($admin) {
            $payload['finance_uid'] = (int)($row['finance_uid'] ?? 0);
            $payload['create_time'] = (int)($row['create_time'] ?? 0);
            $payload['update_time'] = (int)($row['update_time'] ?? 0);
        }
        return $payload;
    }

    private function formatProfile(array $row = [], bool $admin = false): array
    {
        if (!$row) {
            return [];
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'application_id' => (int)($row['application_id'] ?? 0),
            'contract_id' => (int)($row['contract_id'] ?? 0),
            'intended_store_type' => (string)($row['intended_store_type'] ?? ''),
            'store_name' => (string)($row['store_name'] ?? ''),
            'province' => (string)($row['province'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'district' => (string)($row['district'] ?? ''),
            'address' => (string)($row['address'] ?? ''),
            'business_subject_id' => (int)($row['business_subject_id'] ?? 0),
            'system_store_id' => (int)($row['system_store_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->statusText((string)($row['status'] ?? '')),
        ];
    }

    private function formatTask(array $row, bool $admin): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'application_id' => (int)($row['application_id'] ?? 0),
            'task_code' => (string)($row['task_code'] ?? ''),
            'task_name' => (string)($row['task_name'] ?? ''),
            'required_flag' => (int)($row['required_flag'] ?? 0),
            'owner_type' => (string)($row['owner_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->statusText((string)($row['status'] ?? '')),
            'purchase_order_id' => (int)($row['purchase_order_id'] ?? 0),
            'reject_reason' => (string)($row['reject_reason'] ?? ''),
        ];
        if ($admin) {
            $payload['store_profile_id'] = (int)($row['store_profile_id'] ?? 0);
            $payload['verified_uid'] = (int)($row['verified_uid'] ?? 0);
            $payload['verified_time'] = (int)($row['verified_time'] ?? 0);
        }
        return $payload;
    }

    private function pendingAcceptanceDto(int $applicationId): array
    {
        return [
            'id' => 0,
            'application_id' => $applicationId,
            'contract_id' => 0,
            'store_profile_id' => 0,
            'system_store_id' => 0,
            'status' => 'not_started',
            'status_text' => $this->statusText('not_started'),
            'reviewer_uid' => 0,
            'review_time' => 0,
            'reject_reason' => '',
            'submit_allowed' => false,
            'items' => [],
        ];
    }

    private function formatAcceptance(array $row = [], bool $admin = false): array
    {
        if (!$row) {
            return [];
        }
        $items = app()->make(YfthStoreOpeningAcceptanceItemDao::class)->search([])
            ->where('acceptance_id', (int)$row['id'])
            ->order('id asc')
            ->select()
            ->toArray();
        return [
            'id' => (int)($row['id'] ?? 0),
            'application_id' => (int)($row['application_id'] ?? 0),
            'contract_id' => (int)($row['contract_id'] ?? 0),
            'store_profile_id' => (int)($row['store_profile_id'] ?? 0),
            'system_store_id' => (int)($row['system_store_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->statusText((string)($row['status'] ?? '')),
            'reviewer_uid' => $admin ? (int)($row['reviewer_uid'] ?? 0) : 0,
            'review_time' => (int)($row['review_time'] ?? 0),
            'reject_reason' => (string)($row['reject_reason'] ?? ''),
            'items' => $items,
        ];
    }

    private function formatGrant(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'application_id' => (int)($row['application_id'] ?? 0),
            'acceptance_id' => (int)($row['acceptance_id'] ?? 0),
            'target_uid' => (int)($row['target_uid'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'role_code' => (string)($row['role_code'] ?? ''),
            'store_role_id' => (int)($row['store_role_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'grant_time' => (int)($row['grant_time'] ?? 0),
        ];
    }

    private function normalizeStatus(string $status, array $allowed, string $default): string
    {
        $status = trim($status);
        return in_array($status, $allowed, true) ? $status : $default;
    }

    private function normalizeAmount($amount): string
    {
        if (!is_numeric($amount) || (float)$amount < 0 || (float)$amount > 99999999) {
            throw new ApiException('franchise_opening_amount_invalid');
        }
        return sprintf('%.2f', (float)$amount);
    }

    private function normalizeAttachmentIds($value): string
    {
        if (is_array($value)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $value))));
            return $ids ? json_encode($ids, JSON_UNESCAPED_UNICODE) : '';
        }
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode(array_values(array_unique(array_filter(array_map('intval', $decoded)))), JSON_UNESCAPED_UNICODE);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
        return $ids ? json_encode($ids, JSON_UNESCAPED_UNICODE) : '';
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function rowArray($row): array
    {
        if (!$row) {
            return [];
        }
        return is_array($row) ? $row : $row->toArray();
    }

    private function audit(string $objectType, int $objectId, string $action, array $before, array $after, int $operatorUid, string $roleCode, int $storeId, string $reason): void
    {
        app()->make(AuditEventServices::class)->recordSafely(
            self::DOMAIN,
            $objectType,
            (string)$objectId,
            $action,
            $this->sanitizeState($before),
            $this->sanitizeState($after),
            $operatorUid,
            $roleCode,
            $storeId,
            $reason,
            ''
        );
    }

    private function statusText(string $status): string
    {
        $map = [
            'pending_contract' => 'Pending contract',
            'signed' => 'Signed',
            'preparing' => 'Preparing',
            'opened' => 'Opened',
            'not_started' => 'Not started',
            'draft' => 'Draft',
            'pending_user_confirm' => 'Pending user confirm',
            'user_confirmed' => 'User confirmed',
            'hq_confirmed' => 'Headquarters confirmed',
            'pending_upload' => 'Pending upload',
            'uploaded' => 'Uploaded',
            'finance_confirmed' => 'Finance confirmed',
            'rejected' => 'Rejected',
            'submitted' => 'Submitted',
            'verified' => 'Verified',
            'bound' => 'Bound',
            'pending' => 'Pending',
            'in_progress' => 'In progress',
            'approved' => 'Approved',
            'reviewing' => 'Reviewing',
            'passed' => 'Passed',
            'recheck_required' => 'Recheck required',
            'active' => 'Active',
            'revoked' => 'Revoked',
        ];
        return $map[$status] ?? $status;
    }
}
