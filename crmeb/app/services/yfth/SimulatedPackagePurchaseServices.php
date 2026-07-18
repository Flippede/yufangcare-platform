<?php

namespace app\services\yfth;

use app\services\user\UserServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class SimulatedPackagePurchaseServices extends PackageBenefitBaseServices
{
    private const PACKAGE_CODE = 'YFTH-TEST-PACKAGE-V1';
    private const TEST_MARKER = '[YFTH-ACCEPTANCE-TEST-V1]';
    private const SIMULATION_PRICE = '0.10';
    private const SOURCE = 'controlled_simulated_purchase';

    public function context(int $uid, int $templateId): array
    {
        $this->assertEnabled();
        if ($uid <= 0) {
            throw new ApiException('user_login_required');
        }

        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $this->requireRow($userServices->get($uid), 'user_not_found');
        if (trim((string)($user['phone'] ?? '')) === '') {
            throw new ApiException('user_phone_binding_required');
        }

        [$template, $rule] = $this->simulationDefinition($templateId);
        $membership = app()->make(PackageMembershipServices::class)->effectiveMembership($uid);
        $storeId = app()->make(PackageMembershipReferralServices::class)
            ->resolveAuthoritativeStoreForPurchase($uid, 0);
        if ($storeId <= 0 && !empty($membership['member']['store_id'])) {
            $storeId = (int)$membership['member']['store_id'];
        }
        $store = $storeId > 0
            ? app()->make(StoreAccessServices::class)->assertStoreActive($storeId)
            : [];
        $isMember = (bool)$membership['is_member'];

        return [
            'simulation_only' => true,
            'template_id' => (int)$template['id'],
            'package_code' => (string)$template['package_code'],
            'package_name' => (string)$template['package_name'],
            'package_title' => (string)$template['package_title'],
            'rule_version_id' => (int)$rule['id'],
            'price' => self::SIMULATION_PRICE,
            'currency' => (string)($template['currency'] ?? 'CNY'),
            'month_count' => (int)$rule['month_count'],
            'is_member' => $isMember,
            'store_bound' => $storeId > 0,
            'store' => $store,
            'can_simulate' => !$isMember && $storeId > 0,
            'unavailable_reason' => $isMember
                ? '该账号已是永久会员，无需重复购买'
                : ($storeId > 0 ? '' : '尚未绑定上级商家，请先扫描商家获客码完成归属'),
            'notice' => '本流程仅记录0.1元模拟购买事实，不发起微信支付、短信或真实扣款。',
        ];
    }

    public function simulate(int $uid, array $data): array
    {
        $templateId = (int)($data['template_id'] ?? 0);
        if (empty($data['agreement_accepted'])) {
            throw new ApiException('package_agreement_must_be_accepted');
        }

        $result = Db::transaction(function () use ($uid, $templateId, $data) {
            $lockedUser = Db::name('user')->where('uid', $uid)->lock(true)->find();
            if (!$lockedUser) {
                throw new ApiException('user_not_found');
            }

            $existing = Db::name('yfth_package_purchase')
                ->where('uid', $uid)
                ->where('template_id', $templateId)
                ->where('source', self::SOURCE)
                ->lock(true)
                ->find();
            if ($existing) {
                if ((string)$existing['activation_status'] !== 'succeeded' || (int)$existing['instance_id'] <= 0) {
                    throw new ApiException('simulated_package_purchase_incomplete_contact_support');
                }
                return $this->resultDto($existing, true);
            }

            $context = $this->context($uid, $templateId);
            if ($context['is_member']) {
                throw new ApiException('permanent_member_cannot_repeat_simulated_purchase');
            }
            if (!$context['store_bound']) {
                throw new ApiException('simulated_purchase_authoritative_store_required');
            }

            [$template, $rule] = $this->simulationDefinition($templateId);
            $now = time();
            $store = (array)$context['store'];
            $storeId = (int)$store['store_id'];
            $requestId = trim((string)($data['request_id'] ?? ''));
            $requestId = $requestId !== '' ? substr($requestId, 0, 64) : $this->makeNo('YFSIMREQ');
            $agreementId = $this->createAgreementSnapshot($uid, $storeId, $template, $rule, $now);

            $purchaseNo = $this->makeNo('YFSIM');
            $purchaseId = (int)Db::name('yfth_package_purchase')->insertGetId([
                'purchase_no' => $purchaseNo,
                'uid' => $uid,
                'store_id' => $storeId,
                'template_id' => (int)$template['id'],
                'rule_version_id' => (int)$rule['id'],
                'product_id' => 0,
                'product_attr_unique' => '',
                'order_id' => 0,
                'order_sn' => '',
                'order_unique_key' => null,
                'order_sn_unique_key' => null,
                'expected_pay_price' => self::SIMULATION_PRICE,
                'order_pay_price' => self::SIMULATION_PRICE,
                'payment_scene' => 'simulated_acceptance',
                'route_snapshot' => $this->jsonEncode([
                    'simulation_only' => true,
                    'test_marker' => self::TEST_MARKER,
                    'real_payment_created' => false,
                ]),
                'agreement_snapshot_id' => $agreementId,
                'validation_snapshot' => $this->jsonEncode([
                    'authoritative_store' => $store,
                    'simulation_only' => true,
                    'test_marker' => self::TEST_MARKER,
                ]),
                'purchase_status' => 'paid',
                'activation_status' => 'pending',
                'instance_id' => 0,
                'idempotency_key' => 'simulated-package:' . $uid . ':' . $templateId,
                'source' => self::SOURCE,
                'add_time' => $now,
                'update_time' => $now,
            ]);
            $purchase = Db::name('yfth_package_purchase')->where('id', $purchaseId)->find();
            $snapshotId = $this->createPurchaseSnapshot($purchase, $template, $rule, $store, $agreementId, $now);
            Db::name('yfth_package_purchase')->where('id', $purchaseId)->update([
                'snapshot_id' => $snapshotId,
                'update_time' => $now,
            ]);
            $purchase['snapshot_id'] = $snapshotId;

            $instanceId = (int)Db::name('yfth_package_instance')->insertGetId([
                'instance_no' => $this->makeNo('YFSIMI'),
                'purchase_id' => $purchaseId,
                'uid' => $uid,
                'store_id' => $storeId,
                'template_id' => (int)$template['id'],
                'rule_version_id' => (int)$rule['id'],
                'order_id' => 0,
                'order_sn' => '',
                'order_unique_key' => null,
                'plan_id' => 0,
                'status' => 'active',
                'refund_status' => 'none',
                'fulfilled_count' => 0,
                'start_time' => $now,
                'end_time' => strtotime('+' . max(1, (int)$rule['month_count']) . ' months', $now),
                'activated_time' => $now,
                'close_reason' => '',
                'rule_snapshot' => $this->jsonEncode([
                    'rule_version_id' => (int)$rule['id'],
                    'package_price' => self::SIMULATION_PRICE,
                    'grants_permanent_membership' => 1,
                    'simulation_only' => true,
                    'test_marker' => self::TEST_MARKER,
                ]),
                'store_snapshot' => $this->jsonEncode($store),
                'add_time' => $now,
                'update_time' => $now,
            ]);

            $activation = app()->make(PackageMembershipActivationCoordinator::class)->activateInTransaction(
                $purchase,
                [
                    'order_pay_price' => self::SIMULATION_PRICE,
                    'currency' => (string)($template['currency'] ?? 'CNY'),
                    'paid_time' => $now,
                    'grants_permanent_membership' => 1,
                    'simulation_only' => true,
                ],
                $instanceId
            );

            Db::name('yfth_package_purchase')->where('id', $purchaseId)->update([
                'activation_status' => 'succeeded',
                'instance_id' => $instanceId,
                'last_activation_error' => '',
                'update_time' => time(),
            ]);
            $purchase['activation_status'] = 'succeeded';
            $purchase['instance_id'] = $instanceId;
            $purchase['store'] = $store;
            $purchase['activation'] = $activation;
            $purchase['request_id'] = $requestId;
            return $this->resultDto($purchase, false);
        });

        $this->recordPackageAudit(
            'simulated_package_purchase',
            (string)$result['purchase_no'],
            $result['idempotent_replay'] ? 'replay' : 'activate',
            [],
            $result,
            $uid,
            'customer',
            (int)$result['store']['store_id'],
            '0.1元受控模拟购买',
            (string)($data['request_id'] ?? '')
        );
        return $result;
    }

    private function simulationDefinition(int $templateId): array
    {
        if ($templateId <= 0) {
            throw new ApiException('package_template_required');
        }
        /** @var PackageTemplateServices $templates */
        $templates = app()->make(PackageTemplateServices::class);
        $template = $templates->requirePublishedTemplate($templateId);
        if ((string)$template['package_code'] !== self::PACKAGE_CODE) {
            throw new ApiException('simulated_purchase_test_package_only');
        }
        $rule = $templates->currentRule($templateId);
        $snapshot = $this->jsonDecode($rule['benefit_rule_snapshot'] ?? '');
        $decision = app()->make(PackageMembershipGrantPolicy::class)->forRule($rule);
        if (($snapshot['test_marker'] ?? '') !== self::TEST_MARKER
            || !$this->moneyEquals($rule['package_price'], self::SIMULATION_PRICE)
            || empty($decision['grants_permanent_membership'])) {
            throw new ApiException('simulated_package_rule_not_ready');
        }
        return [$template, $rule];
    }

    private function createAgreementSnapshot(int $uid, int $storeId, array $template, array $rule, int $now): int
    {
        return (int)Db::name('yfth_package_agreement_snapshot')->insertGetId([
            'uid' => $uid,
            'store_id' => $storeId,
            'template_id' => (int)$template['id'],
            'rule_version_id' => (int)$rule['id'],
            'template_version' => (int)$rule['version_no'],
            'agreement_title' => (string)$rule['agreement_title'],
            'content_summary' => (string)$rule['agreement_content_summary'],
            'content_hash' => (string)$rule['agreement_content_hash'],
            'source' => 'simulated_acceptance',
            'ip' => app()->request ? app()->request->ip() : '',
            'user_agent' => app()->request ? substr((string)app()->request->header('user-agent', ''), 0, 255) : '',
            'accepted_time' => $now,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }

    private function createPurchaseSnapshot(
        array $purchase,
        array $template,
        array $rule,
        array $store,
        int $agreementId,
        int $now
    ): int {
        $payload = [
            'simulation_only' => true,
            'test_marker' => self::TEST_MARKER,
            'real_payment_created' => false,
            'authoritative_store' => $store,
        ];
        return (int)Db::name('yfth_package_purchase_snapshot')->insertGetId([
            'purchase_id' => (int)$purchase['id'],
            'intent_id' => 0,
            'uid' => (int)$purchase['uid'],
            'store_id' => (int)$purchase['store_id'],
            'template_id' => (int)$template['id'],
            'rule_version_id' => (int)$rule['id'],
            'rule_version_no' => (int)$rule['version_no'],
            'package_code' => (string)$template['package_code'],
            'package_name' => (string)$template['package_name'],
            'package_title' => (string)$template['package_title'],
            'package_type' => (string)$template['package_type'],
            'package_price' => self::SIMULATION_PRICE,
            'currency' => (string)($template['currency'] ?? 'CNY'),
            'month_count' => (int)$rule['month_count'],
            'grants_permanent_membership' => 1,
            'product_id' => 0,
            'product_attr_unique' => '',
            'product_name' => '受控模拟套餐',
            'sku_name' => '',
            'sku_price' => self::SIMULATION_PRICE,
            'agreement_snapshot_id' => $agreementId,
            'agreement_title' => (string)$rule['agreement_title'],
            'agreement_hash' => (string)$rule['agreement_content_hash'],
            'payment_scene' => 'simulated_acceptance',
            'available_store_ids' => $this->jsonEncode([(int)$store['store_id']]),
            'validation_hash' => hash('sha256', $this->jsonEncode($payload)),
            'order_id' => 0,
            'order_sn' => '',
            'order_pay_price' => self::SIMULATION_PRICE,
            'paid_time' => $now,
            'snapshot_payload' => $this->jsonEncode($payload),
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }

    private function resultDto(array $purchase, bool $replay): array
    {
        $store = $purchase['store'] ?? app()->make(StoreAccessServices::class)
            ->assertStoreActive((int)$purchase['store_id']);
        return [
            'simulation_only' => true,
            'idempotent_replay' => $replay,
            'purchase_no' => (string)$purchase['purchase_no'],
            'purchase_status' => (string)$purchase['purchase_status'],
            'activation_status' => (string)$purchase['activation_status'],
            'instance_id' => (int)$purchase['instance_id'],
            'price' => self::SIMULATION_PRICE,
            'currency' => 'CNY',
            'store' => $store,
            'message' => '0.1元模拟购买已完成，未发生真实扣款。',
        ];
    }

    private function assertEnabled(): void
    {
        if (!filter_var(config('yfth.simulated_package_purchase_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new ApiException('simulated_package_purchase_disabled');
        }
    }
}
