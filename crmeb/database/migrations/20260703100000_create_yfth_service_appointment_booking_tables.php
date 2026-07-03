<?php

use think\migration\Migrator;

class CreateYfthServiceAppointmentBookingTables extends Migrator
{
    public function change()
    {
        $this->createAppointmentTable();
        $this->createSlotTable();
        $this->createBenefitLockTable();
        $this->createEventTable();
    }

    private function baseTable(string $name, string $comment)
    {
        return $this->table($name)
            ->setEngine('InnoDB')
            ->setComment($comment)
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at']);
    }

    private function createAppointmentTable(): void
    {
        $this->baseTable('yfth_service_appointment', 'YFTH service appointments')
            ->addColumn('appointment_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'appointment number'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'system_store.id'])
            ->addColumn('store_service_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store service id'])
            ->addColumn('service_project_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service project id'])
            ->addColumn('slot_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'capacity slot id'])
            ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('benefit_plan_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit plan id'])
            ->addColumn('benefit_period_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit period id'])
            ->addColumn('benefit_item_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit item id'])
            ->addColumn('service_date', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'YYYYMMDD'])
            ->addColumn('start_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'minutes from day start'])
            ->addColumn('end_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'minutes from day start'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'slot start timestamp'])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'slot end timestamp'])
            ->addColumn('duration_minutes', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'duration minutes'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending_confirm', 'comment' => 'appointment status'])
            ->addColumn('confirm_mode', 'string', ['limit' => 32, 'default' => 'manual', 'comment' => 'manual/auto'])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => 'package_5980_benefit', 'comment' => 'source type'])
            ->addColumn('user_note', 'string', ['limit' => 255, 'default' => '', 'comment' => 'user note'])
            ->addColumn('cancel_source', 'string', ['limit' => 32, 'default' => '', 'comment' => 'user/admin/system'])
            ->addColumn('cancel_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'cancel reason'])
            ->addColumn('cancel_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'cancel time'])
            ->addColumn('cancel_operator_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'cancel operator'])
            ->addColumn('reject_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reject reason'])
            ->addColumn('reject_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reject time'])
            ->addColumn('reject_operator_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reject operator'])
            ->addColumn('confirm_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'confirm time'])
            ->addColumn('confirm_operator_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'confirm operator'])
            ->addColumn('reschedule_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'reschedule count'])
            ->addColumn('idempotency_key', 'string', ['limit' => 191, 'default' => '', 'comment' => 'create idempotency key'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'last request id'])
            ->addColumn('store_snapshot', 'text', ['null' => true, 'comment' => 'store snapshot json'])
            ->addColumn('service_snapshot', 'text', ['null' => true, 'comment' => 'service snapshot json'])
            ->addColumn('benefit_snapshot', 'text', ['null' => true, 'comment' => 'benefit snapshot json'])
            ->addIndex(['appointment_no'], ['unique' => true, 'name' => 'uniq_yfth_svc_appt_no'])
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_yfth_svc_appt_idem'])
            ->addIndex(['uid', 'status', 'service_date'], ['name' => 'idx_yfth_svc_appt_uid_status_date'])
            ->addIndex(['store_id', 'status', 'service_date'], ['name' => 'idx_yfth_svc_appt_store_status_date'])
            ->addIndex(['store_service_id', 'service_date', 'start_minute'], ['name' => 'idx_yfth_svc_appt_slot_lookup'])
            ->addIndex(['benefit_item_id', 'status'], ['name' => 'idx_yfth_svc_appt_benefit_status'])
            ->create();
    }

    private function createSlotTable(): void
    {
        $this->baseTable('yfth_service_appointment_slot', 'YFTH service appointment capacity slots')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'system_store.id'])
            ->addColumn('store_service_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store service id'])
            ->addColumn('service_project_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service project id'])
            ->addColumn('service_date', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'YYYYMMDD'])
            ->addColumn('start_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'minutes from day start'])
            ->addColumn('end_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'minutes from day start'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'start timestamp'])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'end timestamp'])
            ->addColumn('capacity', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'capacity snapshot'])
            ->addColumn('locked_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'pending locks'])
            ->addColumn('occupied_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'confirmed occupied'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'available', 'comment' => 'available/full/closed'])
            ->addColumn('slot_key', 'string', ['limit' => 191, 'default' => '', 'comment' => 'unique slot key'])
            ->addIndex(['slot_key'], ['unique' => true, 'name' => 'uniq_yfth_svc_appt_slot_key'])
            ->addIndex(['store_service_id', 'service_date', 'start_minute'], ['name' => 'idx_yfth_svc_slot_binding_date'])
            ->addIndex(['store_id', 'service_project_id', 'service_date'], ['name' => 'idx_yfth_svc_slot_store_project'])
            ->create();
    }

    private function createBenefitLockTable(): void
    {
        $this->baseTable('yfth_service_benefit_lock', 'YFTH service benefit appointment locks')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('appointment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'appointment id'])
            ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('benefit_plan_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit plan id'])
            ->addColumn('benefit_period_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit period id'])
            ->addColumn('benefit_item_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit item id'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'locked', 'comment' => 'locked/released/consumed'])
            ->addColumn('consume_status', 'string', ['limit' => 24, 'default' => 'none', 'comment' => 'reserved writeoff state'])
            ->addColumn('locked_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'locked time'])
            ->addColumn('released_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'released time'])
            ->addColumn('release_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'release reason'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'one active lock per benefit item'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_svc_benefit_active'])
            ->addIndex(['appointment_id'], ['name' => 'idx_yfth_svc_benefit_appt'])
            ->addIndex(['uid', 'status'], ['name' => 'idx_yfth_svc_benefit_uid_status'])
            ->addIndex(['benefit_item_id', 'status'], ['name' => 'idx_yfth_svc_benefit_item_status'])
            ->create();
    }

    private function createEventTable(): void
    {
        $this->baseTable('yfth_service_appointment_event', 'YFTH service appointment status events')
            ->addColumn('appointment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'appointment id'])
            ->addColumn('event_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'event type'])
            ->addColumn('from_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'from status'])
            ->addColumn('to_status', 'string', ['limit' => 32, 'default' => '', 'comment' => 'to status'])
            ->addColumn('operator_type', 'string', ['limit' => 24, 'default' => 'system', 'comment' => 'user/admin/system'])
            ->addColumn('operator_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('old_service_date', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'old date'])
            ->addColumn('old_start_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'old start'])
            ->addColumn('old_end_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'old end'])
            ->addColumn('new_service_date', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'new date'])
            ->addColumn('new_start_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'new start'])
            ->addColumn('new_end_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'new end'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reason'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => '', 'comment' => 'request id'])
            ->addIndex(['appointment_id', 'id'], ['name' => 'idx_yfth_svc_appt_event_appt'])
            ->addIndex(['store_id', 'event_type'], ['name' => 'idx_yfth_svc_appt_event_store_type'])
            ->create();
    }
}
