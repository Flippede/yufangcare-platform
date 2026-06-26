<?php

namespace app\api\controller\v1\yfth;

use app\Request;
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
}
