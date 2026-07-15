<?php

use think\migration\Migrator;

class CreateYfthAcceptanceFixture extends Migrator
{
    private const AUTHS = [
        'yfth-user-role-management-fixture-read',
        'yfth-user-role-management-fixture-generate',
        'yfth-user-role-management-fixture-reset',
    ];

    public function up()
    {
        if (!$this->hasTable('yfth_acceptance_fixture')) {
            $this->table('yfth_acceptance_fixture')
                ->setEngine('InnoDB')
                ->setComment('Controlled YFTH user acceptance fixture manifest')
                ->addColumn('fixture_key', 'string', ['limit' => 64, 'default' => '', 'comment' => 'stable fixture key'])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
                ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('subject_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('franchisee_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('manager_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('staff_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('member_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('customer_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('package_template_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('package_rule_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('package_purchase_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('referral_rule_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('created_admin_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('updated_admin_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('last_reason', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('disabled_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['fixture_key'], ['unique' => true, 'name' => 'uniq_yfth_acceptance_fixture_key'])
                ->addIndex(['status'], ['name' => 'idx_yfth_acceptance_fixture_status'])
                ->create();
        }

        $page = $this->menu('yfth-user-role-management-index');
        if (!$page) {
            throw new RuntimeException('yfth_user_role_management_menu_required');
        }
        $this->execute('UPDATE `' . $this->prefixed('system_menus') . '` SET `is_show`=1,`is_show_path`=1,`sort`=90 WHERE `id`=' . (int)$page['id']);
        $rows = [
            $this->api((int)$page['id'], '查看受控验收测试数据', 'yfth/user_role/fixture', 'GET', self::AUTHS[0]),
            $this->api((int)$page['id'], '生成受控验收测试数据', 'yfth/user_role/fixture/generate', 'POST', self::AUTHS[1]),
            $this->api((int)$page['id'], '重置受控验收测试数据', 'yfth/user_role/fixture/reset', 'POST', self::AUTHS[2]),
        ];
        foreach ($rows as $row) {
            $this->ensure($row);
        }
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], self::AUTHS);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
        if ($this->hasTable('yfth_acceptance_fixture')) {
            $this->table('yfth_acceptance_fixture')->drop();
        }
    }

    private function api(int $pageId, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pageId, 'icon' => '', 'menu_name' => $name, 'module' => 'admin',
            'controller' => 'v1.yfth.HqUserRole', 'action' => '', 'api_url' => $url, 'methods' => $method,
            'params' => '', 'sort' => 0, 'is_show' => 0, 'is_show_path' => 0, 'access' => 1,
            'menu_path' => '', 'path' => (string)$pageId, 'auth_type' => 2, 'header' => 'yfth',
            'is_header' => 0, 'unique_auth' => $auth, 'is_del' => 0, 'mark' => 'yfth',
        ];
    }

    private function ensure(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchAll('SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']));
        if (count($existing) > 1) {
            throw new RuntimeException('yfth_acceptance_fixture_permission_duplicate:' . $row['unique_auth']);
        }
        if ($existing) {
            return (int)$existing[0]['id'];
        }
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->menu($row['unique_auth']);
        return (int)$created['id'];
    }

    private function menu(string $auth): array
    {
        $row = $this->getAdapter()->fetchRow('SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth) . ' AND `is_del`=0 LIMIT 1');
        return $row ?: [];
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) return (string)$value;
        if ($value === null) return 'NULL';
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
