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

Route::group(function () {
    Route::get('yfth/service/appointment/benefits', 'v1.yfth.ServiceAppointmentController/availableBenefits')->option(['real_name' => 'YFTH service appointment available benefits']);
    Route::post('yfth/service/appointment', 'v1.yfth.ServiceAppointmentController/create')->option(['real_name' => 'YFTH service appointment create']);
    Route::get('yfth/service/appointment/my', 'v1.yfth.ServiceAppointmentController/myList')->option(['real_name' => 'YFTH my service appointments']);
    Route::get('yfth/service/appointment/:id', 'v1.yfth.ServiceAppointmentController/detail')->option(['real_name' => 'YFTH service appointment detail']);
    Route::post('yfth/service/appointment/:id/cancel', 'v1.yfth.ServiceAppointmentController/cancel')->option(['real_name' => 'YFTH service appointment user cancel']);
    Route::get('yfth/service/appointment/:id/reschedule_slots', 'v1.yfth.ServiceAppointmentController/rescheduleSlots')->option(['real_name' => 'YFTH service appointment reschedule slots']);
    Route::post('yfth/service/appointment/:id/reschedule', 'v1.yfth.ServiceAppointmentController/reschedule')->option(['real_name' => 'YFTH service appointment reschedule']);
    Route::get('yfth/service/appointment/:id/code_status', 'v1.yfth.ServiceAppointmentController/codeStatus')->option(['real_name' => 'YFTH service appointment dynamic code status']);
    Route::post('yfth/service/appointment/:id/code', 'v1.yfth.ServiceAppointmentController/generateCode')->option(['real_name' => 'YFTH service appointment dynamic code generate']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_service_appointment_user', 'mark_name' => 'YFTH service appointment user API']);
