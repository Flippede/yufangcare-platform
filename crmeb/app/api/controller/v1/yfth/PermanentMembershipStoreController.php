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

    public function approve(Request $request, PermanentMembershipServices $services, $id)
    {
        return app('json')->success($services->approveForStore($request, (int)$id, $this->writePayload($request)));
    }

    public function reject(Request $request, PermanentMembershipServices $services, $id)
    {
        return app('json')->success($services->rejectForStore(
            $request,
            (int)$id,
            $this->writePayload($request, [['reason', '']])
        ));
    }

    public function activateIdentity(Request $request, PermanentMembershipServices $services)
    {
        $data = $this->writePayload($request, [['identity_token', '']]);
        return app('json')->success($services->activateIdentityForStore(
            $request,
            (string)$data['identity_token'],
            $data
        ));
    }

    private function writePayload(Request $request, array $extra = []): array
    {
        $data = $request->postMore(array_merge($extra, [['idempotency_key', ''], ['client_operation_key', '']]));
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return $data;
    }
}
