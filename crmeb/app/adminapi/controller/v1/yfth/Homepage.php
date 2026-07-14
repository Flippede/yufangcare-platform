<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\yfth\HomepageServices;

class Homepage extends AuthController
{
    public function config(HomepageServices $services)
    {
        return app('json')->success($services->adminConfig());
    }

    public function save(HomepageServices $services)
    {
        $config = $this->request->post('config', []);
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if (!is_array($config)) {
            return app('json')->fail('homepage_config_invalid');
        }
        return app('json')->success($services->save($config, (int)$this->adminId));
    }
}
