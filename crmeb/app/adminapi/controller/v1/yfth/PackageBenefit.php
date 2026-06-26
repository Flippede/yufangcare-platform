<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\BenefitPeriodServices;
use app\services\yfth\BenefitPlanServices;
use app\services\yfth\BenefitTemplateServices;
use app\services\yfth\PackageActivationRecoveryServices;
use app\services\yfth\PackageInstanceServices;
use app\services\yfth\PackageLifecycleServices;
use app\services\yfth\PackagePurchaseServices;
use app\services\yfth\PackageTemplateServices;
use crmeb\exceptions\AdminException;

class PackageBenefit extends AuthController
{
    public function templateList(PackageTemplateServices $services)
    {
        return app('json')->success($services->adminList($this->request->getMore([
            ['package_code', ''],
            ['package_type', ''],
            ['status', ''],
        ])));
    }

    public function templateSave(PackageTemplateServices $services)
    {
        $services->saveTemplate($this->request->postMore([
            [['id', 'd'], 0],
            ['package_code', ''],
            ['package_name', ''],
            ['package_title', ''],
            ['package_type', 'health_package'],
            ['base_price', '0.00'],
            ['currency', 'CNY'],
            [['benefit_months', 'd'], 0],
            ['service_summary', ''],
            ['agreement_title', ''],
            ['agreement_content', ''],
            ['status', 'draft'],
            [['current_rule_version_id', 'd'], 0],
            ['publish_time', 0],
            [['sort', 'd'], 0],
        ]), (int)$this->adminId);
        return app('json')->success('saved');
    }

    public function ruleSave(PackageTemplateServices $services)
    {
        $services->saveRuleVersion($this->request->postMore([
            [['id', 'd'], 0],
            [['template_id', 'd'], 0],
            [['version_no', 'd'], 0],
            ['rule_code', ''],
            ['status', 'draft'],
            ['package_price', '0.00'],
            [['month_count', 'd'], 0],
            ['benefit_rule_snapshot', []],
            ['agreement_title', ''],
            ['agreement_content', ''],
            ['effective_time', 0],
            ['expire_time', 0],
        ]), (int)$this->adminId);
        return app('json')->success('saved');
    }

    public function ruleCopy(PackageTemplateServices $services, $id)
    {
        return app('json')->success($services->copyRuleVersion((int)$id, (int)$this->adminId));
    }

    public function bindingSave(PackageTemplateServices $services)
    {
        $services->saveProductBinding($this->request->postMore([
            [['id', 'd'], 0],
            [['template_id', 'd'], 0],
            [['rule_version_id', 'd'], 0],
            [['product_id', 'd'], 0],
            ['product_attr_unique', ''],
            ['sku_price_snapshot', '0.00'],
            ['binding_status', 'active'],
        ]), (int)$this->adminId);
        return app('json')->success('saved');
    }

    public function benefitTemplateList(BenefitTemplateServices $services)
    {
        return app('json')->success($services->templateList($this->request->getMore([
            ['benefit_code', ''],
            ['benefit_type', ''],
            ['status', ''],
        ])));
    }

    public function benefitTemplateSave(BenefitTemplateServices $services)
    {
        $services->saveBenefitTemplate($this->request->postMore([
            [['id', 'd'], 0],
            ['benefit_code', ''],
            ['benefit_name', ''],
            ['benefit_type', 'service'],
            ['fulfillment_type', 'manual'],
            ['unit', 'item'],
            ['description', ''],
            ['status', 'active'],
            [['sort', 'd'], 0],
        ]), (int)$this->adminId);
        return app('json')->success('saved');
    }

    public function monthlyRuleList(BenefitTemplateServices $services)
    {
        return app('json')->success($services->monthlyRuleList($this->request->getMore([
            [['template_id', 'd'], 0],
            [['rule_version_id', 'd'], 0],
            [['month_no', 'd'], 0],
            ['status', ''],
        ])));
    }

