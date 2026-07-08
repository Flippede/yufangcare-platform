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

Route::group(function () {
    Route::get('yfth/store_workbench/overview', 'v1.yfth.StoreWorkbenchController/overview')->option(['real_name' => 'YFTH store workbench overview']);
    Route::get('yfth/store_workbench/appointments', 'v1.yfth.StoreWorkbenchController/appointmentList')->option(['real_name' => 'YFTH store workbench appointments']);
    Route::get('yfth/store_workbench/appointments/:id', 'v1.yfth.StoreWorkbenchController/appointmentDetail')->option(['real_name' => 'YFTH store workbench appointment detail']);
    Route::post('yfth/store_workbench/appointments/:id/confirm', 'v1.yfth.StoreWorkbenchController/appointmentConfirm')->option(['real_name' => 'YFTH store workbench appointment confirm']);
    Route::post('yfth/store_workbench/appointments/:id/reject', 'v1.yfth.StoreWorkbenchController/appointmentReject')->option(['real_name' => 'YFTH store workbench appointment reject']);
    Route::post('yfth/store_workbench/appointments/:id/cancel', 'v1.yfth.StoreWorkbenchController/appointmentCancel')->option(['real_name' => 'YFTH store workbench appointment cancel']);
    Route::post('yfth/store_workbench/writeoff/precheck', 'v1.yfth.StoreWorkbenchController/writeoffPrecheck')->option(['real_name' => 'YFTH store workbench writeoff precheck']);
    Route::post('yfth/store_workbench/writeoff/token', 'v1.yfth.StoreWorkbenchController/writeoffToken')->option(['real_name' => 'YFTH store workbench QR writeoff']);
    Route::post('yfth/store_workbench/writeoff/digital', 'v1.yfth.StoreWorkbenchController/writeoffDigital')->option(['real_name' => 'YFTH store workbench digital writeoff']);
    Route::get('yfth/store_workbench/writeoff/records', 'v1.yfth.StoreWorkbenchController/writeoffList')->option(['real_name' => 'YFTH store workbench writeoff records']);
    Route::get('yfth/store_workbench/writeoff/records/:id', 'v1.yfth.StoreWorkbenchController/writeoffDetail')->option(['real_name' => 'YFTH store workbench writeoff record detail']);
    Route::get('yfth/store_workbench/writeoff/result/:id', 'v1.yfth.StoreWorkbenchController/writeoffResult')->option(['real_name' => 'YFTH store workbench writeoff result']);
    Route::get('yfth/store_workbench/orders', 'v1.yfth.StoreWorkbenchController/orderList')->option(['real_name' => 'YFTH store workbench order list']);
    Route::get('yfth/store_workbench/orders/:id', 'v1.yfth.StoreWorkbenchController/orderDetail')->option(['real_name' => 'YFTH store workbench order detail']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_store_workbench_user', 'mark_name' => 'YFTH store workbench user-token API']);

Route::group(function () {
    Route::get('yfth/customer/list', 'v1.yfth.FranchiseCustomerController/customerList')->option(['real_name' => 'YFTH franchise customer list']);
    Route::post('yfth/customer/relation', 'v1.yfth.FranchiseCustomerController/bind')->option(['real_name' => 'YFTH franchise customer relation bind']);
    Route::get('yfth/customer/:id', 'v1.yfth.FranchiseCustomerController/detail')->option(['real_name' => 'YFTH franchise customer detail']);
    Route::post('yfth/customer/:id/follow', 'v1.yfth.FranchiseCustomerController/follow')->option(['real_name' => 'YFTH franchise customer follow']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_franchise_customer_user', 'mark_name' => 'YFTH franchise customer user-token API']);

Route::group(function () {
    Route::get('yfth/supply/catalog', 'v1.yfth.SupplyChainController/catalog')->option(['real_name' => 'YFTH store purchase catalog']);
    Route::post('yfth/supply/purchase_order', 'v1.yfth.SupplyChainController/createOrder')->option(['real_name' => 'YFTH store purchase order create']);
    Route::get('yfth/supply/purchase_order', 'v1.yfth.SupplyChainController/orderList')->option(['real_name' => 'YFTH store purchase orders']);
    Route::get('yfth/supply/purchase_order/:id', 'v1.yfth.SupplyChainController/orderDetail')->option(['real_name' => 'YFTH store purchase order detail']);
    Route::get('yfth/supply/in_transit', 'v1.yfth.SupplyChainController/inTransit')->option(['real_name' => 'YFTH store in-transit purchase orders']);
    Route::post('yfth/supply/purchase_order/:id/receive', 'v1.yfth.SupplyChainController/receive')->option(['real_name' => 'YFTH store purchase receive and stock in']);
    Route::get('yfth/supply/inventory', 'v1.yfth.SupplyChainController/inventory')->option(['real_name' => 'YFTH store inventory balance']);
    Route::get('yfth/supply/ledger', 'v1.yfth.SupplyChainController/ledger')->option(['real_name' => 'YFTH store inventory ledger']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_supply_chain_user', 'mark_name' => 'YFTH supply chain user-token API']);

Route::group(function () {
    Route::post('yfth/franchise/application', 'v1.yfth.FranchiseApplicationController/submit')->option(['real_name' => 'YFTH franchise application submit']);
    Route::get('yfth/franchise/application/my', 'v1.yfth.FranchiseApplicationController/myList')->option(['real_name' => 'YFTH my franchise applications']);
    Route::get('yfth/franchise/application/:id', 'v1.yfth.FranchiseApplicationController/detail')->option(['real_name' => 'YFTH my franchise application detail']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_franchise_application_user', 'mark_name' => 'YFTH franchise application user-token API']);
