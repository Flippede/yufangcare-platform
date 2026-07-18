<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\UnifiedRewardOrchestratorServices;
use think\facade\Db;

class RewardGovernance extends AuthController
{
    public function eventList(UnifiedRewardOrchestratorServices $services)
    {
        $this->auth('yfth/reward_governance/event', 'GET');
        return app('json')->success($services->list($this->request->getMore([
            ['status', ''], ['event_type', ''], ['source_type', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function retry(UnifiedRewardOrchestratorServices $services)
    {
        $this->auth('yfth/reward_governance/retry', 'POST');
        $data = $this->request->postMore([[['limit', 'd'], 50]]);
        return app('json')->success($services->retryDue((int)$data['limit'], 'admin:' . (int)$this->adminId));
    }

    public function openingQuota()
    {
        $this->auth('yfth/reward_governance/opening_quota', 'GET');
        [$page, $limit, $default] = app()->make(UnifiedRewardOrchestratorServices::class)->getPageValue();
        $limit = $limit ?: $default;
        $query = Db::name('yfth_partner_opening_quota_award')->alias('a')
            ->leftJoin('user u', 'u.uid=a.partner_uid')->leftJoin('system_store s', 's.id=a.store_id');
        $count = (int)(clone $query)->count();
        $list = $query->field('a.*,u.nickname,u.account,s.name AS store_name')->page($page, $limit)->order('a.id desc')->select()->toArray();
        return app('json')->success(compact('list', 'count'));
    }

    public function confirmOpeningQuota(int $id, UnifiedRewardOrchestratorServices $services)
    {
        $this->auth('yfth/reward_governance/opening_quota/<id>/confirm', 'POST');
        return app('json')->success($services->confirmOpeningQuota($id, (int)$this->adminId));
    }

    public function consistency(UnifiedRewardOrchestratorServices $services)
    {
        $this->auth('yfth/reward_governance/consistency', 'GET');
        return app('json')->success($services->consistencyIssues((int)$this->request->get('limit', 100)));
    }

    public function migrationIssues()
    {
        $this->auth('yfth/reward_governance/migration_issue', 'GET');
        [$page, $limit, $default] = app()->make(UnifiedRewardOrchestratorServices::class)->getPageValue();
        $limit = $limit ?: $default;
        $query = Db::name('yfth_partner_migration_issue');
        $count = (int)(clone $query)->count();
        return app('json')->success(['list' => $query->page($page, $limit)->order('id desc')->select()->toArray(), 'count' => $count]);
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
