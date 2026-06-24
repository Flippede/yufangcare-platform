<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\yfth\AuditEventServices;
use app\services\yfth\BusinessSubjectServices;
use app\services\yfth\StoreCapabilityServices;
use app\services\yfth\StorePaymentRouteServices;
use app\services\yfth\StoreQualificationServices;
use app\services\yfth\StoreSubjectServices;
use app\services\yfth\UserIdentityServices;
use app\services\yfth\UserStoreRoleServices;

class Foundation extends AuthController
{
    public function identity(UserIdentityServices $services)
    {
        $where = $this->request->getMore([
            [['uid', 'd'], 0],
            ['role_code', ''],
            ['status', ''],
        ]);
        return app('json')->success($services->adminList($where));
    }

    public function storeRole(UserStoreRoleServices $services)
    {
        $where = $this->request->getMore([
            [['uid', 'd'], 0],
            [['store_id', 'd'], 0],
            ['role_code', ''],
            ['status', ''],
        ]);
        return app('json')->success($services->adminList($where));
    }

    public function subject(BusinessSubjectServices $services)
    {
        $where = $this->request->getMore([
            ['subject_type', ''],
            ['status', ''],
        ]);
        return app('json')->success($services->adminList($where));
    }

    public function subjectSave(BusinessSubjectServices $services)
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            ['subject_type', ''],
            ['subject_name', ''],
            ['credit_code', ''],
            ['legal_person', ''],
            ['contact_name', ''],
            ['contact_phone', ''],
            ['registered_address', ''],
            ['status', 'active'],
        ]);
        $services->saveSubject($data, (int)$this->adminId);
        return app('json')->success('保存成功');
    }

    public function storeSubject(StoreSubjectServices $services)
    {
        $where = $this->request->getMore([
            [['store_id', 'd'], 0],
            [['subject_id', 'd'], 0],
            ['subject_role', ''],
            ['status', ''],
        ]);
        return app('json')->success($services->adminList($where));
    }

    public function qualification(StoreQualificationServices $services)
    {
        $where = $this->request->getMore([
            [['store_id', 'd'], 0],
            [['subject_id', 'd'], 0],
            ['qualification_type', ''],
            ['status', ''],
        ]);
        return app('json')->success($services->adminList($where));
    }

    public function qualificationSave(StoreQualificationServices $services)
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['subject_id', 'd'], 0],
            ['qualification_type', ''],
            ['certificate_no', ''],
            [['attachment_id', 'd'], 0],
            ['scope', []],
            ['start_time', 0],
            ['expire_time', 0],
            ['status', 'pending'],
            ['reject_reason', ''],
        ]);
        $services->saveQualification($data, (int)$this->adminId);
        return app('json')->success('提交成功');
    }

    public function qualificationAudit(StoreQualificationServices $services)
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            ['status', ''],
            ['reason', ''],
        ]);
        return app('json')->success($services->auditQualification((int)$data['id'], (string)$data['status'], (string)$data['reason'], (int)$this->adminId));
    }

    public function capability(StoreCapabilityServices $services)
    {
        $where = $this->request->getMore([
            [['store_id', 'd'], 0],
            ['capability_code', ''],
            ['status', ''],
        ]);
        return app('json')->success($services->adminList($where));
    }

    public function paymentRoute(StorePaymentRouteServices $services)
    {
        $where = $this->request->getMore([
            [['store_id', 'd'], 0],
            ['business_scene', ''],
            ['status', ''],
        ]);
        return app('json')->success($services->adminList($where));
    }

    public function auditEvent(AuditEventServices $services)
    {
        $where = $this->request->getMore([
            ['business_domain', ''],
            ['object_type', ''],
            ['object_id', ''],
            [['operator_uid', 'd'], 0],
            [['store_id', 'd'], 0],
        ]);
        return app('json')->success($services->adminList($where));
    }
}
