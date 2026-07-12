<?php

use think\migration\Migrator;

class CreateYfthPermanentMembershipTables extends Migrator
{
    private const AUTHS = [
        'yfth-permanent-membership-index',
        'yfth-permanent-membership-enrollment-read',
        'yfth-permanent-membership-member-read',
        'yfth-permanent-membership-enrollment-create',
        'yfth-permanent-membership-enrollment-bind',
        'yfth-permanent-membership-payment-confirm',
        'yfth-permanent-membership-confirmation-code',
    ];

    public function up()
    {
        $this->createEnrollment();
        $this->createMembership();
        $this->createMembershipEvent();
        $this->createDynamicCode();
        $this->createRewardCandidate();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], self::AUTHS);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
        foreach ([
            'yfth_membership_reward_candidate',
            'yfth_business_dynamic_code',
            'yfth_permanent_membership_event',
            'yfth_permanent_membership',
            'yfth_permanent_membership_enrollment',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createEnrollment(): void
    {
        if ($this->hasTable('yfth_permanent_membership_enrollment')) return;
        $this->table('yfth_permanent_membership_enrollment', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH offline permanent membership enrollment')
            ->addColumn('enrollment_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('target_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 40, 'default' => 'draft'])
            ->addColumn('amount_cents', 'biginteger', ['signed' => false, 'default' => 980000])
            ->addColumn('payment_status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('target_bound_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('payment_confirmed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('activated_member_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('activated_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('created_by_type', 'string', ['limit' => 24, 'default' => 'store_user'])
            ->addColumn('created_by_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('created_by_role', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('active_target_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['enrollment_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_enrollment_no'])
            ->addIndex(['active_target_key'], ['unique' => true, 'name' => 'uniq_yfth_pm_enrollment_target'])
            ->addIndex(['store_id', 'status', 'add_time'], ['name' => 'idx_yfth_pm_enrollment_store'])
            ->addIndex(['target_uid', 'status'], ['name' => 'idx_yfth_pm_enrollment_uid'])
            ->create();
    }

    private function createMembership(): void
    {
        if ($this->hasTable('yfth_permanent_membership')) return;
        $this->table('yfth_permanent_membership', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH permanent membership authority')
            ->addColumn('membership_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('enrollment_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('amount_cents', 'biginteger', ['signed' => false, 'default' => 980000])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('activated_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['membership_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_no'])
            ->addIndex(['uid'], ['unique' => true, 'name' => 'uniq_yfth_pm_uid'])
            ->addIndex(['enrollment_id'], ['unique' => true, 'name' => 'uniq_yfth_pm_enrollment'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_pm_store'])
            ->create();
    }

    private function createMembershipEvent(): void
    {
        if ($this->hasTable('yfth_permanent_membership_event')) return;
        $this->table('yfth_permanent_membership_event', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH permanent membership authority events')
            ->addColumn('event_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('membership_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('membership_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('event_type', 'string', ['limit' => 48, 'default' => 'membership_activated'])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('operator_role_code', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['event_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_event_no'])
            ->addIndex(['membership_id', 'authority_version'], ['unique' => true, 'name' => 'uniq_yfth_pm_event_version'])
            ->addIndex(['uid', 'add_time'], ['name' => 'idx_yfth_pm_event_uid'])
            ->create();
    }

    private function createDynamicCode(): void
    {
        if ($this->hasTable('yfth_business_dynamic_code')) return;
        $this->table('yfth_business_dynamic_code', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH business-scoped single-use dynamic codes')
            ->addColumn('code_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('enrollment_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('target_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'issued'])
            ->addColumn('issued_by_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('issued_by_role', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('used_by_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('used_by_role', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('issued_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('used_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('invalidated_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 128, 'null' => true, 'default' => null])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['code_no'], ['unique' => true, 'name' => 'uniq_yfth_business_code_no'])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_yfth_business_code_hash'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_business_code_active'])
            ->addIndex(['target_uid', 'scene', 'status'], ['name' => 'idx_yfth_business_code_uid'])
            ->addIndex(['enrollment_id', 'scene', 'status'], ['name' => 'idx_yfth_business_code_enrollment'])
            ->create();
        $this->execute('ALTER TABLE `' . $this->prefixed('yfth_business_dynamic_code') . '` MODIFY `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ' . $this->quote(''));
    }

    private function createRewardCandidate(): void
    {
        if ($this->hasTable('yfth_membership_reward_candidate')) return;
        $this->table('yfth_membership_reward_candidate', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH amount-free permanent membership reward candidate fact')
            ->addColumn('candidate_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('business_type', 'string', ['limit' => 48, 'default' => 'permanent_membership_activated'])
            ->addColumn('membership_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('enrollment_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('target_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('unique_key', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['candidate_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_candidate_no'])
            ->addIndex(['unique_key'], ['unique' => true, 'name' => 'uniq_yfth_pm_candidate_key'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_pm_candidate_store'])
            ->create();
    }

    private function seedMenus(): void
    {
        $rootId = $this->rootId();
        $pageId = $this->upsertMenu($this->menuRow($rootId, '永久会员办理', 'yfth/permanent-membership', self::AUTHS[0]));
        foreach ([
            ['查看会员办理', 'yfth/permanent_membership/enrollment', 'GET', self::AUTHS[1]],
            ['查看永久会员', 'yfth/permanent_membership/member', 'GET', self::AUTHS[2]],
            ['创建会员办理', 'yfth/permanent_membership/enrollment', 'POST', self::AUTHS[3]],
            ['绑定办理顾客', 'yfth/permanent_membership/enrollment/<id>/bind', 'POST', self::AUTHS[4]],
            ['确认线下收款', 'yfth/permanent_membership/enrollment/<id>/payment', 'POST', self::AUTHS[5]],
            ['生成会员确认码', 'yfth/permanent_membership/enrollment/<id>/confirmation_code', 'POST', self::AUTHS[6]],
        ] as $item) {
            $this->upsertMenu($this->apiRow($pageId, ...$item));
        }
    }

    private function rootId(): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $row = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote('yfth-foundation') . ' LIMIT 1');
        if (!$row) throw new RuntimeException('yfth_foundation_menu_required');
        return (int)$row['id'];
    }

    private function menuRow(int $pid, string $name, string $url, string $auth): array
    {
        return ['pid'=>$pid,'icon'=>'md-contacts','menu_name'=>$name,'module'=>'admin','controller'=>'v1.yfth.PermanentMembership','action'=>'index','api_url'=>'yfth/permanent_membership/enrollment','methods'=>'GET','params'=>'','sort'=>11,'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'/yfth/permanent-membership','path'=>(string)$pid,'auth_type'=>1,'header'=>'yfth','is_header'=>0,'unique_auth'=>$auth,'is_del'=>0,'mark'=>'yfth'];
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return ['pid'=>$pid,'icon'=>'','menu_name'=>$name,'module'=>'admin','controller'=>'v1.yfth.PermanentMembership','action'=>'','api_url'=>$url,'methods'=>$method,'params'=>'','sort'=>0,'is_show'=>0,'is_show_path'=>0,'access'=>1,'menu_path'=>'','path'=>(string)$pid,'auth_type'=>2,'header'=>'yfth','is_header'=>0,'unique_auth'=>$auth,'is_del'=>0,'mark'=>'yfth'];
    }

    private function upsertMenu(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        if ($existing) return (int)$existing['id'];
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) return (string)$value;
        if ($value === null) return 'NULL';
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        return (method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '') . $table;
    }
}
