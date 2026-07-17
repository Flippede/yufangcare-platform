<?php

namespace app\services\yfth;

use app\Request;
use crmeb\exceptions\ApiException;
use crmeb\services\app\MiniProgramService;
use think\facade\Db;

class StoreAcquisitionServices
{
    private const ROLES = ['store_manager', 'store_staff'];
    private const DOMAIN = 'yfth_store_acquisition';

    public function issue(Request $request, array $data): array
    {
        $context = $this->assertIssuerContext($request);
        $requestId = $this->requestId($data, 'issue');

        $result = Db::transaction(function () use ($context, $requestId) {
            $uid = (int)$context['uid'];
            $storeId = (int)$context['store_id'];
            $roleCode = (string)$context['role_code'];
            $activeKey = $uid . ':' . $storeId . ':' . $roleCode;
            $existing = (array)Db::name('yfth_store_acquisition_code')
                ->where('active_key', $activeKey)->lock(true)->find();
            if ($existing) {
                Db::name('yfth_store_acquisition_code')->where('id', (int)$existing['id'])->update([
                    'status' => 'invalidated',
                    'active_key' => null,
                    'invalidated_at' => time(),
                    'update_time' => time(),
                ]);
            }

            $token = bin2hex(random_bytes(32));
            $now = time();
            $row = [
                'code_no' => $this->makeNo('YFSAC'),
                'token_hash' => hash('sha256', $token),
                'store_id' => $storeId,
                'issuer_uid' => $uid,
                'issuer_role_code' => $roleCode,
                'status' => 'active',
                'issued_at' => $now,
                'expires_at' => $now + 31536000,
                'invalidated_at' => 0,
                'active_key' => $activeKey,
                'request_id' => $requestId,
                'add_time' => $now,
                'update_time' => $now,
            ];
            $row['id'] = (int)Db::name('yfth_store_acquisition_code')->insertGetId($row);
            app()->make(AuditEventServices::class)->recordSafely(
                self::DOMAIN, 'store_acquisition_code', (string)$row['id'], 'issue', [],
                $this->codeAuditDto($row), $uid, $roleCode, $storeId, 'store_acquisition_code_issued', $requestId
            );
            $launchPath = '/pages/yfth/store_acquisition/accept?acquisition_token=' . $token;
            return array_merge($this->codeDto($row), [
                'acquisition_token' => $token,
                'launch_path' => $launchPath,
            ]);
        });
        $result['launch_url'] = $this->miniProgramUrlLink((string)$result['launch_path']);
        $result['launch_url_expires_at'] = $result['launch_url'] !== '' ? time() + 29 * 86400 : 0;
        return $result;
    }

    public function current(Request $request): array
    {
        $context = $this->assertIssuerContext($request);
        $row = (array)Db::name('yfth_store_acquisition_code')
            ->where('active_key', (int)$context['uid'] . ':' . (int)$context['store_id'] . ':' . (string)$context['role_code'])
            ->find();
        return ['active_code' => $row && (int)$row['expires_at'] > time() ? $this->codeDto($row) : null];
    }

    public function resolve(string $token): array
    {
        $row = $this->validCode($token, false);
        return $this->publicCodeDto($row);
    }

