<?php

namespace app\api\controller\v1\yfth;

use app\services\yfth\HomepageServices;

class HomepageController
{
    public function index(HomepageServices $services)
    {
        return app('json')->success($services->publicConfig());
    }
}
