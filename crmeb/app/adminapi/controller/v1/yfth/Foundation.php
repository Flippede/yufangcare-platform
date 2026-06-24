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
        return app('json')->success($services->adminList($where, false));
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
        return app('json')->success('saved');
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

    public function storeSubjectSave(StoreSubjectServices $services)
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['subject_id', 'd'], 0],
            ['store_type', ''],
            ['subject_role', 'sales'],
            [['is_sales_subject', 'd'], 0],
            [['is_service_subject', 'd'], 0],
            [['is_payment_subject', 'd'], 0],
            [['is_fulfillment_subject', 'd'], 0],
            [['is_invoice_subject', 'd'], 0],
            [['is_refund_subject', 'd'], 0],
            [['is_host_subject', 'd'], 0],
            ['status', 'active'],
            ['effective_time', 0],
            ['expire_time', 0],
        ]);
        $services->saveStoreSubject($data, (int)$this->adminId);
        return app('json')->success('saved');
    }

    public function storeSubjectDisable(StoreSubjectServices $services)
    {
        $data = $this->request->postMore([[['id', 'd'], 0]]);
        $services->disableStoreSubject((int)$data['id'], (int)$this->adminId);
        return app('json')->success('disabled');
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
        return app('json')->success('submitted');
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

    public function paymentRouteSave(StorePaymentRouteServices $services)
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['subject_id', 'd'], 0],
            ['business_scene', ''],
            ['route_type', ''],
            ['merchant_ref', ''],
            ['sub_merchant_ref', ''],
            [['receiver_subject_id', 'd'], 0],
            [['invoice_subject_id', 'd'], 0],
            [['refund_subject_id', 'd'], 0],
            ['status', 'active'],
            ['config_status', 'metadata_only'],
            [['version_no', 'd'], 0],
            [['priority', 'd'], 0],
            ['effective_time', 0],
            ['expire_time', 0],
        ]);
        $services->saveRoute($data, (int)$this->adminId);
        return app('json')->success('saved');
    }

    public function paymentRouteDisable(StorePaymentRouteServices $services)
    {
        $data = $this->request->postMore([[['id', 'd'], 0]]);
        $services->disableRoute((int)$data['id'], (int)$this->adminId);
        return app('json')->success('disabled');
    }

    public function paymentRouteResolve(StorePaymentRouteServices $services)
    {
        $where = $this->request->getMore([
            [['store_id', 'd'], 0],
            ['business_scene', ''],
        ]);
        return app('json')->success($services->resolveRoute((int)$where['store_id'], (string)$where['business_scene']));
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
