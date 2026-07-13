<?php

use think\migration\Migrator;

class CreateYfthRewardSettlementLedger extends Migrator
{
    private const TABLE = 'yfth_direct_referral_reward_settlement_ledger';
    private const AUTHS = [
        'yfth-package-membership-reward-settlement-read',
        'yfth-package-membership-reward-settlement-cancel',
        'yfth-package-membership-reward-settlement-correct',
    ];

    public function up()
    {
        if ($this->hasTable(self::TABLE)) {
            $this->assertTable();
        } else {
            $this->table(self::TABLE, ['signed' => false])
                ->setEngine('InnoDB')->setComment('YFTH immutable offline reward settlement facts; no automatic payment')
                ->addColumn('settlement_no', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('candidate_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('candidate_no', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('candidate_type', 'string', ['limit' => 40, 'default' => ''])
                ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('referred_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('reward_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('offline_ref_no', 'string', ['limit' => 128, 'default' => ''])
                ->addColumn('proof_ref', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('remark', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('operator_role_code', 'string', ['limit' => 48, 'default' => ''])
                ->addColumn('settled_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['settlement_no'], ['unique' => true, 'name' => 'uniq_yfth_direct_settlement_no'])
                ->addIndex(['candidate_id'], ['unique' => true, 'name' => 'uniq_yfth_direct_settlement_candidate'])
                ->addIndex(['store_id', 'settled_at'], ['name' => 'idx_yfth_direct_settlement_store_time'])
                ->addIndex(['referrer_uid', 'settled_at'], ['name' => 'idx_yfth_direct_settlement_referrer_time'])
                ->create();
        }
        $this->seedPermissions();
    }

    public function down()
    {
        $this->deletePermissions();
        if ($this->hasTable(self::TABLE)) {
            $this->table(self::TABLE)->drop();
        }
    }

    private function assertTable(): void
    {
        foreach (['settlement_no', 'candidate_id', 'candidate_no', 'store_id', 'reward_amount_cent', 'operator_uid', 'settled_at'] as $column) {
            if (!$this->columnExists(self::TABLE, $column)) {
                throw new RuntimeException('yfth_reward_settlement_ledger_forward_repair_required:' . $column);
            }
        }
        foreach (['uniq_yfth_direct_settlement_no', 'uniq_yfth_direct_settlement_candidate'] as $index) {
            if (!$this->indexExists(self::TABLE, $index)) {
                throw new RuntimeException('yfth_reward_settlement_ledger_forward_repair_required:' . $index);
            }
        }
    }

    private function seedPermissions(): void
    {
        $page = $this->menuByAuth('yfth-package-membership-referral-index');
        if (!$page) {
            throw new RuntimeException('yfth_reward_settlement_package_membership_menu_required');
        }
        foreach ([
            ['Reward settle list', 'yfth/reward_settlement/candidate', 'GET', self::AUTHS[0]],
            ['Reward settle cancel', 'yfth/reward_settlement/candidate/<id>/cancel', 'POST', self::AUTHS[1]],
            ['Reward settle correct', 'yfth/reward_settlement/candidate/<id>/correct', 'POST', self::AUTHS[2]],
        ] as $definition) {
            [$name, $url, $method, $auth] = $definition;
            $existing = $this->menuByAuth($auth);
            $row = [
                'pid' => (int)$page['id'], 'icon' => '', 'menu_name' => $name,
                'module' => 'admin', 'controller' => 'v1.yfth.RewardSettlement', 'action' => '',
                'api_url' => $url, 'methods' => $method, 'params' => '', 'sort' => 0,
                'is_show' => 0, 'is_show_path' => 0, 'access' => 1, 'menu_path' => '',
                'path' => (string)$page['id'], 'auth_type' => 2, 'header' => 'yfth', 'is_header' => 0,
                'unique_auth' => $auth, 'is_del' => 0, 'mark' => 'yfth',
            ];
            if ($existing) {
                foreach (['pid', 'api_url', 'methods', 'auth_type', 'unique_auth', 'is_del'] as $field) {
                    if ((string)$existing[$field] !== (string)$row[$field]) {
                        throw new RuntimeException('yfth_reward_settlement_permission_forward_repair_required:' . $auth);
                    }
                }
                continue;
            }
            $this->insertRow('system_menus', $row);
        }
    }

    private function deletePermissions(): void
    {
        foreach (self::AUTHS as $auth) {
            $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth));
        }
    }

    private function menuByAuth(string $auth): array
    {
        $rows = $this->getAdapter()->fetchAll('SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth) . ' AND `is_del`=0 ORDER BY `id` ASC');
        return $rows ? $rows[0] : [];
    }

    private function insertRow(string $table, array $row): void
    {
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO `' . $this->prefixed($table) . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool)$this->getAdapter()->fetchRow('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)) . ' AND COLUMN_NAME=' . $this->quote($column) . ' LIMIT 1');
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool)$this->getAdapter()->fetchRow('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)) . ' AND INDEX_NAME=' . $this->quote($index) . ' LIMIT 1');
    }

    public function hasTable($table): bool
    {
        return (bool)$this->getAdapter()->fetchRow('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)) . ' LIMIT 1');
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
