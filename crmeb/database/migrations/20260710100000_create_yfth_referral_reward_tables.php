<?php

use think\migration\Migrator;

class CreateYfthReferralRewardTables extends Migrator
{
    private $menuKeys = [
        'yfth-referral-reward-index',
        'yfth-referral-reward-rule-list',
        'yfth-referral-reward-rule-save',
        'yfth-referral-reward-rule-publish',
        'yfth-referral-reward-rule-copy',
        'yfth-referral-candidate-list',
        'yfth-referral-event-list',
        'yfth-referral-attribution-list',
        'yfth-reward-ledger-list',
        'yfth-reward-ledger-detail',
        'yfth-reward-ledger-settle',
        'yfth-reward-ledger-cancel-settlement',
        'yfth-reward-ledger-reverse',
        'yfth-referral-reward-scan',
    ];

    public function up()
    {
        $this->createReferralCode();
        $this->createReferralCandidate();
        $this->createReferralEvent();
        $this->createReferralAttribution();
        $this->createRewardRuleVersion();
        $this->createRewardRuleItem();
        $this->createRewardLedger();
        $this->createRewardLedgerSnapshot();
        $this->createRewardAdjustment();
        $this->createRewardSettlementRecord();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        foreach ([
            'yfth_reward_settlement_record',
            'yfth_reward_adjustment',
            'yfth_reward_ledger_snapshot',
            'yfth_reward_ledger',
            'yfth_reward_rule_item',
            'yfth_reward_rule_version',
            'yfth_referral_attribution',
            'yfth_referral_event',
            'yfth_referral_candidate',
            'yfth_referral_code',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createReferralCode(): void
    {
        if ($this->hasTable('yfth_referral_code')) {
            return;
        }
        $this->table('yfth_referral_code')
            ->setEngine('InnoDB')
            ->setComment('YFTH referral code source independent from CRMEB spread')
            ->addColumn('owner_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user uid'])
            ->addColumn('owner_role_code', 'string', ['limit' => 32, 'default' => 'customer', 'comment' => 'server resolved role code'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'server resolved store id'])
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'package_5980/franchise_opening'])
            ->addColumn('code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'server generated code'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled/expired'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_yfth_referral_code_code'])
            ->addIndex(['owner_uid', 'scene', 'status'], ['name' => 'idx_yfth_referral_code_owner_scene'])
            ->addIndex(['scene', 'status'], ['name' => 'idx_yfth_referral_code_scene_status'])
            ->create();
    }

    private function createReferralCandidate(): void
    {
        if ($this->hasTable('yfth_referral_candidate')) {
            return;
        }
        $this->table('yfth_referral_candidate')
            ->setEngine('InnoDB')
            ->setComment('YFTH referral candidate relation, not an effective reward')
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'referral scene'])
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referrer uid'])
            ->addColumn('referrer_role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'referrer role'])
            ->addColumn('referrer_store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referrer store id'])
            ->addColumn('referred_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referred uid'])
            ->addColumn('referred_phone_hash', 'string', ['limit' => 80, 'default' => '', 'comment' => 'hashed referred phone'])
            ->addColumn('referred_phone_masked', 'string', ['limit' => 32, 'default' => '', 'comment' => 'masked referred phone'])
            ->addColumn('referral_code_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referral code id'])
            ->addColumn('source', 'string', ['limit' => 48, 'default' => 'code', 'comment' => 'code/business_event'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'candidate', 'comment' => 'candidate/registered/bound/attributed/expired/invalid'])
            ->addColumn('active_key', 'string', ['limit' => 128, 'null' => true, 'default' => null, 'comment' => 'active uniqueness key'])
            ->addColumn('bind_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'bind time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_referral_candidate_active_key'])
            ->addIndex(['referrer_uid', 'scene', 'status'], ['name' => 'idx_yfth_referral_candidate_referrer'])
            ->addIndex(['referred_uid', 'scene'], ['name' => 'idx_yfth_referral_candidate_referred'])
            ->addIndex(['scene', 'status'], ['name' => 'idx_yfth_referral_candidate_scene_status'])
            ->create();
    }

    private function createReferralEvent(): void
    {
        if ($this->hasTable('yfth_referral_event')) {
            return;
        }
        $this->table('yfth_referral_event')
            ->setEngine('InnoDB')
            ->setComment('YFTH referral idempotent business events')
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'scene'])
            ->addColumn('candidate_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'candidate id'])
            ->addColumn('event_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'event type'])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'source business type'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'source id'])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'default' => '', 'comment' => 'event idempotency key'])
            ->addColumn('payload_snapshot', 'text', ['null' => true, 'comment' => 'sanitized payload snapshot'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'recorded', 'comment' => 'recorded/failed/ignored'])
            ->addColumn('error_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'error code'])
            ->addColumn('error_message', 'string', ['limit' => 255, 'default' => '', 'comment' => 'error message'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['scene', 'event_type', 'idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_referral_event_idempotency'])
            ->addIndex(['source_type', 'source_id', 'event_type'], ['name' => 'idx_yfth_referral_event_source'])
            ->addIndex(['candidate_id'], ['name' => 'idx_yfth_referral_event_candidate'])
            ->create();
    }

    private function createReferralAttribution(): void
    {
        if ($this->hasTable('yfth_referral_attribution')) {
            return;
        }
        $this->table('yfth_referral_attribution')
            ->setEngine('InnoDB')
            ->setComment('YFTH final referral attribution binding business object read-only')
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'scene'])
            ->addColumn('candidate_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'candidate id'])
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referrer uid'])
            ->addColumn('referrer_store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referrer store id'])
            ->addColumn('referred_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referred uid'])
            ->addColumn('business_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'business type'])
            ->addColumn('business_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'business id'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'attributed', 'comment' => 'attributed/invalid/expired/reversed'])
            ->addColumn('attributed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'attributed time'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['scene', 'business_type', 'business_id'], ['unique' => true, 'name' => 'uniq_yfth_referral_attr_business'])
            ->addIndex(['candidate_id', 'business_type', 'business_id'], ['unique' => true, 'name' => 'uniq_yfth_referral_attr_candidate_business'])
            ->addIndex(['referrer_uid', 'status'], ['name' => 'idx_yfth_referral_attr_referrer'])
            ->addIndex(['referred_uid', 'status'], ['name' => 'idx_yfth_referral_attr_referred'])
            ->create();
    }

    private function createRewardRuleVersion(): void
    {
        if ($this->hasTable('yfth_reward_rule_version')) {
            return;
        }
        $this->table('yfth_reward_rule_version')
            ->setEngine('InnoDB')
            ->setComment('YFTH immutable published reward rule versions')
            ->addColumn('rule_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'rule number'])
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'scene'])
            ->addColumn('name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'rule name'])
            ->addColumn('version_no', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'version no'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'draft', 'comment' => 'draft/published/disabled/archived'])
            ->addColumn('effective_start', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective start'])
            ->addColumn('effective_end', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective end'])
            ->addColumn('published_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'published time'])
            ->addColumn('created_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'admin creator id'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['rule_no'], ['unique' => true, 'name' => 'uniq_yfth_reward_rule_no'])
            ->addIndex(['scene', 'version_no'], ['unique' => true, 'name' => 'uniq_yfth_reward_rule_scene_version'])
            ->addIndex(['scene', 'status'], ['name' => 'idx_yfth_reward_rule_scene_status'])
            ->addIndex(['effective_start', 'effective_end'], ['name' => 'idx_yfth_reward_rule_effective'])
            ->create();
    }

    private function createRewardRuleItem(): void
    {
        if ($this->hasTable('yfth_reward_rule_item')) {
            return;
        }
        $this->table('yfth_reward_rule_item')
            ->setEngine('InnoDB')
            ->setComment('YFTH reward rule items')
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('reward_scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'reward scene'])
            ->addColumn('reward_type', 'string', ['limit' => 48, 'default' => 'offline_reward', 'comment' => 'reward type'])
            ->addColumn('title', 'string', ['limit' => 128, 'default' => '', 'comment' => 'title'])
            ->addColumn('description', 'string', ['limit' => 255, 'default' => '', 'comment' => 'description'])
            ->addColumn('amount_cent', 'integer', ['default' => 0, 'comment' => 'amount in cents'])
            ->addColumn('observe_days', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'observe days'])
            ->addColumn('condition_snapshot', 'text', ['null' => true, 'comment' => 'condition snapshot'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['rule_version_id'], ['name' => 'idx_yfth_reward_rule_item_version'])
            ->addIndex(['reward_scene'], ['name' => 'idx_yfth_reward_rule_item_scene'])
            ->create();
    }

    private function createRewardLedger(): void
    {
        if ($this->hasTable('yfth_reward_ledger')) {
            return;
        }
        $this->table('yfth_reward_ledger')
            ->setEngine('InnoDB')
            ->setComment('YFTH read-only reward ledger, not withdrawable balance')
            ->addColumn('ledger_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'ledger number'])
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'scene'])
            ->addColumn('attribution_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'attribution id'])
            ->addColumn('candidate_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'candidate id'])
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referrer uid'])
            ->addColumn('referrer_store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referrer store id'])
            ->addColumn('referred_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'referred uid'])
            ->addColumn('business_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'business type'])
            ->addColumn('business_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'business id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('rule_item_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule item id'])
            ->addColumn('amount_cent', 'integer', ['default' => 0, 'comment' => 'amount in cents'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'observing', 'comment' => 'observing/valid/pending_settlement/settled/invalid/reversed'])
            ->addColumn('observe_start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'observe start'])
            ->addColumn('observe_end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'observe end'])
            ->addColumn('valid_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'valid time'])
            ->addColumn('settled_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'settled mark time'])
            ->addColumn('settled_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'settled operator'])
            ->addColumn('reversed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reversed time'])
            ->addColumn('reversed_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reversed operator'])
            ->addColumn('active_key', 'string', ['limit' => 160, 'null' => true, 'default' => null, 'comment' => 'ledger uniqueness key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['ledger_no'], ['unique' => true, 'name' => 'uniq_yfth_reward_ledger_no'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_reward_ledger_active_key'])
            ->addIndex(['referrer_uid', 'status'], ['name' => 'idx_yfth_reward_ledger_referrer'])
            ->addIndex(['business_type', 'business_id'], ['name' => 'idx_yfth_reward_ledger_business'])
            ->addIndex(['status', 'observe_end_time'], ['name' => 'idx_yfth_reward_ledger_status_time'])
            ->create();
    }

    private function createRewardLedgerSnapshot(): void
    {
        if ($this->hasTable('yfth_reward_ledger_snapshot')) {
            return;
        }
        $this->table('yfth_reward_ledger_snapshot')
            ->setEngine('InnoDB')
            ->setComment('YFTH reward ledger immutable snapshots')
            ->addColumn('ledger_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'ledger id'])
            ->addColumn('rule_snapshot', 'text', ['null' => true, 'comment' => 'sanitized rule snapshot'])
            ->addColumn('referral_snapshot', 'text', ['null' => true, 'comment' => 'sanitized referral snapshot'])
            ->addColumn('business_snapshot', 'text', ['null' => true, 'comment' => 'sanitized business snapshot'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['ledger_id'], ['name' => 'idx_yfth_reward_snapshot_ledger'])
            ->create();
    }

    private function createRewardAdjustment(): void
    {
        if ($this->hasTable('yfth_reward_adjustment')) {
            return;
        }
        $this->table('yfth_reward_adjustment')
            ->setEngine('InnoDB')
            ->setComment('YFTH reward adjustment append-only records')
            ->addColumn('ledger_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'ledger id'])
            ->addColumn('adjustment_type', 'string', ['limit' => 32, 'default' => 'remark', 'comment' => 'reverse/void/manual_adjust/remark'])
            ->addColumn('amount_cent', 'integer', ['default' => 0, 'comment' => 'adjustment amount cents'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reason'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('before_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'before status'])
            ->addColumn('after_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'after status'])
            ->addColumn('payload_snapshot', 'text', ['null' => true, 'comment' => 'sanitized payload snapshot'])
            ->addColumn('dedupe_key', 'string', ['limit' => 160, 'null' => true, 'default' => null, 'comment' => 'optional adjustment idempotency key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['ledger_id'], ['name' => 'idx_yfth_reward_adjustment_ledger'])
            ->addIndex(['dedupe_key'], ['unique' => true, 'name' => 'uniq_yfth_reward_adjustment_dedupe'])
            ->create();
    }

    private function createRewardSettlementRecord(): void
    {
        if ($this->hasTable('yfth_reward_settlement_record')) {
            return;
        }
        $this->table('yfth_reward_settlement_record')
            ->setEngine('InnoDB')
            ->setComment('YFTH offline settlement marker, not system payment')
            ->addColumn('ledger_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'ledger id'])
            ->addColumn('settlement_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'settlement marker no'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'marked_settled', 'comment' => 'marked_settled/canceled'])
            ->addColumn('amount_cent', 'integer', ['default' => 0, 'comment' => 'settled amount cents'])
            ->addColumn('offline_ref_no', 'string', ['limit' => 128, 'default' => '', 'comment' => 'offline reference no'])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => 'remark'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('mark_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'mark time'])
            ->addColumn('cancel_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'cancel time'])
            ->addColumn('active_key', 'string', ['limit' => 128, 'null' => true, 'default' => null, 'comment' => 'active settlement uniqueness key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['settlement_no'], ['unique' => true, 'name' => 'uniq_yfth_reward_settlement_no'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_reward_settlement_active'])
            ->addIndex(['ledger_id'], ['name' => 'idx_yfth_reward_settlement_ledger'])
            ->addIndex(['status'], ['name' => 'idx_yfth_reward_settlement_status'])
            ->create();
    }

    private function seedMenus(): void
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-gift',
            'menu_name' => 'Referral Reward Ledger',
            'module' => 'admin',
            'controller' => 'v1.yfth.ReferralReward',
            'action' => 'index',
            'api_url' => 'yfth/referral_reward/rule',
            'methods' => 'GET',
            'params' => '',
            'sort' => 7,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/referral-reward',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-referral-reward-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, 'Reward rule list', 'yfth/referral_reward/rule', 'GET', 'yfth-referral-reward-rule-list'),
            $this->apiRow($pageId, 'Reward rule save', 'yfth/referral_reward/rule', 'POST', 'yfth-referral-reward-rule-save'),
            $this->apiRow($pageId, 'Reward rule publish', 'yfth/referral_reward/rule/<id>/publish', 'POST', 'yfth-referral-reward-rule-publish'),
            $this->apiRow($pageId, 'Reward rule copy', 'yfth/referral_reward/rule/<id>/copy', 'POST', 'yfth-referral-reward-rule-copy'),
            $this->apiRow($pageId, 'Referral candidate list', 'yfth/referral_reward/candidate', 'GET', 'yfth-referral-candidate-list'),
            $this->apiRow($pageId, 'Referral event list', 'yfth/referral_reward/event', 'GET', 'yfth-referral-event-list'),
            $this->apiRow($pageId, 'Referral attribution list', 'yfth/referral_reward/attribution', 'GET', 'yfth-referral-attribution-list'),
            $this->apiRow($pageId, 'Reward ledger list', 'yfth/referral_reward/ledger', 'GET', 'yfth-reward-ledger-list'),
            $this->apiRow($pageId, 'Reward ledger detail', 'yfth/referral_reward/ledger/<id>', 'GET', 'yfth-reward-ledger-detail'),
            $this->apiRow($pageId, 'Reward ledger settle', 'yfth/referral_reward/ledger/<id>/settle', 'POST', 'yfth-reward-ledger-settle'),
            $this->apiRow($pageId, 'Reward ledger cancel settlement', 'yfth/referral_reward/ledger/<id>/cancel_settlement', 'POST', 'yfth-reward-ledger-cancel-settlement'),
            $this->apiRow($pageId, 'Reward ledger reverse', 'yfth/referral_reward/ledger/<id>/reverse', 'POST', 'yfth-reward-ledger-reverse'),
            $this->apiRow($pageId, 'Referral reward scan', 'yfth/referral_reward/scan', 'POST', 'yfth-referral-reward-scan'),
        ] as $row) {
            $this->upsertMenu($row);
        }
    }

    private function ensureRoot(): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $root = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote('yfth-foundation') . ' LIMIT 1');
        if ($root) {
            return (int)$root['id'];
        }
        return $this->upsertMenu([
            'pid' => 0,
            'icon' => 'md-git-network',
            'menu_name' => 'YFTH',
            'module' => 'admin',
            'controller' => '',
            'action' => '',
            'api_url' => '',
            'methods' => 'GET',
            'params' => '',
            'sort' => 32,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth',
            'path' => '/yfth',
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 1,
            'unique_auth' => 'yfth-foundation',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pid,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.ReferralReward',
            'action' => '',
            'api_url' => $url,
            'methods' => $method,
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$pid,
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => $auth,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
    }

    private function upsertMenu(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field === 'unique_auth') {
                    continue;
                }
                $sets[] = '`' . $field . '` = ' . $this->quote($value);
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return (int)$existing['id'];
        }

        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map(function ($value) {
            return $this->quote($value);
        }, array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return 'NULL';
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
