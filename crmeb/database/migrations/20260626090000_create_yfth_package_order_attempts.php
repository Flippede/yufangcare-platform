<?php

use think\migration\Migrator;

class CreateYfthPackageOrderAttempts extends Migrator
{
    private $menuKeys = [
        'yfth-package-orphan-scan',
    ];

    public function up()
    {
        $this->createAttemptTable();
        $this->seedMenus();
    }

    public function down()
    {
        $this->dropMenus();
        if ($this->hasTable('yfth_package_order_attempt')) {
            $this->table('yfth_package_order_attempt')->drop();
        }
    }

    private function createAttemptTable(): void
    {
        if ($this->hasTable('yfth_package_order_attempt')) {
            return;
        }
        $this->table('yfth_package_order_attempt')
            ->setEngine('InnoDB')
            ->setComment('YFTH package CRMEB order creation attempts')
            ->addColumn('attempt_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'attempt number'])
            ->addColumn('intent_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase intent id'])
            ->addColumn('intent_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'purchase intent number'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service store id'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'order creation request id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('product_attr_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('order_key', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB store_order.unique source token'])
            ->addColumn('source_token_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'source token sha256'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'creating', 'comment' => 'creating/order_created/bound/orphan_unpaid/orphan_paid_pending/orphan_closed/recovered/recovery_failed/failed'])
            ->addColumn('recovery_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'dry_run/closed/recovered/failed'])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB store_order.id'])
            ->addColumn('order_sn', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB store_order.order_id'])
            ->addColumn('order_paid', 'integer', ['signed' => false, 'limit' => 1, 'default' => 0, 'comment' => 'order paid flag'])
            ->addColumn('timeout_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'creating timeout time'])
            ->addColumn('recoverable_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'first recoverable time'])
            ->addColumn('last_error_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'last safe error code'])
            ->addColumn('last_error_message', 'string', ['limit' => 255, 'default' => '', 'comment' => 'last safe error message'])
            ->addColumn('recovery_error', 'string', ['limit' => 255, 'default' => '', 'comment' => 'recovery error'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at'])
            ->addIndex(['attempt_no'], ['unique' => true, 'name' => 'uniq_yfth_pkg_attempt_no'])
            ->addIndex(['order_key'], ['unique' => true, 'name' => 'uniq_yfth_pkg_attempt_order_key'])
            ->addIndex(['source_token_hash'], ['unique' => true, 'name' => 'uniq_yfth_pkg_attempt_src_hash'])
            ->addIndex(['intent_id', 'status', 'timeout_at'], ['name' => 'idx_yfth_pkg_attempt_intent_status'])
            ->addIndex(['status', 'recovery_status', 'timeout_at'], ['name' => 'idx_yfth_pkg_attempt_recovery'])
            ->addIndex(['order_id'], ['name' => 'idx_yfth_pkg_attempt_order_id'])
            ->addIndex(['order_sn'], ['name' => 'idx_yfth_pkg_attempt_order_sn'])
            ->addIndex(['request_id'], ['name' => 'idx_yfth_pkg_attempt_request'])
            ->create();
    }

    private function seedMenus(): void
    {
        $page = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = ' . $this->quote('yfth-package-benefit-index') . ' LIMIT 1'
        );
        if (!$page) {
            return;
        }
        $this->upsertMenu($this->apiRow((int)$page['id'], 'Package orphan scan', 'yfth/package_benefit/orphan/scan', 'POST', 'yfth-package-orphan-scan'));
    }

    private function dropMenus(): void
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pid,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.PackageBenefit',
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
