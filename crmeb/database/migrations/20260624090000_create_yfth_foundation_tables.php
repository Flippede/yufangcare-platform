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
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at']);
    }

    private function createUserIdentityTable(): void
    {
        $this->baseTable('yfth_user_identity', 'YFTH user identities')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'role code'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addColumn('source_type', 'string', ['limit' => 32, 'default' => 'manual', 'comment' => 'source type'])
            ->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'source id'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'active unique key'])
            ->addIndex(['uid', 'role_code'], ['name' => 'idx_yfth_user_identity_uid_role'])
            ->addIndex(['status'], ['name' => 'idx_yfth_user_identity_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_user_identity_active'])
            ->create();
    }

    private function createUserStoreRoleTable(): void
    {
        $this->baseTable('yfth_user_store_role', 'YFTH user store roles')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'store role code'])
            ->addColumn('permission_scope', 'text', ['null' => true, 'comment' => 'permission scope json'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'start time'])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'end time'])
            ->addColumn('inviter_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'inviter uid'])
            ->addColumn('creator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'creator uid'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'active unique key'])
            ->addIndex(['uid', 'role_code'], ['name' => 'idx_yfth_store_role_uid_role'])
            ->addIndex(['store_id', 'role_code'], ['name' => 'idx_yfth_store_role_store_role'])
            ->addIndex(['status'], ['name' => 'idx_yfth_store_role_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_store_role_active'])
            ->create();
    }

    private function createBusinessSubjectTable(): void
    {
        $this->baseTable('yfth_business_subject', 'YFTH business subjects')
            ->addColumn('subject_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'subject type'])
            ->addColumn('subject_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'subject name'])
            ->addColumn('credit_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'unified credit code'])
            ->addColumn('legal_person', 'string', ['limit' => 64, 'default' => '', 'comment' => 'legal person'])
            ->addColumn('contact_name', 'string', ['limit' => 64, 'default' => '', 'comment' => 'contact name'])
            ->addColumn('contact_phone', 'string', ['limit' => 64, 'default' => '', 'comment' => 'contact phone'])
            ->addColumn('registered_address', 'string', ['limit' => 255, 'default' => '', 'comment' => 'registered address'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addIndex(['subject_type', 'status'], ['name' => 'idx_yfth_subject_type_status'])
            ->addIndex(['credit_code'], ['unique' => true, 'name' => 'uniq_yfth_subject_credit_code'])
            ->create();
    }

    private function createStoreSubjectTable(): void
    {
        $this->baseTable('yfth_store_subject', 'YFTH store subject relations')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'business subject id'])
            ->addColumn('store_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'store type'])
            ->addColumn('subject_role', 'string', ['limit' => 32, 'default' => '', 'comment' => 'subject role'])
            ->addColumn('is_sales_subject', 'boolean', ['default' => 0, 'comment' => 'sales subject flag'])
            ->addColumn('is_service_subject', 'boolean', ['default' => 0, 'comment' => 'legacy service subject flag'])
            ->addColumn('is_payment_subject', 'boolean', ['default' => 0, 'comment' => 'payment subject flag'])
            ->addColumn('is_fulfillment_subject', 'boolean', ['default' => 0, 'comment' => 'fulfillment subject flag'])
            ->addColumn('is_invoice_subject', 'boolean', ['default' => 0, 'comment' => 'invoice subject flag'])
            ->addColumn('is_refund_subject', 'boolean', ['default' => 0, 'comment' => 'refund subject flag'])
            ->addColumn('is_host_subject', 'boolean', ['default' => 0, 'comment' => 'host subject flag'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'store_id:subject_role for active rows'])
            ->addIndex(['store_id', 'subject_role'], ['name' => 'idx_yfth_store_subject_store_role'])
            ->addIndex(['subject_id'], ['name' => 'idx_yfth_store_subject_subject'])
            ->addIndex(['status'], ['name' => 'idx_yfth_store_subject_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_store_subject_active'])
            ->create();
    }

    private function createStoreQualificationTable(): void
    {
        $this->baseTable('yfth_store_qualification', 'YFTH store qualifications')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'business subject id'])
            ->addColumn('qualification_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'qualification type'])
            ->addColumn('certificate_no', 'string', ['limit' => 96, 'default' => '', 'comment' => 'certificate number'])
            ->addColumn('attachment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'attachment id'])
            ->addColumn('scope', 'text', ['null' => true, 'comment' => 'scope json'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'start time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending', 'comment' => 'status'])
            ->addColumn('audit_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'audit uid'])
            ->addColumn('audit_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'audit time'])
            ->addColumn('reject_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reject reason'])
            ->addIndex(['store_id', 'qualification_type'], ['name' => 'idx_yfth_qual_store_type'])
            ->addIndex(['subject_id'], ['name' => 'idx_yfth_qual_subject'])
            ->addIndex(['status', 'expire_time'], ['name' => 'idx_yfth_qual_status_expire'])
            ->create();
    }

    private function createStoreCapabilityTable(): void
    {
        $this->baseTable('yfth_store_capability', 'YFTH store capabilities')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('capability_code', 'string', ['limit' => 48, 'default' => '', 'comment' => 'capability code'])
            ->addColumn('source_qualification_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'source qualification id'])
            ->addColumn('source_authorization', 'string', ['limit' => 96, 'default' => '', 'comment' => 'source authorization'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('close_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'close reason'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'active unique key'])
            ->addIndex(['store_id', 'capability_code'], ['name' => 'idx_yfth_cap_store_code'])
            ->addIndex(['status', 'expire_time'], ['name' => 'idx_yfth_cap_status_expire'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_cap_active'])
            ->create();
    }

    private function createStorePaymentRouteTable(): void
    {
        $this->baseTable('yfth_store_payment_route', 'YFTH store payment routes')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'payment subject id'])
            ->addColumn('business_scene', 'string', ['limit' => 48, 'default' => '', 'comment' => 'business scene'])
            ->addColumn('route_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'route type'])
            ->addColumn('merchant_ref', 'string', ['limit' => 96, 'default' => '', 'comment' => 'merchant reference'])
            ->addColumn('sub_merchant_ref', 'string', ['limit' => 96, 'default' => '', 'comment' => 'sub merchant reference'])
            ->addColumn('receiver_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'receiver subject id'])
            ->addColumn('invoice_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'invoice subject id'])
            ->addColumn('refund_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'refund subject id'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addColumn('config_status', 'string', ['limit' => 24, 'default' => 'metadata_only', 'comment' => 'config status'])
            ->addColumn('version_no', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'route version'])
            ->addColumn('priority', 'integer', ['signed' => true, 'default' => 0, 'comment' => 'route priority'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'store_id:business_scene for active rows'])
            ->addIndex(['store_id', 'business_scene', 'status'], ['name' => 'idx_yfth_pay_route_store_scene'])
            ->addIndex(['subject_id'], ['name' => 'idx_yfth_pay_route_subject'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_pay_route_active'])
            ->create();
    }

    private function createAuditEventTable(): void
    {
        $this->baseTable('yfth_audit_event', 'YFTH audit events')
            ->addColumn('business_domain', 'string', ['limit' => 48, 'default' => '', 'comment' => 'business domain'])
            ->addColumn('object_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'object type'])
            ->addColumn('object_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'object id'])
            ->addColumn('action', 'string', ['limit' => 64, 'default' => '', 'comment' => 'action'])
            ->addColumn('before_state', 'text', ['null' => true, 'comment' => 'before json'])
            ->addColumn('after_state', 'text', ['null' => true, 'comment' => 'after json'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator uid'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'operator role'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'request id'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reason'])
            ->addColumn('ip', 'string', ['limit' => 64, 'default' => '', 'comment' => 'ip'])
            ->addIndex(['business_domain', 'object_type', 'object_id'], ['name' => 'idx_yfth_audit_object'])
            ->addIndex(['operator_uid'], ['name' => 'idx_yfth_audit_operator'])
            ->addIndex(['store_id'], ['name' => 'idx_yfth_audit_store'])
            ->addIndex(['request_id'], ['name' => 'idx_yfth_audit_request'])
            ->create();
    }

    private function createIdempotencyRecordTable(): void
    {
        $this->baseTable('yfth_idempotency_record', 'YFTH idempotency records')
            ->addColumn('business_domain', 'string', ['limit' => 48, 'default' => '', 'comment' => 'business domain'])
            ->addColumn('action_type', 'string', ['limit' => 64, 'default' => '', 'comment' => 'action type'])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'default' => '', 'comment' => 'idempotency key'])
            ->addColumn('object_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'object id'])
            ->addColumn('request_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'request hash'])
            ->addColumn('process_status', 'string', ['limit' => 24, 'default' => 'processing', 'comment' => 'process status'])
            ->addColumn('result_summary', 'text', ['null' => true, 'comment' => 'result summary json'])
            ->addColumn('fail_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'fail reason'])
            ->addColumn('finish_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'finish time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addIndex(['business_domain', 'action_type', 'idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_idem_key'])
            ->addIndex(['process_status', 'expire_time'], ['name' => 'idx_yfth_idem_status_expire'])
            ->addIndex(['object_id'], ['name' => 'idx_yfth_idem_object'])
            ->create();
    }
}