    public function monthlyRuleSave(BenefitTemplateServices $services)
    {
        $services->saveMonthlyRule($this->request->postMore([
            [['id', 'd'], 0],
            [['template_id', 'd'], 0],
            [['rule_version_id', 'd'], 0],
            [['month_no', 'd'], 1],
            [['benefit_template_id', 'd'], 0],
            ['quantity', '1.00'],
            ['per_limit', '0.00'],
            [['available_offset_days', 'd'], 0],
            [['expire_offset_days', 'd'], 0],
            ['service_capability', ''],
            ['status', 'active'],
        ]), (int)$this->adminId);
        return app('json')->success('saved');
    }

    public function purchaseList(PackagePurchaseServices $services)
    {
        return app('json')->success($services->adminList($this->request->getMore([
            [['uid', 'd'], 0],
            [['store_id', 'd'], 0],
            [['template_id', 'd'], 0],
            ['purchase_status', ''],
            ['activation_status', ''],
            ['order_sn', ''],
        ])));
    }

    public function instanceList(PackageInstanceServices $services)
    {
        return app('json')->success($services->adminList($this->request->getMore([
            [['uid', 'd'], 0],
            [['store_id', 'd'], 0],
            [['template_id', 'd'], 0],
            ['status', ''],
            ['refund_status', ''],
            ['order_sn', ''],
        ])));
    }

    public function instanceDetail(PackageInstanceServices $services, $id)
    {
        return app('json')->success($services->adminDetail((int)$id));
    }

    public function planList(BenefitPlanServices $services)
    {
        return app('json')->success($services->adminList($this->request->getMore([
            [['uid', 'd'], 0],
            [['store_id', 'd'], 0],
            [['package_instance_id', 'd'], 0],
            ['status', ''],
        ])));
    }

    public function openPeriods(BenefitPeriodServices $services)
    {
        $data = $this->request->postMore([
            [['limit', 'd'], 100],
        ]);
        return app('json')->success($services->openDuePeriods(0, (int)$data['limit']));
    }

    public function recoverActivation(PackageActivationRecoveryServices $services)
    {
        $this->assertAdminApiAuth('yfth/package_benefit/activation/recover', 'POST');
        $data = $this->request->postMore([
            [['limit', 'd'], 50],
        ]);
        return app('json')->success($services->recoverPaidUnactivated((int)$data['limit'], (int)$this->adminId, 'admin_manual'));
    }

    public function retryActivation(PackageActivationRecoveryServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/package_benefit/purchase/<id>/activation_retry', 'POST');
        $data = $this->request->postMore([
            ['reason', ''],
        ]);
        return app('json')->success($services->retryPurchase((int)$id, (string)$data['reason'], (int)$this->adminId));
    }

    public function scanOrphanOrders(PackagePurchaseServices $services)
    {
        $this->assertAdminApiAuth('yfth/package_benefit/orphan/scan', 'POST');
        $data = $this->request->postMore([
            [['limit', 'd'], 50],
            [['close_unpaid', 'd'], 0],
            [['recover_paid', 'd'], 0],
        ]);
        return app('json')->success($services->scanUnboundPackageIntentOrders(
            (int)$data['limit'],
            (bool)$data['close_unpaid'],
            (bool)$data['recover_paid'],
            (int)$this->adminId
        ));
    }

    public function instanceState(PackageInstanceServices $services, $id)
    {
        $data = $this->request->postMore([
            ['status', ''],
            ['reason', ''],
            ['confirm_text', ''],
        ]);
        if ($data['confirm_text'] !== 'CONFIRM') {
            throw new AdminException('high_risk_confirmation_required');
        }
        $services->changeState((int)$id, (string)$data['status'], (string)$data['reason'], (int)$this->adminId);
        return app('json')->success('updated');
    }

    public function instanceLifecycle(PackageLifecycleServices $services, $id)
    {
        $data = $this->request->postMore([
            ['status', ''],
            ['reason', ''],
            ['confirm_text', ''],
        ]);
        if ($data['confirm_text'] !== 'CONFIRM') {
            throw new AdminException('high_risk_confirmation_required');
        }
        return app('json')->success($services->changeInstanceState((int)$id, (string)$data['status'], (string)$data['reason'], (int)$this->adminId));
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        /** @var SystemRoleServices $roleServices */
        $roleServices = app()->make(SystemRoleServices::class);
        $roleServices->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
