<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace app\services\system\admin;

use app\dao\system\admin\SystemRoleDao;

use app\Request;
use app\services\BaseServices;
use app\services\system\SystemMenusServices;
use crmeb\exceptions\AuthException;
use crmeb\services\CacheService;

/**
 * Class SystemRoleServices
 * @package app\services\system\admin
 * @method update($id, array $data, ?string $key = null) 修改数据
 * @method save(array $data) 保存数据
 * @method get(int $id, ?array $field = []) 获取数据
 * @method delete(int $id, ?string $key = null) 删除数据
 */
class SystemRoleServices extends BaseServices
{

    /**
     * 当前管理员权限缓存前缀
     */
    const ADMIN_RULES_LEVEL = 'Admin_rules_level_';

    /**
     * SystemRoleServices constructor.
     * @param SystemRoleDao $dao
     */
    public function __construct(SystemRoleDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取权限
     * @return mixed
     */
    public function getRoleArray(array $where = [], string $field = '', string $key = '')
    {
        return $this->dao->getRoule($where, $field, $key);
    }

    /**
     * 获取表单所需的权限名称列表
     * @param int $level
     * @return array
     */
    public function getRoleFormSelect(int $level)
    {
        $list = $this->getRoleArray(['level' => $level, 'status' => 1]);
        $options = [];
        foreach ($list as $id => $roleName) {
            $options[] = ['label' => $roleName, 'value' => $id];
        }
        return $options;
    }

    /**
     * 身份管理列表
     * @param array $where
     * @return array
     */
    public function getRoleList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getRouleList($where, $page, $limit);
        $count = $this->dao->count($where);
        /** @var SystemMenusServices $service */
        $service = app()->make(SystemMenusServices::class);
        foreach ($list as &$item) {
            $item['rules'] = implode(',', array_merge($service->column(['id' => $item['rules']], 'menu_name', 'id')));
        }
        return compact('count', 'list');
    }

    /**
     * 后台验证权限
     * @param Request $request
     * @return bool|void
     * @throws \throwable
     */
    public function verifyAuth(Request $request)
    {
        // 获取当前的接口于接口类型
        $rule = $this->normalizeApiRule((string)$request->rule()->getRule());
        $method = $this->normalizeMethod((string)$request->method());

        // 判断接口是一下两种的时候放行
        if (in_array($rule, ['setting/admin/logout', 'menuslist'])) {
            return true;
        }

        // 获取所有接口类型以及对应的接口
        $allAuth = $this->allApiAuth();

        // 权限菜单未添加时放行
        if (!$this->isRegisteredApi($allAuth, $rule, $method)) return true;

        // 如果是crud接口放行
        if (strpos($rule, 'crud/') === 0) return true;

        // 获取管理员的接口权限列表，存在时放行
        if ($this->hasApiAuth($request->adminInfo(), $rule, $method)) {
            return true;
        }
        throw new AuthException(100101);
    }

    public function assertApiAuthForAdmin(array $adminInfo, string $rule, string $method): void
    {
        if (!$this->hasApiAuth($adminInfo, $rule, $method)) {
            throw new AuthException(100101);
        }
    }

    public function hasApiAuth(array $adminInfo, string $rule, string $method): bool
    {
        if (!$adminInfo) {
            return false;
        }
        if ((int)($adminInfo['level'] ?? -1) === 0) {
            return true;
        }
        $roles = $this->normalizeRoleIds($adminInfo['roles'] ?? []);
        if (!$roles) {
            return false;
        }
        $rule = $this->normalizeApiRule($rule);
        $method = $this->normalizeMethod($method);
        $auth = $this->getRolesByAuth($roles, 2);
        return isset($auth[$method]) && $this->ruleMatches($auth[$method], $rule);
    }

    private function allApiAuth(): array
    {
        return CacheService::remember('all_auth_v2', function () {
            /** @var SystemMenusServices $menusService */
            $menusService = app()->make(SystemMenusServices::class);
            $allList = $menusService->getColumn([['api_url', '<>', ''], ['auth_type', '=', 2]], 'api_url,methods');
            $allAuth = [];
            foreach ($allList as $item) {
                $method = $this->normalizeMethod((string)$item['methods']);
                $rule = $this->normalizeApiRule((string)$item['api_url']);
                if ($method !== '' && $rule !== '') {
                    $allAuth[$method][$rule] = true;
                }
            }
            return $allAuth;
        });
    }

    private function isRegisteredApi(array $allAuth, string $rule, string $method): bool
    {
        return isset($allAuth[$method]) && $this->ruleMatches($allAuth[$method], $rule);
    }

    private function ruleMatches(array $rules, string $rule): bool
    {
        if (isset($rules[$rule])) {
            return true;
        }
        foreach ($rules as $pattern => $_) {
            if (strpos($pattern, '<param>') === false) {
                continue;
            }
            $regex = '/^' . str_replace('\\<param\\>', '[^\/]+', preg_quote($pattern, '/')) . '$/';
            if (preg_match($regex, $rule)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeApiRule(string $rule): string
    {
        $rule = trim(strtolower(str_replace(' ', '', $rule)));
        $rule = preg_replace('/<[^\/>]+>/', '<param>', $rule);
        $rule = preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '<param>', $rule);
        return trim((string)$rule, '/');
    }

    private function normalizeMethod(string $method): string
    {
        return trim(strtolower($method));
    }

    private function normalizeRoleIds($roles): array
    {
        if (!is_array($roles)) {
            $roles = explode(',', (string)$roles);
        }
        $roles = array_map('intval', $roles);
        return array_values(array_filter(array_unique($roles)));
    }

    /**
     * 获取指定权限
     * @param array $rules
     * @param int $type
     * @param string $cachePrefix
     * @return array|mixed
     * @throws \throwable
     */
    public function getRolesByAuth(array $rules, int $type = 1, string $cachePrefix = self::ADMIN_RULES_LEVEL)
    {
        if (empty($rules)) return [];
        $cacheName = md5($cachePrefix . '_v2_' . $type . '_' . implode('_', $rules));
        return CacheService::remember($cacheName, function () use ($rules, $type) {
            /** @var SystemMenusServices $menusService */
            $menusService = app()->make(SystemMenusServices::class);
            $authList = $menusService->getColumn([['id', 'IN', $this->getRoleIds($rules)], ['auth_type', '=', $type]], 'api_url,methods');
            $rolesAuth = [];
            foreach ($authList as $item) {
                $method = $this->normalizeMethod((string)$item['methods']);
                $rule = $this->normalizeApiRule((string)$item['api_url']);
                if ($method !== '' && $rule !== '') {
                    $rolesAuth[$method][$rule] = true;
                }
            }
            return $rolesAuth;
        });
    }

    /**
     * 获取权限id
     * @param array $rules
     * @return array
     */
    public function getRoleIds(array $rules)
    {
        $rules = $this->dao->getColumn([['id', 'IN', $rules], ['status', '=', '1']], 'rules', 'id');
        return array_unique(explode(',', implode(',', $rules)));
    }
}
