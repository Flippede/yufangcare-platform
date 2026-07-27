<?php

use think\migration\Migrator;

class AttachYfthWithdrawalToFinanceMenu extends Migrator
{
    private const GROUP_AUTH = 'yfth-finance';
    private const PAGE_AUTH = 'yfth-finance-withdrawal-index';

    public function up()
    {
        $financeRoot = $this->menu('admin-finance');
        $group = $this->menu(self::GROUP_AUTH);
        $page = $this->menu(self::PAGE_AUTH);
        if (!$financeRoot || !$group || !$page) {
            throw new RuntimeException('yfth_finance_menu_forward_repair_required');
        }

        $this->updateMenu((int)$group['id'], [
            'pid' => (int)$financeRoot['id'],
            'menu_name' => '御方通和资金',
            'sort' => 5,
            'menu_path' => '/yfth-finance',
            'path' => (string)$financeRoot['id'],
            'header' => 'finance',
            'is_header' => 0,
            'is_show' => 1,
            'is_show_path' => 1,
        ]);
        $this->updateMenu((int)$page['id'], [
            'pid' => (int)$group['id'],
            'menu_name' => '提现审核',
            'menu_path' => '/yfth-finance/withdrawal',
            'path' => (int)$financeRoot['id'] . '/' . (int)$group['id'],
            'header' => 'finance',
            'is_header' => 0,
            'is_show' => 1,
            'is_show_path' => 1,
        ]);

        $apiPath = (int)$financeRoot['id'] . '/' . (int)$group['id'] . '/' . (int)$page['id'];
        $this->execute(
            'UPDATE `' . $this->prefixed('system_menus') . '` SET ' .
            '`header`=' . $this->quote('finance') . ',`path`=' . $this->quote($apiPath) .
            ' WHERE `pid`=' . (int)$page['id'] . ' AND `is_del`=0'
        );
    }

    public function down()
    {
        $group = $this->menu(self::GROUP_AUTH);
        $page = $this->menu(self::PAGE_AUTH);
        if (!$group || !$page) {
            return;
        }
        $this->updateMenu((int)$group['id'], [
            'pid' => 0,
            'menu_name' => '财务',
            'sort' => 27,
            'menu_path' => '/yfth-finance',
            'path' => '/yfth-finance',
            'header' => 'yfth-finance',
            'is_header' => 1,
        ]);
        $this->updateMenu((int)$page['id'], [
            'pid' => (int)$group['id'],
            'path' => (string)$group['id'],
            'header' => 'yfth-finance',
        ]);
        $this->execute(
            'UPDATE `' . $this->prefixed('system_menus') . '` SET ' .
            '`header`=' . $this->quote('yfth-finance') . ',`path`=' . $this->quote((string)$page['id']) .
            ' WHERE `pid`=' . (int)$page['id'] . ' AND `is_del`=0'
        );
    }

    private function updateMenu(int $id, array $fields): void
    {
        $sets = [];
        foreach ($fields as $field => $value) {
            $sets[] = '`' . $field . '`=' . $this->quote($value);
        }
        $this->execute(
            'UPDATE `' . $this->prefixed('system_menus') . '` SET ' . implode(',', $sets) .
            ' WHERE `id`=' . $id
        );
    }

    private function menu(string $auth): array
    {
        return $this->getAdapter()->fetchRow(
            'SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' .
            $this->quote($auth) . ' AND `is_del`=0 LIMIT 1'
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
