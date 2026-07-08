<?php

use think\migration\Migrator;

class CreateYfthFranchiseApplicationTables extends Migrator
{
    private $menuKeys = [
        'yfth-franchise-application-index',
        'yfth-franchise-application-list',
        'yfth-franchise-application-detail',
        'yfth-franchise-application-assign',
        'yfth-franchise-application-status',
        'yfth-franchise-application-follow',
    ];

    public function up()
    {
        $this->createApplicationTable();
        $this->createFollowRecordTable();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        if ($this->hasTable('yfth_franchise_follow_record')) {
            $this->table('yfth_franchise_follow_record')->drop();
        }
        if ($this->hasTable('yfth_franchise_application')) {
            $this->table('yfth_franchise_application')->drop();
        }
    }

    private function createApplicationTable(): void
    {
        if ($this->hasTable('yfth_franchise_application')) {
            return;
        }

        $this->table('yfth_franchise_application')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise application workflow')
            ->addColumn('application_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'application number'])
            ->addColumn('applicant_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user uid'])
            ->addColumn('name', 'string', ['limit' => 64, 'default' => '', 'comment' => 'applicant name'])
            ->addColumn('phone', 'string', ['limit' => 32, 'default' => '', 'comment' => 'contact phone'])
            ->addColumn('city', 'string', ['limit' => 64, 'default' => '', 'comment' => 'city'])
            ->addColumn('region', 'string', ['limit' => 64, 'default' => '', 'comment' => 'region'])
            ->addColumn('intention_area', 'string', ['limit' => 128, 'default' => '', 'comment' => 'intention area'])
            ->addColumn('budget', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'budget'])
            ->addColumn('source', 'string', ['limit' => 48, 'default' => 'miniapp_cooperation_center', 'comment' => 'application source'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'submitted', 'comment' => 'draft/submitted/contacting/communicating/inspecting/pending_contract'])
            ->addColumn('assigned_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'headquarters owner admin id'])
            ->addColumn('remark', 'text', ['null' => true, 'comment' => 'applicant remark'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['application_no'], ['unique' => true, 'name' => 'uniq_yfth_franchise_app_no'])
            ->addIndex(['applicant_uid', 'status'], ['name' => 'idx_yfth_franchise_app_user_status'])
            ->addIndex(['assigned_uid', 'status'], ['name' => 'idx_yfth_franchise_app_owner_status'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_franchise_app_status_time'])
            ->addIndex(['phone'], ['name' => 'idx_yfth_franchise_app_phone'])
            ->addIndex(['city', 'status'], ['name' => 'idx_yfth_franchise_app_city_status'])
            ->create();
    }

    private function createFollowRecordTable(): void
    {
        if ($this->hasTable('yfth_franchise_follow_record')) {
            return;
        }

        $this->table('yfth_franchise_follow_record')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise application follow records')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'headquarters admin id'])
            ->addColumn('type', 'string', ['limit' => 32, 'default' => 'phone', 'comment' => 'phone/wechat/meeting/inspection/other'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => 'follow content'])
            ->addColumn('next_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'next contact time'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['application_id', 'create_time'], ['name' => 'idx_yfth_franchise_follow_app_time'])
            ->addIndex(['operator_uid'], ['name' => 'idx_yfth_franchise_follow_operator'])
            ->addIndex(['type'], ['name' => 'idx_yfth_franchise_follow_type'])
            ->addIndex(['next_time'], ['name' => 'idx_yfth_franchise_follow_next'])
            ->create();
    }

    private function seedMenus(): void
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-people',
            'menu_name' => '加盟管理',
            'module' => 'admin',
            'controller' => 'v1.yfth.FranchiseApplication',
            'action' => 'index',
            'api_url' => 'yfth/franchise_application/application',
            'methods' => 'GET',
            'params' => '',
            'sort' => 6,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/franchise-application',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-franchise-application-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, '加盟申请列表', 'yfth/franchise_application/application', 'GET', 'yfth-franchise-application-list'),
            $this->apiRow($pageId, '加盟申请详情', 'yfth/franchise_application/application/<id>', 'GET', 'yfth-franchise-application-detail'),
            $this->apiRow($pageId, '加盟申请分配负责人', 'yfth/franchise_application/application/<id>/assign', 'POST', 'yfth-franchise-application-assign'),
            $this->apiRow($pageId, '加盟申请状态推进', 'yfth/franchise_application/application/<id>/status', 'POST', 'yfth-franchise-application-status'),
            $this->apiRow($pageId, '加盟申请沟通记录', 'yfth/franchise_application/application/<id>/follow', 'POST', 'yfth-franchise-application-follow'),
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
            'controller' => 'v1.yfth.FranchiseApplication',
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
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
