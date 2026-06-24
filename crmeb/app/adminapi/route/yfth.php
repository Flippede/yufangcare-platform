<?php

use think\facade\Route;

Route::group('yfth', function () {
    Route::group('foundation', function () {
        Route::get('identity', 'v1.yfth.Foundation/identity')->option(['real_name' => '御方通和用户身份列表']);
        Route::get('store_role', 'v1.yfth.Foundation/storeRole')->option(['real_name' => '御方通和门店角色列表']);
        Route::get('subject', 'v1.yfth.Foundation/subject')->option(['real_name' => '御方通和经营主体列表']);
        Route::post('subject/save', 'v1.yfth.Foundation/subjectSave')->option(['real_name' => '御方通和经营主体保存']);
        Route::get('store_subject', 'v1.yfth.Foundation/storeSubject')->option(['real_name' => '御方通和门店主体列表']);
        Route::get('qualification', 'v1.yfth.Foundation/qualification')->option(['real_name' => '御方通和门店资质列表']);
        Route::post('qualification/save', 'v1.yfth.Foundation/qualificationSave')->option(['real_name' => '御方通和门店资质提交']);
        Route::post('qualification/audit', 'v1.yfth.Foundation/qualificationAudit')->option(['real_name' => '御方通和门店资质审核']);
        Route::get('capability', 'v1.yfth.Foundation/capability')->option(['real_name' => '御方通和门店能力列表']);
        Route::get('payment_route', 'v1.yfth.Foundation/paymentRoute')->option(['real_name' => '御方通和收款路由列表']);
        Route::get('audit_event', 'v1.yfth.Foundation/auditEvent')->option(['real_name' => '御方通和审计事件列表']);
    })->option(['parent' => 'yfth', 'cate_name' => '业务基础域']);
})->middleware([
    \app\http\middleware\AllowOriginMiddleware::class,
    \app\adminapi\middleware\AdminAuthTokenMiddleware::class,
    \app\adminapi\middleware\AdminCheckRoleMiddleware::class,
    \app\adminapi\middleware\AdminLogMiddleware::class
])->option(['mark' => 'yfth', 'mark_name' => '御方通和']);
