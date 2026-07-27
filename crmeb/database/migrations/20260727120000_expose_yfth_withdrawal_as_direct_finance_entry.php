<?php

use think\migration\Migrator;

class ExposeYfthWithdrawalAsDirectFinanceEntry extends Migrator
{
    private const FINANCE_AUTH = 'admin-finance';
    private const GROUP_AUTH = 'yfth-finance';
    private const PAGE_AUTH = 'yfth-finance-withdrawal-index';

    public function up()
    {
        $finance = $this->menu(self::FINANCE_AUTH);
        $group = $this->menu(self::GROUP_AUTH);
        $page = $this->menu(self::PAGE_AUTH);
        if (!$finance || !$group || !$page) {
            throw new RuntimeException('yfth_finance_direct_entry_forward_repair_required');
        }

        $this->updateMenu((int)$group['id'], [
            'is_show' => 0,
            'is_show_path' => 0,
        ]);
        $this->updateMenu((int)$page['id'], [
            'pid' => (int)$finance['id'],
            'menu_name' => '御方通和提现审核',
            'sort' => 5,
            'menu_path' => '/yfth-finance/withdrawal',
            'path' => (string)$finance['id'],
            'header' => 'finance',
            'is_header' => 0,
            'is_show' => 1,
            'is_show_path' => 1,
        ]);

        $apiPath = (int)$finance['id'] . '/' . (int)$page['id'];
        $this->execute(
            'UPDATE `' . $this->prefixed('system_menus') . '` SET ' .
            '`header`=' . $this->quote('finance') . ',`path`=' . $this->quote($apiPath) .
            ' WHERE `pid`=' . (int)$page['id'] . ' AND `is_del`=0'
        );
    }

    public function down()
    {
        $finance = $this->menu(self::FINANCE_AUTH);
        $group = $this->menu(self::GROUP_AUTH);
        $page = $this->menu(self::PAGE_AUTH);
        if (!$finance || !$group || !$page) {
            return;
        }

        $this->updateMenu((int)$group['id'], [
            'is_show' => 1,
            'is_show_path' => 1,
        ]);
        $this->updateMenu((int)$page['id'], [
            'pid' => (int)$group['id'],
            'menu_name' => '提现审核',
            'path' => (int)$finance['id'] . '/' . (int)$group['id'],
        ]);
        $apiPath = (int)$finance['id'] . '/' . (int)$group['id'] . '/' . (int)$page['id'];
        $this->execute(
            'UPDATE `' . $this->prefixed('system_menus') . '` SET `path`=' . $this->quote($apiPath) .
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
