<?php

use think\migration\Migrator;

class SimplifyFranchiseReviewAndLocalizeAdmin extends Migrator
{
    private const REVIEW_AUTH = 'yfth-franchise-application-review';

    public function up()
    {
        $page = $this->menu('yfth-franchise-application-index');
        if (!$page) {
            throw new RuntimeException('yfth_franchise_application_menu_required');
        }
        $this->ensure($this->api((int)$page['id']));
        $this->rename('yfth-franchise-application-index', '总部加盟申请');
        $this->rename('yfth-package-membership-referral-index', '套餐会员与一级推荐');
        $this->rename('yfth-franchise-opening-index', '历史开店流程（兼容）', 0);
        $this->rename('yfth-referral-reward-index', '推荐奖励台账');
        $this->rename('yfth-supply-chain-index', '供应链与门店库存');
    }

    public function down()
    {
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote(self::REVIEW_AUTH));
        $this->rename('yfth-franchise-application-index', '加盟管理');
        $this->rename('yfth-package-membership-referral-index', 'Package Membership Referral');
        $this->rename('yfth-franchise-opening-index', 'Franchise Opening', 1);
        $this->rename('yfth-referral-reward-index', 'Referral Reward Ledger');
        $this->rename('yfth-supply-chain-index', 'Supply Chain');
    }

    private function api(int $pageId): array
    {
        return [
            'pid' => $pageId,
            'icon' => '',
            'menu_name' => '总部审核加盟申请',
            'module' => 'admin',
            'controller' => 'v1.yfth.FranchiseApplication',
            'action' => '',
            'api_url' => 'yfth/franchise_application/application/<id>/review',
            'methods' => 'POST',
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$pageId,
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => self::REVIEW_AUTH,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
    }

    private function ensure(array $row): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchAll('SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']));
        if (count($existing) > 1) {
            throw new RuntimeException('yfth_franchise_review_permission_duplicate');
        }
        if ($existing) {
            return;
        }
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
    }

    private function rename(string $auth, string $name, ?int $isShow = null): void
    {
        $sets = ['`menu_name`=' . $this->quote($name)];
        if ($isShow !== null) {
            $sets[] = '`is_show`=' . $isShow;
        }
        $this->execute('UPDATE `' . $this->prefixed('system_menus') . '` SET ' . implode(',', $sets) . ' WHERE `unique_auth`=' . $this->quote($auth));
    }

    private function menu(string $auth): array
    {
        return $this->getAdapter()->fetchRow('SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth) . ' AND `is_del`=0 LIMIT 1') ?: [];
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
