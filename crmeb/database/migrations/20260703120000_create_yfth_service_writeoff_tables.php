<?php

use think\migration\Migrator;

class CreateYfthServiceWriteoffTables extends Migrator
{
    public function change()
    {
        $this->extendAppointmentTable();
        $this->extendBenefitLockTable();
        $this->createDynamicCodeTable();
        $this->createWriteoffRecordTable();
    }

    private function extendAppointmentTable(): void
    {
        $table = $this->table('yfth_service_appointment');
        if (!$table->hasColumn('check_in_at')) {
            $table
                ->addColumn('check_in_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'check-in time'])
                ->addColumn('writeoff_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'writeoff time'])
                ->addColumn('completed_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'completed time'])
                ->addColumn('writeoff_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'writeoff record id'])
                ->addColumn('writeoff_store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'writeoff store id'])
                ->addColumn('writeoff_operator_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'writeoff operator admin id'])
                ->addColumn('writeoff_operator_type', 'string', ['limit' => 24, 'default' => '', 'comment' => 'operator type'])
                ->addColumn('writeoff_method', 'string', ['limit' => 32, 'default' => '', 'comment' => 'qr_code/digital_code/headquarter_exception'])
                ->addIndex(['writeoff_id'], ['name' => 'idx_yfth_svc_appt_writeoff'])
                ->addIndex(['store_id', 'writeoff_at'], ['name' => 'idx_yfth_svc_appt_store_writeoff'])
                ->update();
        }
    }

    private function extendBenefitLockTable(): void
    {
        $table = $this->table('yfth_service_benefit_lock');
        if (!$table->hasColumn('writeoff_id')) {
            $table
                ->addColumn('writeoff_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'writeoff record id'])
                ->addColumn('consumed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'consumed time'])
                ->addColumn('consume_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'consume reason'])
                ->addIndex(['writeoff_id'], ['name' => 'idx_yfth_svc_benefit_writeoff'])
                ->update();
        }
    }

    private function createDynamicCodeTable(): void
    {
        $this->table('yfth_service_dynamic_code')
            ->setEngine('InnoDB')
            ->setComment('YFTH service appointment dynamic writeoff codes')
            ->addColumn('appointment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'appointment id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'sha256 qr token hash'])
            ->addColumn('digital_code_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'sha256 digital code hash'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'issued', 'comment' => 'issued/used/invalidated/expired'])
            ->addColumn('issued_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'issued time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('used_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'used time'])
            ->addColumn('invalidated_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'invalidated time'])
            ->addColumn('attempt_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'digital attempts'])
            ->addColumn('max_attempts', 'integer', ['signed' => false, 'default' => 5, 'comment' => 'max attempts'])
            ->addColumn('used_admin_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'used admin id'])
            ->addColumn('used_role_code', 'string', ['limit' => 48, 'default' => '', 'comment' => 'used role code'])
            ->addColumn('used_writeoff_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'writeoff record id'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'request id'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'one active code per appointment'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_svc_code_active'])
            ->addIndex(['token_hash'], ['name' => 'idx_yfth_svc_code_token'])
            ->addIndex(['digital_code_hash', 'status'], ['name' => 'idx_yfth_svc_code_digital'])
            ->addIndex(['appointment_id', 'status'], ['name' => 'idx_yfth_svc_code_appt_status'])
            ->create();
    }

    private function createWriteoffRecordTable(): void
    {
        $this->table('yfth_service_writeoff_record')
            ->setEngine('InnoDB')
            ->setComment('YFTH service appointment writeoff records')
            ->addColumn('writeoff_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'writeoff number'])
            ->addColumn('appointment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'appointment id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('service_project_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service project id'])
            ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('benefit_plan_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit plan id'])
            ->addColumn('benefit_period_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit period id'])
            ->addColumn('benefit_item_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit item id'])
            ->addColumn('benefit_lock_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit lock id'])
            ->addColumn('dynamic_code_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'dynamic code id'])
            ->addColumn('writeoff_method', 'string', ['limit' => 32, 'default' => '', 'comment' => 'qr_code/digital_code/headquarter_exception'])
            ->addColumn('operator_type', 'string', ['limit' => 24, 'default' => 'admin', 'comment' => 'operator type'])
            ->addColumn('operator_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('operator_role_code', 'string', ['limit' => 48, 'default' => '', 'comment' => 'operator role code'])
            ->addColumn('before_appointment_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'before appointment status'])
            ->addColumn('after_appointment_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'after appointment status'])
            ->addColumn('before_benefit_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'before benefit lock status'])
            ->addColumn('after_benefit_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'after benefit lock status'])
            ->addColumn('writeoff_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'writeoff time'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'succeeded', 'comment' => 'succeeded/failed'])
            ->addColumn('idempotency_key', 'string', ['limit' => 191, 'default' => '', 'comment' => 'idempotency key'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'request id'])
            ->addColumn('snapshot', 'text', ['null' => true, 'comment' => 'writeoff snapshot json'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'one successful writeoff per appointment'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at'])
            ->addIndex(['writeoff_no'], ['unique' => true, 'name' => 'uniq_yfth_svc_writeoff_no'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_svc_writeoff_active'])
            ->addIndex(['appointment_id'], ['name' => 'idx_yfth_svc_writeoff_appt'])
            ->addIndex(['store_id', 'writeoff_time'], ['name' => 'idx_yfth_svc_writeoff_store_time'])
            ->addIndex(['operator_id', 'writeoff_time'], ['name' => 'idx_yfth_svc_writeoff_operator'])
            ->create();
    }
}
