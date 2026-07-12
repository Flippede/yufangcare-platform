<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\PermanentMembershipServices;

class PermanentMembershipStoreController
{
    public function index(Request $request, PermanentMembershipServices $services)
    {
        return app('json')->success($services->storeList($request, $request->getMore([['status', '']])));
    }

    public function detail(Request $request, PermanentMembershipServices $services, $id)
    {
        return app('json')->success($services->storeDetail($request, (int)$id));
    }

    public function create(Request $request, PermanentMembershipServices $services)
    {
        return app('json')->success($services->createForStore($request, $this->writePayload($request)));
    }

    public function bind(Request $request, PermanentMembershipServices $services, $id)
    {
        $data = $this->writePayload($request, [['identity_token', '']]);
        return app('json')->success($services->bindForStore($request, (int)$id, (string)$data['identity_token'], $data));
    }

    public function payment(Request $request, PermanentMembershipServices $services, $id)
    {
        return app('json')->success($services->confirmPaymentForStore($request, (int)$id, $this->writePayload($request)));
    }

    public function confirmationCode(Request $request, PermanentMembershipServices $services, $id)
    {
        return app('json')->success($services->confirmationCodeForStore($request, (int)$id));
    }

    private function writePayload(Request $request, array $extra = []): array
    {
        $data = $request->postMore(array_merge($extra, [['idempotency_key', ''], ['client_operation_key', '']]));
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return $data;
    }
}
