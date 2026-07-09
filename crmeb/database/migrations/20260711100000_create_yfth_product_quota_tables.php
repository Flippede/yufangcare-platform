<?php

use think\migration\Migrator;

class CreateYfthProductQuotaTables extends Migrator
{
    private $menuKeys = [
        'yfth-product-quota-index',
        'yfth-product-quota-account-read',
        'yfth-product-quota-ledger-read',
        'yfth-product-quota-grant-read',
        'yfth-product-quota-grant-create',
        'yfth-product-quota-grant-confirm',
        'yfth-product-quota-grant-reject',
        'yfth-product-quota-grant-reverse',
        'yfth-product-quota-adjustment-create',
        'yfth-product-quota-account-freeze',
        'yfth-product-quota-account-unfreeze',
        'yfth-product-quota-account-close',
    ];

    public function up()
    {
        $this->createAccount();
        $this->createLedger();
        $this->createGrantOrder();
        $this->createAdjustment();
        $this->createSourceSnapshot();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        foreach ([
            'yfth_product_quota_source_snapshot',
            'yfth_product_quota_adjustment',
            'yfth_product_quota_grant_order',
            'yfth_product_quota_ledger',
            'yfth_product_quota_account',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createAccount(): void
    {
        if ($this->hasTable('yfth_product_quota_account')) {
            return;
        }
        $this->table('yfth_product_quota_account')
            ->setEngine('InnoDB')
            ->setComment('YFTH product return-goods quota account, not CRMEB cash balance')
            ->addColumn('account_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'account number'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('quota_type', 'string', ['limit' => 32, 'default' => 'return_goods', 'comment' => 'quota type'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/frozen/closed'])
            ->addColumn('total_granted_cent', 'biginteger', ['default' => 0, 'comment' => 'total confirmed grant cents'])
            ->addColumn('total_adjusted_cent', 'biginteger', ['default' => 0, 'comment' => 'net manual adjustment cents'])
            ->addColumn('total_reversed_cent', 'biginteger', ['default' => 0, 'comment' => 'total reversed grant cents'])
            ->addColumn('reserved_cent', 'biginteger', ['default' => 0, 'comment' => 'reserved cents, V1 remains zero'])
            ->addColumn('consumed_cent', 'biginteger', ['default' => 0, 'comment' => 'consumed cents, V1 remains zero'])
            ->addColumn('available_cent', 'biginteger', ['default' => 0, 'comment' => 'available product quota cents'])
            ->addColumn('frozen_cent', 'biginteger', ['default' => 0, 'comment' => 'frozen amount cents, V1 remains zero'])
            ->addColumn('version', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'optimistic version'])
            ->addColumn('active_key', 'string', ['limit' => 128, 'null' => true, 'default' => null, 'comment' => 'store:quota active/frozen uniqueness'])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => 'headquarters remark'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['account_no'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_account_no'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_account_active'])
            ->addIndex(['store_id', 'quota_type', 'status'], ['name' => 'idx_yfth_product_quota_account_store'])
            ->addIndex(['status'], ['name' => 'idx_yfth_product_quota_account_status'])
            ->create();
    }

