<?php

use think\migration\Migrator;

class CreateYfthMonthlyBenefitFulfillmentTables extends Migrator
{
    private $menuKeys = [
        'yfth-monthly-benefit-fulfillment-index',
        'yfth-monthly-benefit-fulfillment-read',
        'yfth-monthly-benefit-fulfillment-confirm',
        'yfth-monthly-benefit-fulfillment-reject',
        'yfth-monthly-benefit-fulfillment-prepare',
        'yfth-monthly-benefit-fulfillment-ship',
        'yfth-monthly-benefit-fulfillment-complete',
        'yfth-monthly-benefit-fulfillment-exception',
        'yfth-monthly-benefit-fulfillment-cancel',
    ];

    public function up()
    {
        $this->createFulfillment();
        $this->createFulfillmentEvent();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        foreach ([
            'yfth_benefit_fulfillment_event',
            'yfth_benefit_fulfillment',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createFulfillment(): void
    {
        if ($this->hasTable('yfth_benefit_fulfillment')) {
            return;
        }
        $this->table('yfth_benefit_fulfillment')
            ->setEngine('InnoDB')
            ->setComment('YFTH monthly product benefit claim and fulfillment order')
            ->addColumn('fulfillment_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'fulfillment number'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user uid'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package service store id'])
            ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('benefit_plan_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit plan id'])
            ->addColumn('benefit_period_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit period id'])
            ->addColumn('benefit_item_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit item id'])
            ->addColumn('benefit_template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit template id'])
            ->addColumn('month_no', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit month number'])
            ->addColumn('period_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'benefit period code'])
            ->addColumn('benefit_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'benefit code'])
            ->addColumn('benefit_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'benefit name'])
            ->addColumn('fulfillment_type', 'string', ['limit' => 32, 'default' => 'product', 'comment' => 'product only in V1'])
            ->addColumn('fulfillment_method', 'string', ['limit' => 32, 'default' => 'express_delivery', 'comment' => 'express_delivery/self_pickup'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending_confirm', 'comment' => 'fulfillment status'])
            ->addColumn('quantity_total', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '1.00', 'comment' => 'claim quantity'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id snapshot'])
            ->addColumn('sku_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique snapshot'])
            ->addColumn('product_snapshot', 'text', ['null' => true, 'comment' => 'sanitized product snapshot'])
            ->addColumn('benefit_snapshot', 'text', ['null' => true, 'comment' => 'sanitized benefit snapshot'])
            ->addColumn('recipient_name_masked', 'string', ['limit' => 64, 'default' => '', 'comment' => 'masked recipient'])
            ->addColumn('recipient_phone_masked', 'string', ['limit' => 32, 'default' => '', 'comment' => 'masked phone'])
            ->addColumn('address_snapshot', 'text', ['null' => true, 'comment' => 'sanitized address snapshot'])
            ->addColumn('pickup_store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'pickup store id'])
            ->addColumn('pickup_store_snapshot', 'text', ['null' => true, 'comment' => 'pickup store snapshot'])
            ->addColumn('delivery_company', 'string', ['limit' => 64, 'default' => '', 'comment' => 'delivery company'])
            ->addColumn('delivery_no_masked', 'string', ['limit' => 64, 'default' => '', 'comment' => 'masked delivery no'])
            ->addColumn('delivery_snapshot', 'text', ['null' => true, 'comment' => 'sanitized delivery snapshot'])
            ->addColumn('claim_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'claimed time'])
            ->addColumn('confirmed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'confirmed time'])
            ->addColumn('prepared_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'prepared time'])
            ->addColumn('shipped_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'shipped time'])
            ->addColumn('picked_up_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'picked up time'])
            ->addColumn('completed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'completed time'])
            ->addColumn('cancelled_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'cancelled time'])
            ->addColumn('exception_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'exception time'])
            ->addColumn('operator_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'last operator type'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'last operator uid/admin id'])
            ->addColumn('operator_role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'last operator role'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'last reason'])
            ->addColumn('idempotency_key', 'string', ['limit' => 191, 'default' => '', 'comment' => 'claim idempotency key'])
            ->addColumn('active_key', 'string', ['limit' => 128, 'null' => true, 'default' => null, 'comment' => 'benefit item active fulfillment uniqueness'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['fulfillment_no'], ['unique' => true, 'name' => 'uniq_yfth_benefit_fulfillment_no'])
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_benefit_fulfillment_idem'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_benefit_fulfillment_active'])
            ->addIndex(['benefit_item_id', 'status'], ['name' => 'idx_yfth_benefit_fulfillment_item'])
            ->addIndex(['uid', 'status', 'create_time'], ['name' => 'idx_yfth_benefit_fulfillment_uid'])
            ->addIndex(['store_id', 'status', 'create_time'], ['name' => 'idx_yfth_benefit_fulfillment_store'])
            ->addIndex(['pickup_store_id', 'status', 'create_time'], ['name' => 'idx_yfth_benefit_fulfillment_pickup'])
            ->addIndex(['package_instance_id', 'month_no'], ['name' => 'idx_yfth_benefit_fulfillment_instance'])
            ->create();
    }

    private function createFulfillmentEvent(): void
    {
        if ($this->hasTable('yfth_benefit_fulfillment_event')) {
            return;
        }
        $this->table('yfth_benefit_fulfillment_event')
            ->setEngine('InnoDB')
            ->setComment('YFTH monthly benefit fulfillment event timeline')
            ->addColumn('fulfillment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'fulfillment id'])
            ->addColumn('event_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'event type'])
            ->addColumn('from_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'from status'])
            ->addColumn('to_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'to status'])
            ->addColumn('operator_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'operator type'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator uid/admin id'])
            ->addColumn('operator_role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'operator role'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reason'])
            ->addColumn('before_state', 'text', ['null' => true, 'comment' => 'sanitized before state'])
            ->addColumn('after_state', 'text', ['null' => true, 'comment' => 'sanitized after state'])
            ->addColumn('idempotency_key', 'string', ['limit' => 191, 'default' => '', 'comment' => 'operation idempotency key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['fulfillment_id', 'create_time'], ['name' => 'idx_yfth_benefit_fulfillment_event_order'])
            ->addIndex(['event_type', 'create_time'], ['name' => 'idx_yfth_benefit_fulfillment_event_type'])
            ->addIndex(['idempotency_key'], ['name' => 'idx_yfth_benefit_fulfillment_event_idem'])
            ->create();
    }

    private function seedMenus(): void
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-gift',
            'menu_name' => '月度权益履约',
            'module' => 'admin',
            'controller' => 'v1.yfth.MonthlyBenefitFulfillment',
            'action' => 'index',
            'api_url' => 'yfth/monthly_benefit/fulfillment',
            'methods' => 'GET',
            'params' => '',
            'sort' => 9,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/monthly-benefit-fulfillment',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-monthly-benefit-fulfillment-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, '查看月度权益履约', 'yfth/monthly_benefit/fulfillment', 'GET', 'yfth-monthly-benefit-fulfillment-read'),
            $this->apiRow($pageId, '确认月度权益履约', 'yfth/monthly_benefit/fulfillment/<id>/confirm', 'POST', 'yfth-monthly-benefit-fulfillment-confirm'),
            $this->apiRow($pageId, '驳回月度权益履约', 'yfth/monthly_benefit/fulfillment/<id>/reject', 'POST', 'yfth-monthly-benefit-fulfillment-reject'),
            $this->apiRow($pageId, '月度权益备货', 'yfth/monthly_benefit/fulfillment/<id>/prepare', 'POST', 'yfth-monthly-benefit-fulfillment-prepare'),
            $this->apiRow($pageId, '月度权益发货', 'yfth/monthly_benefit/fulfillment/<id>/ship', 'POST', 'yfth-monthly-benefit-fulfillment-ship'),
            $this->apiRow($pageId, '完成月度权益履约', 'yfth/monthly_benefit/fulfillment/<id>/complete', 'POST', 'yfth-monthly-benefit-fulfillment-complete'),
            $this->apiRow($pageId, '标记月度权益异常', 'yfth/monthly_benefit/fulfillment/<id>/exception', 'POST', 'yfth-monthly-benefit-fulfillment-exception'),
            $this->apiRow($pageId, '取消月度权益履约', 'yfth/monthly_benefit/fulfillment/<id>/cancel', 'POST', 'yfth-monthly-benefit-fulfillment-cancel'),
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
            'controller' => 'v1.yfth.MonthlyBenefitFulfillment',
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
