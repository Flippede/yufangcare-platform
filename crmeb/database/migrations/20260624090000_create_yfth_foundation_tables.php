<?php

use think\migration\Migrator;

class CreateYfthFoundationTables extends Migrator
{
    public function change()
    {
        $this->createUserIdentityTable();
        $this->createUserStoreRoleTable();
        $this->createBusinessSubjectTable();
        $this->createStoreSubjectTable();
        $this->createStoreQualificationTable();
        $this->createStoreCapabilityTable();
        $this->createStorePaymentRouteTable();
        $this->createAuditEventTable();
        $this->createIdempotencyRecordTable();
    }

    private function baseTable(string $name, string $comment)
    {
        return $this->table($name)
            ->setEngine('InnoDB')
            ->setComment($comment)
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '创建时间'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '更新时间']);
    }

    private function createUserIdentityTable(): void
    {
        $this->baseTable('yfth_user_identity', '御方通和用户身份')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => '用户ID'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => '身份编码'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => '状态'])
            ->addColumn('source_type', 'string', ['limit' => 32, 'default' => 'manual', 'comment' => '来源类型'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '来源ID'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '生效时间'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '失效时间'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => '启用态唯一键'])
            ->addIndex(['uid', 'role_code'], ['name' => 'idx_yfth_user_identity_uid_role'])
            ->addIndex(['status'], ['name' => 'idx_yfth_user_identity_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_user_identity_active'])
            ->create();
    }

    private function createUserStoreRoleTable(): void
    {
        $this->baseTable('yfth_user_store_role', '御方通和用户门店角色')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => '用户ID'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '门店ID'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => '门店角色'])
            ->addColumn('permission_scope', 'text', ['null' => true, 'comment' => '权限范围JSON'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => '状态'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '开始时间'])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '结束时间'])
            ->addColumn('inviter_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => '邀请人UID'])
            ->addColumn('creator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => '创建人UID'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => '启用态唯一键'])
            ->addIndex(['uid', 'role_code'], ['name' => 'idx_yfth_store_role_uid_role'])
            ->addIndex(['store_id', 'role_code'], ['name' => 'idx_yfth_store_role_store_role'])
            ->addIndex(['status'], ['name' => 'idx_yfth_store_role_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_store_role_active'])
            ->create();
    }

    private function createBusinessSubjectTable(): void
    {
        $this->baseTable('yfth_business_subject', '御方通和经营主体')
            ->addColumn('subject_type', 'string', ['limit' => 32, 'default' => '', 'comment' => '主体类型'])
            ->addColumn('subject_name', 'string', ['limit' => 128, 'default' => '', 'comment' => '主体名称'])
            ->addColumn('credit_code', 'string', ['limit' => 64, 'default' => '', 'comment' => '统一社会信用代码'])
            ->addColumn('legal_person', 'string', ['limit' => 64, 'default' => '', 'comment' => '法定代表人'])
            ->addColumn('contact_name', 'string', ['limit' => 64, 'default' => '', 'comment' => '联系人'])
            ->addColumn('contact_phone', 'string', ['limit' => 64, 'default' => '', 'comment' => '联系电话'])
            ->addColumn('registered_address', 'string', ['limit' => 255, 'default' => '', 'comment' => '注册地址'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => '状态'])
            ->addIndex(['subject_type', 'status'], ['name' => 'idx_yfth_subject_type_status'])
            ->addIndex(['credit_code'], ['unique' => true, 'name' => 'uniq_yfth_subject_credit_code'])
            ->create();
    }

    private function createStoreSubjectTable(): void
    {
        $this->baseTable('yfth_store_subject', '御方通和门店主体关系')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '门店ID'])
            ->addColumn('subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '经营主体ID'])
            ->addColumn('store_type', 'string', ['limit' => 32, 'default' => '', 'comment' => '门店类型'])
            ->addColumn('subject_role', 'string', ['limit' => 32, 'default' => '', 'comment' => '主体角色'])
            ->addColumn('is_sales_subject', 'boolean', ['default' => 0, 'comment' => '是否销售收款主体'])
            ->addColumn('is_service_subject', 'boolean', ['default' => 0, 'comment' => '是否服务履约主体'])
            ->addColumn('is_invoice_subject', 'boolean', ['default' => 0, 'comment' => '是否开票主体'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => '状态'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '生效时间'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '失效时间'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => '启用态唯一键'])
            ->addIndex(['store_id', 'subject_role'], ['name' => 'idx_yfth_store_subject_store_role'])
            ->addIndex(['subject_id'], ['name' => 'idx_yfth_store_subject_subject'])
            ->addIndex(['status'], ['name' => 'idx_yfth_store_subject_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_store_subject_active'])
            ->create();
    }

    private function createStoreQualificationTable(): void
    {
        $this->baseTable('yfth_store_qualification', '御方通和门店资质')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '门店ID'])
            ->addColumn('subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '经营主体ID'])
            ->addColumn('qualification_type', 'string', ['limit' => 48, 'default' => '', 'comment' => '资质类型'])
            ->addColumn('certificate_no', 'string', ['limit' => 96, 'default' => '', 'comment' => '证照编号'])
            ->addColumn('attachment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '附件ID'])
            ->addColumn('scope', 'text', ['null' => true, 'comment' => '适用范围JSON'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '开始时间'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '过期时间'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending', 'comment' => '状态'])
            ->addColumn('audit_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => '审核人UID'])
            ->addColumn('audit_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '审核时间'])
            ->addColumn('reject_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => '驳回或暂停原因'])
            ->addIndex(['store_id', 'qualification_type'], ['name' => 'idx_yfth_qual_store_type'])
            ->addIndex(['subject_id'], ['name' => 'idx_yfth_qual_subject'])
            ->addIndex(['status', 'expire_time'], ['name' => 'idx_yfth_qual_status_expire'])
            ->create();
    }

    private function createStoreCapabilityTable(): void
    {
        $this->baseTable('yfth_store_capability', '御方通和门店能力')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '门店ID'])
            ->addColumn('capability_code', 'string', ['limit' => 48, 'default' => '', 'comment' => '能力编码'])
            ->addColumn('source_qualification_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '来源资质ID'])
            ->addColumn('source_authorization', 'string', ['limit' => 96, 'default' => '', 'comment' => '来源授权'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => '状态'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '生效时间'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '失效时间'])
            ->addColumn('close_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => '关闭原因'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => '启用态唯一键'])
            ->addIndex(['store_id', 'capability_code'], ['name' => 'idx_yfth_cap_store_code'])
            ->addIndex(['status', 'expire_time'], ['name' => 'idx_yfth_cap_status_expire'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_cap_active'])
            ->create();
    }

    private function createStorePaymentRouteTable(): void
    {
        $this->baseTable('yfth_store_payment_route', '御方通和门店收款路由')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '门店ID'])
            ->addColumn('subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '收款主体ID'])
            ->addColumn('business_scene', 'string', ['limit' => 48, 'default' => '', 'comment' => '业务场景'])
            ->addColumn('route_type', 'string', ['limit' => 48, 'default' => '', 'comment' => '路由类型'])
            ->addColumn('merchant_ref', 'string', ['limit' => 96, 'default' => '', 'comment' => '商户引用'])
            ->addColumn('sub_merchant_ref', 'string', ['limit' => 96, 'default' => '', 'comment' => '子商户引用'])
            ->addColumn('receiver_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '收款主体'])
            ->addColumn('invoice_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '开票主体'])
            ->addColumn('refund_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '退款主体'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => '状态'])
            ->addColumn('config_status', 'string', ['limit' => 24, 'default' => 'metadata_only', 'comment' => '配置状态'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '生效时间'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '失效时间'])
            ->addIndex(['store_id', 'business_scene', 'status'], ['name' => 'idx_yfth_pay_route_store_scene'])
            ->addIndex(['subject_id'], ['name' => 'idx_yfth_pay_route_subject'])
            ->create();
    }

    private function createAuditEventTable(): void
    {
        $this->baseTable('yfth_audit_event', '御方通和审计事件')
            ->addColumn('business_domain', 'string', ['limit' => 48, 'default' => '', 'comment' => '业务域'])
            ->addColumn('object_type', 'string', ['limit' => 48, 'default' => '', 'comment' => '对象类型'])
            ->addColumn('object_id', 'string', ['limit' => 64, 'default' => '', 'comment' => '对象ID'])
            ->addColumn('action', 'string', ['limit' => 64, 'default' => '', 'comment' => '动作'])
            ->addColumn('before_state', 'text', ['null' => true, 'comment' => '变更前JSON'])
            ->addColumn('after_state', 'text', ['null' => true, 'comment' => '变更后JSON'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => '操作人UID'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => '操作身份'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => '门店ID'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => '请求ID'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => '原因'])
            ->addColumn('ip', 'string', ['limit' => 64, 'default' => '', 'comment' => '操作IP'])
            ->addIndex(['business_domain', 'object_type', 'object_id'], ['name' => 'idx_yfth_audit_object'])
            ->addIndex(['operator_uid'], ['name' => 'idx_yfth_audit_operator'])
            ->addIndex(['store_id'], ['name' => 'idx_yfth_audit_store'])
            ->addIndex(['request_id'], ['name' => 'idx_yfth_audit_request'])
            ->create();
    }

    private function createIdempotencyRecordTable(): void
    {
        $this->baseTable('yfth_idempotency_record', '御方通和幂等记录')
            ->addColumn('business_domain', 'string', ['limit' => 48, 'default' => '', 'comment' => '业务域'])
            ->addColumn('action_type', 'string', ['limit' => 64, 'default' => '', 'comment' => '动作类型'])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'default' => '', 'comment' => '幂等键'])
            ->addColumn('object_id', 'string', ['limit' => 64, 'default' => '', 'comment' => '对象ID'])
            ->addColumn('request_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => '请求哈希'])
            ->addColumn('process_status', 'string', ['limit' => 24, 'default' => 'processing', 'comment' => '处理状态'])
            ->addColumn('result_summary', 'text', ['null' => true, 'comment' => '结果摘要JSON'])
            ->addColumn('fail_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => '失败原因'])
            ->addColumn('finish_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '完成时间'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => '失效时间'])
            ->addIndex(['business_domain', 'action_type', 'idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_idem_key'])
            ->addIndex(['process_status', 'expire_time'], ['name' => 'idx_yfth_idem_status_expire'])
            ->addIndex(['object_id'], ['name' => 'idx_yfth_idem_object'])
            ->create();
    }
}