    private function createLedger(): void
    {
        if ($this->hasTable('yfth_product_quota_ledger')) {
            return;
        }
        $this->table('yfth_product_quota_ledger')
            ->setEngine('InnoDB')
            ->setComment('YFTH immutable product quota ledger')
            ->addColumn('ledger_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'ledger number'])
            ->addColumn('account_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'quota account id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('quota_type', 'string', ['limit' => 32, 'default' => 'return_goods', 'comment' => 'quota type'])
            ->addColumn('direction', 'string', ['limit' => 16, 'default' => 'in', 'comment' => 'in/out'])
            ->addColumn('action_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'grant/manual/reverse action'])
            ->addColumn('amount_cent', 'biginteger', ['default' => 0, 'comment' => 'change cents'])
            ->addColumn('balance_before_cent', 'biginteger', ['default' => 0, 'comment' => 'available before'])
            ->addColumn('balance_after_cent', 'biginteger', ['default' => 0, 'comment' => 'available after'])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'source type'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'source id'])
            ->addColumn('idempotency_key', 'string', ['limit' => 160, 'default' => '', 'comment' => 'idempotency key'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'posted', 'comment' => 'posted/reversed/voided'])
            ->addColumn('operator_type', 'string', ['limit' => 32, 'default' => 'admin', 'comment' => 'operator type'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'operation reason'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['ledger_no'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_ledger_no'])
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_ledger_idempotency'])
            ->addIndex(['account_id', 'create_time'], ['name' => 'idx_yfth_product_quota_ledger_account'])
            ->addIndex(['store_id', 'quota_type', 'create_time'], ['name' => 'idx_yfth_product_quota_ledger_store'])
            ->addIndex(['source_type', 'source_id', 'action_type'], ['name' => 'idx_yfth_product_quota_ledger_source'])
            ->create();
    }

    private function createGrantOrder(): void
    {
        if ($this->hasTable('yfth_product_quota_grant_order')) {
            return;
        }
        $this->table('yfth_product_quota_grant_order')
            ->setEngine('InnoDB')
            ->setComment('YFTH headquarters manual product quota grant order')
            ->addColumn('grant_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'grant number'])
            ->addColumn('account_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'account id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('quota_type', 'string', ['limit' => 32, 'default' => 'return_goods', 'comment' => 'quota type'])
            ->addColumn('amount_cent', 'biginteger', ['default' => 0, 'comment' => 'grant cents'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'draft', 'comment' => 'draft/confirmed/rejected/reversed'])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => 'headquarters_manual_grant', 'comment' => 'trusted source type'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'source id'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reason'])
            ->addColumn('applicant_admin_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'creator admin id'])
            ->addColumn('confirm_admin_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'confirm admin id'])
            ->addColumn('reject_admin_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reject admin id'])
            ->addColumn('reverse_admin_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reverse admin id'])
            ->addColumn('confirmed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'confirmed at'])
            ->addColumn('rejected_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rejected at'])
            ->addColumn('reversed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reversed at'])
            ->addColumn('idempotency_key', 'string', ['limit' => 191, 'default' => '', 'comment' => 'mandatory request idempotency key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['grant_no'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_grant_no'])
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_grant_idempotency'])
            ->addIndex(['account_id', 'status'], ['name' => 'idx_yfth_product_quota_grant_account'])
            ->addIndex(['store_id', 'quota_type', 'status'], ['name' => 'idx_yfth_product_quota_grant_store'])
            ->addIndex(['source_type', 'source_id'], ['name' => 'idx_yfth_product_quota_grant_source'])
            ->create();
    }

    private function createAdjustment(): void
    {
        if ($this->hasTable('yfth_product_quota_adjustment')) {
            return;
        }
        $this->table('yfth_product_quota_adjustment')
            ->setEngine('InnoDB')
            ->setComment('YFTH product quota manual adjustment and state operation')
            ->addColumn('adjustment_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'adjustment number'])
            ->addColumn('account_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'account id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('action_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'manual_increase/manual_decrease/freeze/unfreeze/close'])
            ->addColumn('amount_cent', 'biginteger', ['default' => 0, 'comment' => 'adjustment cents'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'posted', 'comment' => 'posted/reversed'])
            ->addColumn('before_state', 'text', ['null' => true, 'comment' => 'sanitized before state'])
            ->addColumn('after_state', 'text', ['null' => true, 'comment' => 'sanitized after state'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'required reason'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('dedupe_key', 'string', ['limit' => 191, 'default' => '', 'comment' => 'mandatory dedupe key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['adjustment_no'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_adjust_no'])
            ->addIndex(['dedupe_key'], ['unique' => true, 'name' => 'uniq_yfth_product_quota_adjustment_dedupe'])
            ->addIndex(['account_id', 'create_time'], ['name' => 'idx_yfth_product_quota_adjust_account'])
            ->addIndex(['store_id', 'action_type'], ['name' => 'idx_yfth_product_quota_adjust_store'])
            ->create();
    }

    private function createSourceSnapshot(): void
    {
        if ($this->hasTable('yfth_product_quota_source_snapshot')) {
            return;
        }
        $this->table('yfth_product_quota_source_snapshot')
            ->setEngine('InnoDB')
            ->setComment('YFTH product quota sanitized source snapshot')
            ->addColumn('account_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'account id'])
            ->addColumn('ledger_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'ledger id'])
            ->addColumn('grant_order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'grant order id'])
            ->addColumn('adjustment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'adjustment id'])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'source type'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'source id'])
            ->addColumn('snapshot_json', 'text', ['null' => true, 'comment' => 'sanitized snapshot'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['account_id', 'create_time'], ['name' => 'idx_yfth_product_quota_snapshot_account'])
            ->addIndex(['ledger_id'], ['name' => 'idx_yfth_product_quota_snapshot_ledger'])
            ->addIndex(['grant_order_id'], ['name' => 'idx_yfth_product_quota_snapshot_grant'])
            ->addIndex(['adjustment_id'], ['name' => 'idx_yfth_product_quota_snapshot_adjust'])
            ->addIndex(['source_type', 'source_id'], ['name' => 'idx_yfth_product_quota_snapshot_source'])
            ->create();
    }

    private function seedMenus(): void
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-card',
            'menu_name' => '产品额度 / 返货额度',
            'module' => 'admin',
            'controller' => 'v1.yfth.ProductQuota',
            'action' => 'index',
            'api_url' => 'yfth/product_quota/account',
            'methods' => 'GET',
            'params' => '',
            'sort' => 8,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/product-quota',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-product-quota-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, 'Product quota account read', 'yfth/product_quota/account', 'GET', 'yfth-product-quota-account-read'),
            $this->apiRow($pageId, 'Product quota ledger read', 'yfth/product_quota/ledger', 'GET', 'yfth-product-quota-ledger-read'),
            $this->apiRow($pageId, 'Product quota grant read', 'yfth/product_quota/grant', 'GET', 'yfth-product-quota-grant-read'),
            $this->apiRow($pageId, 'Product quota grant create', 'yfth/product_quota/grant', 'POST', 'yfth-product-quota-grant-create'),
            $this->apiRow($pageId, 'Product quota grant confirm', 'yfth/product_quota/grant/<id>/confirm', 'POST', 'yfth-product-quota-grant-confirm'),
            $this->apiRow($pageId, 'Product quota grant reject', 'yfth/product_quota/grant/<id>/reject', 'POST', 'yfth-product-quota-grant-reject'),
            $this->apiRow($pageId, 'Product quota grant reverse', 'yfth/product_quota/grant/<id>/reverse', 'POST', 'yfth-product-quota-grant-reverse'),
            $this->apiRow($pageId, 'Product quota adjustment create', 'yfth/product_quota/adjustment', 'POST', 'yfth-product-quota-adjustment-create'),
            $this->apiRow($pageId, 'Product quota account freeze', 'yfth/product_quota/account/<id>/freeze', 'POST', 'yfth-product-quota-account-freeze'),
            $this->apiRow($pageId, 'Product quota account unfreeze', 'yfth/product_quota/account/<id>/unfreeze', 'POST', 'yfth-product-quota-account-unfreeze'),
            $this->apiRow($pageId, 'Product quota account close', 'yfth/product_quota/account/<id>/close', 'POST', 'yfth-product-quota-account-close'),
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
            'controller' => 'v1.yfth.ProductQuota',
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
        if ($value === null) {
            return 'NULL';
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
