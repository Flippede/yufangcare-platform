<?php

use think\facade\Route;

Route::group('yfth', function () {
    Route::group('foundation', function () {
        Route::get('identity', 'v1.yfth.Foundation/identity')->option(['real_name' => 'YFTH identity list']);
        Route::get('store_role', 'v1.yfth.Foundation/storeRole')->option(['real_name' => 'YFTH store role list']);
        Route::get('subject', 'v1.yfth.Foundation/subject')->option(['real_name' => 'YFTH subject list']);
        Route::post('subject/save', 'v1.yfth.Foundation/subjectSave')->option(['real_name' => 'YFTH subject save']);
        Route::get('store_subject', 'v1.yfth.Foundation/storeSubject')->option(['real_name' => 'YFTH store subject list']);
        Route::post('store_subject/save', 'v1.yfth.Foundation/storeSubjectSave')->option(['real_name' => 'YFTH store subject save']);
        Route::post('store_subject/disable', 'v1.yfth.Foundation/storeSubjectDisable')->option(['real_name' => 'YFTH store subject disable']);
        Route::get('qualification', 'v1.yfth.Foundation/qualification')->option(['real_name' => 'YFTH qualification list']);
        Route::post('qualification/save', 'v1.yfth.Foundation/qualificationSave')->option(['real_name' => 'YFTH qualification save']);
        Route::post('qualification/audit', 'v1.yfth.Foundation/qualificationAudit')->option(['real_name' => 'YFTH qualification audit']);
        Route::get('capability', 'v1.yfth.Foundation/capability')->option(['real_name' => 'YFTH capability list']);
        Route::get('payment_route', 'v1.yfth.Foundation/paymentRoute')->option(['real_name' => 'YFTH payment route list']);
        Route::post('payment_route/save', 'v1.yfth.Foundation/paymentRouteSave')->option(['real_name' => 'YFTH payment route save']);
        Route::post('payment_route/disable', 'v1.yfth.Foundation/paymentRouteDisable')->option(['real_name' => 'YFTH payment route disable']);
        Route::get('payment_route/resolve', 'v1.yfth.Foundation/paymentRouteResolve')->option(['real_name' => 'YFTH payment route resolve']);
        Route::get('audit_event', 'v1.yfth.Foundation/auditEvent')->option(['real_name' => 'YFTH audit event list']);
    })->option(['parent' => 'yfth', 'cate_name' => 'YFTH foundation']);

    Route::group('package_benefit', function () {
        Route::get('template', 'v1.yfth.PackageBenefit/templateList')->option(['real_name' => 'YFTH package template list']);
        Route::post('template/save', 'v1.yfth.PackageBenefit/templateSave')->option(['real_name' => 'YFTH package template save']);
        Route::post('rule/save', 'v1.yfth.PackageBenefit/ruleSave')->option(['real_name' => 'YFTH package rule save']);
        Route::post('binding/save', 'v1.yfth.PackageBenefit/bindingSave')->option(['real_name' => 'YFTH package product binding save']);
        Route::get('benefit_template', 'v1.yfth.PackageBenefit/benefitTemplateList')->option(['real_name' => 'YFTH benefit template list']);
        Route::post('benefit_template/save', 'v1.yfth.PackageBenefit/benefitTemplateSave')->option(['real_name' => 'YFTH benefit template save']);
        Route::get('monthly_rule', 'v1.yfth.PackageBenefit/monthlyRuleList')->option(['real_name' => 'YFTH monthly benefit rule list']);
        Route::post('monthly_rule/save', 'v1.yfth.PackageBenefit/monthlyRuleSave')->option(['real_name' => 'YFTH monthly benefit rule save']);
        Route::get('purchase', 'v1.yfth.PackageBenefit/purchaseList')->option(['real_name' => 'YFTH package purchase list']);
        Route::get('instance', 'v1.yfth.PackageBenefit/instanceList')->option(['real_name' => 'YFTH package instance list']);
        Route::get('instance/:id', 'v1.yfth.PackageBenefit/instanceDetail')->option(['real_name' => 'YFTH package instance detail']);
        Route::post('instance/:id/state', 'v1.yfth.PackageBenefit/instanceState')->option(['real_name' => 'YFTH package instance state change']);
        Route::get('plan', 'v1.yfth.PackageBenefit/planList')->option(['real_name' => 'YFTH benefit plan list']);
        Route::post('period/open_due', 'v1.yfth.PackageBenefit/openPeriods')->option(['real_name' => 'YFTH open due benefit periods']);
    })->option(['parent' => 'yfth', 'cate_name' => 'YFTH package benefits']);
})->middleware([
    \app\http\middleware\AllowOriginMiddleware::class,
    \app\adminapi\middleware\AdminAuthTokenMiddleware::class,
    \app\adminapi\middleware\AdminCheckRoleMiddleware::class,
    \app\adminapi\middleware\AdminLogMiddleware::class
])->option(['mark' => 'yfth', 'mark_name' => 'YFTH']);
