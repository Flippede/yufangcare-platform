<?php

use think\migration\Migrator;

class FormalizeYfthUserAccountClosure extends Migrator
{
    private const PERMISSIONS = [
        [
            'old_auth' => 'yfth-user-debug-purge-preflight',
            'new_auth' => 'yfth-user-account-closure-preflight',
            'old_api' => 'yfth/user_role/user/<uid>/purge/preflight',
            'new_api' => 'yfth/user_role/user/<uid>/closure/preflight',
            'old_name' => '预检调试用户删除',
            'new_name' => '用户销户预检',
            'method' => 'GET',
        ],
        [
            'old_auth' => 'yfth-user-debug-purge-execute',
            'new_auth' => 'yfth-user-account-closure-execute',
            'old_api' => 'yfth/user_role/user/<uid>/purge',
            'new_api' => 'yfth/user_role/user/<uid>/closure',
            'old_name' => '执行调试用户删除',
            'new_name' => '总部代办用户销户',
            'method' => 'DELETE',
        ],
    ];

    public function up()
    {
        $this->rewrite(false);
    }

    public function down()
    {
        $this->rewrite(true);
    }

    private function rewrite(bool $reverse): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        foreach (self::PERMISSIONS as $permission) {
            $fromAuth = $reverse ? $permission['new_auth'] : $permission['old_auth'];
            $toAuth = $reverse ? $permission['old_auth'] : $permission['new_auth'];
            $toApi = $reverse ? $permission['old_api'] : $permission['new_api'];
            $toName = $reverse ? $permission['old_name'] : $permission['new_name'];
            $target = $this->getAdapter()->fetchAll(
                'SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($toAuth)
            );
            $source = $this->getAdapter()->fetchAll(
                'SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($fromAuth)
            );
            if (count($target) > 1 || count($source) > 1) {
                throw new RuntimeException('yfth_account_closure_permission_duplicate:' . $toAuth);
            }
            if ($target && $source) {
                $this->execute('DELETE FROM ' . $table . ' WHERE `id`=' . (int)$source[0]['id']);
                continue;
            }
            if ($target) {
                continue;
            }
            if (!$source) {
                throw new RuntimeException('yfth_account_closure_permission_source_missing:' . $fromAuth);
            }
            $this->execute(
                'UPDATE ' . $table . ' SET `unique_auth`=' . $this->quote($toAuth)
                . ',`api_url`=' . $this->quote($toApi)
                . ',`menu_name`=' . $this->quote($toName)
                . ',`methods`=' . $this->quote($permission['method'])
                . ' WHERE `id`=' . (int)$source[0]['id']
            );
        }
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote($value): string
    {
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
