<?php

namespace app\services\yfth;

use app\Request;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class FranchisePartnerServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_franchise_partner';
    private const RANKS = [
        'county_partner' => ['name' => '县级合伙人', 'level' => 1],
        'prefecture_partner' => ['name' => '地级合伙人', 'level' => 2],
        'province_partner' => ['name' => '省级合伙人', 'level' => 3],
        'regional_director' => ['name' => '大区总监', 'level' => 4],
        'platform_director' => ['name' => '平台董事', 'level' => 5],
    ];
    private const REQUIRED_PARENT_RANKS = [
        'county_partner' => 'prefecture_partner',
        'prefecture_partner' => 'province_partner',
        'province_partner' => 'regional_director',
        'regional_director' => 'platform_director',
        'platform_director' => '',
    ];
    private const PROFILE_STATUSES = ['active', 'paused', 'exited'];
    private const REWARD_TRANSITIONS = [
        'confirm' => ['from' => ['pending'], 'to' => 'confirmed'],
        'cancel' => ['from' => ['pending', 'confirmed'], 'to' => 'cancelled'],
    ];

    private $adminScope;
    private $audit;

    public function __construct(AdminStoreContextServices $adminScope, AuditEventServices $audit)
    {
        $this->adminScope = $adminScope;
        $this->audit = $audit;
    }

    public function adminDashboard(array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $counts = [];
        foreach (self::RANKS as $code => $rank) {
            $counts[$code] = (int)Db::name('yfth_partner_profile')->where(['rank_code' => $code, 'status' => 'active'])->count();
        }
        return [
            'rank_counts' => $counts,
            'active_partners' => array_sum($counts),
            'mutable_sources' => (int)Db::name('yfth_franchise_recruit_source')->where('status', 'mutable')->count(),
            'valid_openings' => (int)Db::name('yfth_partner_opening_performance')->where('status', 'valid')->count(),
            'pending_rewards' => (int)Db::name('yfth_partner_reward_candidate')->where('status', 'pending')->count(),
            'open_warnings' => (int)Db::name('yfth_partner_warning')->where('status', 'open')->count(),
            'rank_options' => $this->rankOptions(),
            'disclaimer' => '招商收益候选和线下结算仅记录业务事实，不代表平台自动打款。',
        ];
    }

    public function adminRules(array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $versions = Db::name('yfth_partner_rule_version')->order('version_no desc')->select()->toArray();
        foreach ($versions as &$version) {
            $version['rank_rules'] = $this->rankRules((int)$version['id']);
        }
        return ['list' => $versions, 'rank_options' => $this->rankOptions()];
    }

    public function adminSaveRule(array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->requiredReason($data);
        $orderAmount = $this->money($data['order_amount'] ?? '89100.00');
        $bottleCount = max(1, (int)($data['bottle_count'] ?? 440));
        $dividendBps = max(0, min(10000, (int)($data['platform_dividend_bps'] ?? 100)));
        $rules = (array)($data['rank_rules'] ?? []);
        return Db::transaction(function () use ($orderAmount, $bottleCount, $dividendBps, $rules, $adminId, $reason) {
            $versionNo = (int)Db::name('yfth_partner_rule_version')->lock(true)->max('version_no') + 1;
            $now = time();
            $id = (int)Db::name('yfth_partner_rule_version')->insertGetId([
                'rule_no' => 'YFTH-PARTNER-V' . $versionNo,
                'version_no' => $versionNo,
                'status' => 'draft',
                'order_amount' => $orderAmount,
                'bottle_count' => $bottleCount,
                'platform_dividend_bps' => $dividendBps,
                'effective_time' => 0,
                'active_key' => null,
                'operator_uid' => $adminId,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $active = $this->activeRule();
            $baseRules = $active ? $this->rankRules((int)$active['id']) : [];
            $baseMap = [];
            foreach ($baseRules as $row) {
                $baseMap[(string)$row['rank_code']] = $row;
            }
            foreach (self::RANKS as $code => $meta) {
                $input = (array)($rules[$code] ?? []);
                $base = (array)($baseMap[$code] ?? []);
                Db::name('yfth_partner_rank_rule')->insert([
                    'rule_version_id' => $id,
                    'rank_code' => $code,
                    'rank_name' => $meta['name'],
                    'rank_level' => $meta['level'],
                    'reward_per_bottle' => $this->money($input['reward_per_bottle'] ?? $base['reward_per_bottle'] ?? '0.00'),
                    'promotion_config' => $this->json($input['promotion_config'] ?? $base['promotion_config'] ?? []),
                    'retention_config' => $this->json($input['retention_config'] ?? $base['retention_config'] ?? []),
                    'warning_config' => $this->json($input['warning_config'] ?? $base['warning_config'] ?? []),
                    'status' => 'active',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            $after = Db::name('yfth_partner_rule_version')->where('id', $id)->find();
            $this->recordAudit('partner_rule_version', $id, 'rule_create', [], $after ?: [], $adminId, 0, $reason);
            return ['rule' => $after, 'rank_rules' => $this->rankRules($id)];
        });
    }

    public function adminPublishRule(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->requiredReason($data);
        return Db::transaction(function () use ($id, $adminId, $reason) {
            $rule = Db::name('yfth_partner_rule_version')->where('id', $id)->lock(true)->find();
            if (!$rule) {
                throw new ApiException('partner_rule_not_found');
            }
            if ((string)$rule['status'] === 'published') {
                return ['rule' => $rule, 'idempotent' => true];
            }
            if (count($this->rankRules($id)) !== count(self::RANKS)) {
                throw new ApiException('partner_rule_rank_incomplete');
            }
            $now = time();
            Db::name('yfth_partner_rule_version')->where('active_key', 'published')->update([
                'status' => 'disabled', 'active_key' => null, 'update_time' => $now,
            ]);
            Db::name('yfth_partner_rule_version')->where('id', $id)->update([
                'status' => 'published', 'active_key' => 'published', 'effective_time' => $now,
                'operator_uid' => $adminId, 'update_time' => $now,
            ]);
            $after = Db::name('yfth_partner_rule_version')->where('id', $id)->find();
            $this->recordAudit('partner_rule_version', $id, 'rule_publish', $rule, $after ?: [], $adminId, 0, $reason);
            return ['rule' => $after, 'idempotent' => false];
        });
    }

    public function adminPartners(array $filters, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        [$page, $limit] = $this->paging($filters);
        $query = Db::name('yfth_partner_profile')->alias('p')->leftJoin('user u', 'u.uid=p.uid')
            ->leftJoin('system_store s', 's.id=p.primary_store_id');
        if (!empty($filters['rank_code'])) {
            $query->where('p.rank_code', trim((string)$filters['rank_code']));
        }
        if (!empty($filters['status'])) {
            $query->where('p.status', trim((string)$filters['status']));
        }
        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($where) use ($keyword) {
                $where->whereLike('u.nickname|u.phone|u.account|s.name', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $where->whereOr('p.uid', (int)$keyword);
                }
            });
        }
        $count = (int)(clone $query)->count();
        $rows = $query->field('p.*,u.nickname,u.phone,u.account,s.name AS store_name')->page($page, $limit)->order('p.id desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row = $this->partnerDto($row);
        }
        return ['list' => $rows, 'count' => $count, 'rank_options' => $this->rankOptions()];
    }

    public function adminPartnerDetail(int $uid, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $profile = $this->profile($uid, true);
        return $this->partnerDetail($profile);
    }

    public function adminGrantOptions(string $rankCode, string $keyword, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $rankCode = trim($rankCode);
        if ($rankCode !== '' && !isset(self::RANKS[$rankCode])) {
            throw new ApiException('partner_rank_invalid');
        }
        $requiredParentRank = $rankCode === '' ? '' : self::REQUIRED_PARENT_RANKS[$rankCode];
        $parents = [];
        if ($requiredParentRank !== '') {
            $query = Db::name('yfth_partner_profile')->alias('p')
                ->leftJoin('user u', 'u.uid=p.uid')
                ->where(['p.rank_code' => $requiredParentRank, 'p.status' => 'active']);
            $keyword = trim($keyword);
            if ($keyword !== '') {
                $query->where(function ($where) use ($keyword) {
                    $where->whereLike('u.nickname|u.phone|u.account', '%' . $keyword . '%');
                    if (ctype_digit($keyword)) {
                        $where->whereOr('p.uid', (int)$keyword);
                    }
                });
            }
            $rows = $query->field('p.uid,p.rank_code,u.nickname,u.account,u.phone')
                ->order('p.uid asc')->limit(100)->select()->toArray();
            foreach ($rows as $row) {
                $parents[] = [
                    'uid' => (int)$row['uid'],
                    'nickname' => (string)($row['nickname'] ?? ''),
                    'account' => (string)($row['account'] ?? ''),
                    'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
                    'rank_code' => (string)$row['rank_code'],
                    'rank_name' => self::RANKS[(string)$row['rank_code']]['name'],
                ];
            }
        }
        return [
            'rank_options' => $this->rankOptions(),
            'required_parent_rank' => $requiredParentRank,
            'required_parent_rank_name' => $requiredParentRank !== '' ? self::RANKS[$requiredParentRank]['name'] : '',
            'parent_required' => $requiredParentRank !== '',
            'parent_options' => $parents,
        ];
    }

    public function adminGrantPartner(int $uid, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $rankCode = trim((string)($data['rank_code'] ?? ''));
        if (!isset(self::RANKS[$rankCode])) {
            throw new ApiException('partner_rank_invalid');
        }
        $reason = $this->requiredReason($data);
        $parentUid = max(0, (int)($data['parent_uid'] ?? 0));
        $requiredParentRank = self::REQUIRED_PARENT_RANKS[$rankCode];
        if ($requiredParentRank === '' && $parentUid !== 0) {
            throw new ApiException('partner_top_rank_parent_forbidden');
        }
        if ($requiredParentRank !== '' && $parentUid <= 0) {
            throw new ApiException('partner_parent_required');
        }
        if ($uid <= 0 || $uid === $parentUid) {
            throw new ApiException('partner_relation_cycle_forbidden');
        }
        $user = Db::name('user')->where(['uid' => $uid, 'is_del' => 0])->find();
        if (!$user) {
            throw new ApiException('user_not_found');
        }

        return Db::transaction(function () use ($uid, $rankCode, $parentUid, $requiredParentRank, $reason, $adminId) {
            $lockUids = array_values(array_unique(array_filter([$uid, $parentUid])));
            sort($lockUids, SORT_NUMERIC);
            $lockedProfiles = Db::name('yfth_partner_profile')->whereIn('uid', $lockUids)
                ->order('uid asc')->lock(true)->select()->toArray();
            $profiles = [];
            foreach ($lockedProfiles as $profile) {
                $profiles[(int)$profile['uid']] = $profile;
            }
            $before = $profiles[$uid] ?? [];
            $parent = $parentUid > 0 ? ($profiles[$parentUid] ?? []) : [];
            if ($requiredParentRank !== '') {
                if (!$parent || (string)$parent['status'] !== 'active') {
                    throw new ApiException('partner_parent_not_active');
                }
                if ((string)$parent['rank_code'] !== $requiredParentRank) {
                    throw new ApiException('partner_parent_rank_invalid');
                }
                if ($this->wouldCreateCycle($uid, $parentUid)) {
                    throw new ApiException('partner_relation_cycle_forbidden');
                }
            }

            $relationBefore = Db::name('yfth_partner_relation')->where('active_key', 'partner:' . $uid)->lock(true)->find() ?: [];
            if ($before && (string)$before['status'] === 'active') {
                $sameRelation = $requiredParentRank === ''
                    ? !$relationBefore
                    : ($relationBefore && (int)$relationBefore['parent_uid'] === $parentUid);
                if ((string)$before['rank_code'] === $rankCode && $sameRelation) {
                    return [
                        'partner' => $this->partnerDto($before),
                        'relation' => $relationBefore,
                        'idempotent' => true,
                    ];
                }
                throw new ApiException('partner_already_active');
            }

            $now = time();
            $profileData = [
                'uid' => $uid,
                'rank_code' => $rankCode,
                'primary_store_id' => 0,
                'source_type' => 'headquarters_grant',
                'source_id' => $adminId,
                'legacy_franchisee_role_id' => (int)($before['legacy_franchisee_role_id'] ?? 0),
                'status' => 'active',
                'start_time' => $now,
                'end_time' => 0,
                'active_key' => 'partner:' . $uid,
                'create_time' => (int)($before['create_time'] ?? $now),
                'update_time' => $now,
            ];
            if ($before) {
                Db::name('yfth_partner_profile')->where('id', (int)$before['id'])->update($profileData);
                $profileData['id'] = (int)$before['id'];
            } else {
                $profileData['id'] = (int)Db::name('yfth_partner_profile')->insertGetId($profileData);
            }
            if ($relationBefore) {
                Db::name('yfth_partner_relation')->where('id', (int)$relationBefore['id'])->update([
                    'status' => 'closed', 'end_time' => $now, 'active_key' => null, 'update_time' => $now,
                ]);
            }
            $relation = [];
            if ($parentUid > 0) {
                $relationId = (int)Db::name('yfth_partner_relation')->insertGetId([
                    'partner_uid' => $uid, 'parent_uid' => $parentUid, 'source_application_id' => 0,
                    'status' => 'active', 'start_time' => $now, 'end_time' => 0,
                    'reason' => $reason, 'operator_uid' => $adminId,
                    'active_key' => 'partner:' . $uid, 'create_time' => $now, 'update_time' => $now,
                ]);
                $relation = Db::name('yfth_partner_relation')->where('id', $relationId)->find() ?: [];
            }
            Db::name('yfth_partner_rank_event')->insert([
                'partner_uid' => $uid,
                'from_rank' => (string)($before['rank_code'] ?? ''),
                'to_rank' => $rankCode,
                'action' => 'headquarters_grant',
                'rule_version_id' => (int)($this->activeRule()['id'] ?? 0),
                'reason' => $reason,
                'evidence_snapshot' => $this->json(['parent_uid' => $parentUid, 'parent_rank' => $requiredParentRank]),
                'operator_uid' => $adminId,
                'create_time' => $now,
            ]);
            $this->recordAudit('partner_profile', (int)$profileData['id'], 'headquarters_grant', $before, $profileData, $adminId, 0, $reason);
            if ($parentUid > 0) {
                $this->recordAudit('partner_relation', (int)$relation['id'], 'headquarters_grant_parent', $relationBefore, $relation, $adminId, 0, $reason);
            }
            return [
                'partner' => $this->partnerDto($profileData),
                'relation' => $relation,
                'idempotent' => false,
            ];
        });
    }

    public function adminChangeRank(int $uid, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->requiredReason($data);
        $action = trim((string)($data['action'] ?? 'promote'));
        $targetRank = trim((string)($data['target_rank'] ?? ''));
        if (!in_array($action, ['promote', 'demote', 'pause', 'resume', 'exit'], true)) {
            throw new ApiException('partner_rank_action_invalid');
        }
        if (in_array($action, ['promote', 'demote'], true) && !isset(self::RANKS[$targetRank])) {
            throw new ApiException('partner_rank_invalid');
        }
        return Db::transaction(function () use ($uid, $data, $adminId, $reason, $action, $targetRank) {
            $before = Db::name('yfth_partner_profile')->where('uid', $uid)->lock(true)->find();
            if (!$before) {
                throw new ApiException('partner_not_found');
            }
            $after = $before;
            if (in_array($action, ['promote', 'demote'], true)) {
                $currentLevel = self::RANKS[(string)$before['rank_code']]['level'] ?? 0;
                $targetLevel = self::RANKS[$targetRank]['level'];
                if (($action === 'promote' && $targetLevel <= $currentLevel) || ($action === 'demote' && $targetLevel >= $currentLevel)) {
                    throw new ApiException('partner_rank_direction_invalid');
                }
                $after['rank_code'] = $targetRank;
            } elseif ($action === 'pause') {
                $after['status'] = 'paused';
                $after['active_key'] = null;
            } elseif ($action === 'resume') {
                $after['status'] = 'active';
                $after['active_key'] = 'partner:' . $uid;
                $after['end_time'] = 0;
            } else {
                $after['status'] = 'exited';
                $after['active_key'] = null;
                $after['end_time'] = time();
            }
            $after['update_time'] = time();
            Db::name('yfth_partner_profile')->where('id', (int)$before['id'])->update([
                'rank_code' => $after['rank_code'], 'status' => $after['status'],
                'active_key' => $after['active_key'], 'end_time' => $after['end_time'], 'update_time' => $after['update_time'],
            ]);
            Db::name('yfth_partner_rank_event')->insert([
                'partner_uid' => $uid, 'from_rank' => (string)$before['rank_code'], 'to_rank' => (string)$after['rank_code'],
                'action' => $action, 'rule_version_id' => (int)($this->activeRule()['id'] ?? 0), 'reason' => $reason,
                'evidence_snapshot' => $this->json($data['evidence'] ?? []), 'operator_uid' => $adminId, 'create_time' => time(),
            ]);
            $this->recordAudit('partner_profile', (int)$before['id'], 'rank_' . $action, $before, $after, $adminId, (int)$before['primary_store_id'], $reason);
            return ['partner' => $this->partnerDto($after)];
        });
    }

    public function adminChangeParent(int $uid, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->requiredReason($data);
        $parentUid = max(0, (int)($data['parent_uid'] ?? 0));
        $profile = $this->profile($uid, true);
        $rankCode = (string)$profile['rank_code'];
        $requiredParentRank = self::REQUIRED_PARENT_RANKS[$rankCode] ?? null;
        if ($requiredParentRank === null) {
            throw new ApiException('partner_rank_invalid');
        }
        if ($uid === $parentUid || ($parentUid > 0 && $this->wouldCreateCycle($uid, $parentUid))) {
            throw new ApiException('partner_relation_cycle_forbidden');
        }
        if ($requiredParentRank === '' && $parentUid !== 0) {
            throw new ApiException('partner_top_rank_parent_forbidden');
        }
        if ($requiredParentRank !== '' && $parentUid <= 0) {
            throw new ApiException('partner_parent_required');
        }
        if ($parentUid > 0) {
            $parent = $this->profile($parentUid, true);
            if ((string)$parent['rank_code'] !== $requiredParentRank) {
                throw new ApiException('partner_parent_rank_invalid');
            }
        }
        return Db::transaction(function () use ($uid, $parentUid, $adminId, $reason, $requiredParentRank) {
            $locked = Db::name('yfth_partner_profile')->whereIn('uid', array_values(array_unique(array_filter([$uid, $parentUid]))))
                ->order('uid asc')->lock(true)->select()->toArray();
            $profiles = [];
            foreach ($locked as $row) {
                $profiles[(int)$row['uid']] = $row;
            }
            if (empty($profiles[$uid]) || (string)$profiles[$uid]['status'] !== 'active') {
                throw new ApiException('partner_not_found');
            }
            if ($requiredParentRank !== '' && (empty($profiles[$parentUid])
                || (string)$profiles[$parentUid]['status'] !== 'active'
                || (string)$profiles[$parentUid]['rank_code'] !== $requiredParentRank)) {
                throw new ApiException('partner_parent_rank_invalid');
            }
            $before = Db::name('yfth_partner_relation')->where('active_key', 'partner:' . $uid)->lock(true)->find();
            if ($before && (int)$before['parent_uid'] === $parentUid) {
                return ['relation' => $before, 'idempotent' => true];
            }
            $now = time();
            if ($before) {
                Db::name('yfth_partner_relation')->where('id', (int)$before['id'])->update([
                    'status' => 'closed', 'end_time' => $now, 'active_key' => null, 'update_time' => $now,
                ]);
            }
            $relation = [];
            if ($parentUid > 0) {
                $id = (int)Db::name('yfth_partner_relation')->insertGetId([
                    'partner_uid' => $uid, 'parent_uid' => $parentUid, 'source_application_id' => 0,
                    'status' => 'active', 'start_time' => $now, 'end_time' => 0, 'reason' => $reason,
                    'operator_uid' => $adminId, 'active_key' => 'partner:' . $uid, 'create_time' => $now, 'update_time' => $now,
                ]);
                $relation = Db::name('yfth_partner_relation')->where('id', $id)->find() ?: [];
            }
            $this->recordAudit('partner_relation', (int)($relation['id'] ?? $before['id'] ?? 0), 'parent_change', $before ?: [], $relation, $adminId, 0, $reason);
            return ['relation' => $relation, 'idempotent' => false];
        });
    }

    public function adminCorrectSource(int $applicationId, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->requiredReason($data);
        $partnerUid = max(0, (int)($data['direct_partner_uid'] ?? 0));
        return Db::transaction(function () use ($applicationId, $partnerUid, $adminId, $reason) {
            $source = Db::name('yfth_franchise_recruit_source')->where('application_id', $applicationId)->lock(true)->find();
            if (!$source) {
                throw new ApiException('franchise_recruit_source_not_found');
            }
            if ((string)$source['status'] !== 'mutable') {
                throw new ApiException('franchise_recruit_source_frozen');
            }
            if ($partnerUid === (int)$source['applicant_uid']) {
                throw new ApiException('franchise_recruit_self_forbidden');
            }
            $chain = $partnerUid > 0 ? $this->chainSnapshot($partnerUid) : [];
            $before = $source;
            $after = array_merge($source, [
                'source_type' => $partnerUid > 0 ? 'partner_invite' : 'headquarters_direct',
                'direct_partner_uid' => $partnerUid,
                'chain_snapshot' => $this->json($chain),
                'correction_reason' => $reason,
                'operator_uid' => $adminId,
                'update_time' => time(),
            ]);
            Db::name('yfth_franchise_recruit_source')->where('id', (int)$source['id'])->update($after);
            $this->recordAudit('franchise_recruit_source', (int)$source['id'], 'source_correct', $before, $after, $adminId, 0, $reason);
            return ['source' => $this->sourceDto($after)];
        });
    }

    public function adminPerformances(array $filters, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        [$page, $limit] = $this->paging($filters);
        $query = Db::name('yfth_partner_opening_performance')->alias('p')->leftJoin('system_store s', 's.id=p.store_id');
        if (!empty($filters['status'])) {
            $query->where('p.status', trim((string)$filters['status']));
        }
        if (!empty($filters['partner_uid'])) {
            $query->where('p.direct_partner_uid', (int)$filters['partner_uid']);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->field('p.*,s.name AS store_name')->page($page, $limit)->order('p.id desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row['chain_snapshot'] = $this->decode((string)($row['chain_snapshot'] ?? ''));
        }
        return ['list' => $rows, 'count' => $count];
    }

    public function adminRewards(array $filters, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        return $this->rewardList($filters, 0, true);
    }

    public function adminRewardTransition(int $id, string $action, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        if (!isset(self::REWARD_TRANSITIONS[$action])) {
            throw new ApiException('partner_reward_action_invalid');
        }
        $reason = $this->requiredReason($data);
        return Db::transaction(function () use ($id, $action, $adminId, $reason) {
            $before = Db::name('yfth_partner_reward_candidate')->where('id', $id)->lock(true)->find();
            if (!$before) {
                throw new ApiException('partner_reward_not_found');
            }
            $transition = self::REWARD_TRANSITIONS[$action];
            if ((string)$before['status'] === $transition['to']) {
                return ['candidate' => $before, 'idempotent' => true];
            }
            if (!in_array((string)$before['status'], $transition['from'], true)) {
                throw new ApiException('partner_reward_status_invalid');
            }
            $after = array_merge($before, [
                'status' => $transition['to'], 'operator_uid' => $adminId, 'operator_time' => time(),
                'remark' => $reason, 'update_time' => time(),
            ]);
            Db::name('yfth_partner_reward_candidate')->where('id', $id)->update([
                'status' => $after['status'], 'operator_uid' => $adminId, 'operator_time' => $after['operator_time'],
                'remark' => $reason, 'update_time' => $after['update_time'],
            ]);
            $this->recordAudit('partner_reward_candidate', $id, 'reward_' . $action, $before, $after, $adminId, (int)$before['store_id'], $reason);
            return ['candidate' => $after, 'idempotent' => false];
        });
    }

    public function adminSettleReward(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->requiredReason($data);
        $evidence = trim((string)($data['evidence'] ?? ''));
        if ($evidence === '') {
            throw new ApiException('partner_reward_settlement_evidence_required');
        }
        return Db::transaction(function () use ($id, $data, $adminId, $reason, $evidence) {
            $candidate = Db::name('yfth_partner_reward_candidate')->where('id', $id)->lock(true)->find();
            if (!$candidate) {
                throw new ApiException('partner_reward_not_found');
            }
            $existing = Db::name('yfth_partner_reward_settlement')->where('candidate_id', $id)->find();
            if ($existing) {
                return ['candidate' => $candidate, 'settlement' => $existing, 'idempotent' => true];
            }
            if ((string)$candidate['status'] !== 'confirmed') {
                throw new ApiException('partner_reward_settle_status_invalid');
            }
            $now = time();
            $settlementId = (int)Db::name('yfth_partner_reward_settlement')->insertGetId([
                'settlement_no' => 'YFPRS' . date('YmdHis') . str_pad((string)$id, 8, '0', STR_PAD_LEFT),
                'candidate_id' => $id, 'amount' => $candidate['amount'], 'method' => 'offline',
                'evidence' => mb_substr($evidence, 0, 255), 'remark' => $reason,
                'operator_uid' => $adminId, 'settled_time' => $now, 'create_time' => $now,
            ]);
            Db::name('yfth_partner_reward_candidate')->where('id', $id)->update([
                'status' => 'settled', 'operator_uid' => $adminId, 'operator_time' => $now,
                'remark' => $reason, 'update_time' => $now,
            ]);
            $after = Db::name('yfth_partner_reward_candidate')->where('id', $id)->find() ?: [];
            $settlement = Db::name('yfth_partner_reward_settlement')->where('id', $settlementId)->find() ?: [];
            $this->recordAudit('partner_reward_candidate', $id, 'reward_settle_offline', $candidate, $after, $adminId, (int)$candidate['store_id'], $reason);
            return ['candidate' => $after, 'settlement' => $settlement, 'idempotent' => false];
        });
    }

    public function adminWarnings(array $filters, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        [$page, $limit] = $this->paging($filters);
        $query = Db::name('yfth_partner_warning');
        if (!empty($filters['status'])) {
            $query->where('status', trim((string)$filters['status']));
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row['rank_name'] = self::RANKS[(string)$row['rank_code']]['name'] ?? (string)$row['rank_code'];
            $row['metrics_snapshot'] = $this->decode((string)($row['metrics_snapshot'] ?? ''));
        }
        return ['list' => $rows, 'count' => $count];
    }

    public function adminPromotionApplications(array $filters, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        [$page, $limit] = $this->paging($filters);
        $query = Db::name('yfth_partner_promotion_application')->alias('a')
            ->leftJoin('user u', 'u.uid=a.partner_uid');
        if (!empty($filters['status'])) {
            $query->where('a.status', trim((string)$filters['status']));
        }
        $count = (int)(clone $query)->count();
        $rows = $query->field('a.*,u.nickname,u.account,u.phone')->page($page, $limit)->order('a.id desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row['phone_masked'] = $this->maskPhone((string)($row['phone'] ?? ''));
            unset($row['phone']);
            $row['from_rank_name'] = self::RANKS[(string)$row['from_rank']]['name'] ?? (string)$row['from_rank'];
            $row['target_rank_name'] = self::RANKS[(string)$row['target_rank']]['name'] ?? (string)$row['target_rank'];
            $row['evidence_snapshot'] = $this->decode((string)($row['evidence_snapshot'] ?? ''));
        }
        return ['list' => $rows, 'count' => $count];
    }

    public function adminReviewPromotion(int $id, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $reason = $this->requiredReason($data);
        $action = trim((string)($data['action'] ?? 'approve'));
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new ApiException('partner_promotion_review_action_invalid');
        }
        return Db::transaction(function () use ($id, $action, $reason, $adminId) {
            $application = Db::name('yfth_partner_promotion_application')->where('id', $id)->lock(true)->find();
            if (!$application) {
                throw new ApiException('partner_promotion_application_not_found');
            }
            if ((string)$application['status'] !== 'pending') {
                return ['application' => $application, 'idempotent' => true];
            }
            $profile = Db::name('yfth_partner_profile')->where('uid', (int)$application['partner_uid'])->lock(true)->find();
            if (!$profile || (string)$profile['status'] !== 'active') {
                throw new ApiException('partner_not_found');
            }
            if ((string)$profile['rank_code'] !== (string)$application['from_rank']) {
                throw new ApiException('partner_promotion_rank_changed');
            }
            $now = time();
            if ($action === 'approve') {
                Db::name('yfth_partner_profile')->where('id', (int)$profile['id'])->update([
                    'rank_code' => (string)$application['target_rank'], 'update_time' => $now,
                ]);
                Db::name('yfth_partner_rank_event')->insert([
                    'partner_uid' => (int)$application['partner_uid'], 'from_rank' => (string)$application['from_rank'],
                    'to_rank' => (string)$application['target_rank'], 'action' => 'promotion_approved',
                    'rule_version_id' => (int)$application['rule_version_id'], 'reason' => $reason,
                    'evidence_snapshot' => (string)$application['evidence_snapshot'], 'operator_uid' => $adminId,
                    'create_time' => $now,
                ]);
            }
            $status = $action === 'approve' ? 'approved' : 'rejected';
            Db::name('yfth_partner_promotion_application')->where('id', $id)->update([
                'status' => $status, 'review_reason' => $reason, 'reviewer_uid' => $adminId,
                'review_time' => $now, 'active_key' => null, 'update_time' => $now,
            ]);
            $after = Db::name('yfth_partner_promotion_application')->where('id', $id)->find() ?: [];
            $this->recordAudit('partner_promotion_application', $id, 'promotion_' . $action, $application, $after, $adminId, (int)$profile['primary_store_id'], $reason);
            return ['application' => $after, 'idempotent' => false];
        });
    }

    public function applyPromotion(Request $request, array $data): array
    {
        $uid = $this->requestUid($request);
        $profile = $this->profile($uid, true);
        $targetRank = $this->nextRank((string)$profile['rank_code']);
        if ($targetRank === '') {
            throw new ApiException('partner_promotion_top_rank');
        }
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException('partner_promotion_reason_required');
        }
        return Db::transaction(function () use ($uid, $profile, $targetRank, $reason) {
            $activeKey = 'partner:' . $uid;
            $existing = Db::name('yfth_partner_promotion_application')->where('active_key', $activeKey)->lock(true)->find();
            if ($existing) {
                return ['application' => $existing, 'idempotent' => true];
            }
            $rule = $this->activeRule();
            $detail = $this->partnerDetail($profile);
            $now = time();
            $id = (int)Db::name('yfth_partner_promotion_application')->insertGetId([
                'application_no' => 'YFPPA' . date('YmdHis') . str_pad((string)$uid, 8, '0', STR_PAD_LEFT),
                'partner_uid' => $uid, 'from_rank' => (string)$profile['rank_code'], 'target_rank' => $targetRank,
                'rule_version_id' => (int)($rule['id'] ?? 0),
                'evidence_snapshot' => $this->json([
                    'performance' => $detail['performance'],
                    'promotion_rule' => $detail['promotion_rule'],
                    'submitted_reason' => mb_substr($reason, 0, 255),
                ]),
                'status' => 'pending', 'apply_reason' => mb_substr($reason, 0, 255),
                'review_reason' => '', 'reviewer_uid' => 0, 'review_time' => 0,
                'active_key' => $activeKey, 'create_time' => $now, 'update_time' => $now,
            ]);
            $after = Db::name('yfth_partner_promotion_application')->where('id', $id)->find() ?: [];
            $this->audit->record(self::DOMAIN, 'partner_promotion_application', (string)$id, 'promotion_apply', [], $after,
                $uid, 'partner', (int)$profile['primary_store_id'], $reason, 'partner-promotion-apply-' . $id);
            return ['application' => $after, 'idempotent' => false];
        });
    }

    public function createInvite(Request $request): array
    {
        $uid = $this->requestUid($request);
        $profile = $this->profile($uid, true);
        if ((string)$profile['status'] !== 'active') {
            throw new ApiException('partner_invite_profile_inactive');
        }
        return Db::transaction(function () use ($uid) {
            $existing = Db::name('yfth_partner_invite')->where('active_key', 'partner:' . $uid)->lock(true)->find();
            if ($existing) {
                Db::name('yfth_partner_invite')->where('id', (int)$existing['id'])->update([
                    'status' => 'replaced', 'active_key' => null, 'invalidated_time' => time(), 'update_time' => time(),
                ]);
            }
            $token = bin2hex(random_bytes(32));
            $now = time();
            $id = (int)Db::name('yfth_partner_invite')->insertGetId([
                'partner_uid' => $uid, 'token_hash' => hash('sha256', $token), 'code_tail' => substr($token, -8),
                'status' => 'active', 'expire_time' => $now + 86400 * 30, 'invalidated_time' => 0,
                'active_key' => 'partner:' . $uid, 'create_time' => $now, 'update_time' => $now,
            ]);
            return [
                'invite_id' => $id,
                'invite_token' => $token,
                'invite_path' => '/pages/yfth/franchise/apply?partner_invite=' . $token,
                'expire_time' => $now + 86400 * 30,
            ];
        });
    }

    public function myWorkbench(Request $request): array
    {
        $uid = $this->requestUid($request);
        $profile = $this->profile($uid, true);
        $detail = $this->partnerDetail($profile);
        unset($detail['rank_events']);
        $detail['my_applications'] = $this->partnerApplications($uid, 10);
        $detail['reward_summary'] = $this->rewardSummary($uid);
        $detail['warnings'] = Db::name('yfth_partner_warning')->where(['partner_uid' => $uid, 'status' => 'open'])->order('id desc')->select()->toArray();
        $detail['promotion_application'] = Db::name('yfth_partner_promotion_application')->where('partner_uid', $uid)->order('id desc')->find() ?: [];
        $detail['next_rank'] = $this->nextRank((string)$profile['rank_code']);
        return $detail;
    }

    public function myTeam(Request $request): array
    {
        $uid = $this->requestUid($request);
        $this->profile($uid, true);
        return ['tree' => $this->teamTree($uid, 0, 5)];
    }

    public function myRewards(Request $request, array $filters): array
    {
        $uid = $this->requestUid($request);
        $this->profile($uid, true);
        return $this->rewardList($filters, $uid, false);
    }

    public function captureRecruitSource(int $applicationId, int $applicantUid, string $inviteToken): array
    {
        $existing = Db::name('yfth_franchise_recruit_source')->where('application_id', $applicationId)->find();
        if ($existing) {
            return $existing;
        }
        $partnerUid = 0;
        $sourceType = 'headquarters_direct';
        $chain = [];
        $inviteToken = trim($inviteToken);
        if ($inviteToken !== '') {
            $invite = Db::name('yfth_partner_invite')->where('token_hash', hash('sha256', $inviteToken))->find();
            if (!$invite || (string)$invite['status'] !== 'active' || ((int)$invite['expire_time'] > 0 && (int)$invite['expire_time'] < time())) {
                throw new ApiException('partner_invite_invalid');
            }
            $partnerUid = (int)$invite['partner_uid'];
            if ($partnerUid === $applicantUid) {
                throw new ApiException('franchise_recruit_self_forbidden');
            }
            $chain = $this->chainSnapshot($partnerUid);
            $sourceType = 'partner_invite';
        }
        $now = time();
        $id = (int)Db::name('yfth_franchise_recruit_source')->insertGetId([
            'application_id' => $applicationId, 'applicant_uid' => $applicantUid,
            'source_type' => $sourceType, 'direct_partner_uid' => $partnerUid,
            'chain_snapshot' => $this->json($chain), 'status' => 'mutable', 'frozen_time' => 0,
            'correction_reason' => '', 'operator_uid' => 0, 'create_time' => $now, 'update_time' => $now,
        ]);
        return Db::name('yfth_franchise_recruit_source')->where('id', $id)->find() ?: [];
    }

    public function freezeRecruitSource(int $applicationId, int $adminId): array
    {
        $source = Db::name('yfth_franchise_recruit_source')->where('application_id', $applicationId)->lock(true)->find();
        if (!$source) {
            $application = Db::name('yfth_franchise_application')->where('id', $applicationId)->find();
            if (!$application) {
                throw new ApiException('franchise_application_not_found');
            }
            $source = $this->captureRecruitSource($applicationId, (int)$application['applicant_uid'], '');
        }
        if ((string)$source['status'] === 'frozen') {
            return $source;
        }
        if ((string)$source['status'] !== 'mutable') {
            throw new ApiException('franchise_recruit_source_invalid');
        }
        $before = $source;
        $source['status'] = 'frozen';
        $source['frozen_time'] = time();
        $source['operator_uid'] = $adminId;
        $source['update_time'] = time();
        Db::name('yfth_franchise_recruit_source')->where('id', (int)$source['id'])->update([
            'status' => 'frozen', 'frozen_time' => $source['frozen_time'], 'operator_uid' => $adminId, 'update_time' => $source['update_time'],
        ]);
        $this->recordAudit('franchise_recruit_source', (int)$source['id'], 'source_freeze', $before, $source, $adminId, 0, 'finance_confirmed');
        return $source;
    }

    public function finalizeOpeningInTransaction(array $application, int $storeId, int $legacyRoleId, int $adminId): array
    {
        $applicationId = (int)$application['id'];
        $uid = (int)$application['applicant_uid'];
        $source = $this->freezeRecruitSource($applicationId, $adminId);
        $profile = Db::name('yfth_partner_profile')->where('uid', $uid)->lock(true)->find();
        if ($profile && (string)$profile['status'] === 'active') {
            if ((int)$profile['primary_store_id'] !== $storeId && (int)$profile['source_id'] === $applicationId) {
                throw new ApiException('partner_opening_store_conflict');
            }
            if ((int)$profile['legacy_franchisee_role_id'] <= 0) {
                Db::name('yfth_partner_profile')->where('id', (int)$profile['id'])->update([
                    'legacy_franchisee_role_id' => $legacyRoleId,
                    'update_time' => time(),
                ]);
                $profile['legacy_franchisee_role_id'] = $legacyRoleId;
            }
        } else {
            $now = time();
            $data = [
                'uid' => $uid, 'rank_code' => 'county_partner', 'primary_store_id' => $storeId,
                'source_type' => 'franchise_opening', 'source_id' => $applicationId,
                'legacy_franchisee_role_id' => $legacyRoleId, 'status' => 'active',
                'start_time' => $now, 'end_time' => 0, 'active_key' => 'partner:' . $uid,
                'create_time' => $now, 'update_time' => $now,
            ];
            if ($profile) {
                Db::name('yfth_partner_profile')->where('id', (int)$profile['id'])->update($data);
                $profile = array_merge($profile, $data);
            } else {
                $data['id'] = (int)Db::name('yfth_partner_profile')->insertGetId($data);
                $profile = $data;
            }
            Db::name('yfth_partner_rank_event')->insert([
                'partner_uid' => $uid, 'from_rank' => '', 'to_rank' => 'county_partner', 'action' => 'opening_grant',
                'rule_version_id' => (int)($this->activeRule()['id'] ?? 0), 'reason' => 'formal_franchise_opening',
                'evidence_snapshot' => $this->json(['application_id' => $applicationId, 'store_id' => $storeId, 'legacy_role_id' => $legacyRoleId]),
                'operator_uid' => $adminId, 'create_time' => $now,
            ]);
        }
        $parentUid = (int)($source['direct_partner_uid'] ?? 0);
        if ($parentUid > 0) {
            $this->profile($parentUid, true);
            if ($this->wouldCreateCycle($uid, $parentUid)) {
                throw new ApiException('partner_relation_cycle_detected');
            }
            $activeKey = 'partner:' . $uid;
            $relation = Db::name('yfth_partner_relation')->where('active_key', $activeKey)->find();
            if (!$relation) {
                $now = time();
                Db::name('yfth_partner_relation')->insert([
                    'partner_uid' => $uid, 'parent_uid' => $parentUid, 'source_application_id' => $applicationId,
                    'status' => 'active', 'start_time' => $now, 'end_time' => 0, 'reason' => 'formal_franchise_opening',
                    'operator_uid' => $adminId, 'active_key' => $activeKey, 'create_time' => $now, 'update_time' => $now,
                ]);
            }
        }
        $performance = $this->ensurePerformanceAndRewards($application, $storeId, $source, $adminId);
        $this->recordAudit('partner_profile', (int)$profile['id'], 'opening_county_partner_grant', [], $profile, $adminId, $storeId, 'formal_franchise_opening');
        return ['partner' => $this->partnerDto($profile), 'performance' => $performance];
    }

    private function ensurePerformanceAndRewards(array $application, int $storeId, array $source, int $adminId): array
    {
        $applicationId = (int)$application['id'];
        $existing = Db::name('yfth_partner_opening_performance')->where('application_id', $applicationId)->find();
        if ($existing) {
            return $existing;
        }
        $rule = $this->activeRule();
        if (!$rule) {
            throw new ApiException('partner_active_rule_missing');
        }
        $chain = $this->decode((string)($source['chain_snapshot'] ?? ''));
        $now = time();
        $performanceId = (int)Db::name('yfth_partner_opening_performance')->insertGetId([
            'performance_no' => 'YFPOP' . date('YmdHis') . str_pad((string)$applicationId, 8, '0', STR_PAD_LEFT),
            'application_id' => $applicationId, 'applicant_uid' => (int)$application['applicant_uid'], 'store_id' => $storeId,
            'direct_partner_uid' => (int)($source['direct_partner_uid'] ?? 0), 'rule_version_id' => (int)$rule['id'],
            'order_amount' => $rule['order_amount'], 'bottle_count' => (int)$rule['bottle_count'],
            'chain_snapshot' => $this->json($chain), 'status' => 'valid', 'opened_time' => $now,
            'invalid_reason' => '', 'create_time' => $now, 'update_time' => $now,
        ]);
        $rankRules = [];
        foreach ($this->rankRules((int)$rule['id']) as $rankRule) {
            $rankRules[(string)$rankRule['rank_code']] = $rankRule;
        }
        $usedRanks = [];
        foreach ($chain as $position => $snapshot) {
            $beneficiaryUid = (int)($snapshot['uid'] ?? 0);
            $current = $beneficiaryUid > 0 ? Db::name('yfth_partner_profile')->where(['uid' => $beneficiaryUid, 'status' => 'active'])->find() : null;
            if (!$current) {
                continue;
            }
            // The finance-confirmed recruiting chain is immutable. Current state is used only
            // to prove that the beneficiary still exists and is active at opening time.
            $rankCode = (string)($snapshot['rank_code'] ?? '');
            if (isset($usedRanks[$rankCode]) || !isset($rankRules[$rankCode])) {
                continue;
            }
            $usedRanks[$rankCode] = true;
            $rankRule = $rankRules[$rankCode];
            $amount = bcmul((string)$rule['bottle_count'], (string)$rankRule['reward_per_bottle'], 2);
            Db::name('yfth_partner_reward_candidate')->insert([
                'candidate_no' => 'YFPRC' . date('YmdHis') . str_pad((string)$performanceId, 7, '0', STR_PAD_LEFT) . (int)$rankRule['rank_level'],
                'performance_id' => $performanceId, 'application_id' => $applicationId, 'store_id' => $storeId,
                'beneficiary_uid' => $beneficiaryUid, 'rank_code' => $rankCode,
                'rank_name_snapshot' => (string)$rankRule['rank_name'], 'chain_position' => $position + 1,
                'rule_version_id' => (int)$rule['id'], 'bottle_count' => (int)$rule['bottle_count'],
                'reward_per_bottle' => $rankRule['reward_per_bottle'], 'amount' => $amount,
                'status' => 'pending', 'operator_uid' => 0, 'operator_time' => 0, 'remark' => '',
                'create_time' => $now, 'update_time' => $now,
            ]);
        }
        $performance = Db::name('yfth_partner_opening_performance')->where('id', $performanceId)->find() ?: [];
        $this->recordAudit('partner_opening_performance', $performanceId, 'opening_performance_create', [], $performance, $adminId, $storeId, 'formal_franchise_opening');
        return $performance;
    }

    private function partnerDetail(array $profile): array
    {
        $uid = (int)$profile['uid'];
        $parent = Db::name('yfth_partner_relation')->alias('r')->leftJoin('user u', 'u.uid=r.parent_uid')
            ->where('r.active_key', 'partner:' . $uid)->field('r.*,u.nickname,u.account')->find() ?: [];
        $children = Db::name('yfth_partner_relation')->alias('r')->leftJoin('yfth_partner_profile p', 'p.uid=r.partner_uid')
            ->leftJoin('user u', 'u.uid=r.partner_uid')->where(['r.parent_uid' => $uid, 'r.status' => 'active'])
            ->field('r.partner_uid,p.rank_code,p.status,u.nickname,u.account')->select()->toArray();
        $storeRoles = Db::name('yfth_user_store_role')->alias('r')->leftJoin('system_store s', 's.id=r.store_id')
            ->where(['r.uid' => $uid, 'r.status' => 'active'])->field('r.store_id,r.role_code,s.name AS store_name')->select()->toArray();
        $personal = (int)Db::name('yfth_partner_opening_performance')->where(['direct_partner_uid' => $uid, 'status' => 'valid'])->count();
        $teamUids = $this->descendantUids($uid);
        $team = $personal;
        if ($teamUids) {
            $team += (int)Db::name('yfth_partner_opening_performance')->whereIn('direct_partner_uid', $teamUids)->where('status', 'valid')->count();
        }
        return [
            'profile' => $this->partnerDto($profile),
            'parent' => $parent,
            'direct_children' => $children,
            'store_roles' => $storeRoles,
            'performance' => ['personal_openings' => $personal, 'team_openings' => $team, 'team_size' => count($teamUids)],
            'promotion_rule' => $this->currentRankRule((string)$profile['rank_code']),
            'rank_events' => Db::name('yfth_partner_rank_event')->where('partner_uid', $uid)->order('id desc')->select()->toArray(),
            'reward_summary' => $this->rewardSummary($uid),
        ];
    }

    private function rewardList(array $filters, int $uid, bool $admin): array
    {
        [$page, $limit] = $this->paging($filters);
        $query = Db::name('yfth_partner_reward_candidate')->alias('c')->leftJoin('user u', 'u.uid=c.beneficiary_uid')
            ->leftJoin('system_store s', 's.id=c.store_id');
        if ($uid > 0) {
            $query->where('c.beneficiary_uid', $uid);
        }
        if (!empty($filters['status'])) {
            $query->where('c.status', trim((string)$filters['status']));
        }
        if (!empty($filters['rank_code'])) {
            $query->where('c.rank_code', trim((string)$filters['rank_code']));
        }
        $count = (int)(clone $query)->count();
        $fields = 'c.id,c.candidate_no,c.application_id,c.store_id,c.rank_code,c.rank_name_snapshot,c.bottle_count,c.reward_per_bottle,c.amount,c.status,c.operator_time,c.remark,c.create_time,s.name AS store_name';
        if ($admin) {
            $fields .= ',c.beneficiary_uid,u.nickname,u.account';
        }
        $rows = $query->field($fields)->page($page, $limit)->order('c.id desc')->select()->toArray();
        return ['list' => $rows, 'count' => $count, 'disclaimer' => '待确认收益不代表已结算或已支付。'];
    }

    private function rewardSummary(int $uid): array
    {
        $summary = ['pending' => '0.00', 'confirmed' => '0.00', 'settled' => '0.00', 'cancelled' => '0.00'];
        $rows = Db::name('yfth_partner_reward_candidate')->where('beneficiary_uid', $uid)->field('status,SUM(amount) AS amount')->group('status')->select()->toArray();
        foreach ($rows as $row) {
            $summary[(string)$row['status']] = (string)$row['amount'];
        }
        return $summary;
    }

    private function partnerApplications(int $uid, int $limit): array
    {
        return Db::name('yfth_franchise_recruit_source')->alias('r')->leftJoin('yfth_franchise_application a', 'a.id=r.application_id')
            ->where('r.direct_partner_uid', $uid)
            ->field('a.id,a.application_no,a.name,a.city,a.status,a.create_time,r.status AS source_status')
            ->order('a.id desc')->limit($limit)->select()->toArray();
    }

    private function chainSnapshot(int $directPartnerUid): array
    {
        $chain = [];
        $seen = [];
        $uid = $directPartnerUid;
        for ($depth = 0; $depth < 20 && $uid > 0; $depth++) {
            if (isset($seen[$uid])) {
                throw new ApiException('partner_relation_cycle_detected');
            }
            $seen[$uid] = true;
            $profile = $this->profile($uid, true);
            if ((string)$profile['status'] !== 'active') {
                throw new ApiException('partner_recruiter_inactive');
            }
            $chain[] = [
                'uid' => $uid,
                'rank_code' => (string)$profile['rank_code'],
                'rank_name' => self::RANKS[(string)$profile['rank_code']]['name'] ?? (string)$profile['rank_code'],
                'store_id' => (int)$profile['primary_store_id'],
            ];
            $relation = Db::name('yfth_partner_relation')->where('active_key', 'partner:' . $uid)->find();
            $uid = $relation ? (int)$relation['parent_uid'] : 0;
        }
        return $chain;
    }

    private function wouldCreateCycle(int $uid, int $parentUid): bool
    {
        $seen = [];
        $cursor = $parentUid;
        for ($depth = 0; $depth < 50 && $cursor > 0; $depth++) {
            if ($cursor === $uid || isset($seen[$cursor])) {
                return true;
            }
            $seen[$cursor] = true;
            $relation = Db::name('yfth_partner_relation')->where('active_key', 'partner:' . $cursor)->find();
            $cursor = $relation ? (int)$relation['parent_uid'] : 0;
        }
        return false;
    }

    private function descendantUids(int $uid): array
    {
        $result = [];
        $frontier = [$uid];
        for ($depth = 0; $depth < 20 && $frontier; $depth++) {
            $children = Db::name('yfth_partner_relation')->whereIn('parent_uid', $frontier)->where('status', 'active')->column('partner_uid');
            $frontier = [];
            foreach (array_map('intval', $children) as $child) {
                if ($child > 0 && !isset($result[$child])) {
                    $result[$child] = true;
                    $frontier[] = $child;
                }
            }
        }
        return array_keys($result);
    }

    private function teamTree(int $uid, int $depth, int $maxDepth): array
    {
        if ($depth >= $maxDepth) {
            return [];
        }
        $rows = Db::name('yfth_partner_relation')->alias('r')->leftJoin('yfth_partner_profile p', 'p.uid=r.partner_uid')
            ->leftJoin('user u', 'u.uid=r.partner_uid')->where(['r.parent_uid' => $uid, 'r.status' => 'active'])
            ->field('r.partner_uid,p.rank_code,p.status,u.nickname,u.account')->select()->toArray();
        foreach ($rows as &$row) {
            $row['rank_name'] = self::RANKS[(string)$row['rank_code']]['name'] ?? (string)$row['rank_code'];
            $row['children'] = $this->teamTree((int)$row['partner_uid'], $depth + 1, $maxDepth);
        }
        return $rows;
    }

    private function profile(int $uid, bool $requireActive): array
    {
        $profile = $uid > 0 ? Db::name('yfth_partner_profile')->where('uid', $uid)->find() : null;
        if (!$profile || ($requireActive && (string)$profile['status'] !== 'active')) {
            throw new ApiException('partner_not_found');
        }
        return $profile;
    }

    private function partnerDto(array $row): array
    {
        $rank = self::RANKS[(string)($row['rank_code'] ?? '')] ?? ['name' => (string)($row['rank_code'] ?? ''), 'level' => 0];
        return [
            'id' => (int)($row['id'] ?? 0), 'uid' => (int)($row['uid'] ?? 0),
            'nickname' => (string)($row['nickname'] ?? ''), 'account' => (string)($row['account'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
            'rank_code' => (string)($row['rank_code'] ?? ''), 'rank_name' => $rank['name'], 'rank_level' => $rank['level'],
            'primary_store_id' => (int)($row['primary_store_id'] ?? 0), 'store_name' => (string)($row['store_name'] ?? ''),
            'status' => (string)($row['status'] ?? ''), 'start_time' => (int)($row['start_time'] ?? 0),
            'source_type' => (string)($row['source_type'] ?? ''), 'source_id' => (int)($row['source_id'] ?? 0),
            'legacy_compatible' => (int)($row['legacy_franchisee_role_id'] ?? 0) > 0,
        ];
    }

    private function sourceDto(array $row): array
    {
        return [
            'application_id' => (int)($row['application_id'] ?? 0),
            'source_type' => (string)($row['source_type'] ?? ''),
            'direct_partner_uid' => (int)($row['direct_partner_uid'] ?? 0),
            'chain_snapshot' => $this->decode((string)($row['chain_snapshot'] ?? '')),
            'status' => (string)($row['status'] ?? ''), 'frozen_time' => (int)($row['frozen_time'] ?? 0),
        ];
    }

    private function activeRule(): array
    {
        return Db::name('yfth_partner_rule_version')->where(['status' => 'published', 'active_key' => 'published'])->find() ?: [];
    }

    private function rankRules(int $ruleId): array
    {
        $rows = Db::name('yfth_partner_rank_rule')->where('rule_version_id', $ruleId)->order('rank_level asc')->select()->toArray();
        foreach ($rows as &$row) {
            foreach (['promotion_config', 'retention_config', 'warning_config'] as $field) {
                $row[$field] = $this->decode((string)($row[$field] ?? ''));
            }
        }
        return $rows;
    }

    private function currentRankRule(string $rankCode): array
    {
        $active = $this->activeRule();
        if (!$active) {
            return [];
        }
        foreach ($this->rankRules((int)$active['id']) as $rule) {
            if ((string)$rule['rank_code'] === $rankCode) {
                return $rule;
            }
        }
        return [];
    }

    private function rankOptions(): array
    {
        $result = [];
        foreach (self::RANKS as $value => $rank) {
            $result[] = ['value' => $value, 'label' => $rank['name'], 'level' => $rank['level']];
        }
        return $result;
    }

    private function nextRank(string $rankCode): string
    {
        $codes = array_keys(self::RANKS);
        $index = array_search($rankCode, $codes, true);
        return $index === false || !isset($codes[$index + 1]) ? '' : $codes[$index + 1];
    }

    private function requestUid(Request $request): int
    {
        $uid = (int)$request->uid();
        if ($uid <= 0) {
            throw new ApiException('user_not_login');
        }
        return $uid;
    }

    private function assertHeadquarters(array $adminInfo): void
    {
        $this->adminScope->assertHeadquarterScope($adminInfo);
    }

    private function requiredReason(array $data): string
    {
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException('operation_reason_required');
        }
        return mb_substr($reason, 0, 255);
    }

    private function recordAudit(string $type, int $id, string $action, array $before, array $after, int $operatorUid, int $storeId, string $reason): void
    {
        $this->audit->record(self::DOMAIN, $type, (string)$id, $action, $before, $after, $operatorUid, 'headquarters_admin', $storeId, $reason, 'partner-' . $action . '-' . $id . '-' . time());
    }

    private function paging(array $filters): array
    {
        return [max(1, (int)($filters['page'] ?? 1)), max(1, min(100, (int)($filters['limit'] ?? 20)))];
    }

    private function money($value): string
    {
        if (!is_numeric($value) || bccomp((string)$value, '0', 2) < 0) {
            throw new ApiException('amount_invalid');
        }
        return bcadd((string)$value, '0', 2);
    }

    private function json($value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        return (string)json_encode($value ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function maskPhone(string $phone): string
    {
        return preg_match('/^(\d{3})\d+(\d{4})$/', $phone, $matches) ? $matches[1] . '****' . $matches[2] : $phone;
    }
}
