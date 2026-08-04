<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

class CustomerServiceServices
{
    public function workbench(int $uid): array
    {
        $this->assertRole($uid);
        $bindings = Db::name('yfth_customer_service_store_binding')->alias('b')
            ->join('system_store s', 's.id=b.store_id')
            ->where(['b.customer_service_uid' => $uid, 'b.status' => 'active'])
            ->field('b.id AS binding_id,b.store_id,b.add_time,s.name AS store_name,s.phone AS store_phone,s.address AS store_address')
            ->order('b.id desc')->select()->toArray();
        foreach ($bindings as &$row) {
            $manager = Db::name('yfth_user_store_role')->alias('r')->join('user u', 'u.uid=r.uid')
                ->where(['r.store_id' => (int)$row['store_id'], 'r.role_code' => 'store_manager', 'r.status' => 'active'])
                ->field('u.nickname,u.phone')->order('r.id desc')->find();
            $manager = $this->row($manager);
            $row['manager_name'] = (string)($manager['nickname'] ?? '');
            $row['manager_phone_masked'] = $this->maskPhone((string)($manager['phone'] ?? ''));
            $row['open_issue_count'] = 0;
        }
        unset($row);
        return [
            'role_code' => 'customer_service',
            'role_name' => '客服',
            'assigned_store_count' => count($bindings),
            'stores' => $bindings,
            'notice' => '客服仅服务已分配的B端门店，不可查看C端客户、收益或结算数据。',
        ];
    }

    public function adminList(array $where = []): array
    {
        $query = Db::name('yfth_user_identity')->alias('i')->join('user u', 'u.uid=i.uid')
            ->where(['i.role_code' => 'customer_service', 'i.status' => 'active'])
            ->field('i.id AS identity_id,i.uid,i.status,i.add_time,u.nickname,u.phone,u.account');
        $keyword = trim((string)($where['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('u.nickname', '%' . $keyword . '%')->whereOrLike('u.phone', '%' . $keyword . '%')
                    ->whereOrLike('u.account', '%' . $keyword . '%');
            });
        }
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 20)));
        $count = (int)(clone $query)->count();
        $rows = $query->order('i.id desc')->page($page, $limit)->select()->toArray();
        foreach ($rows as &$row) {
            $row['phone_masked'] = $this->maskPhone((string)$row['phone']);
            unset($row['phone']);
            $row['stores'] = Db::name('yfth_customer_service_store_binding')->alias('b')
                ->join('system_store s', 's.id=b.store_id')->where([
                    'b.customer_service_uid' => (int)$row['uid'], 'b.status' => 'active',
                ])->field('b.id AS binding_id,b.store_id,s.name AS store_name')->select()->toArray();
        }
        unset($row);
        return ['list' => $rows, 'count' => $count];
    }

    public function grant(int $uid, array $data, int $operatorUid): array
    {
        $storeId = (int)($data['store_id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        if ($uid <= 0 || $storeId <= 0 || mb_strlen($reason) < 2) {
            throw new ApiException('customer_service_grant_invalid');
        }
        return Db::transaction(function () use ($uid, $storeId, $reason, $operatorUid, $data) {
            if (!Db::name('user')->where('uid', $uid)->lock(true)->find()) throw new ApiException('user_not_found');
            if (!Db::name('system_store')->where('id', $storeId)->find()) throw new ApiException('store_not_found');
            $identity = $this->row(Db::name('yfth_user_identity')->where([
                'uid' => $uid, 'role_code' => 'customer_service', 'status' => 'active',
            ])->lock(true)->find());
            if (!$identity) {
                Db::name('yfth_user_identity')->insert([
                    'uid' => $uid, 'role_code' => 'customer_service',
                    'status' => 'active', 'source_type' => 'headquarters_grant', 'source_id' => $operatorUid,
                    'effective_time' => time(), 'expire_time' => 0,
                    'active_key' => $uid . ':customer_service',
                    'add_time' => time(), 'update_time' => time(),
                ]);
            }
            $occupied = $this->row(Db::name('yfth_customer_service_store_binding')->where([
                'active_store_key' => $storeId, 'status' => 'active',
            ])->lock(true)->find());
            if ($occupied && (int)$occupied['customer_service_uid'] !== $uid) {
                throw new ApiException('store_customer_service_already_assigned');
            }
            $binding = $occupied ?: $this->row(Db::name('yfth_customer_service_store_binding')->where([
                'customer_service_uid' => $uid, 'store_id' => $storeId, 'status' => 'active',
            ])->lock(true)->find());
            if (!$binding) {
                $binding = [
                    'customer_service_uid' => $uid, 'store_id' => $storeId, 'status' => 'active',
                    'active_store_key' => $storeId, 'operator_uid' => $operatorUid, 'reason' => $reason,
                    'ended_at' => 0, 'add_time' => time(), 'update_time' => time(),
                ];
                $binding['id'] = (int)Db::name('yfth_customer_service_store_binding')->insertGetId($binding);
                app()->make(AuditEventServices::class)->record(
                    'customer_service', 'store_binding', (string)$binding['id'], 'grant', [], $binding,
                    $operatorUid, 'headquarter_operator', $storeId, $reason, (string)($data['request_id'] ?? '')
                );
            }
            return $binding;
        });
    }

    public function revokeBinding(int $bindingId, string $reason, int $operatorUid): array
    {
        if ($bindingId <= 0 || mb_strlen(trim($reason)) < 2) throw new ApiException('customer_service_revoke_invalid');
        return Db::transaction(function () use ($bindingId, $reason, $operatorUid) {
            $binding = $this->row(Db::name('yfth_customer_service_store_binding')->where('id', $bindingId)->lock(true)->find());
            if (!$binding) throw new ApiException('customer_service_binding_not_found');
            if ((string)$binding['status'] !== 'active') return $binding;
            $update = ['status' => 'disabled', 'active_store_key' => null, 'ended_at' => time(), 'update_time' => time()];
            Db::name('yfth_customer_service_store_binding')->where('id', $bindingId)->update($update);
            $remaining = (int)Db::name('yfth_customer_service_store_binding')->where([
                'customer_service_uid' => (int)$binding['customer_service_uid'], 'status' => 'active',
            ])->count();
            if ($remaining === 0) {
                Db::name('yfth_user_identity')->where([
                    'uid' => (int)$binding['customer_service_uid'], 'role_code' => 'customer_service', 'status' => 'active',
                ])->update(['status' => 'disabled', 'active_key' => null, 'expire_time' => time(), 'update_time' => time()]);
            }
            $after = array_merge($binding, $update);
            app()->make(AuditEventServices::class)->record(
                'customer_service', 'store_binding', (string)$bindingId, 'revoke', $binding, $after,
                $operatorUid, 'headquarter_operator', (int)$binding['store_id'], $reason, ''
            );
            return $after;
        });
    }

    private function assertRole(int $uid): void
    {
        if (!Db::name('yfth_user_identity')->where([
            'uid' => $uid, 'role_code' => 'customer_service', 'status' => 'active',
        ])->find()) throw new ApiException('customer_service_context_not_effective');
    }

    private function row($row): array
    {
        if (!$row) return [];
        return is_array($row) ? $row : $row->toArray();
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) >= 7 ? substr($phone, 0, 3) . '****' . substr($phone, -4) : '';
    }
}
