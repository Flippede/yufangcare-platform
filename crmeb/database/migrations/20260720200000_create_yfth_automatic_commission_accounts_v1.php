<?php

use think\migration\Migrator;

class CreateYfthAutomaticCommissionAccountsV1 extends Migrator
{
    private const TABLES = [
        'yfth_commission_rule_version',
        'yfth_mall_commission_order_snapshot',
        'yfth_commission_accrual',
        'yfth_user_commission_account',
        'yfth_store_commission_account',
        'yfth_commission_ledger',
        'yfth_c1_withdrawal',
        'yfth_store_withdrawal',
        'yfth_withdrawal_allocation',
        'yfth_store_settlement_account',
    ];

    private const AUTHS = [
        'yfth-auto-commission-index',
        'yfth-auto-commission-rule-read',
        'yfth-auto-commission-rule-write',
        'yfth-auto-commission-account-read',
        'yfth-auto-commission-accrual-read',
        'yfth-auto-commission-ledger-read',
        'yfth-auto-commission-adjust',
        'yfth-auto-commission-withdrawal-read',
        'yfth-auto-commission-withdrawal-complete',
        'yfth-auto-commission-retry',
    ];

    public function up()
    {
        $this->addPackageObservationDays();
        if ($this->hasTable(self::TABLES[0])) {
            $this->assertComplete();
            $this->seedPermissions();
            return;
        }

        foreach (self::TABLES as $table) {
            if ($this->hasTable($table)) {
                throw new RuntimeException('yfth_auto_commission_partial_schema:' . $table);
            }
        }

        $this->createRuleVersion();
        $this->createMallOrderSnapshot();
        $this->createAccrual();
        $this->createUserAccount();
        $this->createStoreAccount();
        $this->createLedger();
        $this->createC1Withdrawal();
        $this->createStoreWithdrawal();
        $this->createWithdrawalAllocation();
        $this->createSettlementAccount();
        $this->seedPermissions();
        $this->assertComplete();
    }

