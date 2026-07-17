<?php

use think\migration\Migrator;

class CreateYfthFranchisePartnerHierarchy extends Migrator
{
    private const MENU_KEYS = [
        'yfth-franchise-partner-index',
        'yfth-franchise-partner-dashboard',
        'yfth-franchise-partner-rule-list',
        'yfth-franchise-partner-rule-save',
        'yfth-franchise-partner-rule-publish',
        'yfth-franchise-partner-profile-list',
        'yfth-franchise-partner-profile-detail',
        'yfth-franchise-partner-rank-change',
        'yfth-franchise-partner-parent-change',
        'yfth-franchise-partner-source-correct',
        'yfth-franchise-partner-performance-list',
        'yfth-franchise-partner-reward-list',
        'yfth-franchise-partner-reward-confirm',
        'yfth-franchise-partner-reward-cancel',
        'yfth-franchise-partner-reward-settle',
        'yfth-franchise-partner-warning-list',
        'yfth-franchise-partner-promotion-list',
        'yfth-franchise-partner-promotion-review',
        'yfth-franchise-partner-opening-complete',
        'yfth-franchise-opening-store-create',
    ];

    public function up()
    {
        $this->createRuleVersionTable();
        $this->createRankRuleTable();
        $this->createProfileTable();
        $this->createRelationTable();
        $this->createRankEventTable();
        $this->createInviteTable();
        $this->createRecruitSourceTable();
        $this->createPerformanceTable();
        $this->createRewardCandidateTable();
        $this->createSettlementTable();
        $this->createWarningTable();
        $this->createPromotionApplicationTable();
        $this->seedDefaultRule();
        $this->backfillLegacyFranchisees();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, self::MENU_KEYS);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        foreach ([
            'yfth_partner_promotion_application',
            'yfth_partner_warning',
            'yfth_partner_reward_settlement',
            'yfth_partner_reward_candidate',
            'yfth_partner_opening_performance',
            'yfth_franchise_recruit_source',
            'yfth_partner_invite',
            'yfth_partner_rank_event',
            'yfth_partner_relation',
            'yfth_partner_profile',
            'yfth_partner_rank_rule',
            'yfth_partner_rule_version',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createRuleVersionTable(): void
    {
        if ($this->hasTable('yfth_partner_rule_version')) {
            return;
        }
        $this->table('yfth_partner_rule_version')
            ->setEngine('InnoDB')
            ->setComment('YFTH partner hierarchy versioned rules')
            ->addColumn('rule_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'immutable rule number'])
            ->addColumn('version_no', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'version number'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'draft', 'comment' => 'draft/published/disabled'])
            ->addColumn('order_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '89100.00', 'comment' => 'valid opening amount snapshot'])
            ->addColumn('bottle_count', 'integer', ['signed' => false, 'default' => 440, 'comment' => 'default bottle count'])
            ->addColumn('platform_dividend_bps', 'integer', ['signed' => false, 'default' => 100, 'comment' => 'platform director qualification bps'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective at'])
            ->addColumn('active_key', 'string', ['limit' => 32, 'null' => true, 'default' => null, 'comment' => 'published singleton'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'admin operator'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['rule_no'], ['unique' => true, 'name' => 'uniq_yfth_partner_rule_no'])
            ->addIndex(['version_no'], ['unique' => true, 'name' => 'uniq_yfth_partner_rule_version'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_rule_active'])
            ->create();
    }

    private function createRankRuleTable(): void
    {
        if ($this->hasTable('yfth_partner_rank_rule')) {
            return;
        }
        $this->table('yfth_partner_rank_rule')
            ->setEngine('InnoDB')
            ->setComment('YFTH partner rank rules by version')
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('rank_name', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('rank_level', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('reward_per_bottle', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('promotion_config', 'text', ['null' => true])
            ->addColumn('retention_config', 'text', ['null' => true])
            ->addColumn('warning_config', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['rule_version_id', 'rank_code'], ['unique' => true, 'name' => 'uniq_yfth_partner_rank_rule'])
            ->addIndex(['rank_code', 'status'], ['name' => 'idx_yfth_partner_rank_status'])
            ->create();
    }

    private function createProfileTable(): void
    {
        if ($this->hasTable('yfth_partner_profile')) {
            return;
        }
        $this->table('yfth_partner_profile')
            ->setEngine('InnoDB')
            ->setComment('YFTH current招商合伙人 profile')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => 'county_partner'])
            ->addColumn('primary_store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 32, 'default' => 'franchise_opening'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('legacy_franchisee_role_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/paused/exited'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['uid'], ['unique' => true, 'name' => 'uniq_yfth_partner_profile_uid'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_profile_active'])
            ->addIndex(['rank_code', 'status'], ['name' => 'idx_yfth_partner_profile_rank'])
            ->addIndex(['primary_store_id', 'status'], ['name' => 'idx_yfth_partner_profile_store'])
            ->create();
    }

    private function createRelationTable(): void
    {
        if ($this->hasTable('yfth_partner_relation')) {
            return;
        }
        $this->table('yfth_partner_relation')
            ->setEngine('InnoDB')
            ->setComment('YFTH招商合伙人 direct hierarchy')
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('parent_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_application_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_relation_active'])
            ->addIndex(['parent_uid', 'status'], ['name' => 'idx_yfth_partner_relation_parent'])
            ->addIndex(['partner_uid', 'create_time'], ['name' => 'idx_yfth_partner_relation_history'])
            ->create();
    }

    private function createRankEventTable(): void
    {
        if ($this->hasTable('yfth_partner_rank_event')) {
            return;
        }
        $this->table('yfth_partner_rank_event')
            ->setEngine('InnoDB')
            ->setComment('YFTH immutable partner rank events')
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('from_rank', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('to_rank', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('action', 'string', ['limit' => 32, 'default' => 'grant'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('evidence_snapshot', 'text', ['null' => true])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['partner_uid', 'create_time'], ['name' => 'idx_yfth_partner_rank_event_uid'])
            ->create();
    }

    private function createInviteTable(): void
    {
        if ($this->hasTable('yfth_partner_invite')) {
            return;
        }
        $this->table('yfth_partner_invite')
            ->setEngine('InnoDB')
            ->setComment('YFTH partner franchise application invites')
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('code_tail', 'string', ['limit' => 12, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('invalidated_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_yfth_partner_invite_hash'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_invite_active'])
            ->create();
    }

    private function createRecruitSourceTable(): void
    {
        if ($this->hasTable('yfth_franchise_recruit_source')) {
            return;
        }
        $this->table('yfth_franchise_recruit_source')
            ->setEngine('InnoDB')
            ->setComment('YFTH frozen franchise recruitment source')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('applicant_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 32, 'default' => 'headquarters_direct'])
            ->addColumn('direct_partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('chain_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'mutable', 'comment' => 'mutable/frozen/invalid'])
            ->addColumn('frozen_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('correction_reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_franchise_recruit_source_app'])
            ->addIndex(['direct_partner_uid', 'status'], ['name' => 'idx_yfth_franchise_recruit_partner'])
            ->create();
    }

    private function createPerformanceTable(): void
    {
        if ($this->hasTable('yfth_partner_opening_performance')) {
            return;
        }
        $this->table('yfth_partner_opening_performance')
            ->setEngine('InnoDB')
            ->setComment('YFTH effective franchise opening performance snapshots')
            ->addColumn('performance_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('applicant_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('direct_partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('order_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('bottle_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('chain_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'valid'])
            ->addColumn('opened_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('invalid_reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['performance_no'], ['unique' => true, 'name' => 'uniq_yfth_partner_performance_no'])
            ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_partner_performance_app'])
            ->addIndex(['direct_partner_uid', 'status'], ['name' => 'idx_yfth_partner_performance_direct'])
            ->create();
    }

    private function createRewardCandidateTable(): void
    {
        if ($this->hasTable('yfth_partner_reward_candidate')) {
            return;
        }
        $this->table('yfth_partner_reward_candidate')
            ->setEngine('InnoDB')
            ->setComment('YFTH招商收益 candidates, not platform payout')
            ->addColumn('candidate_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('performance_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('beneficiary_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('rank_name_snapshot', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('chain_position', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('bottle_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reward_per_bottle', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending', 'comment' => 'pending/confirmed/settled/cancelled'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('operator_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['candidate_no'], ['unique' => true, 'name' => 'uniq_yfth_partner_candidate_no'])
            ->addIndex(['performance_id', 'rank_code'], ['unique' => true, 'name' => 'uniq_yfth_partner_candidate_rank'])
            ->addIndex(['beneficiary_uid', 'status'], ['name' => 'idx_yfth_partner_candidate_beneficiary'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_partner_candidate_store'])
            ->create();
    }

    private function createSettlementTable(): void
    {
        if ($this->hasTable('yfth_partner_reward_settlement')) {
            return;
        }
        $this->table('yfth_partner_reward_settlement')
            ->setEngine('InnoDB')
            ->setComment('YFTH partner offline settlement facts')
            ->addColumn('settlement_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('candidate_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('method', 'string', ['limit' => 32, 'default' => 'offline'])
            ->addColumn('evidence', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('settled_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['settlement_no'], ['unique' => true, 'name' => 'uniq_yfth_partner_settlement_no'])
            ->addIndex(['candidate_id'], ['unique' => true, 'name' => 'uniq_yfth_partner_settlement_candidate'])
            ->create();
    }

    private function createWarningTable(): void
    {
        if ($this->hasTable('yfth_partner_warning')) {
            return;
        }
        $this->table('yfth_partner_warning')
            ->setEngine('InnoDB')
            ->setComment('YFTH partner manual-governance warnings')
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('period_key', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('warning_type', 'string', ['limit' => 32, 'default' => 'retention'])
            ->addColumn('metrics_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'open'])
            ->addColumn('resolution', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['partner_uid', 'period_key', 'warning_type'], ['unique' => true, 'name' => 'uniq_yfth_partner_warning_period'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_partner_warning_status'])
            ->create();
    }

    private function createPromotionApplicationTable(): void
    {
        if ($this->hasTable('yfth_partner_promotion_application')) {
            return;
        }
        $this->table('yfth_partner_promotion_application')
            ->setEngine('InnoDB')
            ->setComment('YFTH partner manual promotion applications')
            ->addColumn('application_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('from_rank', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('target_rank', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('evidence_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending', 'comment' => 'pending/approved/rejected/cancelled'])
            ->addColumn('apply_reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('review_reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('reviewer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('review_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['application_no'], ['unique' => true, 'name' => 'uniq_yfth_partner_promotion_no'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_promotion_active'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_partner_promotion_status'])
            ->create();
    }

    private function seedDefaultRule(): void
    {
        $table = '`' . $this->prefixed('yfth_partner_rule_version') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `rule_no` = ' . $this->quote('YFTH-PARTNER-V1') . ' LIMIT 1');
        if ($existing) {
            return;
        }
        $now = time();
        $this->execute('INSERT INTO ' . $table . ' (`rule_no`,`version_no`,`status`,`order_amount`,`bottle_count`,`platform_dividend_bps`,`effective_time`,`active_key`,`operator_uid`,`create_time`,`update_time`) VALUES (' .
            $this->quote('YFTH-PARTNER-V1') . ',1,' . $this->quote('published') . ',89100.00,440,100,' . $now . ',' . $this->quote('published') . ',0,' . $now . ',' . $now . ')');
        $row = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `rule_no` = ' . $this->quote('YFTH-PARTNER-V1') . ' LIMIT 1');
        $ruleId = (int)$row['id'];
        $ranks = [
            ['county_partner', '县级合伙人', 1, '40.00', ['opened_recruits' => 2, 'personal_openings' => 4, 'team_openings' => 6, 'tenure_months' => 3], ['personal_monthly' => 1], ['zero_months' => 3]],
            ['prefecture_partner', '地级合伙人', 2, '17.00', ['team_size' => 3, 'tenure_months' => 3, 'quarter_team_openings' => 12, 'develop_manager' => 1], ['team_monthly' => 4, 'personal_monthly' => 1], ['miss_months' => 3]],
            ['province_partner', '省级合伙人', 3, '10.00', ['team_size' => 12, 'manager_groups' => 3, 'tenure_months' => 4, 'quarter_openings' => 36, 'develop_manager' => 1], ['area_monthly' => 12, 'quarter_talent' => 1], ['miss_quarters' => 3]],
            ['regional_director', '大区总监', 4, '8.00', ['tenure_months' => 4], ['company_monthly' => 30], ['annual_target_percent' => 80]],
            ['platform_director', '平台董事', 5, '5.00', ['regional_directors' => 3, 'hq_approval' => 1], [], ['manual_governance' => 1]],
        ];
        $rankTable = '`' . $this->prefixed('yfth_partner_rank_rule') . '`';
        foreach ($ranks as $rank) {
            $this->execute('INSERT INTO ' . $rankTable . ' (`rule_version_id`,`rank_code`,`rank_name`,`rank_level`,`reward_per_bottle`,`promotion_config`,`retention_config`,`warning_config`,`status`,`create_time`,`update_time`) VALUES (' .
                $ruleId . ',' . $this->quote($rank[0]) . ',' . $this->quote($rank[1]) . ',' . (int)$rank[2] . ',' . $this->quote($rank[3]) . ',' .
                $this->quote(json_encode($rank[4], JSON_UNESCAPED_UNICODE)) . ',' . $this->quote(json_encode($rank[5], JSON_UNESCAPED_UNICODE)) . ',' .
                $this->quote(json_encode($rank[6], JSON_UNESCAPED_UNICODE)) . ',' . $this->quote('active') . ',' . $now . ',' . $now . ')');
        }
    }

    private function backfillLegacyFranchisees(): void
    {
        $roleTable = '`' . $this->prefixed('yfth_user_store_role') . '`';
        $profileTable = '`' . $this->prefixed('yfth_partner_profile') . '`';
        $eventTable = '`' . $this->prefixed('yfth_partner_rank_event') . '`';
        $rows = $this->getAdapter()->fetchAll('SELECT MIN(`id`) AS `role_id`,`uid`,MIN(`store_id`) AS `store_id`,MIN(`start_time`) AS `start_time` FROM ' . $roleTable . ' WHERE `role_code` = ' . $this->quote('franchisee') . ' AND `status` = ' . $this->quote('active') . ' GROUP BY `uid`');
        $now = time();
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $profileTable . ' WHERE `uid` = ' . $uid . ' LIMIT 1');
            if ($existing) {
                continue;
            }
            $start = (int)$row['start_time'] ?: $now;
            $this->execute('INSERT INTO ' . $profileTable . ' (`uid`,`rank_code`,`primary_store_id`,`source_type`,`source_id`,`legacy_franchisee_role_id`,`status`,`start_time`,`end_time`,`active_key`,`create_time`,`update_time`) VALUES (' .
                $uid . ',' . $this->quote('county_partner') . ',' . (int)$row['store_id'] . ',' . $this->quote('legacy_franchisee_migration') . ',0,' . (int)$row['role_id'] . ',' . $this->quote('active') . ',' . $start . ',0,' . $this->quote('partner:' . $uid) . ',' . $now . ',' . $now . ')');
            $this->execute('INSERT INTO ' . $eventTable . ' (`partner_uid`,`from_rank`,`to_rank`,`action`,`rule_version_id`,`reason`,`evidence_snapshot`,`operator_uid`,`create_time`) VALUES (' .
                $uid . ',' . $this->quote('') . ',' . $this->quote('county_partner') . ',' . $this->quote('legacy_migrate') . ',0,' . $this->quote('Preserve active franchisee role as county partner') . ',' .
                $this->quote(json_encode(['legacy_role_id' => (int)$row['role_id'], 'store_id' => (int)$row['store_id']], JSON_UNESCAPED_UNICODE)) . ',0,' . $now . ')');
        }
    }

    private function seedMenus(): void
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-people',
            'menu_name' => '招商合伙人与开店',
            'module' => 'admin',
            'controller' => 'v1.yfth.FranchisePartner',
            'action' => 'index',
            'api_url' => 'yfth/franchise_partner/dashboard',
            'methods' => 'GET',
            'params' => '',
            'sort' => 9,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/franchise-partner',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-franchise-partner-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);
        $apis = [
            ['Dashboard', 'dashboard', 'GET', 'dashboard'],
            ['Rule list', 'rule', 'GET', 'rule-list'],
            ['Rule save', 'rule', 'POST', 'rule-save'],
            ['Rule publish', 'rule/<id>/publish', 'POST', 'rule-publish'],
            ['Partner list', 'partner', 'GET', 'profile-list'],
            ['Partner detail', 'partner/<uid>', 'GET', 'profile-detail'],
            ['Rank change', 'partner/<uid>/rank', 'POST', 'rank-change'],
            ['Parent change', 'partner/<uid>/parent', 'POST', 'parent-change'],
            ['Recruit source correct', 'source/<application_id>/correct', 'POST', 'source-correct'],
            ['Performance list', 'performance', 'GET', 'performance-list'],
            ['Reward list', 'reward', 'GET', 'reward-list'],
            ['Reward confirm', 'reward/<id>/confirm', 'POST', 'reward-confirm'],
            ['Reward cancel', 'reward/<id>/cancel', 'POST', 'reward-cancel'],
            ['Reward settle', 'reward/<id>/settle', 'POST', 'reward-settle'],
            ['Warning list', 'warning', 'GET', 'warning-list'],
            ['Promotion list', 'promotion', 'GET', 'promotion-list'],
            ['Promotion review', 'promotion/<id>/review', 'POST', 'promotion-review'],
            ['Complete opening', 'opening/complete', 'POST', 'opening-complete'],
        ];
        foreach ($apis as $api) {
            $this->upsertMenu($this->apiRow($pageId, $api[0], 'yfth/franchise_partner/' . $api[1], $api[2], 'yfth-franchise-partner-' . $api[3]));
        }
        $this->upsertMenu($this->apiRow(
            $pageId,
            'Create formal franchise store',
            'yfth/franchise_opening/profile/<id>/create_store',
            'POST',
            'yfth-franchise-opening-store-create'
        ));
    }

    private function ensureRoot(): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $root = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote('yfth-foundation') . ' LIMIT 1');
        if ($root) {
            return (int)$root['id'];
        }
        throw new RuntimeException('yfth_foundation_menu_required');
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pid, 'icon' => '', 'menu_name' => $name, 'module' => 'admin',
            'controller' => 'v1.yfth.FranchisePartner', 'action' => '', 'api_url' => $url,
            'methods' => $method, 'params' => '', 'sort' => 0, 'is_show' => 0,
            'is_show_path' => 0, 'access' => 1, 'menu_path' => '', 'path' => (string)$pid,
            'auth_type' => 2, 'header' => 'yfth', 'is_header' => 0,
            'unique_auth' => $auth, 'is_del' => 0, 'mark' => 'yfth',
        ];
    }

    private function upsertMenu(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field !== 'unique_auth') {
                    $sets[] = '`' . $field . '` = ' . $this->quote($value);
                }
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return (int)$existing['id'];
        }
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map(function ($value) { return $this->quote($value); }, array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
