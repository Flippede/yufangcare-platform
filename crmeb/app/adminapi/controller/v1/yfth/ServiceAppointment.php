<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\ServiceAppointmentBookingServices;
use app\services\yfth\ServiceAppointmentWriteoffServices;
use app\services\yfth\ServiceProjectServices;
use app\services\yfth\StoreServiceAppointmentServices;
use app\services\yfth\StoreServiceScheduleServices;

class ServiceAppointment extends AuthController
{
    public function projectList(ServiceProjectServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/project', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([
            ['service_code', ''],
            ['service_type', ''],
            ['status', ''],
        ])));
    }

    public function projectSave(ServiceProjectServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/project/save', 'POST');
        $services->saveProject($this->request->postMore([
            [['id', 'd'], 0],
            ['service_code', ''],
            ['service_name', ''],
            ['service_type', 'health_service'],
            ['service_desc', ''],
            [['suggested_duration_minutes', 'd'], 30],
            [['allow_benefit', 'd'], 1],
            ['required_benefit_type', 'service'],
            ['required_benefit_template_ids', ''],
            [['allow_paid', 'd'], 0],
            ['status', 'active'],
            [['sort', 'd'], 0],
        ], false, false), (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('saved');
    }

    public function projectDisable(ServiceProjectServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/project/disable', 'POST');
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            ['reason', ''],
        ]);
        $services->disableProject((int)$data['id'], (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('disabled');
    }

    public function storeServiceList(StoreServiceAppointmentServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/store_service', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([
            [['store_id', 'd'], 0],
            [['service_project_id', 'd'], 0],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function storeServiceSave(StoreServiceAppointmentServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/store_service/save', 'POST');
        $services->saveStoreService($this->request->postMore([
            [['id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['service_project_id', 'd'], 0],
            ['service_alias', ''],
            ['service_description', ''],
            [['duration_minutes', 'd'], 30],
            [['requires_confirmation', 'd'], 0],
            [['appointment_enabled', 'd'], 1],
            [['advance_min_minutes', 'd'], 120],
            [['advance_max_days', 'd'], 30],
            [['cancel_deadline_minutes', 'd'], 1440],
            [['default_capacity', 'd'], 1],
            ['timezone', 'Asia/Shanghai'],
            ['status', 'active'],
        ]), (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('saved');
    }

    public function storeServiceDisable(StoreServiceAppointmentServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/store_service/disable', 'POST');
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            ['reason', ''],
        ]);
        $services->disableStoreService((int)$data['id'], (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('disabled');
    }

    public function scheduleRuleList(StoreServiceScheduleServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/schedule_rule', 'GET');
        return app('json')->success($services->scheduleRuleList($this->request->getMore([
            [['store_id', 'd'], 0],
            [['service_project_id', 'd'], 0],
            [['store_service_id', 'd'], 0],
            [['weekday', 'd'], 0],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function scheduleRuleSave(StoreServiceScheduleServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/schedule_rule/save', 'POST');
        $services->saveScheduleRule($this->request->postMore([
            [['id', 'd'], 0],
            [['store_service_id', 'd'], 0],
            [['weekday', 'd'], 1],
            [['start_minute', 'd'], 0],
            [['end_minute', 'd'], 0],
            [['slot_interval_minutes', 'd'], 0],
            [['slot_capacity', 'd'], 1],
            ['status', 'active'],
        ]), (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('saved');
    }

    public function scheduleRuleDisable(StoreServiceScheduleServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/schedule_rule/disable', 'POST');
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            ['reason', ''],
        ]);
        $services->disableScheduleRule((int)$data['id'], (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('disabled');
    }

    public function specialDayList(StoreServiceScheduleServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/special_day', 'GET');
        return app('json')->success($services->specialDayList($this->request->getMore([
            [['store_id', 'd'], 0],
            [['service_project_id', 'd'], 0],
            [['store_service_id', 'd'], 0],
            ['service_date', ''],
            ['date_type', ''],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function specialDaySave(StoreServiceScheduleServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/special_day/save', 'POST');
        $services->saveSpecialDay($this->request->postMore([
            [['id', 'd'], 0],
            [['store_service_id', 'd'], 0],
            ['service_date', ''],
            ['date_type', 'closed'],
            [['start_minute', 'd'], 0],
            [['end_minute', 'd'], 1440],
            [['slot_capacity', 'd'], 0],
            ['reason', ''],
            ['status', 'active'],
        ]), (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('saved');
    }

    public function specialDayDisable(StoreServiceScheduleServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/special_day/disable', 'POST');
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            ['reason', ''],
        ]);
        $services->disableSpecialDay((int)$data['id'], (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: []);
        return app('json')->success('disabled');
    }

    public function slotPreview(StoreServiceScheduleServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/slot_preview', 'GET');
        return app('json')->success($services->previewSlots($this->request->getMore([
            [['store_service_id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['service_project_id', 'd'], 0],
            ['date', ''],
            ['start_date', ''],
            ['end_date', ''],
        ]), $this->adminInfo ?: []));
    }

    public function appointmentList(ServiceAppointmentBookingServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/appointment', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([
            [['store_id', 'd'], 0],
            [['service_project_id', 'd'], 0],
            [['uid', 'd'], 0],
            ['status', ''],
            ['service_date', ''],
        ]), $this->adminInfo ?: []));
    }

    public function appointmentDetail(ServiceAppointmentBookingServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/appointment/<id>', 'GET');
        return app('json')->success($services->adminDetail((int)$id, $this->adminInfo ?: []));
    }

    public function appointmentConfirm(ServiceAppointmentBookingServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/appointment/<id>/confirm', 'POST');
        $data = $this->request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return app('json')->success($services->confirmByAdmin((int)$id, (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: [], $data));
    }

    public function appointmentReject(ServiceAppointmentBookingServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/appointment/<id>/reject', 'POST');
        $data = $this->request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return app('json')->success($services->rejectByAdmin((int)$id, (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: [], $data));
    }

    public function appointmentCancel(ServiceAppointmentBookingServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/appointment/<id>/cancel', 'POST');
        $data = $this->request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return app('json')->success($services->cancelByAdmin((int)$id, (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: [], $data));
    }

    public function writeoffList(ServiceAppointmentWriteoffServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/writeoff', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([
            [['store_id', 'd'], 0],
            [['appointment_id', 'd'], 0],
            [['uid', 'd'], 0],
            ['status', ''],
            ['writeoff_method', ''],
        ]), $this->adminInfo ?: []));
    }

    public function writeoffDetail(ServiceAppointmentWriteoffServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/writeoff/record/<id>', 'GET');
        return app('json')->success($services->adminDetail((int)$id, $this->adminInfo ?: []));
    }

    public function writeoffPrecheck(ServiceAppointmentWriteoffServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/writeoff/precheck', 'POST');
        $data = $this->request->postMore([
            ['qr_token', ''],
            ['digital_code', ''],
        ]);
        if (trim((string)$data['qr_token']) !== '') {
            return app('json')->success($services->precheckByToken((string)$data['qr_token'], $this->adminInfo ?: []));
        }
        return app('json')->success($services->precheckByDigital((string)$data['digital_code'], $this->adminInfo ?: []));
    }

    public function writeoffToken(ServiceAppointmentWriteoffServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/writeoff/token', 'POST');
        $data = $this->request->postMore([
            ['qr_token', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return app('json')->success($services->writeoffByToken((string)$data['qr_token'], $this->adminInfo ?: [], $data));
    }

    public function writeoffDigital(ServiceAppointmentWriteoffServices $services)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/writeoff/digital', 'POST');
        $data = $this->request->postMore([
            ['digital_code', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return app('json')->success($services->writeoffByDigital((string)$data['digital_code'], $this->adminInfo ?: [], $data));
    }

    public function writeoffResult(ServiceAppointmentWriteoffServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/writeoff/<id>', 'GET');
        return app('json')->success($services->writeoffResultForAppointment((int)$id));
    }

    public function appointmentExceptionWriteoff(ServiceAppointmentWriteoffServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/service_appointment/appointment/<id>/exception_writeoff', 'POST');
        $data = $this->request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return app('json')->success($services->exceptionWriteoff((int)$id, $this->adminInfo ?: [], (string)$data['reason'], $data));
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        /** @var SystemRoleServices $roleServices */
        $roleServices = app()->make(SystemRoleServices::class);
        $roleServices->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
