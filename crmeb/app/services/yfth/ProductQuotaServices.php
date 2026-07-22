<?php

namespace app\services\yfth;

use app\Request;
use app\dao\yfth\YfthProductQuotaAccountDao;
use app\dao\yfth\YfthProductQuotaAdjustmentDao;
use app\dao\yfth\YfthProductQuotaGrantOrderDao;
use app\dao\yfth\YfthProductQuotaLedgerDao;
use app\dao\yfth\YfthProductQuotaSourceSnapshotDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class ProductQuotaServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_product_quota';
    private const DEFAULT_QUOTA_TYPE = 'return_goods';
    private const ACCOUNT_STATUSES = ['active', 'frozen', 'closed'];
    private const GRANT_STATUSES = ['draft', 'confirmed', 'rejected', 'reversed'];
    private const USER_READ_ROLES = ['store_manager', 'county_partner', 'prefecture_partner', 'province_partner', 'regional_director', 'platform_director'];
    private const USER_FORBIDDEN_FIELDS = [
        'amount',
        'amount_cent',
        'balance',
        'balance_cent',
        'available_cent',
        'reserved_cent',
        'consumed_cent',
        'frozen_cent',
        'status',
        'total_granted_cent',
        'total_adjusted_cent',
        'total_reversed_cent',
        'balance_before_cent',
        'balance_after_cent',
        'source_id',
        'idempotency_key',
        'operator_uid',
        'operator_type',
        'operator_role_code',
        'uid',
        'owner_uid',
        'reason',
        'before_state',
        'after_state',
        'snapshot_json',
    ];
    private const GRANT_SOURCE_TYPES = ['headquarters_manual_grant', 'franchise_opening_initial_quota'];
    private const RESERVED_SOURCE_TYPES = ['referral_reward_converted', 'purchase_after_sale_return'];

    public function __construct(YfthProductQuotaAccountDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminAccountList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $query = $this->accountQuery($where);
        return $this->paginateQuery($query, function ($row) {
            return $this->formatAccount($row, true);
        });
    }

    public function adminAccountDetail(int $id, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $account = $this->requireAccount($id);
        return [
            'account' => $this->formatAccount($account, true),
            'recent_ledgers' => $this->recentLedgers((int)$account['id'], true),
            'recent_grants' => $this->recentGrants((int)$account['id']),
            'recent_adjustments' => $this->recentAdjustments((int)$account['id']),
        ];
    }

    public function adminLedgerList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $query = app()->make(YfthProductQuotaLedgerDao::class)->search([]);
        foreach (['store_id', 'account_id'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (int)$where[$field]);
            }
        }
        foreach (['quota_type', 'direction', 'action_type', 'source_type', 'status'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (string)$where[$field]);
            }
        }
        return $this->paginateQuery($query, function ($row) {
            return $this->formatLedger($row, true);
        });
    }

    public function adminGrantList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $query = app()->make(YfthProductQuotaGrantOrderDao::class)->search([]);
        foreach (['store_id', 'account_id'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (int)$where[$field]);
            }
        }
        foreach (['quota_type', 'status', 'source_type'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (string)$where[$field]);
            }
        }
        return $this->paginateQuery($query, function ($row) {
            return $this->formatGrant($row, true);
        });
    }

    public function adminCreateGrant(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $storeId = (int)($data['store_id'] ?? 0);
        $quotaType = $this->normalizeQuotaType((string)($data['quota_type'] ?? self::DEFAULT_QUOTA_TYPE));
        $amount = $this->positiveCent($data['amount_cent'] ?? 0, 'product_quota_grant_amount_invalid');
        $reason = $this->requiredReason($data['reason'] ?? '', 'product_quota_grant_reason_required');
        $sourceType = $this->normalizeGrantSource((string)($data['source_type'] ?? 'headquarters_manual_grant'));
        $sourceId = (int)($data['source_id'] ?? 0);
        $idempotencyKey = $this->normalizeOperationKey($data, 'idempotency_key', 'product_quota_grant_create', $adminId, 'product_quota_idempotency_key_required');

        return Db::transaction(function () use ($storeId, $quotaType, $amount, $reason, $sourceType, $sourceId, $adminId, $idempotencyKey) {
            $account = $this->ensureAccount($storeId, $quotaType, $adminId, 'grant_create');
            $expectedPayload = [
                'account_id' => (int)$account['id'],
                'store_id' => $storeId,
                'quota_type' => $quotaType,
                'amount_cent' => $amount,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => $reason,
            ];
            $existing = $this->findGrantByIdempotencyKey($idempotencyKey);
            if ($existing) {
                $this->assertGrantIdempotentPayload($existing, $expectedPayload);
                return ['grant' => $this->formatGrant($existing, true)];
            }
            if ((string)$account['status'] !== 'active') {
                throw new ApiException('product_quota_account_amount_change_forbidden');
            }
            $this->validateGrantSource($sourceType, $sourceId, $storeId);
            $now = time();
            $grantData = [
                'grant_no' => $this->makeNo('PQG'),
                'account_id' => $expectedPayload['account_id'],
                'store_id' => $storeId,
                'quota_type' => $quotaType,
                'amount_cent' => $amount,
                'status' => 'draft',
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => $reason,
                'applicant_admin_id' => $adminId,
                'confirm_admin_id' => 0,
                'reject_admin_id' => 0,
                'reverse_admin_id' => 0,
                'confirmed_time' => 0,
                'rejected_time' => 0,
                'reversed_time' => 0,
                'idempotency_key' => $idempotencyKey,
                'create_time' => $now,
                'update_time' => $now,
            ];
            try {
                $grant = $this->rowArray(app()->make(YfthProductQuotaGrantOrderDao::class)->save($grantData));
            } catch (\Throwable $e) {
                $existing = $this->findGrantByIdempotencyKey($idempotencyKey);
                if ($existing) {
                    $this->assertGrantIdempotentPayload($existing, $expectedPayload);
                    return ['grant' => $this->formatGrant($existing, true)];
                }
                throw $e;
            }
            $this->writeSnapshot((int)$account['id'], 0, (int)$grant['id'], 0, $sourceType, $sourceId, [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'store_id' => $storeId,
                'quota_type' => $quotaType,
                'amount_cent' => $amount,
            ]);
            $this->audit('product_quota_grant_order', (int)$grant['id'], 'grant_create', [], $grant, $adminId, 'headquarter_operator', $storeId, $reason);
            return ['grant' => $this->formatGrant($grant, true)];
        });
    }

    public function adminConfirmGrant(int $id, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $adminId) {
            $grant = $this->lockGrant($id);
            if ((string)$grant['status'] === 'confirmed') {
                return ['grant' => $this->formatGrant($grant, true), 'account' => $this->formatAccount($this->requireAccount((int)$grant['account_id']), true)];
            }
            if ((string)$grant['status'] !== 'draft') {
                throw new ApiException('product_quota_grant_confirm_status_invalid');
            }
            $account = $this->lockAccount((int)$grant['account_id']);
            $this->assertAccountAmountWritable($account);
            $this->validateGrantSource((string)$grant['source_type'], (int)$grant['source_id'], (int)$grant['store_id']);
            $before = $account;
            $afterBalance = (int)$account['available_cent'] + (int)$grant['amount_cent'];
            $now = time();
            $ledger = $this->createLedger($account, 'in', 'headquarters_manual_grant', (int)$grant['amount_cent'], (int)$account['available_cent'], $afterBalance, (string)$grant['source_type'], (int)$grant['source_id'], 'product_quota_grant_confirm:' . $id, $adminId, (string)$grant['reason']);
            $accountAfter = $this->updateAccountTotals((int)$account['id'], [
                'total_granted_cent' => (int)$account['total_granted_cent'] + (int)$grant['amount_cent'],
                'available_cent' => $afterBalance,
                'version' => (int)$account['version'] + 1,
                'update_time' => $now,
            ], $account);
            app()->make(YfthProductQuotaGrantOrderDao::class)->update($id, [
                'status' => 'confirmed',
                'confirm_admin_id' => $adminId,
                'confirmed_time' => $now,
                'update_time' => $now,
            ]);
            $grantAfter = array_merge($grant, [
                'status' => 'confirmed',
                'confirm_admin_id' => $adminId,
                'confirmed_time' => $now,
                'update_time' => $now,
            ]);
            $this->writeSnapshot((int)$account['id'], (int)$ledger['id'], $id, 0, (string)$grant['source_type'], (int)$grant['source_id'], [
                'grant_order' => $grantAfter,
                'ledger' => $ledger,
            ]);
            $this->audit('product_quota_grant_order', $id, 'grant_confirm', $grant, $grantAfter, $adminId, 'headquarter_finance', (int)$grant['store_id'], (string)$grant['reason']);
            $this->audit('product_quota_account', (int)$account['id'], 'account_amount_change', $before, $accountAfter, $adminId, 'headquarter_finance', (int)$account['store_id'], (string)$grant['reason']);
            return ['grant' => $this->formatGrant($grantAfter, true), 'account' => $this->formatAccount($accountAfter, true), 'ledger' => $this->formatLedger($ledger, true)];
        });
    }

    public function adminRejectGrant(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $data, $adminId) {
            $grant = $this->lockGrant($id);
            if ((string)$grant['status'] === 'rejected') {
                return ['grant' => $this->formatGrant($grant, true)];
            }
            if ((string)$grant['status'] !== 'draft') {
                throw new ApiException('product_quota_grant_reject_status_invalid');
            }
            $reason = $this->requiredReason($data['reason'] ?? '', 'product_quota_grant_reject_reason_required');
            $now = time();
            $after = array_merge($grant, [
                'status' => 'rejected',
                'reject_admin_id' => $adminId,
                'rejected_time' => $now,
                'reason' => $reason,
                'update_time' => $now,
            ]);
            app()->make(YfthProductQuotaGrantOrderDao::class)->update($id, [
                'status' => 'rejected',
                'reject_admin_id' => $adminId,
                'rejected_time' => $now,
                'reason' => $reason,
                'update_time' => $now,
            ]);
            $this->audit('product_quota_grant_order', $id, 'grant_reject', $grant, $after, $adminId, 'headquarter_operator', (int)$grant['store_id'], $reason);
            return ['grant' => $this->formatGrant($after, true)];
        });
    }

    public function adminReverseGrant(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $data, $adminId) {
            $grant = $this->lockGrant($id);
            if ((string)$grant['status'] === 'reversed') {
                return ['grant' => $this->formatGrant($grant, true), 'account' => $this->formatAccount($this->requireAccount((int)$grant['account_id']), true)];
            }
            if ((string)$grant['status'] !== 'confirmed') {
                throw new ApiException('product_quota_grant_reverse_status_invalid');
            }
            $reason = $this->requiredReason($data['reason'] ?? '', 'product_quota_grant_reverse_reason_required');
            $account = $this->lockAccount((int)$grant['account_id']);
            $this->assertAccountAmountWritable($account);
            if ((int)$account['available_cent'] < (int)$grant['amount_cent']) {
                throw new ApiException('product_quota_available_not_enough');
            }
            $before = $account;
            $afterBalance = (int)$account['available_cent'] - (int)$grant['amount_cent'];
            $now = time();
            $ledger = $this->createLedger($account, 'out', 'reverse_grant', (int)$grant['amount_cent'], (int)$account['available_cent'], $afterBalance, 'product_quota_grant_order', $id, 'product_quota_grant_reverse:' . $id, $adminId, $reason);
            $accountAfter = $this->updateAccountTotals((int)$account['id'], [
                'total_reversed_cent' => (int)$account['total_reversed_cent'] + (int)$grant['amount_cent'],
                'available_cent' => $afterBalance,
                'version' => (int)$account['version'] + 1,
                'update_time' => $now,
            ], $account);
            app()->make(YfthProductQuotaGrantOrderDao::class)->update($id, [
                'status' => 'reversed',
                'reverse_admin_id' => $adminId,
                'reversed_time' => $now,
                'update_time' => $now,
            ]);
            $grantAfter = array_merge($grant, [
                'status' => 'reversed',
                'reverse_admin_id' => $adminId,
                'reversed_time' => $now,
                'update_time' => $now,
            ]);
            $adjustment = $this->createAdjustmentRecord($accountAfter, 'reverse_grant', (int)$grant['amount_cent'], $grant, $grantAfter, $reason, $adminId, 'reverse_grant:' . $id);
            $this->writeSnapshot((int)$account['id'], (int)$ledger['id'], $id, (int)$adjustment['id'], 'product_quota_grant_order', $id, [
                'grant_order' => $grantAfter,
                'ledger' => $ledger,
                'adjustment' => $adjustment,
            ]);
            $this->audit('product_quota_grant_order', $id, 'grant_reverse', $grant, $grantAfter, $adminId, 'headquarter_finance', (int)$grant['store_id'], $reason);
            $this->audit('product_quota_account', (int)$account['id'], 'account_amount_change', $before, $accountAfter, $adminId, 'headquarter_finance', (int)$account['store_id'], $reason);
            return ['grant' => $this->formatGrant($grantAfter, true), 'account' => $this->formatAccount($accountAfter, true), 'ledger' => $this->formatLedger($ledger, true)];
        });
    }

    public function adminCreateAdjustment(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $accountId = (int)($data['account_id'] ?? 0);
        $action = trim((string)($data['action_type'] ?? ''));
        if (!in_array($action, ['manual_increase', 'manual_decrease'], true)) {
            throw new ApiException('product_quota_adjustment_action_invalid');
        }
        $amount = $this->positiveCent($data['amount_cent'] ?? 0, 'product_quota_adjustment_amount_invalid');
        $reason = $this->requiredReason($data['reason'] ?? '', 'product_quota_adjustment_reason_required');
        $dedupeKey = $this->normalizeOperationKey($data, 'dedupe_key', 'product_quota_adjustment_post', $adminId, 'product_quota_dedupe_key_required');
        $expectedPayload = [
            'account_id' => $accountId,
            'action_type' => $action,
            'amount_cent' => $amount,
            'reason' => $reason,
        ];
        $existing = $this->findAdjustmentByDedupeKey($dedupeKey);
        if ($existing) {
            $this->assertAdjustmentDedupePayload($existing, $expectedPayload);
            return $this->formatExistingAdjustmentResult($existing);
        }
        return Db::transaction(function () use ($accountId, $action, $amount, $reason, $dedupeKey, $adminId) {
            $account = $this->lockAccount($accountId);
            $expectedPayload = [
                'account_id' => $accountId,
                'action_type' => $action,
                'amount_cent' => $amount,
                'reason' => $reason,
            ];
            $existing = $this->findAdjustmentByDedupeKey($dedupeKey);
            if ($existing) {
                $this->assertAdjustmentDedupePayload($existing, $expectedPayload);
                return $this->formatExistingAdjustmentResult($existing);
            }
            $this->assertAccountAmountWritable($account);
            $before = $account;
            $delta = $action === 'manual_increase' ? $amount : -$amount;
            $afterBalance = (int)$account['available_cent'] + $delta;
            if ($afterBalance < 0) {
                throw new ApiException('product_quota_available_not_enough');
            }
            $now = time();
            $accountAfter = $this->updateAccountTotals($accountId, [
                'total_adjusted_cent' => (int)$account['total_adjusted_cent'] + $delta,
                'available_cent' => $afterBalance,
                'version' => (int)$account['version'] + 1,
                'update_time' => $now,
            ], $account);
            $adjustment = $this->createAdjustmentRecord($accountAfter, $action, $amount, $before, $accountAfter, $reason, $adminId, $dedupeKey);
            $ledger = $this->createLedger($accountAfter, $delta >= 0 ? 'in' : 'out', $action, $amount, (int)$account['available_cent'], $afterBalance, 'correction_adjustment', (int)$adjustment['id'], 'product_quota_adjustment:' . (int)$adjustment['id'], $adminId, $reason);
            $this->writeSnapshot($accountId, (int)$ledger['id'], 0, (int)$adjustment['id'], 'correction_adjustment', (int)$adjustment['id'], [
                'adjustment' => $adjustment,
                'ledger' => $ledger,
            ]);
            $this->audit('product_quota_adjustment', (int)$adjustment['id'], $action, $before, $accountAfter, $adminId, 'headquarter_operator', (int)$account['store_id'], $reason);
            $this->audit('product_quota_account', $accountId, 'account_amount_change', $before, $accountAfter, $adminId, 'headquarter_operator', (int)$account['store_id'], $reason);
            return ['adjustment' => $this->formatAdjustment($adjustment, true), 'account' => $this->formatAccount($accountAfter, true), 'ledger' => $this->formatLedger($ledger, true)];
        });
    }

    public function adminFreezeAccount(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        return $this->changeAccountStatus($id, 'freeze', 'frozen', $data, $adminId, $adminInfo);
    }

    public function adminUnfreezeAccount(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        return $this->changeAccountStatus($id, 'unfreeze', 'active', $data, $adminId, $adminInfo);
    }

    public function adminCloseAccount(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        return $this->changeAccountStatus($id, 'close', 'closed', $data, $adminId, $adminInfo);
    }

    public function userSummary(Request $request, array $where): array
    {
        $scope = $this->resolveUserReadScope($request, $where);
        $accounts = app()->make(YfthProductQuotaAccountDao::class)->search([])
            ->where('store_id', (int)$scope['store_id'])
            ->whereIn('status', ['active', 'frozen'])
            ->order('id desc')
            ->select()
            ->toArray();
        $totalAvailable = 0;
        foreach ($accounts as $row) {
            $totalAvailable += (int)$row['available_cent'];
        }
        return [
            'store_id' => (int)$scope['store_id'],
            'role_code' => (string)$scope['role_code'],
            'account_count' => count($accounts),
            'available_cent' => $totalAvailable,
            'accounts' => array_map(function ($row) {
                return $this->formatAccount($row, false);
            }, $accounts),
        ];
    }

    public function userAccountList(Request $request, array $where): array
    {
        $this->assertUserReadonlyPayload($where);
        $scope = $this->resolveUserReadScope($request, $where);
        $query = app()->make(YfthProductQuotaAccountDao::class)->search([])
            ->where('store_id', (int)$scope['store_id'])
            ->whereIn('status', ['active', 'frozen']);
        if (!empty($where['quota_type'])) {
            $query->where('quota_type', $this->normalizeQuotaType((string)$where['quota_type']));
        }
        return $this->paginateQuery($query, function ($row) {
            return $this->formatAccount($row, false);
        });
    }

    public function userAccountDetail(Request $request, int $id, array $where): array
    {
        $this->assertUserReadonlyPayload($where);
        $scope = $this->resolveUserReadScope($request, $where);
        $account = $this->requireAccount($id);
        if ((int)$account['store_id'] !== (int)$scope['store_id'] || !in_array((string)$account['status'], ['active', 'frozen'], true)) {
            throw new ApiException('product_quota_account_not_found');
        }
        return [
            'account' => $this->formatAccount($account, false),
            'recent_ledgers' => $this->recentLedgers((int)$account['id'], false),
        ];
    }

    public function userLedgerList(Request $request, array $where): array
    {
        $this->assertUserReadonlyPayload($where);
        $scope = $this->resolveUserReadScope($request, $where);
        $query = app()->make(YfthProductQuotaLedgerDao::class)->search([])
            ->where('store_id', (int)$scope['store_id']);
        if (!empty($where['account_id'])) {
            $query->where('account_id', (int)$where['account_id']);
        }
        if (!empty($where['quota_type'])) {
            $query->where('quota_type', $this->normalizeQuotaType((string)$where['quota_type']));
        }
        return $this->paginateQuery($query, function ($row) {
            return $this->formatLedger($row, false);
        });
    }

    private function changeAccountStatus(int $id, string $action, string $targetStatus, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $reason = $this->requiredReason($data['reason'] ?? '', 'product_quota_status_reason_required');
        return Db::transaction(function () use ($id, $action, $targetStatus, $reason, $adminId) {
            $account = $this->lockAccount($id);
            if ((string)$account['status'] === $targetStatus) {
                return ['account' => $this->formatAccount($account, true)];
            }
            if ($action === 'freeze' && (string)$account['status'] !== 'active') {
                throw new ApiException('product_quota_freeze_status_invalid');
            }
            if ($action === 'unfreeze' && (string)$account['status'] !== 'frozen') {
                throw new ApiException('product_quota_unfreeze_status_invalid');
            }
            if ($action === 'close' && !in_array((string)$account['status'], ['active', 'frozen'], true)) {
                throw new ApiException('product_quota_close_status_invalid');
            }
            $before = $account;
            $now = time();
            $after = array_merge($account, [
                'status' => $targetStatus,
                'active_key' => $targetStatus === 'closed' ? null : $this->accountActiveKey((int)$account['store_id'], (string)$account['quota_type']),
                'version' => (int)$account['version'] + 1,
                'update_time' => $now,
            ]);
            $dedupeKey = 'product_quota_account_status:' . $action . ':' . $id . ':' . (int)$account['version'];
            app()->make(YfthProductQuotaAccountDao::class)->update($id, [
                'status' => $after['status'],
                'active_key' => $after['active_key'],
                'version' => $after['version'],
                'update_time' => $now,
            ]);
            $adjustment = $this->createAdjustmentRecord($after, $action, 0, $before, $after, $reason, $adminId, $dedupeKey);
            $this->audit('product_quota_adjustment', (int)$adjustment['id'], $action, $before, $after, $adminId, 'headquarter_operator', (int)$account['store_id'], $reason);
            $this->audit('product_quota_account', $id, 'account_' . $action, $before, $after, $adminId, 'headquarter_operator', (int)$account['store_id'], $reason);
            return ['account' => $this->formatAccount($after, true), 'adjustment' => $this->formatAdjustment($adjustment, true)];
        });
    }

    private function accountQuery(array $where)
    {
        $query = app()->make(YfthProductQuotaAccountDao::class)->search([]);
        if (!empty($where['store_id'])) {
            $query->where('store_id', (int)$where['store_id']);
        }
        foreach (['quota_type', 'status'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (string)$where[$field]);
            }
        }
        return $query;
    }

    private function ensureAccount(int $storeId, string $quotaType, int $adminId, string $reason): array
    {
        if ($storeId <= 0) {
            throw new ApiException('product_quota_store_id_required');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        $activeKey = $this->accountActiveKey($storeId, $quotaType);
        $existing = $this->rowArray(app()->make(YfthProductQuotaAccountDao::class)->getOne(['active_key' => $activeKey]));
        if ($existing) {
            return $existing;
        }
        $now = time();
        try {
            $account = $this->rowArray(app()->make(YfthProductQuotaAccountDao::class)->save([
                'account_no' => $this->makeNo('PQA'),
                'store_id' => $storeId,
                'quota_type' => $quotaType,
                'status' => 'active',
                'total_granted_cent' => 0,
                'total_adjusted_cent' => 0,
                'total_reversed_cent' => 0,
                'reserved_cent' => 0,
                'consumed_cent' => 0,
                'available_cent' => 0,
                'frozen_cent' => 0,
                'version' => 1,
                'active_key' => $activeKey,
                'remark' => '',
                'create_time' => $now,
                'update_time' => $now,
            ]));
        } catch (\Throwable $e) {
            $existing = $this->rowArray(app()->make(YfthProductQuotaAccountDao::class)->getOne(['active_key' => $activeKey]));
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
        $this->audit('product_quota_account', (int)$account['id'], 'account_create', [], $account, $adminId, 'headquarter_operator', $storeId, $reason);
        return $account;
    }

    private function assertAccountAmountWritable(array $account): void
    {
        if ((string)($account['status'] ?? '') !== 'active') {
            throw new ApiException('product_quota_account_amount_change_forbidden');
        }
    }

    private function createLedger(array $account, string $direction, string $actionType, int $amount, int $before, int $after, string $sourceType, int $sourceId, string $idempotencyKey, int $operatorUid, string $reason): array
    {
        if (trim($idempotencyKey) === '') {
            throw new ApiException('product_quota_idempotency_key_required');
        }
        $existing = app()->make(YfthProductQuotaLedgerDao::class)->getOne(['idempotency_key' => $idempotencyKey]);
        if ($existing) {
            $existing = $this->rowArray($existing);
            $this->assertLedgerIdempotentPayload($existing, [
                'account_id' => (int)$account['id'],
                'store_id' => (int)$account['store_id'],
                'quota_type' => (string)$account['quota_type'],
                'direction' => $direction,
                'action_type' => $actionType,
                'amount_cent' => $amount,
                'balance_before_cent' => $before,
                'balance_after_cent' => $after,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
            return $existing;
        }
        $now = time();
        return $this->rowArray(app()->make(YfthProductQuotaLedgerDao::class)->save([
            'ledger_no' => $this->makeNo('PQL'),
            'account_id' => (int)$account['id'],
            'store_id' => (int)$account['store_id'],
            'quota_type' => (string)$account['quota_type'],
            'direction' => $direction,
            'action_type' => $actionType,
            'amount_cent' => $amount,
            'balance_before_cent' => $before,
            'balance_after_cent' => $after,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'status' => 'posted',
            'operator_type' => 'admin',
            'operator_uid' => $operatorUid,
            'reason' => $reason,
            'create_time' => $now,
        ]));
    }

    private function createAdjustmentRecord(array $account, string $actionType, int $amount, array $before, array $after, string $reason, int $operatorUid, string $dedupeKey): array
    {
        if (trim($dedupeKey) === '') {
            throw new ApiException('product_quota_dedupe_key_required');
        }
        $existing = $this->findAdjustmentByDedupeKey($dedupeKey);
        if ($existing) {
            return $existing;
        }
        return $this->rowArray(app()->make(YfthProductQuotaAdjustmentDao::class)->save([
            'adjustment_no' => $this->makeNo('PQAJS'),
            'account_id' => (int)$account['id'],
            'store_id' => (int)$account['store_id'],
            'action_type' => $actionType,
            'amount_cent' => $amount,
            'status' => 'posted',
            'before_state' => $this->jsonEncode($this->sanitizeState($before)),
            'after_state' => $this->jsonEncode($this->sanitizeState($after)),
            'reason' => $reason,
            'operator_uid' => $operatorUid,
            'dedupe_key' => $dedupeKey,
            'create_time' => time(),
        ]));
    }

    private function normalizeOperationKey(array $data, string $primaryField, string $scope, int $adminId, string $error): string
    {
        $clientKey = trim((string)($data[$primaryField] ?? ($data['client_operation_key'] ?? '')));
        if ($clientKey === '') {
            throw new ApiException($error);
        }
        if (strlen($clientKey) > 191) {
            $clientKey = hash('sha256', $clientKey);
        }
        return $scope . ':' . $adminId . ':' . hash('sha256', $clientKey);
    }

    private function findGrantByIdempotencyKey(string $idempotencyKey): array
    {
        return $this->rowArray(app()->make(YfthProductQuotaGrantOrderDao::class)->getOne(['idempotency_key' => $idempotencyKey]));
    }

    private function findAdjustmentByDedupeKey(string $dedupeKey): array
    {
        return $this->rowArray(app()->make(YfthProductQuotaAdjustmentDao::class)->getOne(['dedupe_key' => $dedupeKey]));
    }

    private function assertGrantIdempotentPayload(array $existing, array $expected): void
    {
        $this->assertIdempotentPayload($existing, $expected, [
            'account_id',
            'store_id',
            'quota_type',
            'amount_cent',
            'source_type',
            'source_id',
            'reason',
        ]);
    }

    private function assertAdjustmentDedupePayload(array $existing, array $expected): void
    {
        $this->assertIdempotentPayload($existing, $expected, [
            'account_id',
            'action_type',
            'amount_cent',
            'reason',
        ]);
    }

    private function assertLedgerIdempotentPayload(array $existing, array $expected): void
    {
        $this->assertIdempotentPayload($existing, $expected, [
            'account_id',
            'store_id',
            'quota_type',
            'direction',
            'action_type',
            'amount_cent',
            'balance_before_cent',
            'balance_after_cent',
            'source_type',
            'source_id',
        ]);
    }

    private function assertIdempotentPayload(array $existing, array $expected, array $fields): void
    {
        foreach ($fields as $field) {
            $left = $existing[$field] ?? null;
            $right = $expected[$field] ?? null;
            if (is_numeric($left) || is_numeric($right)) {
                if ((string)(int)$left !== (string)(int)$right) {
                    throw new ApiException('product_quota_idempotency_payload_mismatch');
                }
                continue;
            }
            if ((string)$left !== (string)$right) {
                throw new ApiException('product_quota_idempotency_payload_mismatch');
            }
        }
    }

    private function formatExistingAdjustmentResult(array $adjustment): array
    {
        $result = ['adjustment' => $this->formatAdjustment($adjustment, true)];
        $account = $this->rowArray(app()->make(YfthProductQuotaAccountDao::class)->get((int)$adjustment['account_id']));
        if ($account) {
            $result['account'] = $this->formatAccount($account, true);
        }
        $ledger = $this->rowArray(app()->make(YfthProductQuotaLedgerDao::class)->getOne([
            'source_type' => 'correction_adjustment',
            'source_id' => (int)$adjustment['id'],
        ]));
        if ($ledger) {
            $result['ledger'] = $this->formatLedger($ledger, true);
        }
        return $result;
    }

    private function updateAccountTotals(int $accountId, array $changes, array $before): array
    {
        app()->make(YfthProductQuotaAccountDao::class)->update($accountId, $changes);
        return array_merge($before, $changes);
    }

    private function writeSnapshot(int $accountId, int $ledgerId, int $grantOrderId, int $adjustmentId, string $sourceType, int $sourceId, array $snapshot): void
    {
        app()->make(YfthProductQuotaSourceSnapshotDao::class)->save([
            'account_id' => $accountId,
            'ledger_id' => $ledgerId,
            'grant_order_id' => $grantOrderId,
            'adjustment_id' => $adjustmentId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'snapshot_json' => $this->jsonEncode($this->sanitizeState($snapshot)),
            'create_time' => time(),
        ]);
    }

    private function resolveUserReadScope(Request $request, array $where): array
    {
        $this->assertUserReadonlyPayload($where);
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $roleCode = (string)($context['role_code'] ?? '');
        if (!in_array($roleCode, self::USER_READ_ROLES, true)) {
            throw new ApiException('product_quota_user_role_forbidden');
        }
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new ApiException('product_quota_store_required');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        return [
            'context' => $context,
            'store_id' => $storeId,
            'role_code' => $roleCode,
            'operator_uid' => (int)($context['uid'] ?? 0),
        ];
    }

    private function assertUserReadonlyPayload(array $data): void
    {
        foreach (self::USER_FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                throw new ApiException('product_quota_user_field_forbidden');
            }
        }
    }

    private function validateGrantSource(string $sourceType, int $sourceId, int $storeId): void
    {
        if ($sourceType === 'headquarters_manual_grant') {
            return;
        }
        if (in_array($sourceType, self::RESERVED_SOURCE_TYPES, true)) {
            throw new ApiException('product_quota_source_reserved_not_open');
        }
        if ($sourceType !== 'franchise_opening_initial_quota') {
            throw new ApiException('product_quota_source_invalid');
        }
        if ($sourceId <= 0) {
            throw new ApiException('product_quota_franchise_application_required');
        }
        $application = Db::name('yfth_franchise_application')->where('id', $sourceId)->find();
        if (!$application || (string)($application['status'] ?? '') !== 'opened') {
            throw new ApiException('product_quota_franchise_opening_not_opened');
        }
        $profile = Db::name('yfth_franchise_store_profile')->where('application_id', $sourceId)->find();
        if (!$profile || !in_array((string)($profile['status'] ?? ''), ['verified', 'bound'], true) || (int)($profile['system_store_id'] ?? 0) !== $storeId) {
            throw new ApiException('product_quota_franchise_store_not_bound');
        }
        $grant = Db::name('yfth_franchise_identity_grant')
            ->where('application_id', $sourceId)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->find();
        if (!$grant) {
            throw new ApiException('product_quota_franchise_identity_not_active');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
    }

    private function normalizeGrantSource(string $sourceType): string
    {
        $sourceType = trim($sourceType) ?: 'headquarters_manual_grant';
        if (in_array($sourceType, self::GRANT_SOURCE_TYPES, true)) {
            return $sourceType;
        }
        if (in_array($sourceType, self::RESERVED_SOURCE_TYPES, true)) {
            return $sourceType;
        }
        throw new ApiException('product_quota_source_invalid');
    }

    private function normalizeQuotaType(string $quotaType): string
    {
        $quotaType = trim($quotaType) ?: self::DEFAULT_QUOTA_TYPE;
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $quotaType)) {
            throw new ApiException('product_quota_type_invalid');
        }
        return $quotaType;
    }

    private function positiveCent($value, string $error): int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            $amount = (int)$value;
        } else {
            $amount = (int)$value;
        }
        if ($amount <= 0) {
            throw new ApiException($error);
        }
        return $amount;
    }

    private function requiredReason($value, string $error): string
    {
        $reason = trim((string)$value);
        if ($reason === '') {
            throw new ApiException($error);
        }
        return substr($reason, 0, 255);
    }

    private function requireAccount(int $id): array
    {
        $row = app()->make(YfthProductQuotaAccountDao::class)->get($id);
        if (!$row) {
            throw new ApiException('product_quota_account_not_found');
        }
        return $this->rowArray($row);
    }

    private function lockAccount(int $id): array
    {
        if ($id <= 0) {
            throw new ApiException('product_quota_account_id_required');
        }
        $row = Db::name('yfth_product_quota_account')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('product_quota_account_not_found');
        }
        return $row;
    }

    private function lockGrant(int $id): array
    {
        if ($id <= 0) {
            throw new ApiException('product_quota_grant_id_required');
        }
        $row = Db::name('yfth_product_quota_grant_order')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('product_quota_grant_not_found');
        }
        return $row;
    }

    private function recentLedgers(int $accountId, bool $admin): array
    {
        return array_map(function ($row) use ($admin) {
            return $this->formatLedger($row, $admin);
        }, app()->make(YfthProductQuotaLedgerDao::class)->search([])->where('account_id', $accountId)->order('id desc')->limit(10)->select()->toArray());
    }

    private function recentGrants(int $accountId): array
    {
        return array_map(function ($row) {
            return $this->formatGrant($row, true);
        }, app()->make(YfthProductQuotaGrantOrderDao::class)->search([])->where('account_id', $accountId)->order('id desc')->limit(10)->select()->toArray());
    }

    private function recentAdjustments(int $accountId): array
    {
        return array_map(function ($row) {
            return $this->formatAdjustment($row, true);
        }, app()->make(YfthProductQuotaAdjustmentDao::class)->search([])->where('account_id', $accountId)->order('id desc')->limit(10)->select()->toArray());
    }

    private function paginateQuery($query, callable $formatter): array
    {
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map($formatter, $rows),
            'count' => $count,
        ];
    }

    private function formatAccount(array $row, bool $admin): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'account_no' => (string)($row['account_no'] ?? ''),
            'store_id' => (int)($row['store_id'] ?? 0),
            'quota_type' => (string)($row['quota_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'total_granted_cent' => (int)($row['total_granted_cent'] ?? 0),
            'total_adjusted_cent' => (int)($row['total_adjusted_cent'] ?? 0),
            'total_reversed_cent' => (int)($row['total_reversed_cent'] ?? 0),
            'reserved_cent' => (int)($row['reserved_cent'] ?? 0),
            'consumed_cent' => (int)($row['consumed_cent'] ?? 0),
            'available_cent' => (int)($row['available_cent'] ?? 0),
            'frozen_cent' => (int)($row['frozen_cent'] ?? 0),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
        if ($admin) {
            $payload['version'] = (int)($row['version'] ?? 0);
            $payload['active_key'] = (string)($row['active_key'] ?? '');
            $payload['remark'] = (string)($row['remark'] ?? '');
        }
        return $payload;
    }

    private function formatLedger(array $row, bool $admin): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'ledger_no' => (string)($row['ledger_no'] ?? ''),
            'account_id' => (int)($row['account_id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'quota_type' => (string)($row['quota_type'] ?? ''),
            'direction' => (string)($row['direction'] ?? ''),
            'action_type' => (string)($row['action_type'] ?? ''),
            'amount_cent' => (int)($row['amount_cent'] ?? 0),
            'balance_before_cent' => (int)($row['balance_before_cent'] ?? 0),
            'balance_after_cent' => (int)($row['balance_after_cent'] ?? 0),
            'source_type' => (string)($row['source_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
        if ($admin) {
            $payload['source_id'] = (int)($row['source_id'] ?? 0);
            $payload['idempotency_key'] = (string)($row['idempotency_key'] ?? '');
            $payload['operator_type'] = (string)($row['operator_type'] ?? '');
            $payload['operator_uid'] = (int)($row['operator_uid'] ?? 0);
            $payload['reason'] = (string)($row['reason'] ?? '');
        }
        return $payload;
    }

    private function formatGrant(array $row, bool $admin): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'grant_no' => (string)($row['grant_no'] ?? ''),
            'account_id' => (int)($row['account_id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'quota_type' => (string)($row['quota_type'] ?? ''),
            'amount_cent' => (int)($row['amount_cent'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_id' => (int)($row['source_id'] ?? 0),
            'reason' => (string)($row['reason'] ?? ''),
            'confirmed_time' => (int)($row['confirmed_time'] ?? 0),
            'rejected_time' => (int)($row['rejected_time'] ?? 0),
            'reversed_time' => (int)($row['reversed_time'] ?? 0),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
        if ($admin) {
            $payload['applicant_admin_id'] = (int)($row['applicant_admin_id'] ?? 0);
            $payload['confirm_admin_id'] = (int)($row['confirm_admin_id'] ?? 0);
            $payload['reject_admin_id'] = (int)($row['reject_admin_id'] ?? 0);
            $payload['reverse_admin_id'] = (int)($row['reverse_admin_id'] ?? 0);
        }
        return $payload;
    }

    private function formatAdjustment(array $row, bool $admin): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'adjustment_no' => (string)($row['adjustment_no'] ?? ''),
            'account_id' => (int)($row['account_id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'action_type' => (string)($row['action_type'] ?? ''),
            'amount_cent' => (int)($row['amount_cent'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
        if ($admin) {
            $payload['reason'] = (string)($row['reason'] ?? '');
            $payload['operator_uid'] = (int)($row['operator_uid'] ?? 0);
            $payload['before_state'] = $this->jsonDecode((string)($row['before_state'] ?? ''));
            $payload['after_state'] = $this->jsonDecode((string)($row['after_state'] ?? ''));
        }
        return $payload;
    }

    private function assertHeadquarterAdmin(array $adminInfo): void
    {
        if (!$adminInfo || (int)($adminInfo['id'] ?? 0) <= 0) {
            throw new ApiException('headquarter_admin_required');
        }
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
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

    private function accountActiveKey(int $storeId, string $quotaType): string
    {
        return $storeId . ':' . $quotaType;
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
}
