<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\PermanentMembershipServices;

class PermanentMembershipController
{
    public function identityCode(Request $request, PermanentMembershipServices $services)
    {
        return app('json')->success($services->generateCustomerIdentityCode((int)$request->uid()));
    }

    public function me(Request $request, PermanentMembershipServices $services)
    {
        return app('json')->success($services->me((int)$request->uid()));
    }

    public function confirm(Request $request, PermanentMembershipServices $services)
    {
        $data = $request->postMore([['confirmation_token', ''], ['idempotency_key', ''], ['client_operation_key', '']]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->confirmByCustomer((int)$request->uid(), (string)$data['confirmation_token'], $data));
    }
}
