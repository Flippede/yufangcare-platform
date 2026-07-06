<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\StoreWorkbenchBusinessAdapterServices;

class StoreWorkbenchController
{
    public function overview(Request $request, StoreWorkbenchBusinessAdapterServices $services)
    {
        return app('json')->success($services->overview($request));
    }

    public function appointmentList(Request $request, StoreWorkbenchBusinessAdapterServices $services)
    {
        return app('json')->success($services->appointmentList($request, $request->getMore([
            ['status', ''],
            [['service_project_id', 'd'], 0],
            [['uid', 'd'], 0],
            ['service_date', ''],
        ])));
    }

    public function appointmentDetail(Request $request, StoreWorkbenchBusinessAdapterServices $services, $id)
    {
        return app('json')->success($services->appointmentDetail($request, (int)$id));
    }

    public function appointmentConfirm(Request $request, StoreWorkbenchBusinessAdapterServices $services, $id)
    {
        $data = $request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->confirmAppointment($request, (int)$id, (string)$data['reason'], $data));
    }

    public function appointmentReject(Request $request, StoreWorkbenchBusinessAdapterServices $services, $id)
    {
        $data = $request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->rejectAppointment($request, (int)$id, (string)$data['reason'], $data));
    }

    public function appointmentCancel(Request $request, StoreWorkbenchBusinessAdapterServices $services, $id)
    {
        $data = $request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->cancelAppointment($request, (int)$id, (string)$data['reason'], $data));
    }

    public function writeoffPrecheck(Request $request, StoreWorkbenchBusinessAdapterServices $services)
    {
        $data = $request->postMore([
            ['qr_token', ''],
            ['digital_code', ''],
        ]);
        return app('json')->success($services->writeoffPrecheck($request, (string)$data['qr_token'], (string)$data['digital_code']));
    }

    public function writeoffToken(Request $request, StoreWorkbenchBusinessAdapterServices $services)
    {
        $data = $request->postMore([
            ['qr_token', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->writeoffByToken($request, (string)$data['qr_token'], $data));
    }

    public function writeoffDigital(Request $request, StoreWorkbenchBusinessAdapterServices $services)
    {
        $data = $request->postMore([
            ['digital_code', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->writeoffByDigital($request, (string)$data['digital_code'], $data));
    }

    public function writeoffList(Request $request, StoreWorkbenchBusinessAdapterServices $services)
    {
        return app('json')->success($services->writeoffList($request, $request->getMore([
            [['appointment_id', 'd'], 0],
            [['uid', 'd'], 0],
            ['status', ''],
            ['writeoff_method', ''],
        ])));
    }

    public function writeoffDetail(Request $request, StoreWorkbenchBusinessAdapterServices $services, $id)
    {
        return app('json')->success($services->writeoffDetail($request, (int)$id));
    }

    public function writeoffResult(Request $request, StoreWorkbenchBusinessAdapterServices $services, $id)
    {
        return app('json')->success($services->writeoffResult($request, (int)$id));
    }

    public function orderList(Request $request, StoreWorkbenchBusinessAdapterServices $services)
    {
        return app('json')->success($services->orderList($request, $request->getMore([
            ['status', ''],
            ['order_sn', ''],
            ['date', ''],
            ['start_date', ''],
            ['end_date', ''],
        ])));
    }

    public function orderDetail(Request $request, StoreWorkbenchBusinessAdapterServices $services, $id)
    {
        return app('json')->success($services->orderDetail($request, (int)$id));
    }
}
