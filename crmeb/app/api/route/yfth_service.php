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
    Route::get('yfth/hq_authority/me', 'v1.yfth.HqAuthorityReadController/me')->option(['real_name' => 'YFTH my headquarters attribution']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_hq_authority_user_read', 'mark_name' => 'YFTH headquarters authority user read API']);

Route::group(function () {
    Route::get('yfth/package_membership/me', 'v1.yfth.PackageMembershipReferralController/me')->option(['real_name' => 'YFTH package membership status']);
    Route::post('yfth/package_membership/store_qr_bind', 'v1.yfth.PackageMembershipReferralController/bindStoreFromQr')->option(['real_name' => 'YFTH customer scan store QR binding']);
    Route::post('yfth/package_membership/invite', 'v1.yfth.PackageMembershipReferralController/issueInvite')->option(['real_name' => 'YFTH direct referral invite issue']);
    Route::post('yfth/package_membership/invite/accept', 'v1.yfth.PackageMembershipReferralController/acceptInvite')->option(['real_name' => 'YFTH direct referral invite accept']);
    Route::get('yfth/package_membership/candidate', 'v1.yfth.PackageMembershipReferralController/candidates')->option(['real_name' => 'YFTH my reward candidates']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_package_membership_user', 'mark_name' => 'YFTH package membership and referral user API']);

Route::group(function () {
    Route::get('yfth/store_workbench/overview', 'v1.yfth.StoreWorkbenchController/overview')->option(['real_name' => 'YFTH store workbench overview']);
    Route::get('yfth/store_workbench/customer_attribution', 'v1.yfth.HqAuthorityStoreReadController/index')->option(['real_name' => 'YFTH store customer attribution list']);
    Route::get('yfth/store_workbench/customer_attribution/:id', 'v1.yfth.HqAuthorityStoreReadController/detail')->option(['real_name' => 'YFTH store customer attribution detail']);
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
    Route::get('yfth/store_workbench/monthly_benefit/pickup', 'v1.yfth.StoreWorkbenchController/monthlyBenefitPickup')->option(['real_name' => 'YFTH store workbench monthly benefit pickup list']);
    Route::get('yfth/store_workbench/monthly_benefit/pickup/:id', 'v1.yfth.StoreWorkbenchController/monthlyBenefitPickupDetail')->option(['real_name' => 'YFTH store workbench monthly benefit pickup detail']);
    Route::post('yfth/store_workbench/monthly_benefit/pickup/:id/confirm', 'v1.yfth.StoreWorkbenchController/monthlyBenefitPickupConfirm')->option(['real_name' => 'YFTH store workbench monthly benefit pickup confirm']);
    Route::get('yfth/store_workbench/package_membership/member', 'v1.yfth.PackageMembershipReferralStoreController/members')->option(['real_name' => 'YFTH store permanent memberships']);
    Route::get('yfth/store_workbench/package_membership/candidate', 'v1.yfth.PackageMembershipReferralStoreController/candidates')->option(['real_name' => 'YFTH store reward candidates']);
    Route::get('yfth/store_workbench/reward_settlement/candidate', 'v1.yfth.RewardSettlementStoreController/candidates')->option(['real_name' => 'YFTH store reward settlement candidates']);
    Route::post('yfth/store_workbench/reward_settlement/candidate/:id/confirm', 'v1.yfth.RewardSettlementStoreController/confirm')->option(['real_name' => 'YFTH store reward candidate confirm']);
    Route::post('yfth/store_workbench/reward_settlement/candidate/:id/settle', 'v1.yfth.RewardSettlementStoreController/settle')->option(['real_name' => 'YFTH store reward candidate offline settlement']);
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

Route::group(function () {
    Route::get('yfth/franchise/opening/my', 'v1.yfth.FranchiseOpeningController/my')->option(['real_name' => 'YFTH my franchise opening progress']);
    Route::get('yfth/franchise/opening/contract/:id', 'v1.yfth.FranchiseOpeningController/contractDetail')->option(['real_name' => 'YFTH my franchise contract detail']);
    Route::post('yfth/franchise/opening/contract/:id/confirm', 'v1.yfth.FranchiseOpeningController/contractConfirm')->option(['real_name' => 'YFTH franchise contract user confirm']);
    Route::post('yfth/franchise/opening/payment/:id/proof', 'v1.yfth.FranchiseOpeningController/paymentProof')->option(['real_name' => 'YFTH franchise payment proof upload']);
    Route::get('yfth/franchise/opening/tasks', 'v1.yfth.FranchiseOpeningController/tasks')->option(['real_name' => 'YFTH franchise preparation tasks']);
    Route::post('yfth/franchise/opening/tasks/:id/submit', 'v1.yfth.FranchiseOpeningController/taskSubmit')->option(['real_name' => 'YFTH franchise preparation task submit']);
    Route::get('yfth/franchise/opening/acceptance', 'v1.yfth.FranchiseOpeningController/acceptance')->option(['real_name' => 'YFTH franchise opening acceptance']);
    Route::post('yfth/franchise/opening/acceptance/submit', 'v1.yfth.FranchiseOpeningController/acceptanceSubmit')->option(['real_name' => 'YFTH franchise opening acceptance submit']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_franchise_opening_user', 'mark_name' => 'YFTH franchise opening user-token API']);

Route::group(function () {
    Route::post('yfth/referral/code', 'v1.yfth.ReferralRewardController/createCode')->option(['real_name' => 'YFTH referral code create']);
    Route::get('yfth/referral/code', 'v1.yfth.ReferralRewardController/code')->option(['real_name' => 'YFTH my referral code']);
    Route::post('yfth/referral/bind', 'v1.yfth.ReferralRewardController/bind')->option(['real_name' => 'YFTH referral candidate bind']);
    Route::get('yfth/referral/candidates', 'v1.yfth.ReferralRewardController/candidates')->option(['real_name' => 'YFTH referral candidates']);
    Route::get('yfth/referral/ledger', 'v1.yfth.ReferralRewardController/ledger')->option(['real_name' => 'YFTH read-only reward ledger']);
    Route::get('yfth/referral/ledger/:id', 'v1.yfth.ReferralRewardController/ledgerDetail')->option(['real_name' => 'YFTH read-only reward ledger detail']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_referral_reward_user', 'mark_name' => 'YFTH referral reward user-token API']);

Route::group(function () {
    Route::get('yfth/product_quota/summary', 'v1.yfth.ProductQuotaController/summary')->option(['real_name' => 'YFTH product quota summary']);
    Route::get('yfth/product_quota/account', 'v1.yfth.ProductQuotaController/account')->option(['real_name' => 'YFTH product quota accounts']);
    Route::get('yfth/product_quota/ledger', 'v1.yfth.ProductQuotaController/ledger')->option(['real_name' => 'YFTH product quota ledgers']);
    Route::get('yfth/product_quota/account/:id', 'v1.yfth.ProductQuotaController/accountDetail')->option(['real_name' => 'YFTH product quota account detail']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_product_quota_user', 'mark_name' => 'YFTH product quota user-token API']);

Route::group(function () {
    Route::get('yfth/monthly_benefit/current', 'v1.yfth.MonthlyBenefitFulfillmentController/current')->option(['real_name' => 'YFTH current monthly product benefits']);
    Route::get('yfth/monthly_benefit/history', 'v1.yfth.MonthlyBenefitFulfillmentController/history')->option(['real_name' => 'YFTH monthly benefit fulfillment history']);
    Route::get('yfth/monthly_benefit/fulfillment/:id', 'v1.yfth.MonthlyBenefitFulfillmentController/detail')->option(['real_name' => 'YFTH monthly benefit fulfillment detail']);
    Route::post('yfth/monthly_benefit/claim', 'v1.yfth.MonthlyBenefitFulfillmentController/claim')->option(['real_name' => 'YFTH monthly benefit claim']);
    Route::post('yfth/monthly_benefit/fulfillment/:id/cancel', 'v1.yfth.MonthlyBenefitFulfillmentController/cancel')->option(['real_name' => 'YFTH monthly benefit fulfillment cancel']);
})->middleware(\app\http\middleware\AllowOriginMiddleware::class)
    ->middleware(\app\api\middleware\StationOpenMiddleware::class)
    ->middleware(\app\api\middleware\AuthTokenMiddleware::class)
    ->option(['mark' => 'yfth_monthly_benefit_user', 'mark_name' => 'YFTH monthly benefit fulfillment user-token API']);
