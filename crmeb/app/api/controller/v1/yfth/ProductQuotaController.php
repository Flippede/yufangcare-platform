<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\ProductQuotaServices;

class ProductQuotaController
{
    public function summary(Request $request, ProductQuotaServices $services)
    {
        return app('json')->success($services->userSummary($request, $this->query($request, [
            'quota_type' => '',
        ])));
    }

    public function account(Request $request, ProductQuotaServices $services)
    {
        return app('json')->success($services->userAccountList($request, $this->query($request, [
            'quota_type' => '',
        ])));
    }

    public function ledger(Request $request, ProductQuotaServices $services)
    {
        return app('json')->success($services->userLedgerList($request, $this->query($request, [
            'account_id' => 0,
            'quota_type' => '',
        ])));
    }

    public function accountDetail(Request $request, ProductQuotaServices $services, $id)
    {
        return app('json')->success($services->userAccountDetail($request, (int)$id, $this->query($request, [])));
    }

    private function query(Request $request, array $defaults): array
    {
        $raw = (array)$request->get();
        $data = [];
        foreach ($defaults as $field => $default) {
            $data[$field] = array_key_exists($field, $raw) ? $raw[$field] : $default;
        }
        foreach (['amount', 'amount_cent', 'balance', 'balance_cent', 'available_cent', 'reserved_cent', 'consumed_cent', 'frozen_cent', 'status', 'total_granted_cent', 'total_adjusted_cent', 'total_reversed_cent', 'balance_before_cent', 'balance_after_cent', 'source_id', 'idempotency_key', 'operator_uid', 'operator_type', 'operator_role_code', 'uid', 'owner_uid', 'reason', 'before_state', 'after_state', 'snapshot_json'] as $field) {
            if (array_key_exists($field, $raw)) {
                $data[$field] = $raw[$field];
            }
        }
        if (isset($data['account_id'])) {
            $data['account_id'] = (int)$data['account_id'];
        }
        return $data;
    }
}
