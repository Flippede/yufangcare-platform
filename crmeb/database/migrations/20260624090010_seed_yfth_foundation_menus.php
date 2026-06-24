<?php

use think\migration\Migrator;

class SeedYfthFoundationMenus extends Migrator
{
    private $menuKeys = [
        'yfth-foundation',
        'yfth-foundation-index',
        'yfth-foundation-identity-list',
        'yfth-foundation-store-role-list',
        'yfth-foundation-subject-list',
        'yfth-foundation-subject-save',
        'yfth-foundation-store-subject-list',
        'yfth-foundation-qualification-list',
        'yfth-foundation-qualification-save',
        'yfth-foundation-qualification-audit',
        'yfth-foundation-capability-list',
        'yfth-foundation-payment-route-list',
        'yfth-foundation-audit-event-list',
    ];

    public function up()
    {
        $this->table('system_menus')->insert([
            [
                'pid' => 0,
                'icon' => 'md-git-network',
                'menu_name' => '御方通和',
                'module' => 'admin',
                'controller' => '',
                'action' => '',
                'api_url' => '',
                'methods' => 'GET',
                'params' => '',
                'sort' => 320,
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
            ],
            [
                'pid' => 0,
                'icon' => 'md-business',
                'menu_name' => '基础域',
                'module' => 'admin',
                'controller' => 'v1.yfth.Foundation',
                'action' => 'index',
                'api_url' => 'yfth/foundation/identity',
                'methods' => 'GET',
                'params' => '',
                'sort' => 10,
                'is_show' => 1,
                'is_show_path' => 1,
                'access' => 1,
                'menu_path' => '/yfth/foundation',
                'path' => '/yfth/foundation',
                'auth_type' => 1,
                'header' => 'yfth',
                'is_header' => 0,
                'unique_auth' => 'yfth-foundation-index',
                'is_del' => 0,
                'mark' => 'yfth',
            ],
            $this->apiRow('用户身份列表', 'yfth/foundation/identity', 'GET', 'yfth-foundation-identity-list'),
            $this->apiRow('门店角色列表', 'yfth/foundation/store_role', 'GET', 'yfth-foundation-store-role-list'),
            $this->apiRow('经营主体列表', 'yfth/foundation/subject', 'GET', 'yfth-foundation-subject-list'),
            $this->apiRow('保存经营主体', 'yfth/foundation/subject/save', 'POST', 'yfth-foundation-subject-save'),
            $this->apiRow('门店主体列表', 'yfth/foundation/store_subject', 'GET', 'yfth-foundation-store-subject-list'),
            $this->apiRow('门店资质列表', 'yfth/foundation/qualification', 'GET', 'yfth-foundation-qualification-list'),
            $this->apiRow('提交门店资质', 'yfth/foundation/qualification/save', 'POST', 'yfth-foundation-qualification-save'),
            $this->apiRow('审核门店资质', 'yfth/foundation/qualification/audit', 'POST', 'yfth-foundation-qualification-audit'),
            $this->apiRow('门店能力列表', 'yfth/foundation/capability', 'GET', 'yfth-foundation-capability-list'),
            $this->apiRow('收款路由列表', 'yfth/foundation/payment_route', 'GET', 'yfth-foundation-payment-route-list'),
            $this->apiRow('审计事件列表', 'yfth/foundation/audit_event', 'GET', 'yfth-foundation-audit-event-list'),
        ])->saveData();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return "'" . str_replace("'", "''", $key) . "'";
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
    }

    private function apiRow(string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => 0,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.Foundation',
            'action' => '',
            'api_url' => $url,
            'methods' => $method,
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => '',
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => $auth,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
