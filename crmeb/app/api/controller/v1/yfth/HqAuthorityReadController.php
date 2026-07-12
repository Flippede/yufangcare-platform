<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\HqAuthorityUserReadServices;

class HqAuthorityReadController
{
    public function me(Request $request, HqAuthorityUserReadServices $services)
    {
        return app('json')->success($services->me((int)$request->uid()));
    }
}