    public function down()
    {
        foreach (self::AUTHS as $auth) {
            $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth));
        }
        foreach (array_reverse(self::TABLES) as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
        if ($this->hasTable('yfth_direct_referral_rule_version')
            && $this->table('yfth_direct_referral_rule_version')->hasColumn('package_observation_days')) {
            $this->table('yfth_direct_referral_rule_version')->removeColumn('package_observation_days')->update();
        }
    }

    private function createRuleVersion(): void
    {
        $this->table('yfth_commission_rule_version', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Versioned mall C1 and B1 commission rules')
            ->addColumn('rule_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('version_no', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('scope_type', 'string', ['limit' => 24, 'default' => 'all'])
            ->addColumn('scope_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('c1_ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('b1_ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('observation_days', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('enabled', 'boolean', ['signed' => false, 'default' => 1])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'draft'])
            ->addColumn('effective_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('expires_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 80, 'null' => true, 'default' => null])
            ->addColumn('note', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('created_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('published_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('published_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['rule_no'], ['unique' => true, 'name' => 'uniq_yfth_commission_rule_no'])
            ->addIndex(['version_no'], ['unique' => true, 'name' => 'uniq_yfth_commission_rule_version'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_commission_rule_active'])
            ->addIndex(['status', 'effective_at', 'expires_at'], ['name' => 'idx_yfth_commission_rule_effective'])
            ->create();
    }

    private function createMallOrderSnapshot(): void
    {
        $this->table('yfth_mall_commission_order_snapshot', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Immutable paid-order commission snapshot before observation')
            ->addColumn('snapshot_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('order_sn', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('buyer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('relation_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('pay_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('commission_base_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('item_snapshot_json', 'text', ['null' => false])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'paid'])
            ->addColumn('completed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('due_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('refunded_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['snapshot_no'], ['unique' => true, 'name' => 'uniq_yfth_mall_commission_snapshot_no'])
            ->addIndex(['order_id'], ['unique' => true, 'name' => 'uniq_yfth_mall_commission_order'])
            ->addIndex(['status', 'due_at'], ['name' => 'idx_yfth_mall_commission_due'])
            ->addIndex(['store_id', 'add_time'], ['name' => 'idx_yfth_mall_commission_store'])
            ->create();
    }

    private function createAccrual(): void
    {
        $this->table('yfth_commission_accrual', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Automatic package and mall commission accrual facts')
            ->addColumn('accrual_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_type', 'string', ['limit' => 32, 'default' => 'mall_order_item'])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_unique_key', 'char', ['limit' => 64, 'default' => ''])
            ->addColumn('candidate_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('category_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('c1_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('buyer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('base_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('c1_ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('b1_ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('c1_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('b1_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'observing'])
            ->addColumn('due_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('credited_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reversed_c1_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('reversed_b1_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('snapshot_json', 'text', ['null' => false])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['accrual_no'], ['unique' => true, 'name' => 'uniq_yfth_commission_accrual_no'])
            ->addIndex(['source_unique_key'], ['unique' => true, 'name' => 'uniq_yfth_commission_accrual_source'])
            ->addIndex(['status', 'due_at'], ['name' => 'idx_yfth_commission_accrual_due'])
            ->addIndex(['store_id', 'status', 'add_time'], ['name' => 'idx_yfth_commission_accrual_store'])
            ->addIndex(['c1_uid', 'status', 'add_time'], ['name' => 'idx_yfth_commission_accrual_c1'])
            ->create();
        $this->forceAscii('yfth_commission_accrual', 'source_unique_key');
    }

    private function createUserAccount(): void
    {
        $this->table('yfth_user_commission_account', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Withdraw-only YFTH user commission balance adapter')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('available_cent', 'biginteger', ['default' => 0])
            ->addColumn('frozen_cent', 'biginteger', ['default' => 0])
            ->addColumn('withdrawn_cent', 'biginteger', ['default' => 0])
            ->addColumn('version', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['uid'], ['unique' => true, 'name' => 'uniq_yfth_user_commission_uid'])
            ->create();
    }

    private function createStoreAccount(): void
    {
        $this->table('yfth_store_commission_account', ['signed' => false])
            ->setEngine('InnoDB')->setComment('B1 own commission and C1 proxy payable account projection')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('own_available_cent', 'biginteger', ['default' => 0])
            ->addColumn('proxy_available_cent', 'biginteger', ['default' => 0])
            ->addColumn('hq_frozen_cent', 'biginteger', ['default' => 0])
            ->addColumn('hq_withdrawn_cent', 'biginteger', ['default' => 0])
            ->addColumn('c1_pending_cent', 'biginteger', ['default' => 0])
            ->addColumn('c1_paid_cent', 'biginteger', ['default' => 0])
            ->addColumn('reversed_cent', 'biginteger', ['default' => 0])
            ->addColumn('version', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['store_id'], ['unique' => true, 'name' => 'uniq_yfth_store_commission_store'])
            ->create();
    }

    private function createLedger(): void
    {
        $this->table('yfth_commission_ledger', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Immutable authoritative YFTH commission account ledger')
            ->addColumn('ledger_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('account_type', 'string', ['limit' => 16, 'default' => 'user'])
            ->addColumn('account_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('bucket', 'string', ['limit' => 32, 'default' => 'c1_commission'])
            ->addColumn('direction', 'string', ['limit' => 12, 'default' => 'credit'])
            ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('balance_before_cent', 'biginteger', ['default' => 0])
            ->addColumn('balance_after_cent', 'biginteger', ['default' => 0])
            ->addColumn('available_after_cent', 'biginteger', ['default' => 0])
            ->addColumn('frozen_after_cent', 'biginteger', ['default' => 0])
            ->addColumn('withdrawn_after_cent', 'biginteger', ['default' => 0])
            ->addColumn('remaining_withdrawable_cent', 'biginteger', ['default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_order_item_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('c1_ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('b1_ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reverse_ledger_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_unique_key', 'char', ['limit' => 64, 'default' => ''])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('snapshot_json', 'text', ['null' => false])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['ledger_no'], ['unique' => true, 'name' => 'uniq_yfth_commission_ledger_no'])
            ->addIndex(['source_unique_key'], ['unique' => true, 'name' => 'uniq_yfth_commission_ledger_source'])
            ->addIndex(['account_type', 'account_id', 'add_time'], ['name' => 'idx_yfth_commission_ledger_account'])
            ->addIndex(['account_type', 'account_id', 'bucket', 'remaining_withdrawable_cent', 'id'], ['name' => 'idx_yfth_commission_ledger_fifo'])
            ->create();
        $this->forceAscii('yfth_commission_ledger', 'source_unique_key');
    }

    private function createC1Withdrawal(): void
    {
        $this->table('yfth_c1_withdrawal', ['signed' => false])
            ->setEngine('InnoDB')->setComment('C1 withdrawal request paid offline by responsible B1')
            ->addColumn('withdrawal_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('offline_ref_no', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('proof_ref', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('completed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['withdrawal_no'], ['unique' => true, 'name' => 'uniq_yfth_c1_withdrawal_no'])
            ->addIndex(['uid', 'request_id'], ['unique' => true, 'name' => 'uniq_yfth_c1_withdrawal_request'])
            ->addIndex(['store_id', 'status', 'add_time'], ['name' => 'idx_yfth_c1_withdrawal_store'])
            ->create();
    }

    private function createStoreWithdrawal(): void
    {
        $this->table('yfth_store_withdrawal', ['signed' => false])
            ->setEngine('InnoDB')->setComment('B1 withdrawal to headquarters settlement account')
            ->addColumn('withdrawal_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('own_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('proxy_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'reviewing'])
            ->addColumn('settlement_account_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('settlement_snapshot_json', 'text', ['null' => false])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('admin_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('completed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['withdrawal_no'], ['unique' => true, 'name' => 'uniq_yfth_store_withdrawal_no'])
            ->addIndex(['store_id', 'request_id'], ['unique' => true, 'name' => 'uniq_yfth_store_withdrawal_request'])
            ->addIndex(['status', 'add_time'], ['name' => 'idx_yfth_store_withdrawal_status'])
            ->create();
    }

    private function createWithdrawalAllocation(): void
    {
        $this->table('yfth_withdrawal_allocation', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Immutable FIFO source allocation for B1 withdrawals')
            ->addColumn('withdrawal_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('ledger_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('bucket', 'string', ['limit' => 32, 'default' => 'store_own'])
            ->addColumn('amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['withdrawal_id', 'ledger_id'], ['unique' => true, 'name' => 'uniq_yfth_withdrawal_allocation'])
            ->addIndex(['ledger_id'], ['name' => 'idx_yfth_withdrawal_allocation_ledger'])
            ->create();
    }

    private function createSettlementAccount(): void
    {
        $this->table('yfth_store_settlement_account', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Encrypted default B1 bank settlement account')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('account_type', 'string', ['limit' => 24, 'default' => 'personal'])
            ->addColumn('account_name_enc', 'text', ['null' => false])
            ->addColumn('account_no_enc', 'text', ['null' => false])
            ->addColumn('bank_name_enc', 'text', ['null' => false])
            ->addColumn('bank_branch_enc', 'text', ['null' => false])
            ->addColumn('reserved_phone_enc', 'text', ['null' => false])
            ->addColumn('contact_name_enc', 'text', ['null' => false])
            ->addColumn('contact_phone_enc', 'text', ['null' => false])
            ->addColumn('account_no_masked', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('is_default', 'boolean', ['signed' => false, 'default' => 1])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['store_id', 'is_default'], ['unique' => true, 'name' => 'uniq_yfth_store_settlement_default'])
            ->create();
    }

    private function addPackageObservationDays(): void
    {
        if (!$this->hasTable('yfth_direct_referral_rule_version')) {
            throw new RuntimeException('yfth_direct_referral_rule_version_required');
        }
        $table = $this->table('yfth_direct_referral_rule_version');
        if (!$table->hasColumn('package_observation_days')) {
            $table->addColumn('package_observation_days', 'integer', [
                'signed' => false,
                'default' => 0,
                'after' => 'package_ratio_third_bps',
                'comment' => 'package reward observation days',
            ])->update();
        }
    }

    private function seedPermissions(): void
    {
        $root = $this->menuByAuth('yfth-foundation');
        if (!$root) {
            throw new RuntimeException('yfth_foundation_menu_required');
        }
        $page = $this->menuByAuth(self::AUTHS[0]);
        if (!$page) {
            $this->insertMenu([
                'pid' => (int)$root['id'], 'icon' => 'md-wallet', 'menu_name' => '佣金与提现',
                'module' => 'admin', 'controller' => 'v1.yfth.CommissionFinance', 'action' => 'index',
                'api_url' => 'yfth/commission/account', 'methods' => 'GET', 'params' => '', 'sort' => 8,
                'is_show' => 1, 'is_show_path' => 1, 'access' => 1,
                'menu_path' => '/yfth/commission-finance', 'path' => (string)$root['id'],
                'auth_type' => 1, 'header' => 'yfth', 'is_header' => 0,
                'unique_auth' => self::AUTHS[0], 'is_del' => 0, 'mark' => 'yfth',
            ]);
            $page = $this->menuByAuth(self::AUTHS[0]);
        }
        $defs = [
            ['佣金规则查看', 'yfth/commission/rule', 'GET', self::AUTHS[1]],
            ['佣金规则维护', 'yfth/commission/rule', 'POST', self::AUTHS[2]],
            ['佣金账户查看', 'yfth/commission/account', 'GET', self::AUTHS[3]],
            ['自动结算记录', 'yfth/commission/accrual', 'GET', self::AUTHS[4]],
            ['佣金流水查看', 'yfth/commission/ledger', 'GET', self::AUTHS[5]],
            ['佣金余额调整', 'yfth/commission/adjustment', 'POST', self::AUTHS[6]],
            ['门店提现查看', 'yfth/commission/withdrawal', 'GET', self::AUTHS[7]],
            ['门店提现完成', 'yfth/commission/withdrawal/<id>/complete', 'POST', self::AUTHS[8]],
            ['佣金到期补偿', 'yfth/commission/retry', 'POST', self::AUTHS[9]],
        ];
        foreach ($defs as $def) {
            if ($this->menuByAuth($def[3])) continue;
            $this->insertMenu([
                'pid' => (int)$page['id'], 'icon' => '', 'menu_name' => $def[0],
                'module' => 'admin', 'controller' => 'v1.yfth.CommissionFinance', 'action' => '',
                'api_url' => $def[1], 'methods' => $def[2], 'params' => '', 'sort' => 0,
                'is_show' => 0, 'is_show_path' => 0, 'access' => 1, 'menu_path' => '',
                'path' => (string)$page['id'], 'auth_type' => 2, 'header' => 'yfth', 'is_header' => 0,
                'unique_auth' => $def[3], 'is_del' => 0, 'mark' => 'yfth',
            ]);
        }
    }

    private function assertComplete(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) {
                throw new RuntimeException('yfth_auto_commission_forward_repair_required:' . $table);
            }
        }
    }

    private function menuByAuth(string $auth): array
    {
        $rows = $this->getAdapter()->fetchAll('SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth) . ' AND `is_del`=0 ORDER BY `id` ASC');
        return $rows ? $rows[0] : [];
    }

    private function insertMenu(array $row): void
    {
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO `' . $this->prefixed('system_menus') . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
    }

    private function forceAscii(string $table, string $column): void
    {
        $this->execute('ALTER TABLE `' . $this->prefixed($table) . '` MODIFY `' . $column . '` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\'');
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        return (method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '') . $table;
    }

    private function quote($value): string
    {
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