    public function accept(int $uid, array $data): array
    {
        $token = strtolower(trim((string)($data['acquisition_token'] ?? '')));
        if ($uid <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new ApiException('store_acquisition_code_invalid');
        }
        $requestId = $this->requestId($data, 'accept');
        $idempotencyKey = trim((string)($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new ApiException('authority_idempotency_key_required');
        }

        return Db::transaction(function () use ($uid, $token, $requestId, $idempotencyKey) {
            $code = $this->validCode($token, true);
            $codeId = (int)$code['id'];
            $storeId = (int)$code['store_id'];
            $issuerUid = (int)$code['issuer_uid'];
            $issuerRole = (string)$code['issuer_role_code'];
            if ($uid === $issuerUid) {
                throw new ApiException('store_acquisition_self_bind_forbidden');
            }
            $this->assertIssuerRoleActive($code, true);

            $existing = (array)Db::name('yfth_store_acquisition_acceptance')
                ->where('customer_uid', $uid)->lock(true)->find();
            if ($existing) {
                if ((int)$existing['code_id'] === $codeId && (int)$existing['store_id'] === $storeId) {
                    return $this->acceptanceDto($existing, true);
                }
                throw new ApiException('store_acquisition_customer_already_bound');
            }

            $current = app()->make(HqCustomerAttributionServices::class)->lockCurrents([$uid])[$uid];
            // The authority-current lock serializes two first-bind requests for the same customer.
            $existing = (array)Db::name('yfth_store_acquisition_acceptance')
                ->where('customer_uid', $uid)->find();
            if ($existing) {
                if ((int)$existing['code_id'] === $codeId && (int)$existing['store_id'] === $storeId) {
                    return $this->acceptanceDto($existing, true);
                }
                throw new ApiException('store_acquisition_customer_already_bound');
            }
            if ((string)$current['status'] !== 'unassigned' || (int)$current['authority_version'] !== 0) {
                throw new ApiException('store_acquisition_customer_already_attributed');
            }

            $now = time();
            $acceptance = [
                'acceptance_no' => $this->makeNo('YFSAA'),
                'code_id' => $codeId,
                'customer_uid' => $uid,
                'store_id' => $storeId,
                'issuer_uid' => $issuerUid,
                'issuer_role_code' => $issuerRole,
                'attribution_current_id' => 0,
                'customer_relation_id' => 0,
                'status' => 'accepted',
                'request_id' => $requestId,
                'accepted_at' => $now,
                'add_time' => $now,
                'update_time' => $now,
            ];
            $acceptance['id'] = (int)Db::name('yfth_store_acquisition_acceptance')->insertGetId($acceptance);
            $source = HqAuthoritySource::fromTrusted('store_acquisition_code', $acceptance['id'], []);
            $mutation = new HqAuthorityMutation(
                $source, $uid, 'customer', 'store_acquisition_code_accepted', $requestId, $idempotencyKey
            );
            $attribution = app()->make(HqCustomerAttributionServices::class)
                ->assignFirstWithLockedCurrentsInTransaction($uid, $storeId, $mutation, [$uid => $current]);
            $projection = app()->make(FranchiseCustomerServices::class)->syncAuthorityCustomerInTransaction(
                $uid, $storeId, 'store_acquisition', $acceptance['id'], $issuerUid,
                $uid, 'customer', 'store_acquisition_code_accepted', $requestId
            );
            $acceptance['attribution_current_id'] = (int)($attribution['after']['id'] ?? 0);
            $acceptance['customer_relation_id'] = (int)($projection['relation']['id'] ?? 0);
            Db::name('yfth_store_acquisition_acceptance')->where('id', $acceptance['id'])->update([
                'attribution_current_id' => $acceptance['attribution_current_id'],
                'customer_relation_id' => $acceptance['customer_relation_id'],
                'update_time' => time(),
            ]);
            app()->make(AuditEventServices::class)->recordSafely(
                self::DOMAIN, 'store_acquisition_acceptance', (string)$acceptance['id'], 'accept', [],
                $this->acceptanceAuditDto($acceptance), $uid, 'customer', $storeId,
                'store_acquisition_code_accepted', $requestId
            );
            return $this->acceptanceDto($acceptance, false);
        });
    }

    private function assertIssuerContext(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        if (!in_array((string)$context['role_code'], self::ROLES, true) || (int)$context['store_id'] <= 0) {
            throw new ApiException('store_acquisition_role_forbidden');
        }
        $this->assertIssuerRoleActive([
            'issuer_uid' => (int)$context['uid'],
            'store_id' => (int)$context['store_id'],
            'issuer_role_code' => (string)$context['role_code'],
        ], false);
        return $context;
    }

    private function assertIssuerRoleActive(array $code, bool $lock): void
    {
        $query = Db::name('yfth_user_store_role')
            ->where('uid', (int)$code['issuer_uid'])
            ->where('store_id', (int)$code['store_id'])
            ->where('role_code', (string)$code['issuer_role_code'])
            ->where('status', 'active');
        if ($lock) {
            $query->lock(true);
        }
        if (!$query->find()) {
            throw new ApiException('store_acquisition_issuer_role_inactive');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive((int)$code['store_id']);
    }

    private function validCode(string $token, bool $lock): array
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new ApiException('store_acquisition_code_invalid');
        }
        $query = Db::name('yfth_store_acquisition_code')->where('token_hash', hash('sha256', $token));
        if ($lock) {
            $query->lock(true);
        }
        $row = (array)$query->find();
        if (!$row || (string)$row['status'] !== 'active' || (int)$row['expires_at'] <= time()) {
            throw new ApiException('store_acquisition_code_unavailable');
        }
        $this->assertIssuerRoleActive($row, $lock);
        return $row;
    }

