<?php

use think\migration\Migrator;

class CreateYfthServiceAppointmentTables extends Migrator
{
    public function change()
    {
        $this->createServiceProjectTable();
        $this->createStoreServiceTable();
        $this->createScheduleRuleTable();
        $this->createSpecialDayTable();
    }

    private function baseTable(string $name, string $comment)
    {
        return $this->table($name)
            ->setEngine('InnoDB')
            ->setComment($comment)
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at']);
    }

    private function addOperatorColumns($table)
    {
        return $table
            ->addColumn('created_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'creator admin id'])
            ->addColumn('updated_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'last updater admin id'])
            ->addColumn('disabled_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'disabler admin id'])
            ->addColumn('disabled_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'disabled time'])
            ->addColumn('close_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'disable or close reason']);
    }

    private function createServiceProjectTable(): void
    {
        $table = $this->baseTable('yfth_service_project', 'YFTH service project definitions')
            ->addColumn('service_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'service code'])
            ->addColumn('service_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'service name'])
            ->addColumn('service_type', 'string', ['limit' => 32, 'default' => 'health_service', 'comment' => 'service type'])
            ->addColumn('service_desc', 'text', ['null' => true, 'comment' => 'service description'])
            ->addColumn('suggested_duration_minutes', 'integer', ['signed' => false, 'default' => 30, 'comment' => 'suggested service duration'])
            ->addColumn('allow_benefit', 'boolean', ['default' => 1, 'comment' => 'allow benefit usage'])
            ->addColumn('required_benefit_type', 'string', ['limit' => 32, 'default' => 'service', 'comment' => 'required benefit type'])
            ->addColumn('required_benefit_template_ids', 'string', ['limit' => 255, 'default' => '', 'comment' => 'allowed service benefit template ids'])
            ->addColumn('allow_paid', 'boolean', ['default' => 0, 'comment' => 'allow future paid service'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('sort', 'integer', ['signed' => true, 'default' => 0, 'comment' => 'sort']);
        $this->addOperatorColumns($table)
            ->addIndex(['service_code'], ['unique' => true, 'name' => 'uniq_yfth_svc_project_code'])
            ->addIndex(['status', 'sort'], ['name' => 'idx_yfth_svc_project_status_sort'])
            ->addIndex(['service_type', 'status'], ['name' => 'idx_yfth_svc_project_type_status'])
            ->create();
    }

    private function createStoreServiceTable(): void
    {
        $table = $this->baseTable('yfth_store_service', 'YFTH store service authorizations')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'system_store.id'])
            ->addColumn('service_project_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service project id'])
            ->addColumn('service_alias', 'string', ['limit' => 128, 'default' => '', 'comment' => 'store display name'])
            ->addColumn('service_description', 'text', ['null' => true, 'comment' => 'store service description'])
            ->addColumn('duration_minutes', 'integer', ['signed' => false, 'default' => 30, 'comment' => 'actual service duration'])
            ->addColumn('requires_confirmation', 'boolean', ['default' => 0, 'comment' => 'manual confirmation required'])
            ->addColumn('appointment_enabled', 'boolean', ['default' => 1, 'comment' => 'appointment enabled'])
            ->addColumn('advance_min_minutes', 'integer', ['signed' => false, 'default' => 120, 'comment' => 'minimum minutes before appointment'])
            ->addColumn('advance_max_days', 'integer', ['signed' => false, 'default' => 30, 'comment' => 'maximum days ahead'])
            ->addColumn('cancel_deadline_minutes', 'integer', ['signed' => false, 'default' => 1440, 'comment' => 'reserved cancel deadline'])
            ->addColumn('default_capacity', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'default slot capacity'])
            ->addColumn('timezone', 'string', ['limit' => 64, 'default' => 'Asia/Shanghai', 'comment' => 'store service timezone'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'one active binding per store and project']);
        $this->addOperatorColumns($table)
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_store_svc_active'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_store_svc_store_status'])
            ->addIndex(['service_project_id', 'status'], ['name' => 'idx_yfth_store_svc_project_status'])
            ->addIndex(['store_id', 'service_project_id', 'status'], ['name' => 'idx_yfth_store_svc_lookup'])
            ->create();
    }

    private function createScheduleRuleTable(): void
    {
        $table = $this->baseTable('yfth_store_service_schedule_rule', 'YFTH store service weekly schedule rules')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'system_store.id'])
            ->addColumn('service_project_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service project id'])
            ->addColumn('store_service_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store service authorization id'])
            ->addColumn('weekday', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'ISO weekday 1-7'])
            ->addColumn('start_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'minutes from day start'])
            ->addColumn('end_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'minutes from day start'])
            ->addColumn('slot_interval_minutes', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'slot start interval, 0 means service duration'])
            ->addColumn('slot_capacity', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'slot capacity'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'active weekly rule key']);
        $this->addOperatorColumns($table)
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_svc_rule_active'])
            ->addIndex(['store_service_id', 'weekday', 'status'], ['name' => 'idx_yfth_svc_rule_binding_weekday'])
            ->addIndex(['store_id', 'service_project_id', 'weekday', 'status'], ['name' => 'idx_yfth_svc_rule_store_project'])
            ->create();
    }

    private function createSpecialDayTable(): void
    {
        $table = $this->baseTable('yfth_store_service_special_day', 'YFTH store service special date rules')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'system_store.id'])
            ->addColumn('service_project_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service project id'])
            ->addColumn('store_service_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store service authorization id'])
            ->addColumn('service_date', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'YYYYMMDD service date'])
            ->addColumn('date_type', 'string', ['limit' => 24, 'default' => 'closed', 'comment' => 'closed/extra/capacity_override'])
            ->addColumn('start_minute', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'minutes from day start'])
            ->addColumn('end_minute', 'integer', ['signed' => false, 'default' => 1440, 'comment' => 'minutes from day start'])
            ->addColumn('slot_capacity', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'override or extra capacity'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'special day reason'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'active special day key']);
        $this->addOperatorColumns($table)
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_svc_day_active'])
            ->addIndex(['store_service_id', 'service_date', 'status'], ['name' => 'idx_yfth_svc_day_binding_date'])
            ->addIndex(['store_id', 'service_project_id', 'service_date', 'status'], ['name' => 'idx_yfth_svc_day_store_project'])
            ->create();
    }
}
