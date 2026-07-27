<?php

use think\migration\Migrator;

class CreateYfthFundWithdrawalFinanceV1 extends Migrator
{
    private const MENU_AUTHS = [
        'yfth-finance',
        'yfth-finance-withdrawal-index',
        'yfth-finance-withdrawal-read',
        'yfth-finance-withdrawal-detail',
        'yfth-finance-withdrawal-review',
        'yfth-finance-withdrawal-pay',
    ];

    public function up()
    {
        $this->createSettingTable();
        $this->createRequestTable();
        $this->createAllocationTable();
        $this->seedSetting();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], self::MENU_AUTHS);
        $this->execute(
            'DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' .
            implode(',', $quoted) . ')'
        );
        foreach (['yfth_fund_withdrawal_allocation', 'yfth_fund_withdrawal_request', 'yfth_fund_withdrawal_setting'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createSettingTable(): void
    {
        if ($this->hasTable('yfth_fund_withdrawal_setting')) {
            return;
        }
        $this->table('yfth_fund_withdrawal_setting', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('YFTH partner and store withdrawal safety settings')
            ->addColumn('partner_observation_days', 'integer', ['signed' => false, 'default' => 7])
            ->addColumn('store_observation_days', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('enabled', 'boolean', ['default' => 1])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
    }

    private function createRequestTable(): void
    {
        if ($this->hasTable('yfth_fund_withdrawal_request')) {
            return;
        }
        $this->table('yfth_fund_withdrawal_request', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('YFTH partner/store manual bank withdrawal requests')
            ->addColumn('request_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('owner_type', 'string', ['limit' => 16, 'default' => 'partner'])
            ->addColumn('owner_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('applicant_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('rank_code', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending_review'])
            ->addColumn('payout_method', 'string', ['limit' => 24, 'default' => 'bank'])
            ->addColumn('receiver_name_enc', 'text', ['null' => false])
            ->addColumn('receiver_name_masked', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('receiver_account_enc', 'text', ['null' => false])
            ->addColumn('receiver_account_masked', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('bank_name', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('request_id', 'string', ['limit' => 96, 'default' => ''])
            ->addColumn('applicant_remark', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('review_admin_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('review_reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('review_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('pay_admin_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('pay_reference', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('pay_remark', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('paid_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['request_no'], ['unique' => true, 'name' => 'uniq_yfth_fund_withdrawal_no'])
            ->addIndex(['owner_type', 'owner_id', 'request_id'], ['unique' => true, 'name' => 'uniq_yfth_fund_withdrawal_request'])
            ->addIndex(['owner_type', 'owner_id', 'status', 'add_time'], ['name' => 'idx_yfth_fund_withdrawal_owner'])
            ->addIndex(['status', 'add_time'], ['name' => 'idx_yfth_fund_withdrawal_finance'])
            ->create();
    }

    private function createAllocationTable(): void
    {
        if ($this->hasTable('yfth_fund_withdrawal_allocation')) {
            return;
        }
        $this->table('yfth_fund_withdrawal_allocation', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('Frozen source allocations for YFTH withdrawals')
            ->addColumn('request_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('owner_type', 'string', ['limit' => 16, 'default' => 'partner'])
            ->addColumn('owner_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'frozen'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['request_id', 'source_type', 'source_id'], ['unique' => true, 'name' => 'uniq_yfth_fund_withdrawal_allocation'])
            ->addIndex(['owner_type', 'owner_id', 'status'], ['name' => 'idx_yfth_fund_withdrawal_allocation_owner'])
            ->addIndex(['source_type', 'source_id', 'status'], ['name' => 'idx_yfth_fund_withdrawal_allocation_source'])
            ->create();
    }

    private function seedSetting(): void
    {
        $table = '`' . $this->prefixed('yfth_fund_withdrawal_setting') . '`';
        $exists = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' ORDER BY `id` ASC LIMIT 1');
        if (!$exists) {
            $now = time();
            $this->execute(
                'INSERT INTO ' . $table .
                ' (`partner_observation_days`,`store_observation_days`,`enabled`,`operator_uid`,`add_time`,`update_time`)' .
                ' VALUES (7,0,1,0,' . $now . ',' . $now . ')'
            );
        }
    }

    private function seedMenus(): void
    {
        $financeRoot = $this->menu('admin-finance');
        if (!$financeRoot) {
            throw new RuntimeException('admin_finance_menu_required');
        }
        $root = $this->ensureMenu([
            'pid' => (int)$financeRoot['id'], 'icon' => 'md-cash', 'menu_name' => '御方通和资金', 'module' => 'admin',
            'controller' => '', 'action' => '', 'api_url' => '', 'methods' => 'GET',
            'params' => '', 'sort' => 5, 'is_show' => 1, 'is_show_path' => 1, 'access' => 1,
            'menu_path' => '/yfth-finance', 'path' => (string)$financeRoot['id'], 'auth_type' => 1,
            'header' => 'finance', 'is_header' => 0, 'unique_auth' => self::MENU_AUTHS[0],
            'is_del' => 0, 'mark' => 'yfth-finance',
        ]);
        $page = $this->ensureMenu([
            'pid' => (int)$root['id'], 'icon' => 'md-card', 'menu_name' => '提现审核',
            'module' => 'admin', 'controller' => 'v1.yfth.FundFinance', 'action' => 'index',
            'api_url' => 'yfth/fund_finance/withdrawal', 'methods' => 'GET', 'params' => '',
            'sort' => 10, 'is_show' => 1, 'is_show_path' => 1, 'access' => 1,
            'menu_path' => '/yfth-finance/withdrawal',
            'path' => (int)$financeRoot['id'] . '/' . (int)$root['id'],
            'auth_type' => 1, 'header' => 'finance', 'is_header' => 0,
            'unique_auth' => self::MENU_AUTHS[1], 'is_del' => 0, 'mark' => 'yfth-finance',
        ]);
        foreach ([
            ['提现申请查询', 'yfth/fund_finance/withdrawal', 'GET', self::MENU_AUTHS[2]],
            ['提现申请详情', 'yfth/fund_finance/withdrawal/<id>', 'GET', self::MENU_AUTHS[3]],
            ['提现申请审核', 'yfth/fund_finance/withdrawal/<id>/review', 'POST', self::MENU_AUTHS[4]],
            ['确认线下打款', 'yfth/fund_finance/withdrawal/<id>/paid', 'POST', self::MENU_AUTHS[5]],
        ] as $def) {
            $this->ensureMenu([
                'pid' => (int)$page['id'], 'icon' => '', 'menu_name' => $def[0],
                'module' => 'admin', 'controller' => 'v1.yfth.FundFinance', 'action' => '',
                'api_url' => $def[1], 'methods' => $def[2], 'params' => '', 'sort' => 0,
                'is_show' => 0, 'is_show_path' => 0, 'access' => 1, 'menu_path' => '',
                'path' => (int)$financeRoot['id'] . '/' . (int)$root['id'] . '/' . (int)$page['id'],
                'auth_type' => 2, 'header' => 'finance',
                'is_header' => 0, 'unique_auth' => $def[3], 'is_del' => 0, 'mark' => 'yfth-finance',
            ]);
        }
    }

    private function menu(string $auth): array
    {
        return $this->getAdapter()->fetchRow(
            'SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' .
            $this->quote($auth) . ' AND `is_del`=0 LIMIT 1'
        ) ?: [];
    }

    private function ensureMenu(array $row): array
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']) . ' LIMIT 1'
        );
        if ($existing) {
            return $existing;
        }
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        return $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']) . ' LIMIT 1'
        ) ?: [];
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
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
}
