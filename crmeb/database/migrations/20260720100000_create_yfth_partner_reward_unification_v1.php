<?php

use think\migration\Migrator;

class CreateYfthPartnerRewardUnificationV1 extends Migrator
{
    public function up()
    {
        $this->extendPartnerProfile();
        $this->createPartnerStoreBinding();
        $this->createPartnerStoreBindingEvent();
        $this->createRewardEvent();
        $this->createRewardAdjustmentLedger();
        $this->createOpeningQuotaAward();
        $this->createQuotaReservation();
        $this->createMigrationIssue();
        $this->backfillPartnerBindings();
        $this->seedPermissions();
    }

    public function down()
    {
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', array_map([$this, 'quote'], [
            'yfth-reward-governance-event-list', 'yfth-reward-governance-retry',
            'yfth-reward-governance-opening-quota', 'yfth-reward-governance-migration-issue',
            'yfth-franchise-partner-opening-cancel',
        ])) . ')');
        foreach ([
            'yfth_partner_migration_issue',
            'yfth_product_quota_reservation',
            'yfth_partner_opening_quota_award',
            'yfth_reward_adjustment_ledger',
            'yfth_reward_event',
            'yfth_partner_store_binding_event',
            'yfth_partner_store_binding',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
        if ($this->hasTable('yfth_partner_profile')) {
            $table = $this->table('yfth_partner_profile');
            foreach (['qualification_status', 'valid_from', 'valid_to'] as $column) {
                if ($table->hasColumn($column)) {
                    $table->removeColumn($column)->update();
                    $table = $this->table('yfth_partner_profile');
                }
            }
        }
    }

    private function extendPartnerProfile(): void
    {
        if (!$this->hasTable('yfth_partner_profile')) return;
        $table = $this->table('yfth_partner_profile');
        if (!$table->hasColumn('qualification_status')) {
            $table->addColumn('qualification_status', 'string', ['limit' => 24, 'default' => 'effective', 'after' => 'status', 'comment' => 'pending/effective/frozen/invalid/terminated']);
        }
        if (!$table->hasColumn('valid_from')) {
            $table->addColumn('valid_from', 'integer', ['signed' => false, 'default' => 0, 'after' => 'qualification_status']);
        }
        if (!$table->hasColumn('valid_to')) {
            $table->addColumn('valid_to', 'integer', ['signed' => false, 'default' => 0, 'after' => 'valid_from']);
        }
        $table->update();
        $this->execute('UPDATE `' . $this->prefixed('yfth_partner_profile') . '` SET `qualification_status`=CASE WHEN `status`="active" THEN "effective" WHEN `status`="paused" THEN "frozen" ELSE "terminated" END, `valid_from`=CASE WHEN `valid_from`=0 THEN `start_time` ELSE `valid_from` END');
    }

    private function createPartnerStoreBinding(): void
    {
        if ($this->hasTable('yfth_partner_store_binding')) return;
        $this->table('yfth_partner_store_binding')
            ->setEngine('InnoDB')->setComment('Current partner-to-store ownership, independent of manager roles')
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => 'franchise_opening'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('valid_from', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('valid_to', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_store_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['active_store_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_store_active'])
            ->addIndex(['partner_uid', 'status'], ['name' => 'idx_yfth_partner_store_partner'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_partner_store_store'])
            ->create();
    }

    private function createPartnerStoreBindingEvent(): void
    {
        if ($this->hasTable('yfth_partner_store_binding_event')) return;
        $this->table('yfth_partner_store_binding_event')
            ->setEngine('InnoDB')->setComment('Append-only partner store ownership events')
            ->addColumn('binding_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('event_type', 'string', ['limit' => 32, 'default' => 'bound'])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('snapshot_json', 'text', ['null' => true])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('event_key', 'string', ['limit' => 160, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['event_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_store_event'])
            ->addIndex(['partner_uid', 'create_time'], ['name' => 'idx_yfth_partner_store_event_partner'])
            ->create();
    }

    private function createRewardEvent(): void
    {
        if ($this->hasTable('yfth_reward_event')) return;
        $this->table('yfth_reward_event')
            ->setEngine('InnoDB')->setComment('Durable unified reward outbox and processing state')
            ->addColumn('event_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('event_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('canonical_key', 'char', ['limit' => 64, 'default' => ''])
            ->addColumn('payload_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('retry_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('next_retry_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('processing_owner', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('processing_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('result_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('result_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('last_error', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('processed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['event_no'], ['unique' => true, 'name' => 'uniq_yfth_reward_event_no'])
            ->addIndex(['canonical_key'], ['unique' => true, 'name' => 'uniq_yfth_reward_event_key'])
            ->addIndex(['status', 'next_retry_at', 'id'], ['name' => 'idx_yfth_reward_event_retry'])
            ->addIndex(['source_type', 'source_id'], ['name' => 'idx_yfth_reward_event_source'])
            ->create();
        $this->execute('ALTER TABLE `' . $this->prefixed('yfth_reward_event') . '` MODIFY `canonical_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    }

    private function createRewardAdjustmentLedger(): void
    {
        if ($this->hasTable('yfth_reward_adjustment_ledger')) return;
        $this->table('yfth_reward_adjustment_ledger')
            ->setEngine('InnoDB')->setComment('Append-only reward confirmation settlement and reversal ledger')
            ->addColumn('ledger_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('candidate_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('candidate_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('action_type', 'string', ['limit' => 32, 'default' => 'confirm'])
            ->addColumn('amount_cent', 'biginteger', ['default' => 0])
            ->addColumn('reversal_of_ledger_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('canonical_key', 'char', ['limit' => 64, 'default' => ''])
            ->addColumn('snapshot_json', 'text', ['null' => true])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['ledger_no'], ['unique' => true, 'name' => 'uniq_yfth_reward_adjust_no'])
            ->addIndex(['canonical_key'], ['unique' => true, 'name' => 'uniq_yfth_reward_adjust_key'])
            ->addIndex(['candidate_id', 'create_time'], ['name' => 'idx_yfth_reward_adjust_candidate'])
            ->create();
        $this->execute('ALTER TABLE `' . $this->prefixed('yfth_reward_adjustment_ledger') . '` MODIFY `canonical_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    }

    private function createOpeningQuotaAward(): void
    {
        if ($this->hasTable('yfth_partner_opening_quota_award')) return;
        $this->table('yfth_partner_opening_quota_award')
            ->setEngine('InnoDB')->setComment('Direct partner first-three-store opening product quota awards')
            ->addColumn('award_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('performance_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('sequence_no', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('fee_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('quota_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('quota_account_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('quota_ledger_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('snapshot_json', 'text', ['null' => true])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['award_no'], ['unique' => true, 'name' => 'uniq_yfth_opening_quota_no'])
            ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_opening_quota_app'])
            ->addIndex(['partner_uid', 'sequence_no'], ['unique' => true, 'name' => 'uniq_yfth_opening_quota_seq'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_opening_quota_status'])
            ->create();
    }

    private function createQuotaReservation(): void
    {
        if ($this->hasTable('yfth_product_quota_reservation')) return;
        $this->table('yfth_product_quota_reservation')
            ->setEngine('InnoDB')->setComment('Product quota reservation/use/release/refund/reversal for purchase orders')
            ->addColumn('reservation_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('purchase_order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('account_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('order_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('quota_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('online_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('used_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('released_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('refunded_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('reversed_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'reserved'])
            ->addColumn('idempotency_key', 'string', ['limit' => 160, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['reservation_no'], ['unique' => true, 'name' => 'uniq_yfth_quota_reservation_no'])
            ->addIndex(['purchase_order_id'], ['unique' => true, 'name' => 'uniq_yfth_quota_reservation_order'])
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_quota_reservation_key'])
            ->addIndex(['account_id', 'status'], ['name' => 'idx_yfth_quota_reservation_account'])
            ->create();
    }

    private function createMigrationIssue(): void
    {
        if ($this->hasTable('yfth_partner_migration_issue')) return;
        $this->table('yfth_partner_migration_issue')
            ->setEngine('InnoDB')->setComment('Deterministic legacy franchisee migration exception report')
            ->addColumn('issue_key', 'string', ['limit' => 160, 'default' => ''])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('issue_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('source_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'open'])
            ->addColumn('resolution', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['issue_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_migration_issue'])
            ->addIndex(['status', 'issue_type'], ['name' => 'idx_yfth_partner_migration_status'])
            ->create();
    }

    private function backfillPartnerBindings(): void
    {
        if (!$this->hasTable('yfth_partner_profile')) return;
        $now = time();
        $profiles = $this->fetchAll('SELECT `uid`,`primary_store_id`,`source_type`,`source_id`,`start_time` FROM `' . $this->prefixed('yfth_partner_profile') . '` WHERE `status`="active" AND `primary_store_id`>0 ORDER BY `uid` ASC');
        foreach ($profiles as $profile) {
            $storeId = (int)$profile['primary_store_id'];
            $uid = (int)$profile['uid'];
            $existing = $this->fetchRow('SELECT `id`,`partner_uid` FROM `' . $this->prefixed('yfth_partner_store_binding') . '` WHERE `active_store_key`=' . $this->quote('store:' . $storeId) . ' LIMIT 1');
            if ($existing && (int)$existing['partner_uid'] !== $uid) {
                $key = 'duplicate_store_owner:' . $storeId . ':' . $uid;
                $this->execute('INSERT IGNORE INTO `' . $this->prefixed('yfth_partner_migration_issue') . '` (`issue_key`,`uid`,`store_id`,`issue_type`,`source_snapshot`,`status`,`resolution`,`create_time`,`update_time`) VALUES (' . $this->quote($key) . ',' . $uid . ',' . $storeId . ',"duplicate_store_owner",' . $this->quote(json_encode($profile, JSON_UNESCAPED_UNICODE)) . ',"open","",' . $now . ',' . $now . ')');
                continue;
            }
            if ($existing) continue;
            $sourceType = (string)$profile['source_type'];
            $sourceId = (int)$profile['source_id'];
            $validFrom = (int)$profile['start_time'];
            $this->execute('INSERT INTO `' . $this->prefixed('yfth_partner_store_binding') . '` (`partner_uid`,`store_id`,`source_type`,`source_id`,`status`,`valid_from`,`valid_to`,`active_store_key`,`operator_uid`,`reason`,`create_time`,`update_time`) VALUES (' . $uid . ',' . $storeId . ',' . $this->quote($sourceType) . ',' . $sourceId . ',"active",' . $validFrom . ',0,' . $this->quote('store:' . $storeId) . ',0,"migration_backfill",' . $now . ',' . $now . ')');
            $inserted = $this->fetchRow('SELECT `id` FROM `' . $this->prefixed('yfth_partner_store_binding') . '` WHERE `active_store_key`=' . $this->quote('store:' . $storeId) . ' LIMIT 1');
            $bindingId = (int)($inserted['id'] ?? 0);
            $eventKey = 'migration_backfill:' . $uid . ':' . $storeId;
            $this->execute('INSERT IGNORE INTO `' . $this->prefixed('yfth_partner_store_binding_event') . '` (`binding_id`,`partner_uid`,`store_id`,`event_type`,`source_type`,`source_id`,`snapshot_json`,`operator_uid`,`reason`,`event_key`,`create_time`) VALUES (' . $bindingId . ',' . $uid . ',' . $storeId . ',"bound",' . $this->quote($sourceType) . ',' . $sourceId . ',' . $this->quote(json_encode($profile, JSON_UNESCAPED_UNICODE)) . ',0,"migration_backfill",' . $this->quote($eventKey) . ',' . $now . ')');
        }
    }

    private function seedPermissions(): void
    {
        $menus = '`' . $this->prefixed('system_menus') . '`';
        $parent = $this->fetchRow('SELECT `id` FROM ' . $menus . ' WHERE `unique_auth`=' . $this->quote('yfth-franchise-partner-index') . ' LIMIT 1');
        if (!$parent) throw new RuntimeException('yfth_franchise_partner_menu_required');
        $pid = (int)$parent['id'];
        $apis = [
            ['Unified reward event list', 'yfth/reward_governance/event', 'GET', 'yfth-reward-governance-event-list'],
            ['Retry unified reward events', 'yfth/reward_governance/retry', 'POST', 'yfth-reward-governance-retry'],
            ['Opening quota awards', 'yfth/reward_governance/opening_quota', 'GET', 'yfth-reward-governance-opening-quota'],
            ['Confirm opening quota award', 'yfth/reward_governance/opening_quota/<id>/confirm', 'POST', 'yfth-reward-governance-opening-quota-confirm'],
            ['Unified reward consistency scan', 'yfth/reward_governance/consistency', 'GET', 'yfth-reward-governance-consistency'],
            ['Legacy migration issues', 'yfth/reward_governance/migration_issue', 'GET', 'yfth-reward-governance-migration-issue'],
            ['Cancel formal opening', 'yfth/franchise_partner/opening/<application_id>/cancel', 'POST', 'yfth-franchise-partner-opening-cancel'],
        ];
        foreach ($apis as $api) {
            $existing = $this->fetchRow('SELECT `id` FROM ' . $menus . ' WHERE `unique_auth`=' . $this->quote($api[3]) . ' LIMIT 1');
            if ($existing) continue;
            $row = [
                'pid' => $pid, 'icon' => '', 'menu_name' => $api[0], 'module' => 'admin',
                'controller' => 'v1.yfth.RewardGovernance', 'action' => '', 'api_url' => $api[1],
                'methods' => $api[2], 'params' => '', 'sort' => 0, 'is_show' => 0,
                'is_show_path' => 0, 'access' => 1, 'menu_path' => '', 'path' => (string)$pid,
                'auth_type' => 2, 'header' => 'yfth', 'is_header' => 0,
                'unique_auth' => $api[3], 'is_del' => 0, 'mark' => 'yfth',
            ];
            $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
            $values = array_map([$this, 'quote'], array_values($row));
            $this->execute('INSERT INTO ' . $menus . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        }
    }

    private function prefixed(string $table): string
    {
        return (string)$this->getAdapter()->getOption('table_prefix') . $table;
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) return (string)$value;
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
