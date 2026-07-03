<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\ServiceAppointmentBookingServices;
use app\services\yfth\ServiceAppointmentQueryServices;

class ServiceAppointmentController
{
    public function projectList(Request $request, ServiceAppointmentQueryServices $services)
    {
        return app('json')->success($services->projectList($request->getMore([
            ['service_type', ''],
        ])));
    }

    public function projectDetail(ServiceAppointmentQueryServices $services, $id)
    {
        return app('json')->success($services->projectDetail((int)$id));
    }

    public function serviceStores(ServiceAppointmentQueryServices $services, $id)
    {
        return app('json')->success($services->serviceStores((int)$id));
    }

    public function availableDates(Request $request, ServiceAppointmentQueryServices $services, $id)
    {
        return app('json')->success($services->availableDates((int)$id, $request->getMore([
            [['store_id', 'd'], 0],
            ['start_date', ''],
            ['end_date', ''],
        ])));
    }

    public function daySlots(Request $request, ServiceAppointmentQueryServices $services, $id)
    {
        return app('json')->success($services->daySlots((int)$id, $request->getMore([
            [['store_id', 'd'], 0],
            ['date', ''],
        ])));
    }

    public function availableBenefits(Request $request, ServiceAppointmentBookingServices $services)
    {
        return app('json')->success($services->availableBenefits((int)$request->uid(), $request->getMore([
            [['service_project_id', 'd'], 0],
            [['package_instance_id', 'd'], 0],
        ])));
    }

    public function create(Request $request, ServiceAppointmentBookingServices $services)
    {
        $data = $request->postMore([
            [['store_id', 'd'], 0],
            [['service_project_id', 'd'], 0],
            [['benefit_item_id', 'd'], 0],
            ['date', ''],
            [['start_minute', 'd'], 0],
            ['user_note', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->createAppointment((int)$request->uid(), $data));
    }

    public function myList(Request $request, ServiceAppointmentBookingServices $services)
    {
        return app('json')->success($services->userList((int)$request->uid(), $request->getMore([
            ['status', ''],
            [['store_id', 'd'], 0],
        ])));
    }

    public function detail(Request $request, ServiceAppointmentBookingServices $services, $id)
    {
        return app('json')->success($services->userDetail((int)$request->uid(), (int)$id));
    }

    public function cancel(Request $request, ServiceAppointmentBookingServices $services, $id)
    {
        $data = $request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->cancelByUser((int)$request->uid(), (int)$id, (string)$data['reason'], $data));
    }

    public function rescheduleSlots(Request $request, ServiceAppointmentBookingServices $services, $id)
    {
        return app('json')->success($services->rescheduleSlots((int)$request->uid(), (int)$id, $request->getMore([
            ['date', ''],
        ])));
    }

    public function reschedule(Request $request, ServiceAppointmentBookingServices $services, $id)
    {
        $data = $request->postMore([
            ['date', ''],
            [['start_minute', 'd'], 0],
            ['reason', ''],
            ['idempotency_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->rescheduleByUser((int)$request->uid(), (int)$id, $data));
    }
}
