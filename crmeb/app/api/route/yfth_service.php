<?php

use think\facade\Route;

Route::group(function () {
    Route::get('yfth/service/project', 'v1.yfth.ServiceAppointmentController/projectList')->option(['real_name' => 'YFTH service project public list']);
    Route::get('yfth/service/project/:id', 'v1.yfth.ServiceAppointmentController/projectDetail')->option(['real_name' => 'YFTH service project public detail']);
    Route::get('yfth/service/project/:id/stores', 'v1.yfth.ServiceAppointmentController/serviceStores')->option(['real_name' => 'YFTH service available stores']);
    Route::get('yfth/service/project/:id/dates', 'v1.yfth.ServiceAppointmentController/availableDates')->option(['real_name' => 'YFTH service available dates']);
    Route::get('yfth/service/project/:id/slots', 'v1.yfth.ServiceAppointmentController/daySlots')->option(['real_name' => 'YFTH service available slots']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class, false)
    ->option(['mark' => 'yfth_service_public', 'mark_name' => 'YFTH service appointment public API']);
