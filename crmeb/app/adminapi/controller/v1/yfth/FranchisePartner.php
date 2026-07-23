<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\FranchiseOpeningServices;
use app\services\yfth\FranchisePartnerServices;
use app\services\yfth\ProcurementPartnerProfitServices;

class FranchisePartner extends AuthController
{
    public function dashboard(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/dashboard', 'GET');
        return app('json')->success($services->adminDashboard($this->adminInfo ?: []));
    }

    public function ruleList(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/rule', 'GET');
        return app('json')->success($services->adminRules($this->adminInfo ?: []));
    }

    public function ruleSave(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/rule', 'POST');
        return app('json')->success($services->adminSaveRule($this->request->postMore([
            ['order_amount', '89100.00'], [['bottle_count', 'd'], 440],
            [['platform_dividend_bps', 'd'], 100], ['rank_rules', []], ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function rulePublish(FranchisePartnerServices $services, $id)
    {
        $this->auth('yfth/franchise_partner/rule/<id>/publish', 'POST');
        return app('json')->success($services->adminPublishRule((int)$id, $this->request->postMore([['reason', '']]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function partnerList(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/partner', 'GET');
        return app('json')->success($services->adminPartners($this->request->getMore([
            ['keyword', ''], ['rank_code', ''], ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function partnerDetail(FranchisePartnerServices $services, $uid)
    {
        $this->auth('yfth/franchise_partner/partner/<uid>', 'GET');
        return app('json')->success($services->adminPartnerDetail((int)$uid, $this->adminInfo ?: []));
    }

    public function rankChange(FranchisePartnerServices $services, $uid)
    {
        $this->auth('yfth/franchise_partner/partner/<uid>/rank', 'POST');
        return app('json')->success($services->adminChangeRank((int)$uid, $this->request->postMore([
            ['action', 'promote'], ['target_rank', ''], ['reason', ''], ['evidence', []],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function parentChange(FranchisePartnerServices $services, $uid)
    {
        $this->auth('yfth/franchise_partner/partner/<uid>/parent', 'POST');
        return app('json')->success($services->adminChangeParent((int)$uid, $this->request->postMore([
            [['parent_uid', 'd'], 0], ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function sourceCorrect(FranchisePartnerServices $services, $application_id)
    {
        $this->auth('yfth/franchise_partner/source/<application_id>/correct', 'POST');
        return app('json')->success($services->adminCorrectSource((int)$application_id, $this->request->postMore([
            [['direct_partner_uid', 'd'], 0], ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function performanceList(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/performance', 'GET');
        return app('json')->success($services->adminPerformances($this->request->getMore([
            ['status', ''], [['partner_uid', 'd'], 0], [['page', 'd'], 1], [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function rewardList(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/reward', 'GET');
        return app('json')->success($services->adminRewards($this->request->getMore([
            ['status', ''], ['rank_code', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function rewardConfirm(FranchisePartnerServices $services, $id)
    {
        $this->auth('yfth/franchise_partner/reward/<id>/confirm', 'POST');
        return app('json')->success($services->adminRewardTransition((int)$id, 'confirm', $this->request->postMore([['reason', '']]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function rewardCancel(FranchisePartnerServices $services, $id)
    {
        $this->auth('yfth/franchise_partner/reward/<id>/cancel', 'POST');
        return app('json')->success($services->adminRewardTransition((int)$id, 'cancel', $this->request->postMore([['reason', '']]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function rewardSettle(FranchisePartnerServices $services, $id)
    {
        $this->auth('yfth/franchise_partner/reward/<id>/settle', 'POST');
        return app('json')->success($services->adminSettleReward((int)$id, $this->request->postMore([
            ['evidence', ''], ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function warningList(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/warning', 'GET');
        return app('json')->success($services->adminWarnings($this->request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function promotionList(FranchisePartnerServices $services)
    {
        $this->auth('yfth/franchise_partner/promotion', 'GET');
        return app('json')->success($services->adminPromotionApplications($this->request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function promotionReview(FranchisePartnerServices $services, $id)
    {
        $this->auth('yfth/franchise_partner/promotion/<id>/review', 'POST');
        return app('json')->success($services->adminReviewPromotion((int)$id, $this->request->postMore([
            ['action', 'approve'], ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function openingComplete(FranchiseOpeningServices $services)
    {
        $this->auth('yfth/franchise_partner/opening/complete', 'POST');
        return app('json')->success($services->adminGrantIdentity($this->request->postMore([
            [['application_id', 'd'], 0], ['role_code', 'store_manager'], ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function openingCancel(FranchisePartnerServices $services, $application_id)
    {
        $this->auth('yfth/franchise_partner/opening/<application_id>/cancel', 'POST');
        $data = $this->request->postMore([['reason', '']]);
        return app('json')->success($services->adminCancelOpening((int)$application_id, (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function procurementProfitList(ProcurementPartnerProfitServices $services)
    {
        $this->auth('yfth/franchise_partner/procurement_profit', 'GET');
        return app('json')->success($services->adminProcurementProfits($this->request->getMore([
            ['rank_code', ''], ['status', ''], [['store_id', 'd'], 0],
            [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function openingRewardList(ProcurementPartnerProfitServices $services)
    {
        $this->auth('yfth/franchise_partner/opening_reward', 'GET');
        return app('json')->success($services->adminOpeningRewards($this->request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function dividendList(ProcurementPartnerProfitServices $services)
    {
        $this->auth('yfth/franchise_partner/dividend', 'GET');
        return app('json')->success($services->adminDividends($this->request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function dividendGenerate(ProcurementPartnerProfitServices $services)
    {
        $this->auth('yfth/franchise_partner/dividend/generate', 'POST');
        $data = $this->request->postMore([['period_key', date('Y-m')]]);
        return app('json')->success($services->generateDividend((string)$data['period_key']));
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
