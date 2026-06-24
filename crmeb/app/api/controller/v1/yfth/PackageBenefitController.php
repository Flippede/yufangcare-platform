<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\BenefitPeriodServices;
use app\services\yfth\BenefitPlanServices;
use app\services\yfth\PackageInstanceServices;
use app\services\yfth\PackagePurchaseServices;
use app\services\yfth\PackageTemplateServices;

class PackageBenefitController
{
    public function packageList(Request $request, PackageTemplateServices $services)
    {
        return app('json')->success($services->publicList($request->getMore([
            ['package_type', ''],
        ])));
    }

    public function packageDetail(PackageTemplateServices $services, $id)
    {
        return app('json')->success($services->publicDetail((int)$id));
    }

    public function serviceStores(PackagePurchaseServices $services, $id)
    {
        return app('json')->success($services->serviceStores((int)$id));
    }

    public function rulePreview(Request $request, PackageTemplateServices $services, $id)
    {
        $data = $request->getMore([[['rule_version_id', 'd'], 0]]);
        return app('json')->success($services->rulePreview((int)$id, (int)$data['rule_version_id']));
    }

    public function createPurchase(Request $request, PackagePurchaseServices $services)
    {
        return app('json')->success($services->createPurchase((int)$request->uid(), $request->postMore([
            [['template_id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['product_id', 'd'], 0],
            ['product_attr_unique', ''],
            [['rule_version_id', 'd'], 0],
            ['client_price', null],
            ['client_month_count', null],
            ['client_benefit_hash', ''],
            ['order_sn', ''],
            [['agreement_accepted', 'd'], 0],
            ['source', 'mobile'],
        ])));
    }

    public function purchaseStatus(Request $request, PackagePurchaseServices $services, $purchaseNo)
    {
        return app('json')->success($services->purchaseStatus((int)$request->uid(), (string)$purchaseNo));
    }

    public function myPackages(Request $request, PackageInstanceServices $services)
    {
        return app('json')->success($services->myPackages((int)$request->uid()));
    }

    public function myPackageDetail(Request $request, PackageInstanceServices $services, $id)
    {
        return app('json')->success($services->userDetail((int)$request->uid(), (int)$id));
    }

    public function benefitPlan(Request $request, BenefitPlanServices $services, $instanceId)
    {
        $plan = $services->planByInstance((int)$instanceId);
        if (!$plan || (int)$plan['uid'] !== (int)$request->uid()) {
            return app('json')->fail('benefit_plan_not_found');
        }
        return app('json')->success($plan);
    }

    public function monthTimeline(Request $request, BenefitPeriodServices $services, $instanceId)
    {
        return app('json')->success($services->timeline((int)$request->uid(), (int)$instanceId));
    }

    public function currentMonthBenefits(Request $request, BenefitPeriodServices $services)
    {
        $data = $request->getMore([[['instance_id', 'd'], 0]]);
        return app('json')->success($services->currentMonthBenefits((int)$request->uid(), (int)$data['instance_id']));
    }

    public function benefitHistory(Request $request, BenefitPeriodServices $services)
    {
        return app('json')->success($services->benefitHistory((int)$request->uid()));
    }

    public function agreementRecord(Request $request, PackagePurchaseServices $services, $purchaseNo)
    {
        return app('json')->success($services->agreementRecord((int)$request->uid(), (string)$purchaseNo));
    }
}
