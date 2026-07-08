<?php

namespace app\services\yfth;

use app\Request;
use app\dao\yfth\YfthReferralAttributionDao;
use app\dao\yfth\YfthReferralCandidateDao;
use app\dao\yfth\YfthReferralCodeDao;
use app\dao\yfth\YfthReferralEventDao;
use app\dao\yfth\YfthRewardAdjustmentDao;
use app\dao\yfth\YfthRewardLedgerDao;
use app\dao\yfth\YfthRewardLedgerSnapshotDao;
use app\dao\yfth\YfthRewardRuleItemDao;
use app\dao\yfth\YfthRewardRuleVersionDao;
use app\dao\yfth\YfthRewardSettlementRecordDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class ReferralRewardServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_referral_reward';
    private const SCENES = ['package_5980', 'franchise_opening'];
    private const ACTIVE_CANDIDATE_STATUSES = ['candidate', 'registered', 'bound', 'attributed'];
    private const LEDGER_VISIBLE_STATUSES = ['observing', 'valid', 'pending_settlement', 'settled', 'invalid', 'reversed'];

    public function __construct(YfthReferralCodeDao $dao)
    {
        $this->dao = $dao;
    }

    public function userCreateCode(Request $request, array $data): array
    {
        $this->assertNoClientOwnerFields($data);
        $scene = $this->normalizeScene((string)($data['scene'] ?? 'package_5980'));
        $scope = $this->resolveReferralOwner($request, $scene);
        $expireTime = $this->parseTime($data['expire_time'] ?? 0);

        $existing = $this->dao->search([])
            ->where('owner_uid', (int)$scope['owner_uid'])
            ->where('owner_role_code', (string)$scope['owner_role_code'])
            ->where('store_id', (int)$scope['store_id'])
            ->where('scene', $scene)
            ->where('status', 'active')
            ->find();
        if ($existing) {
            return ['code' => $this->formatCode($this->rowArray($existing))];
        }

        $now = time();
        $row = null;
        for ($i = 0; $i < 5; $i++) {
            try {
                $row = $this->dao->save([
                    'owner_uid' => (int)$scope['owner_uid'],
                    'owner_role_code' => (string)$scope['owner_role_code'],
                    'store_id' => (int)$scope['store_id'],
                    'scene' => $scene,
                    'code' => $this->makeCode($scene),
                    'status' => 'active',
                    'expire_time' => $expireTime,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                break;
            } catch (\Throwable $e) {
                if (!$this->isUniqueConflict($e) || $i === 4) {
                    throw $e;
                }
            }
        }
        $row = $this->rowArray($row);
        $this->audit('referral_code', (int)$row['id'], 'referral_code_create', [], $row, (int)$scope['owner_uid'], (string)$scope['owner_role_code'], (int)$scope['store_id'], '');
        return ['code' => $this->formatCode($row)];
    }

    public function userCodeList(Request $request, array $where): array
    {
        $scene = $this->normalizeScene((string)($where['scene'] ?? 'package_5980'));
        $scope = $this->resolveReferralOwner($request, $scene);
        $queryWhere = [
            'owner_uid' => (int)$scope['owner_uid'],
            'owner_role_code' => (string)$scope['owner_role_code'],
            'store_id' => (int)$scope['store_id'],
            'scene' => $scene,
        ];
        return $this->pageList($queryWhere, '*', 'id desc', function ($row) {
            return $this->formatCode($row);
        });
    }

    public function userBindCandidate(Request $request, array $data): array
    {
        $this->assertNoClientOwnerFields($data);
        $uid = $this->requestUid($request);
        $scene = $this->normalizeScene((string)($data['scene'] ?? 'package_5980'));
        $codeValue = strtoupper(trim((string)($data['code'] ?? '')));
        if ($codeValue === '') {
            throw new ApiException('referral_code_required');
        }
        return Db::transaction(function () use ($uid, $scene, $codeValue) {
            $code = Db::name('yfth_referral_code')
                ->where('code', $codeValue)
                ->where('scene', $scene)
                ->lock(true)
                ->find();
            if (!$code || (string)$code['status'] !== 'active') {
                throw new ApiException('referral_code_invalid');
            }
            if ((int)$code['expire_time'] > 0 && (int)$code['expire_time'] <= time()) {
                throw new ApiException('referral_code_expired');
            }
            if ((int)$code['owner_uid'] === $uid) {
                throw new ApiException('referral_self_bind_forbidden');
            }
            $activeKey = $this->candidateActiveKey($scene, $uid, '');
            $existing = app()->make(YfthReferralCandidateDao::class)->getOne(['active_key' => $activeKey]);
            if ($existing) {
                throw new ApiException('referral_candidate_already_bound');
            }
            $now = time();
            $candidate = app()->make(YfthReferralCandidateDao::class)->save([
                'scene' => $scene,
                'referrer_uid' => (int)$code['owner_uid'],
                'referrer_role_code' => (string)$code['owner_role_code'],
                'referrer_store_id' => (int)$code['store_id'],
                'referred_uid' => $uid,
                'referred_phone_hash' => '',
                'referred_phone_masked' => '',
                'referral_code_id' => (int)$code['id'],
                'source' => 'code',
                'status' => 'bound',
                'active_key' => $activeKey,
                'bind_time' => $now,
                'expire_time' => $now + 90 * 86400,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $candidate = $this->rowArray($candidate);
            $this->audit('referral_candidate', (int)$candidate['id'], 'referral_candidate_bind', [], $candidate, $uid, 'customer', (int)$code['store_id'], '');
            return ['candidate' => $this->formatCandidate($candidate, false)];
        });
    }

    public function userCandidateList(Request $request, array $where): array
    {
        $uid = $this->requestUid($request);
        $scene = trim((string)($where['scene'] ?? ''));
        $query = app()->make(YfthReferralCandidateDao::class)->search([])
            ->where(function ($query) use ($uid) {
                $query->where('referrer_uid', $uid)->whereOr('referred_uid', $uid);
            });
        if ($scene !== '') {
            $query->where('scene', $this->normalizeScene($scene));
        }
        return $this->paginateQuery($query, function ($row) {
            return $this->formatCandidate($row, false);
        });
    }

    public function userLedgerList(Request $request, array $where): array
    {
        $uid = $this->requestUid($request);
        $scene = trim((string)($where['scene'] ?? ''));
        $query = app()->make(YfthRewardLedgerDao::class)->search([])->where('referrer_uid', $uid);
        if ($scene !== '') {
            $query->where('scene', $this->normalizeScene($scene));
        }
        return $this->paginateQuery($query, function ($row) {
            return $this->formatLedger($row, false);
        });
    }

    public function userLedgerDetail(Request $request, int $id): array
    {
        $uid = $this->requestUid($request);
        $ledger = $this->requireLedger($id);
        if ((int)$ledger['referrer_uid'] !== $uid) {
            throw new ApiException('reward_ledger_not_found');
        }
        return ['ledger' => $this->formatLedgerDetail($ledger, false)];
    }

    public function adminRuleList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $query = app()->make(YfthRewardRuleVersionDao::class)->search([]);
        $scene = trim((string)($where['scene'] ?? ''));
        $status = trim((string)($where['status'] ?? ''));
        if ($scene !== '') {
            $query->where('scene', $this->normalizeScene($scene));
        }
        if ($status !== '') {
            $query->where('status', $this->normalizeRuleStatus($status, ''));
        }
        return $this->paginateQuery($query, function ($row) {
            $row = $this->formatRule($row, true);
            $row['items'] = $this->ruleItems((int)$row['id']);
            return $row;
        });
    }

    public function adminRuleSave(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $id = (int)($data['id'] ?? 0);
        return Db::transaction(function () use ($id, $data, $adminId) {
            $dao = app()->make(YfthRewardRuleVersionDao::class);
            $before = $id > 0 ? $this->rowArray($dao->get($id)) : [];
            if ($id > 0 && !$before) {
                throw new ApiException('reward_rule_not_found');
            }
            if ($before && (string)$before['status'] === 'published') {
                throw new ApiException('published_reward_rule_immutable');
            }
            $payload = $this->normalizeRulePayload($data, $adminId, $before);
            if ($id > 0) {
                $dao->update($id, $payload);
                $rule = array_merge($before, $payload);
                $action = 'reward_rule_update';
            } else {
                $payload['rule_no'] = $this->makeNo('RR');
                $rule = $this->rowArray($dao->save($payload));
                $id = (int)$rule['id'];
                $action = 'reward_rule_create';
            }
            $this->replaceRuleItems($id, (array)($data['items'] ?? []));
            $rule['items'] = $this->ruleItems($id);
            $this->audit('reward_rule_version', $id, $action, $before, $rule, $adminId, 'headquarter_admin', 0, '');
            return ['rule' => $this->formatRuleWithItems($rule)];
        });
    }

    public function adminRulePublish(int $id, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $adminId) {
            $before = $this->requireRule($id);
            if ((string)$before['status'] !== 'draft') {
                throw new ApiException('reward_rule_publish_status_invalid');
            }
            $items = $this->ruleItems($id);
            if (!$items) {
                throw new ApiException('reward_rule_item_required');
            }
            $after = $before;
            $after['status'] = 'published';
            $after['published_time'] = time();
            $after['update_time'] = time();
            app()->make(YfthRewardRuleVersionDao::class)->update($id, [
                'status' => 'published',
                'published_time' => $after['published_time'],
                'update_time' => $after['update_time'],
            ]);
            $this->audit('reward_rule_version', $id, 'reward_rule_publish', $before, $after, $adminId, 'headquarter_admin', 0, '');
            $after['items'] = $items;
            return ['rule' => $this->formatRuleWithItems($after)];
        });
    }

    public function adminRuleCopy(int $id, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $adminId) {
            $source = $this->requireRule($id);
            $maxVersion = (int)app()->make(YfthRewardRuleVersionDao::class)->search([])
                ->where('scene', (string)$source['scene'])
                ->max('version_no');
            $now = time();
            $copy = $source;
            unset($copy['id']);
            $copy['rule_no'] = $this->makeNo('RR');
            $copy['version_no'] = $maxVersion + 1;
            $copy['status'] = 'draft';
            $copy['published_time'] = 0;
            $copy['created_uid'] = $adminId;
            $copy['create_time'] = $now;
            $copy['update_time'] = $now;
            $newRule = $this->rowArray(app()->make(YfthRewardRuleVersionDao::class)->save($copy));
            foreach ($this->ruleItems($id) as $item) {
                app()->make(YfthRewardRuleItemDao::class)->save([
                    'rule_version_id' => (int)$newRule['id'],
                    'reward_scene' => (string)$item['reward_scene'],
                    'reward_type' => (string)$item['reward_type'],
                    'title' => (string)$item['title'],
                    'description' => (string)$item['description'],
                    'amount_cent' => (int)$item['amount_cent'],
                    'observe_days' => (int)$item['observe_days'],
                    'condition_snapshot' => $this->jsonEncode($this->sanitizeState($item['condition_snapshot'] ?? [])),
                    'status' => (string)$item['status'],
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            $newRule['items'] = $this->ruleItems((int)$newRule['id']);
            $this->audit('reward_rule_version', (int)$newRule['id'], 'reward_rule_copy', $source, $newRule, $adminId, 'headquarter_admin', 0, '');
            return ['rule' => $this->formatRuleWithItems($newRule)];
        });
    }

    public function adminCandidateList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return $this->adminGenericList(app()->make(YfthReferralCandidateDao::class), $where, [$this, 'formatCandidate']);
    }

    public function adminEventList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return $this->adminGenericList(app()->make(YfthReferralEventDao::class), $where, [$this, 'formatEvent']);
    }

    public function adminAttributionList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return $this->adminGenericList(app()->make(YfthReferralAttributionDao::class), $where, [$this, 'formatAttribution']);
    }

    public function adminLedgerList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $query = app()->make(YfthRewardLedgerDao::class)->search([]);
        foreach (['scene', 'status', 'business_type'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (string)$where[$field]);
            }
        }
        if (!empty($where['referrer_uid'])) {
            $query->where('referrer_uid', (int)$where['referrer_uid']);
        }
        return $this->paginateQuery($query, function ($row) {
            return $this->formatLedger($row, true);
        });
    }

    public function adminLedgerDetail(int $id, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return ['ledger' => $this->formatLedgerDetail($this->requireLedger($id), true)];
    }

    public function adminSettleLedger(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $data, $adminId) {
            $before = $this->lockLedger($id);
            if (!in_array((string)$before['status'], ['valid', 'pending_settlement'], true)) {
                throw new ApiException('reward_ledger_settle_status_invalid');
            }
            $settleDao = app()->make(YfthRewardSettlementRecordDao::class);
            if ($settleDao->getOne(['active_key' => 'ledger:' . $id])) {
                throw new ApiException('reward_ledger_settlement_exists');
            }
            $now = time();
            $record = $this->rowArray($settleDao->save([
                'ledger_id' => $id,
                'settlement_no' => $this->makeNo('RS'),
                'status' => 'marked_settled',
                'amount_cent' => (int)$before['amount_cent'],
                'offline_ref_no' => substr(trim((string)($data['offline_ref_no'] ?? '')), 0, 128),
                'remark' => substr(trim((string)($data['remark'] ?? '')), 0, 255),
                'operator_uid' => $adminId,
                'mark_time' => $now,
                'cancel_time' => 0,
                'active_key' => 'ledger:' . $id,
                'create_time' => $now,
                'update_time' => $now,
            ]));
            $after = $before;
            $after['status'] = 'settled';
            $after['settled_time'] = $now;
            $after['settled_uid'] = $adminId;
            $after['update_time'] = $now;
            app()->make(YfthRewardLedgerDao::class)->update($id, [
                'status' => 'settled',
                'settled_time' => $now,
                'settled_uid' => $adminId,
                'update_time' => $now,
            ]);
            $this->audit('reward_ledger', $id, 'reward_settlement_mark', $before, ['ledger' => $after, 'settlement' => $record], $adminId, 'headquarter_finance', (int)$before['referrer_store_id'], '');
            return ['ledger' => $this->formatLedgerDetail($after, true)];
        });
    }

    public function adminCancelSettlement(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $data, $adminId) {
            $before = $this->lockLedger($id);
            if ((string)$before['status'] !== 'settled') {
                throw new ApiException('reward_ledger_cancel_settlement_status_invalid');
            }
            $reason = trim((string)($data['reason'] ?? ''));
            if ($reason === '') {
                throw new ApiException('reward_cancel_settlement_reason_required');
            }
            $settlement = app()->make(YfthRewardSettlementRecordDao::class)->getOne(['active_key' => 'ledger:' . $id]);
            if ($settlement) {
                app()->make(YfthRewardSettlementRecordDao::class)->update((int)$settlement['id'], [
                    'status' => 'canceled',
                    'cancel_time' => time(),
                    'active_key' => null,
                    'remark' => $reason,
                    'update_time' => time(),
                ]);
            }
            $after = $before;
            $after['status'] = 'pending_settlement';
            $after['settled_time'] = 0;
            $after['settled_uid'] = 0;
            $after['update_time'] = time();
            app()->make(YfthRewardLedgerDao::class)->update($id, [
                'status' => 'pending_settlement',
                'settled_time' => 0,
                'settled_uid' => 0,
                'update_time' => $after['update_time'],
            ]);
            $this->adjustment($id, 'manual_adjust', 0, $reason, $adminId, (string)$before['status'], (string)$after['status'], ['settlement_cancelled' => true]);
            $this->audit('reward_ledger', $id, 'reward_settlement_cancel', $before, $after, $adminId, 'headquarter_finance', (int)$before['referrer_store_id'], $reason);
            return ['ledger' => $this->formatLedgerDetail($after, true)];
        });
    }

    public function adminReverseLedger(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $data, $adminId) {
            $before = $this->lockLedger($id);
            $reason = trim((string)($data['reason'] ?? ''));
            if ($reason === '') {
                throw new ApiException('reward_reverse_reason_required');
            }
            if (in_array((string)$before['status'], ['reversed', 'invalid'], true)) {
                return ['ledger' => $this->formatLedgerDetail($before, true)];
            }
            $now = time();
            $after = $before;
            $after['status'] = 'reversed';
            $after['reversed_time'] = $now;
            $after['reversed_uid'] = $adminId;
            $after['active_key'] = null;
            $after['update_time'] = $now;
            app()->make(YfthRewardLedgerDao::class)->update($id, [
                'status' => 'reversed',
                'reversed_time' => $now,
                'reversed_uid' => $adminId,
                'active_key' => null,
                'update_time' => $now,
            ]);
            $this->adjustment($id, 'reverse', 0 - (int)$before['amount_cent'], $reason, $adminId, (string)$before['status'], 'reversed', ['manual_reverse' => true]);
            $this->audit('reward_ledger', $id, 'reward_reverse', $before, $after, $adminId, 'headquarter_admin', (int)$before['referrer_store_id'], $reason);
            return ['ledger' => $this->formatLedgerDetail($after, true)];
        });
    }

    public function adminScan(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $dryRun = (int)($data['dry_run'] ?? 1) === 1;
        $limit = max(1, min(200, (int)($data['limit'] ?? 50)));
        $rows = app()->make(YfthRewardLedgerDao::class)->search([])
            ->where('status', 'observing')
            ->where('observe_end_time', '>', 0)
            ->where('observe_end_time', '<=', time())
            ->limit($limit)
            ->order('id asc')
            ->select()
            ->toArray();
        $changed = 0;
        if (!$dryRun) {
            foreach ($rows as $row) {
                $this->markLedgerValid((int)$row['id'], $adminId, 'reward_scan');
                $changed++;
            }
        }
        $summary = [
            'dry_run' => $dryRun ? 1 : 0,
            'matched' => count($rows),
            'changed' => $changed,
        ];
        $this->audit('reward_scan', 0, 'reward_scan', [], $summary, $adminId, 'headquarter_admin', 0, $dryRun ? 'dry_run' : 'run');
        return $summary;
    }

    public function recordBusinessEvent(array $data): array
    {
        $scene = $this->normalizeScene((string)($data['scene'] ?? ''));
        $eventType = trim((string)($data['event_type'] ?? ''));
        $sourceType = trim((string)($data['source_type'] ?? ''));
        $sourceId = (int)($data['source_id'] ?? 0);
        $idempotencyKey = trim((string)($data['idempotency_key'] ?? ''));
        if ($eventType === '' || $sourceType === '' || $sourceId <= 0 || $idempotencyKey === '') {
            throw new ApiException('referral_event_source_required');
        }
        $candidateId = (int)($data['candidate_id'] ?? 0);
        $candidate = $candidateId > 0 ? $this->requireCandidate($candidateId) : $this->activeCandidateForReferred($scene, (int)($data['referred_uid'] ?? 0));

        return Db::transaction(function () use ($scene, $eventType, $sourceType, $sourceId, $idempotencyKey, $candidate, $data) {
            $eventDao = app()->make(YfthReferralEventDao::class);
            $existing = $eventDao->getOne([
                'scene' => $scene,
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
            ]);
            if ($existing) {
                return ['event' => $this->formatEvent($this->rowArray($existing), true), 'replay' => 1];
            }
            $now = time();
            $event = $this->rowArray($eventDao->save([
                'scene' => $scene,
                'candidate_id' => (int)($candidate['id'] ?? 0),
                'event_type' => $eventType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'payload_snapshot' => $this->jsonEncode($this->sanitizeState((array)($data['payload_snapshot'] ?? []))),
                'status' => $candidate ? 'recorded' : 'ignored',
                'error_code' => $candidate ? '' : 'candidate_missing',
                'error_message' => $candidate ? '' : 'No active referral candidate',
                'create_time' => $now,
                'update_time' => $now,
            ]));
            if ($candidate) {
                $this->audit('referral_event', (int)$event['id'], 'referral_event_record', [], $event, 0, 'system', (int)$candidate['referrer_store_id'], '');
                if (in_array($eventType, ['package_activated', 'franchise_opened'], true)) {
                    $this->createAttributionAndLedger($candidate, $scene, $sourceType, $sourceId, (array)($data['business_snapshot'] ?? []));
                }
                if (in_array($eventType, ['package_refunded', 'package_closed', 'package_frozen', 'franchise_terminated', 'franchise_revoked'], true)) {
                    $this->reverseLedgersForBusiness($scene, $sourceType, $sourceId, 'source_event:' . $eventType);
                }
            }
            return ['event' => $this->formatEvent($event, true), 'replay' => 0];
        });
    }

    private function createAttributionAndLedger(array $candidate, string $scene, string $businessType, int $businessId, array $businessSnapshot): void
    {
        $attrDao = app()->make(YfthReferralAttributionDao::class);
        $attribution = $attrDao->getOne([
            'scene' => $scene,
            'business_type' => $businessType,
            'business_id' => $businessId,
        ]);
        if (!$attribution) {
            $now = time();
            $attribution = $this->rowArray($attrDao->save([
                'scene' => $scene,
                'candidate_id' => (int)$candidate['id'],
                'referrer_uid' => (int)$candidate['referrer_uid'],
                'referrer_store_id' => (int)$candidate['referrer_store_id'],
                'referred_uid' => (int)$candidate['referred_uid'],
                'business_type' => $businessType,
                'business_id' => $businessId,
                'status' => 'attributed',
                'attributed_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]));
            app()->make(YfthReferralCandidateDao::class)->update((int)$candidate['id'], [
                'status' => 'attributed',
                'update_time' => $now,
            ]);
            $this->audit('referral_attribution', (int)$attribution['id'], 'referral_attribution_create', [], $attribution, 0, 'system', (int)$candidate['referrer_store_id'], '');
        } else {
            $attribution = $this->rowArray($attribution);
        }
        $this->createLedgerForAttribution($attribution, $businessSnapshot);
    }

    private function createLedgerForAttribution(array $attribution, array $businessSnapshot): void
    {
        $rule = $this->currentRule((string)$attribution['scene']);
        if (!$rule) {
            return;
        }
        foreach ($this->ruleItems((int)$rule['id']) as $item) {
            if ((string)$item['status'] !== 'active') {
                continue;
            }
            $activeKey = implode(':', [
                (string)$attribution['scene'],
                (string)$attribution['business_type'],
                (int)$attribution['business_id'],
                (int)$attribution['referrer_uid'],
                (int)$item['id'],
            ]);
            if (app()->make(YfthRewardLedgerDao::class)->getOne(['active_key' => $activeKey])) {
                continue;
            }
            $now = time();
            $observeDays = max(0, (int)$item['observe_days']);
            $ledger = $this->rowArray(app()->make(YfthRewardLedgerDao::class)->save([
                'ledger_no' => $this->makeNo('RL'),
                'scene' => (string)$attribution['scene'],
                'attribution_id' => (int)$attribution['id'],
                'candidate_id' => (int)$attribution['candidate_id'],
                'referrer_uid' => (int)$attribution['referrer_uid'],
                'referrer_store_id' => (int)$attribution['referrer_store_id'],
                'referred_uid' => (int)$attribution['referred_uid'],
                'business_type' => (string)$attribution['business_type'],
                'business_id' => (int)$attribution['business_id'],
                'rule_version_id' => (int)$rule['id'],
                'rule_item_id' => (int)$item['id'],
                'amount_cent' => (int)$item['amount_cent'],
                'status' => 'observing',
                'observe_start_time' => $now,
                'observe_end_time' => $now + ($observeDays * 86400),
                'valid_time' => 0,
                'settled_time' => 0,
                'settled_uid' => 0,
                'reversed_time' => 0,
                'reversed_uid' => 0,
                'active_key' => $activeKey,
                'create_time' => $now,
                'update_time' => $now,
            ]));
            app()->make(YfthRewardLedgerSnapshotDao::class)->save([
                'ledger_id' => (int)$ledger['id'],
                'rule_snapshot' => $this->jsonEncode($this->sanitizeState(['rule' => $rule, 'item' => $item])),
                'referral_snapshot' => $this->jsonEncode($this->sanitizeState($attribution)),
                'business_snapshot' => $this->jsonEncode($this->sanitizeState($businessSnapshot)),
                'create_time' => $now,
            ]);
            $this->audit('reward_ledger', (int)$ledger['id'], 'reward_ledger_create', [], $ledger, 0, 'system', (int)$ledger['referrer_store_id'], '');
            if ($observeDays === 0) {
                $this->markLedgerValid((int)$ledger['id'], 0, 'zero_observe_days');
            }
        }
    }

    private function reverseLedgersForBusiness(string $scene, string $businessType, int $businessId, string $reason): void
    {
        $rows = app()->make(YfthRewardLedgerDao::class)->search([])
            ->where('scene', $scene)
            ->where('business_type', $businessType)
            ->where('business_id', $businessId)
            ->whereIn('status', ['observing', 'valid', 'pending_settlement', 'settled'])
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $before = $this->lockLedger((int)$row['id']);
            if (!in_array((string)$before['status'], ['observing', 'valid', 'pending_settlement', 'settled'], true)) {
                continue;
            }
            $now = time();
            app()->make(YfthRewardLedgerDao::class)->update((int)$before['id'], [
                'status' => 'reversed',
                'reversed_time' => $now,
                'reversed_uid' => 0,
                'active_key' => null,
                'update_time' => $now,
            ]);
            $this->adjustment((int)$before['id'], 'reverse', 0 - (int)$before['amount_cent'], $reason, 0, (string)$before['status'], 'reversed', ['source_event' => $reason]);
        }
    }

    private function markLedgerValid(int $id, int $operatorUid, string $reason): void
    {
        $before = $this->lockLedger($id);
        if ((string)$before['status'] !== 'observing') {
            return;
        }
        $now = time();
        $after = $before;
        $after['status'] = 'valid';
        $after['valid_time'] = $now;
        $after['update_time'] = $now;
        app()->make(YfthRewardLedgerDao::class)->update($id, [
            'status' => 'valid',
            'valid_time' => $now,
            'update_time' => $now,
        ]);
        $this->audit('reward_ledger', $id, 'reward_ledger_valid', $before, $after, $operatorUid, $operatorUid > 0 ? 'headquarter_admin' : 'system', (int)$before['referrer_store_id'], $reason);
    }

    private function adjustment(int $ledgerId, string $type, int $amountCent, string $reason, int $operatorUid, string $beforeStatus, string $afterStatus, array $payload = []): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException('reward_adjustment_reason_required');
        }
        app()->make(YfthRewardAdjustmentDao::class)->save([
            'ledger_id' => $ledgerId,
            'adjustment_type' => $type,
            'amount_cent' => $amountCent,
            'reason' => substr($reason, 0, 255),
            'operator_uid' => $operatorUid,
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
            'payload_snapshot' => $this->jsonEncode($this->sanitizeState($payload)),
            'dedupe_key' => null,
            'create_time' => time(),
        ]);
    }

    private function resolveReferralOwner(Request $request, string $scene): array
    {
        $uid = $this->requestUid($request);
        if ($scene === 'package_5980') {
            return [
                'owner_uid' => $uid,
                'owner_role_code' => 'customer',
                'store_id' => 0,
            ];
        }
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $roleCode = (string)($context['role_code'] ?? '');
        if (!in_array($roleCode, ['franchisee', 'store_manager'], true)) {
            throw new ApiException('franchise_referral_role_required');
        }
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new ApiException('franchise_referral_store_required');
        }
        return [
            'owner_uid' => $uid,
            'owner_role_code' => $roleCode,
            'store_id' => $storeId,
        ];
    }

    private function normalizeRulePayload(array $data, int $adminId, array $before): array
    {
        $scene = $this->normalizeScene((string)($data['scene'] ?? ($before['scene'] ?? 'package_5980')));
        $name = trim((string)($data['name'] ?? ($before['name'] ?? '')));
        if ($name === '') {
            throw new ApiException('reward_rule_name_required');
        }
        $versionNo = (int)($data['version_no'] ?? ($before['version_no'] ?? 1));
        if ($versionNo <= 0) {
            throw new ApiException('reward_rule_version_invalid');
        }
        $now = time();
        return [
            'scene' => $scene,
            'name' => $name,
            'version_no' => $versionNo,
            'status' => $this->normalizeRuleStatus((string)($data['status'] ?? ($before['status'] ?? 'draft')), 'draft'),
            'effective_start' => $this->parseTime($data['effective_start'] ?? ($before['effective_start'] ?? 0)),
            'effective_end' => $this->parseTime($data['effective_end'] ?? ($before['effective_end'] ?? 0)),
            'published_time' => (int)($before['published_time'] ?? 0),
            'created_uid' => $before ? (int)($before['created_uid'] ?? 0) : $adminId,
            'create_time' => $before ? (int)($before['create_time'] ?? 0) : $now,
            'update_time' => $now,
        ];
    }

    private function replaceRuleItems(int $ruleId, array $items): void
    {
        app()->make(YfthRewardRuleItemDao::class)->search([])->where('rule_version_id', $ruleId)->delete();
        $now = time();
        foreach ($items as $item) {
            $amountCent = (int)($item['amount_cent'] ?? 0);
            if ($amountCent < 0) {
                throw new ApiException('reward_rule_amount_cent_invalid');
            }
            app()->make(YfthRewardRuleItemDao::class)->save([
                'rule_version_id' => $ruleId,
                'reward_scene' => trim((string)($item['reward_scene'] ?? 'default')),
                'reward_type' => trim((string)($item['reward_type'] ?? 'offline_reward')),
                'title' => trim((string)($item['title'] ?? 'Referral reward')),
                'description' => trim((string)($item['description'] ?? '')),
                'amount_cent' => $amountCent,
                'observe_days' => max(0, (int)($item['observe_days'] ?? 0)),
                'condition_snapshot' => $this->jsonEncode($this->sanitizeState((array)($item['condition_snapshot'] ?? []))),
                'status' => in_array((string)($item['status'] ?? 'active'), ['active', 'disabled'], true) ? (string)$item['status'] : 'active',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    private function ruleItems(int $ruleId): array
    {
        $rows = app()->make(YfthRewardRuleItemDao::class)->search([])
            ->where('rule_version_id', $ruleId)
            ->order('id asc')
            ->select()
            ->toArray();
        return array_map(function ($row) {
            $row['condition_snapshot'] = $this->jsonDecode((string)($row['condition_snapshot'] ?? ''));
            return $row;
        }, $rows);
    }

    private function currentRule(string $scene): array
    {
        $now = time();
        $row = app()->make(YfthRewardRuleVersionDao::class)->search([])
            ->where('scene', $scene)
            ->where('status', 'published')
            ->where(function ($query) use ($now) {
                $query->where('effective_start', 0)->whereOr('effective_start', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->where('effective_end', 0)->whereOr('effective_end', '>', $now);
            })
            ->order('version_no desc,id desc')
            ->find();
        return $this->rowArray($row);
    }

    private function adminGenericList($dao, array $where, callable $formatter): array
    {
        $query = $dao->search([]);
        foreach (['scene', 'status', 'event_type', 'business_type'] as $field) {
            if (!empty($where[$field])) {
                $query->where($field, (string)$where[$field]);
            }
        }
        if (!empty($where['referrer_uid'])) {
            $query->where('referrer_uid', (int)$where['referrer_uid']);
        }
        return $this->paginateQuery($query, function ($row) use ($formatter) {
            return $formatter($row, true);
        });
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

    private function activeCandidateForReferred(string $scene, int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        $row = app()->make(YfthReferralCandidateDao::class)->search([])
            ->where('scene', $scene)
            ->where('referred_uid', $uid)
            ->whereIn('status', self::ACTIVE_CANDIDATE_STATUSES)
            ->order('id desc')
            ->find();
        return $this->rowArray($row);
    }

    private function requireCandidate(int $id): array
    {
        $row = app()->make(YfthReferralCandidateDao::class)->get($id);
        if (!$row) {
            throw new ApiException('referral_candidate_not_found');
        }
        return $this->rowArray($row);
    }

    private function requireRule(int $id): array
    {
        $row = app()->make(YfthRewardRuleVersionDao::class)->get($id);
        if (!$row) {
            throw new ApiException('reward_rule_not_found');
        }
        return $this->rowArray($row);
    }

    private function requireLedger(int $id): array
    {
        $row = app()->make(YfthRewardLedgerDao::class)->get($id);
        if (!$row) {
            throw new ApiException('reward_ledger_not_found');
        }
        return $this->rowArray($row);
    }

    private function lockLedger(int $id): array
    {
        $row = Db::name('yfth_reward_ledger')->where('id', $id)->lock(true)->find();
        if (!$row) {
            throw new ApiException('reward_ledger_not_found');
        }
        return $row;
    }

    private function normalizeScene(string $scene): string
    {
        $scene = trim($scene);
        if (!in_array($scene, self::SCENES, true)) {
            throw new ApiException('referral_scene_invalid');
        }
        return $scene;
    }

    private function normalizeRuleStatus(string $status, string $default): string
    {
        $status = trim($status);
        return in_array($status, ['draft', 'published', 'disabled', 'archived'], true) ? $status : $default;
    }

    private function assertNoClientOwnerFields(array $data): void
    {
        foreach (['owner_uid', 'owner_role_code', 'store_id', 'store_ids', 'referrer_uid', 'referrer_role_code', 'referrer_store_id', 'amount_cent', 'settled_uid', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                throw new ApiException('referral_client_owner_field_forbidden');
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

    private function assertHeadquarterAdmin(array $adminInfo): void
    {
        if (!$adminInfo || (int)($adminInfo['id'] ?? 0) <= 0) {
            throw new ApiException('headquarter_admin_required');
        }
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
    }

    private function candidateActiveKey(string $scene, int $uid, string $phoneHash): string
    {
        return $uid > 0 ? $scene . ':uid:' . $uid : $scene . ':phone:' . $phoneHash;
    }

    private function makeCode(string $scene): string
    {
        $prefix = $scene === 'package_5980' ? 'C' : 'B';
        return $prefix . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function formatCode(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'scene' => (string)($row['scene'] ?? ''),
            'code' => (string)($row['code'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'owner_role_code' => (string)($row['owner_role_code'] ?? ''),
            'store_id' => (int)($row['store_id'] ?? 0),
            'expire_time' => (int)($row['expire_time'] ?? 0),
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
    }

    private function formatCandidate(array $row, bool $admin = false): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'scene' => (string)($row['scene'] ?? ''),
            'referrer_uid' => (int)($row['referrer_uid'] ?? 0),
            'referrer_role_code' => (string)($row['referrer_role_code'] ?? ''),
            'referrer_store_id' => (int)($row['referrer_store_id'] ?? 0),
            'referred_uid' => (int)($row['referred_uid'] ?? 0),
            'referred_phone_masked' => (string)($row['referred_phone_masked'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'bind_time' => (int)($row['bind_time'] ?? 0),
            'expire_time' => (int)($row['expire_time'] ?? 0),
        ];
        if ($admin) {
            $payload['referral_code_id'] = (int)($row['referral_code_id'] ?? 0);
            $payload['create_time'] = (int)($row['create_time'] ?? 0);
        }
        return $payload;
    }

    private function formatEvent(array $row, bool $admin = false): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'scene' => (string)($row['scene'] ?? ''),
            'candidate_id' => (int)($row['candidate_id'] ?? 0),
            'event_type' => (string)($row['event_type'] ?? ''),
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_id' => (int)($row['source_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'error_code' => (string)($row['error_code'] ?? ''),
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
        if ($admin) {
            $payload['payload_snapshot'] = $this->jsonDecode((string)($row['payload_snapshot'] ?? ''));
            $payload['error_message'] = (string)($row['error_message'] ?? '');
        }
        return $payload;
    }

    private function formatAttribution(array $row, bool $admin = false): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'scene' => (string)($row['scene'] ?? ''),
            'candidate_id' => (int)($row['candidate_id'] ?? 0),
            'referrer_uid' => (int)($row['referrer_uid'] ?? 0),
            'referrer_store_id' => (int)($row['referrer_store_id'] ?? 0),
            'referred_uid' => (int)($row['referred_uid'] ?? 0),
            'business_type' => (string)($row['business_type'] ?? ''),
            'business_id' => (int)($row['business_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'attributed_time' => (int)($row['attributed_time'] ?? 0),
        ];
    }

    private function formatRule(array $row, bool $admin = false): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'rule_no' => (string)($row['rule_no'] ?? ''),
            'scene' => (string)($row['scene'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'version_no' => (int)($row['version_no'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'effective_start' => (int)($row['effective_start'] ?? 0),
            'effective_end' => (int)($row['effective_end'] ?? 0),
            'published_time' => (int)($row['published_time'] ?? 0),
            'created_uid' => $admin ? (int)($row['created_uid'] ?? 0) : 0,
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
    }

    private function formatRuleWithItems(array $row): array
    {
        $payload = $this->formatRule($row, true);
        $payload['items'] = $row['items'] ?? $this->ruleItems((int)$payload['id']);
        return $payload;
    }

    private function formatLedger(array $row, bool $admin = false): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'ledger_no' => (string)($row['ledger_no'] ?? ''),
            'scene' => (string)($row['scene'] ?? ''),
            'referrer_uid' => (int)($row['referrer_uid'] ?? 0),
            'referrer_store_id' => (int)($row['referrer_store_id'] ?? 0),
            'referred_uid' => (int)($row['referred_uid'] ?? 0),
            'business_type' => (string)($row['business_type'] ?? ''),
            'business_id' => (int)($row['business_id'] ?? 0),
            'amount_cent' => (int)($row['amount_cent'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'observe_start_time' => (int)($row['observe_start_time'] ?? 0),
            'observe_end_time' => (int)($row['observe_end_time'] ?? 0),
            'valid_time' => (int)($row['valid_time'] ?? 0),
            'settled_time' => (int)($row['settled_time'] ?? 0),
            'create_time' => (int)($row['create_time'] ?? 0),
        ];
    }

    private function formatLedgerDetail(array $row, bool $admin): array
    {
        $payload = $this->formatLedger($row, $admin);
        $payload['attribution_id'] = (int)($row['attribution_id'] ?? 0);
        $payload['candidate_id'] = (int)($row['candidate_id'] ?? 0);
        $payload['rule_version_id'] = (int)($row['rule_version_id'] ?? 0);
        $payload['rule_item_id'] = (int)($row['rule_item_id'] ?? 0);
        $payload['settled_uid'] = $admin ? (int)($row['settled_uid'] ?? 0) : 0;
        $payload['reversed_time'] = (int)($row['reversed_time'] ?? 0);
        $payload['reversed_uid'] = $admin ? (int)($row['reversed_uid'] ?? 0) : 0;
        if ($admin) {
            $payload['snapshots'] = $this->ledgerSnapshots((int)$row['id']);
            $payload['adjustments'] = $this->ledgerAdjustments((int)$row['id']);
            $payload['settlements'] = $this->ledgerSettlements((int)$row['id']);
        }
        return $payload;
    }

    private function ledgerSnapshots(int $ledgerId): array
    {
        $rows = app()->make(YfthRewardLedgerSnapshotDao::class)->search([])->where('ledger_id', $ledgerId)->select()->toArray();
        return array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'rule_snapshot' => $this->jsonDecode((string)($row['rule_snapshot'] ?? '')),
                'referral_snapshot' => $this->jsonDecode((string)($row['referral_snapshot'] ?? '')),
                'business_snapshot' => $this->jsonDecode((string)($row['business_snapshot'] ?? '')),
                'create_time' => (int)($row['create_time'] ?? 0),
            ];
        }, $rows);
    }

    private function ledgerAdjustments(int $ledgerId): array
    {
        return app()->make(YfthRewardAdjustmentDao::class)->search([])->where('ledger_id', $ledgerId)->order('id desc')->select()->toArray();
    }

    private function ledgerSettlements(int $ledgerId): array
    {
        return app()->make(YfthRewardSettlementRecordDao::class)->search([])->where('ledger_id', $ledgerId)->order('id desc')->select()->toArray();
    }

    private function rowArray($row): array
    {
        if (!$row) {
            return [];
        }
        return is_array($row) ? $row : $row->toArray();
    }

    private function isUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000';
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
}
