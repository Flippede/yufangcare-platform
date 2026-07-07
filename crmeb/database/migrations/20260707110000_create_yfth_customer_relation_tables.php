<?php

use think\migration\Migrator;

class CreateYfthCustomerRelationTables extends Migrator
{
    public function change()
    {
        $this->createCustomerRelationTable();
        $this->createCustomerFollowRecordTable();
    }

    private function createCustomerRelationTable(): void
    {
        $this->table('yfth_customer_relation')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise customer operating relations')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operating store id'])
            ->addColumn('owner_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'first binding operator uid'])
            ->addColumn('source', 'string', ['limit' => 48, 'default' => 'store_visit', 'comment' => 'customer source'])
            ->addColumn('customer_status', 'string', ['limit' => 32, 'default' => 'potential', 'comment' => 'potential/leads/registered/purchased/serving/repeat/lost'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/inactive'])
            ->addColumn('bind_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'binding time'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'unique active customer key'])
            ->addIndex(['uid'], ['name' => 'idx_yfth_customer_relation_uid'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_customer_relation_store_status'])
            ->addIndex(['owner_uid'], ['name' => 'idx_yfth_customer_relation_owner'])
            ->addIndex(['source'], ['name' => 'idx_yfth_customer_relation_source'])
            ->addIndex(['customer_status'], ['name' => 'idx_yfth_customer_relation_customer_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_customer_relation_active'])
            ->create();
    }

    private function createCustomerFollowRecordTable(): void
    {
        $this->table('yfth_customer_follow_record')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise customer follow records')
            ->addColumn('customer_relation_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'customer relation id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operating store id'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator uid'])
            ->addColumn('follow_type', 'string', ['limit' => 32, 'default' => 'other', 'comment' => 'phone/wechat/store_visit/other'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => 'follow content'])
            ->addColumn('next_follow_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'next follow time'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['customer_relation_id', 'create_time'], ['name' => 'idx_yfth_follow_relation_time'])
            ->addIndex(['uid'], ['name' => 'idx_yfth_follow_uid'])
            ->addIndex(['store_id', 'create_time'], ['name' => 'idx_yfth_follow_store_time'])
            ->addIndex(['operator_uid'], ['name' => 'idx_yfth_follow_operator'])
            ->addIndex(['follow_type'], ['name' => 'idx_yfth_follow_type'])
            ->create();
    }
}
