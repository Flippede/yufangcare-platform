<?php

use Phinx\Migration\AbstractMigration;

class CreateYfthProcurementPartnerProfitV1 extends AbstractMigration
{
    public function up(): void
    {
        $rankRule = $this->table('yfth_partner_rank_rule');
        if (!$rankRule->hasColumn('procurement_rate_bps')) {
            $rankRule->addColumn('procurement_rate_bps', 'integer', [
                'signed' => false,
                'default' => 0,
                'after' => 'reward_per_bottle',
                'comment' => 'store procurement profit rate in basis points',
            ])->update();
        }
        $rankRule = $this->table('yfth_partner_rank_rule');
        if (!$rankRule->hasColumn('opening_reward_amount_cent')) {
            $rankRule->addColumn('opening_reward_amount_cent', 'biginteger', [
                'signed' => false,
                'default' => 0,
                'after' => 'procurement_rate_bps',
                'comment' => 'offline opening service reward in cents',
            ])->update();
        }

        $this->createSnapshotTable();
        $this->createLedgerTable();
        $this->createOpeningRewardTable();
        $this->createDividendTables();
        $this->createServiceAreaTable();
        $this->seedDefaultRates();
        $this->seedPermissions();
    }

    public function down(): void
    {
        $menu = '`' . $this->prefixed('system_menus') . '`';
        $auths = [
            'yfth-franchise-partner-procurement-profit-list',
            'yfth-franchise-partner-opening-reward-list',
            'yfth-franchise-partner-dividend-list',
            'yfth-franchise-partner-dividend-generate',
        ];
        $quoted = array_map([$this, 'quote'], $auths);
        $this->execute('DELETE FROM ' . $menu . ' WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        foreach ([
            'yfth_platform_dividend_item',
            'yfth_platform_dividend_batch',
            'yfth_partner_opening_reward_ledger',
            'yfth_procurement_profit_ledger',
            'yfth_procurement_profit_snapshot',
            'yfth_partner_service_area',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }

        $rankRule = $this->table('yfth_partner_rank_rule');
        if ($rankRule->hasColumn('opening_reward_amount_cent')) {
            $rankRule->removeColumn('opening_reward_amount_cent')->update();
        }
        if ($rankRule->hasColumn('procurement_rate_bps')) {
            $rankRule->removeColumn('procurement_rate_bps')->update();
        }
    }