    private function codeDto(array $row): array
    {
        return array_merge($this->publicCodeDto($row), [
            'code_no' => (string)$row['code_no'],
            'issued_at' => (int)$row['issued_at'],
        ]);
    }

    private function publicCodeDto(array $row): array
    {
        $store = (array)Db::name('system_store')->where('id', (int)$row['store_id'])->find();
        $user = (array)Db::name('user')->where('uid', (int)$row['issuer_uid'])->field('nickname,avatar')->find();
        return [
            'store_id' => (int)$row['store_id'],
            'store_name' => (string)($store['name'] ?? ''),
            'issuer_name' => (string)($user['nickname'] ?? ''),
            'issuer_avatar' => (string)($user['avatar'] ?? ''),
            'issuer_role_code' => (string)$row['issuer_role_code'],
            'issuer_role_name' => (string)$row['issuer_role_code'] === 'store_manager' ? '店长' : '店员',
            'expires_at' => (int)$row['expires_at'],
        ];
    }

    private function acceptanceDto(array $row, bool $replay): array
    {
        $storeName = (string)Db::name('system_store')->where('id', (int)$row['store_id'])->value('name');
        $issuerName = (string)Db::name('user')->where('uid', (int)$row['issuer_uid'])->value('nickname');
        return [
            'accepted' => true,
            'idempotent_replay' => $replay,
            'store_id' => (int)$row['store_id'],
            'store_name' => $storeName,
            'source_employee_name' => $issuerName,
            'source_role_name' => (string)$row['issuer_role_code'] === 'store_manager' ? '店长' : '店员',
            'customer_relation_id' => (int)($row['customer_relation_id'] ?? 0),
        ];
    }

    private function codeAuditDto(array $row): array
    {
        return [
            'code_no' => (string)$row['code_no'], 'store_id' => (int)$row['store_id'],
            'issuer_uid' => (int)$row['issuer_uid'], 'issuer_role_code' => (string)$row['issuer_role_code'],
            'expires_at' => (int)$row['expires_at'],
        ];
    }

    private function acceptanceAuditDto(array $row): array
    {
        return [
            'code_id' => (int)$row['code_id'], 'customer_uid' => (int)$row['customer_uid'],
            'store_id' => (int)$row['store_id'], 'issuer_uid' => (int)$row['issuer_uid'],
            'issuer_role_code' => (string)$row['issuer_role_code'],
            'attribution_current_id' => (int)$row['attribution_current_id'],
            'customer_relation_id' => (int)$row['customer_relation_id'],
        ];
    }

    private function requestId(array $data, string $prefix): string
    {
        $requestId = trim((string)($data['request_id'] ?? ''));
        return $requestId !== '' ? substr($requestId, 0, 64) : $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }

    private function miniProgramUrlLink(string $launchPath): string
    {
        $parts = explode('?', ltrim($launchPath, '/'), 2);
        $url = MiniProgramService::getUrlLink([
            'path' => $parts[0],
            'query' => $parts[1] ?? '',
        ]);
        return is_string($url) && preg_match('#^https://wxaurl\.cn/#i', $url) ? $url : '';
    }
}