    private function createSnapshotTable(): void
    {
        if ($this->hasTable('yfth_procurement_profit_snapshot')) {
            return;
        }
        $this->table('yfth_procurement_profit_snapshot', [
            'engine' => 'InnoDB',
            'comment' => 'Immutable partner hierarchy and procurement profit snapshot',
        ])
            ->addColumn('purchase_order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('purchase_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('base_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('platform_dividend_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('chain_snapshot', 'text', ['null' => true])
            ->addColumn('rate_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'frozen'])
            ->addColumn('recognized_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reversed_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['purchase_order_id'], ['unique' => true, 'name' => 'uniq_yfth_procurement_snapshot_order'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_procurement_snapshot_store'])
            ->create();
    }

    private function createLedgerTable(): void
    {
        if ($this->hasTable('yfth_procurement_profit_ledger')) {
            return;
        }
        $this->table('yfth_procurement_profit_ledger', [
            'engine' => 'InnoDB',
            'comment' => 'Immutable store procurement partner profit ledger',
        ])
            ->addColumn('snapshot_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('purchase_order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('beneficiary_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('entry_type', 'string', ['limit' => 32, 'default' => 'procurement_profit'])
            ->addColumn('base_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('rate_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('amount_cent', 'biginteger', ['signed' => true, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('source_unique_key', 'string', ['limit' => 160, 'default' => ''])
            ->addColumn('settled_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['source_unique_key'], ['unique' => true, 'name' => 'uniq_yfth_procurement_profit_source'])
            ->addIndex(['beneficiary_uid', 'status', 'create_time'], ['name' => 'idx_yfth_procurement_profit_partner'])
            ->addIndex(['purchase_order_id', 'rank_code'], ['name' => 'idx_yfth_procurement_profit_order'])
            ->create();
    }

    private function createOpeningRewardTable(): void
    {
        if ($this->hasTable('yfth_partner_opening_reward_ledger')) {
            return;
        }
        $this->table('yfth_partner_opening_reward_ledger', [
            'engine' => 'InnoDB',
            'comment' => 'Offline franchise opening service reward facts',
        ])
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => 'county_partner'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('source_unique_key', 'string', ['limit' => 160, 'default' => ''])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['source_unique_key'], ['unique' => true, 'name' => 'uniq_yfth_opening_reward_source'])
            ->addIndex(['partner_uid', 'status'], ['name' => 'idx_yfth_opening_reward_partner'])
            ->create();
    }

    private function createDividendTables(): void
    {
        if (!$this->hasTable('yfth_platform_dividend_batch')) {
            $this->table('yfth_platform_dividend_batch', [
                'engine' => 'InnoDB',
                'comment' => 'Platform director weighted procurement dividend batch',
            ])
                ->addColumn('period_key', 'string', ['limit' => 24, 'default' => ''])
                ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('performance_cent', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('pool_bps', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('pool_cent', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
                ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['period_key', 'rule_version_id'], ['unique' => true, 'name' => 'uniq_yfth_platform_dividend_batch'])
                ->create();
        }
        if (!$this->hasTable('yfth_platform_dividend_item')) {
            $this->table('yfth_platform_dividend_item', [
                'engine' => 'InnoDB',
                'comment' => 'Platform director weighted dividend items',
            ])
                ->addColumn('batch_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('beneficiary_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('weight_basis', 'integer', ['signed' => false, 'default' => 1])
                ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
                ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['batch_id', 'beneficiary_uid'], ['unique' => true, 'name' => 'uniq_yfth_platform_dividend_item'])
                ->addIndex(['beneficiary_uid', 'status'], ['name' => 'idx_yfth_platform_dividend_partner'])
                ->create();
        }
    }

    private function createServiceAreaTable(): void
    {
        if ($this->hasTable('yfth_partner_service_area')) {
            return;
        }
        $this->table('yfth_partner_service_area', [
            'engine' => 'InnoDB',
            'comment' => 'Partner service area used for deterministic nearest assignment',
        ])
            ->addColumn('partner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => 'county_partner'])
            ->addColumn('province', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('city', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('district', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('priority', 'integer', ['signed' => false, 'default' => 100])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('active_key', 'string', ['limit' => 96, 'null' => true, 'default' => null])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_partner_service_area'])
            ->addIndex(['rank_code', 'province', 'city', 'district', 'status'], ['name' => 'idx_yfth_partner_service_area_match'])
            ->create();
    }

    private function seedDefaultRates(): void
    {
        $table = '`' . $this->prefixed('yfth_partner_rank_rule') . '`';
        $defaults = [
            'county_partner' => [2000, 1760000],
            'prefecture_partner' => [1000, 0],
            'province_partner' => [500, 0],
            'regional_director' => [300, 0],
            'platform_director' => [100, 0],
        ];
        foreach ($defaults as $rank => $values) {
            $this->execute('UPDATE ' . $table . ' SET `procurement_rate_bps`=' . $values[0]
                . ',`opening_reward_amount_cent`=' . $values[1]
                . ' WHERE `rank_code`=' . $this->quote($rank)
                . ' AND `procurement_rate_bps`=0 AND `opening_reward_amount_cent`=0');
        }
    }

    private function seedPermissions(): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $page = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote('yfth-franchise-partner-index') . ' LIMIT 1');
        if (!$page) {
            throw new RuntimeException('yfth_franchise_partner_menu_required');
        }
        $apis = [
            ['采购分润明细', 'procurement_profit', 'GET', 'yfth-franchise-partner-procurement-profit-list'],
            ['开店服务奖励', 'opening_reward', 'GET', 'yfth-franchise-partner-opening-reward-list'],
            ['平台加权分红', 'dividend', 'GET', 'yfth-franchise-partner-dividend-list'],
            ['生成平台分红批次', 'dividend/generate', 'POST', 'yfth-franchise-partner-dividend-generate'],
        ];
        foreach ($apis as $api) {
            $auth = $this->quote($api[3]);
            $row = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $auth . ' LIMIT 1');
            $values = [
                'pid' => (int)$page['id'], 'icon' => '', 'menu_name' => $api[0], 'module' => 'admin',
                'controller' => 'v1.yfth.FranchisePartner', 'action' => '', 'api_url' => 'yfth/franchise_partner/' . $api[1],
                'methods' => $api[2], 'params' => '', 'sort' => 0, 'is_show' => 0, 'is_show_path' => 0,
                'access' => 1, 'menu_path' => '', 'path' => (string)$page['id'], 'auth_type' => 2,
                'header' => 'yfth', 'is_header' => 0, 'unique_auth' => $api[3], 'is_del' => 0, 'mark' => 'yfth',
            ];
            if ($row) {
                continue;
            }
            $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($values));
            $quoted = array_map([$this, 'quote'], array_values($values));
            $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $quoted) . ')');
        }
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
