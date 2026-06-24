<?php

namespace app\services\yfth;

use app\dao\yfth\YfthPackageAgreementSnapshotDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use app\dao\yfth\YfthStoreCapabilityDao;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\user\UserServices;
use crmeb\exceptions\ApiException;

class PackagePurchaseServices extends PackageBenefitBaseServices
{
    public function __construct(YfthPackagePurchaseDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'template_id' => (int)($where['template_id'] ?? 0) ?: '',
            'purchase_status' => $where['purchase_status'] ?? '',
            'activation_status' => $where['activation_status'] ?? '',
            'order_sn' => $where['order_sn'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->formatPurchase($row);
        });
    }

    public function createPurchase(int $uid, array $data): array
    {
        $validation = $this->validatePurchaseRequest($uid, $data);

        return $this->transaction(function () use ($uid, $data, $validation) {
            if (!empty($validation['order']['order_id'])) {
                $existing = $this->dao->getOne(['order_id' => (int)$validation['order']['order_id']]);
                if ($existing) {
                    $row = $existing->toArray();
                    if ((int)$row['uid'] !== $uid) {
                        throw new ApiException('order_already_bound_to_other_user');
                    }
                    return $this->formatPurchase($row);
                }
            }

            $agreement = $this->createAgreementSnapshot($uid, $validation, $data);
            $purchaseNo = $this->makeNo('YFP');
            $orderId = (int)($validation['order']['order_id'] ?? 0);
            $purchaseStatus = $orderId > 0 ? 'wait_pay' : 'created';
            $payload = $this->withTimestamps([
                'purchase_no' => $purchaseNo,
                'uid' => $uid,
                'store_id' => (int)$validation['store']['store_id'],
                'template_id' => (int)$validation['template']['id'],
                'rule_version_id' => (int)$validation['rule']['id'],
                'product_id' => (int)$validation['product']['product_id'],
                'product_attr_unique' => (string)$validation['product']['product_attr_unique'],
                'order_id' => $orderId,
                'order_sn' => (string)($validation['order']['order_sn'] ?? ''),
                'expected_pay_price' => $validation['rule']['package_price'],
                'order_pay_price' => (string)($validation['order']['pay_price'] ?? '0.00'),
                'payment_scene' => 'package_5980',
                'route_snapshot' => $this->jsonEncode($validation['payment_route']),
                'agreement_snapshot_id' => (int)$agreement['id'],
                'validation_snapshot' => $this->jsonEncode($validation),
                'purchase_status' => $purchaseStatus,
                'activation_status' => 'pending',
                'instance_id' => 0,
                'idempotency_key' => $orderId > 0 ? 'package_activate:' . $orderId : '',
                'source' => trim((string)($data['source'] ?? 'mobile')) ?: 'mobile',
            ], true);
            $purchase = $this->dao->save($payload);
            $row = array_merge($payload, ['id' => (int)$purchase->id]);
            $this->recordPackageAudit('package_purchase', (string)$purchase->id, 'create', [], $row, $uid, 'customer', (int)$payload['store_id']);
            return $this->formatPurchase($row);
        });
    }

    public function purchaseStatus(int $uid, string $purchaseNo): array
    {
        $purchase = $this->dao->getOne(['purchase_no' => $purchaseNo]);
        $row = $this->requireRow($purchase, 'package_purchase_not_found');
        if ((int)$row['uid'] !== $uid) {
            throw new ApiException('package_purchase_forbidden');
        }
        return $this->formatPurchase($row);
    }

    public function agreementRecord(int $uid, string $purchaseNo): array
    {
        $purchase = $this->purchaseStatus($uid, $purchaseNo);
        /** @var YfthPackageAgreementSnapshotDao $agreementDao */
        $agreementDao = app()->make(YfthPackageAgreementSnapshotDao::class);
        $agreement = $agreementDao->get((int)$purchase['agreement_snapshot_id']);
        return $this->requireRow($agreement, 'agreement_snapshot_not_found');
    }

    public function serviceStores(int $templateId): array
    {
        /** @var PackageTemplateServices $templateServices */
        $templateServices = app()->make(PackageTemplateServices::class);
        $templateServices->requirePublishedTemplate($templateId);

        /** @var YfthStoreCapabilityDao $capabilityDao */
        $capabilityDao = app()->make(YfthStoreCapabilityDao::class);
        $rows = $capabilityDao->selectList([
            'capability_code' => 'package_sale',
            'status' => 'active',
        ], '*', 0, 0, 'store_id asc,id desc', [], false)->toArray();

        $stores = [];
        foreach ($rows as $row) {
            $storeId = (int)$row['store_id'];
            if (isset($stores[$storeId])) {
                continue;
            }
            try {
                $store = app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
                $this->assertStoreReadyForPackage($storeId);
                $stores[$storeId] = $store;
            } catch (\Throwable $e) {
                continue;
            }
        }
        return array_values($stores);
    }

    public function validatePurchaseRequest(int $uid, array $data): array
    {
        if ($uid <= 0) {
            throw new ApiException('user_login_required');
        }
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $user = $this->requireRow($userServices->get($uid), 'user_not_found');
        if (trim((string)($user['phone'] ?? '')) === '') {
            throw new ApiException('user_phone_binding_required');
        }

        $templateId = (int)($data['template_id'] ?? 0);
        $storeId = (int)($data['store_id'] ?? 0);
        $productId = (int)($data['product_id'] ?? 0);
        $skuUnique = trim((string)($data['product_attr_unique'] ?? ''));
        if ($templateId <= 0 || $storeId <= 0 || $productId <= 0 || $skuUnique === '') {
            throw new ApiException('template_store_product_and_sku_required');
        }

        /** @var PackageTemplateServices $templateServices */
        $templateServices = app()->make(PackageTemplateServices::class);
        $template = $templateServices->requirePublishedTemplate($templateId);
        $rule = $templateServices->currentRule($templateId);
        $clientRuleVersionId = (int)($data['rule_version_id'] ?? 0);
        if ($clientRuleVersionId > 0 && $clientRuleVersionId !== (int)$rule['id']) {
            throw new ApiException('client_rule_version_mismatch');
        }
        if (isset($data['client_month_count']) && (int)$data['client_month_count'] !== (int)$rule['month_count']) {
            throw new ApiException('client_month_count_mismatch');
        }
        if (isset($data['client_price']) && !$this->moneyEquals($data['client_price'], $rule['package_price'])) {
            throw new ApiException('client_price_mismatch');
        }
        $benefitHash = $templateServices->benefitHash((int)$rule['id']);
        if (!empty($data['client_benefit_hash']) && !hash_equals($benefitHash, (string)$data['client_benefit_hash'])) {
            throw new ApiException('client_benefit_hash_mismatch');
        }

        $binding = $templateServices->activeBinding((int)$template['id'], (int)$rule['id'], $productId, $skuUnique);
        [$product, $sku] = $templateServices->assertProductSkuActive($productId, $skuUnique);
        if (!$this->moneyEquals($sku['price'], $rule['package_price']) || !$this->moneyEquals($binding['sku_price_snapshot'], $rule['package_price'])) {
            throw new ApiException('package_sku_price_mismatch');
        }

        $store = app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        $storeChecks = $this->assertStoreReadyForPackage($storeId);

        $order = [];
        $orderSn = trim((string)($data['order_sn'] ?? ''));
        if ($orderSn !== '') {
            $order = $this->assertOrderMatchesPackage($uid, $orderSn, $storeId, $productId, $skuUnique, $rule);
        }

        if (empty($data['agreement_accepted'])) {
            throw new ApiException('package_agreement_must_be_accepted');
        }

        return [
            'user' => [
                'uid' => $uid,
                'phone_masked' => $this->maskPhone((string)$user['phone']),
            ],
            'template' => [
                'id' => (int)$template['id'],
                'package_code' => $template['package_code'],
                'package_name' => $template['package_name'],
                'package_title' => $template['package_title'],
            ],
            'rule' => [
                'id' => (int)$rule['id'],
                'version_no' => (int)$rule['version_no'],
                'package_price' => $this->normalizeMoney($rule['package_price']),
                'month_count' => (int)$rule['month_count'],
                'benefit_hash' => $benefitHash,
                'agreement_title' => $rule['agreement_title'],
                'agreement_content_summary' => $rule['agreement_content_summary'],
                'agreement_content_hash' => $rule['agreement_content_hash'],
            ],
            'product' => [
                'product_id' => (int)$product['id'],
                'product_name' => (string)$product['store_name'],
                'product_attr_unique' => $skuUnique,
                'sku_name' => (string)($sku['suk'] ?? ''),
                'sku_price' => $this->normalizeMoney($sku['price']),
                'binding_id' => (int)$binding['id'],
            ],
            'store' => $store,
            'store_checks' => $storeChecks,
            'payment_route' => $storeChecks['payment_route'],
            'order' => $order,
        ];
    }

    private function createAgreementSnapshot(int $uid, array $validation, array $data): array
    {
        /** @var YfthPackageAgreementSnapshotDao $agreementDao */
        $agreementDao = app()->make(YfthPackageAgreementSnapshotDao::class);
        $row = $this->withTimestamps([
            'uid' => $uid,
            'store_id' => (int)$validation['store']['store_id'],
            'template_id' => (int)$validation['template']['id'],
            'rule_version_id' => (int)$validation['rule']['id'],
            'template_version' => (int)$validation['rule']['version_no'],
            'agreement_title' => (string)$validation['rule']['agreement_title'],
            'content_summary' => (string)$validation['rule']['agreement_content_summary'],
            'content_hash' => (string)$validation['rule']['agreement_content_hash'],
            'source' => trim((string)($data['source'] ?? 'mobile')) ?: 'mobile',
            'ip' => app()->request ? app()->request->ip() : '',
            'user_agent' => app()->request ? substr((string)app()->request->header('user-agent', ''), 0, 255) : '',
            'accepted_time' => time(),
        ], true);
        $saved = $agreementDao->save($row);
        $row['id'] = (int)$saved->id;
        return $row;
    }

    private function assertStoreReadyForPackage(int $storeId): array
    {
        /** @var StoreCapabilityServices $capabilityServices */
        $capabilityServices = app()->make(StoreCapabilityServices::class);
        foreach (['package_sale', 'online_payment'] as $capability) {
            if (!$capabilityServices->isAvailable($storeId, $capability)) {
                throw new ApiException('store_capability_missing:' . $capability);
            }
        }

        /** @var StoreSubjectServices $storeSubjectServices */
        $storeSubjectServices = app()->make(StoreSubjectServices::class);
        $subjects = $storeSubjectServices->listActiveByStore($storeId);
        $roles = array_values(array_unique(array_column($subjects, 'subject_role')));
        foreach (['sales', 'payment', 'fulfillment', 'refund'] as $role) {
            if (!in_array($role, $roles, true)) {
                throw new ApiException('store_subject_role_missing:' . $role);
            }
        }

        /** @var StorePaymentRouteServices $routeServices */
        $routeServices = app()->make(StorePaymentRouteServices::class);
        $route = $routeServices->resolveRoute($storeId, 'package_5980');

        return [
            'capabilities' => ['package_sale', 'online_payment'],
            'subject_roles' => $roles,
            'payment_route' => $route,
        ];
    }

    private function assertOrderMatchesPackage(int $uid, string $orderSn, int $storeId, int $productId, string $skuUnique, array $rule): array
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $this->requireRow($orderServices->getOne(['order_id' => $orderSn]), 'order_not_found');
        if ((int)$order['uid'] !== $uid) {
            throw new ApiException('order_user_mismatch');
        }
        if ((int)($order['paid'] ?? 0) !== 0) {
            throw new ApiException('order_already_paid_before_package_binding');
        }
        if ((int)($order['is_del'] ?? 0) !== 0 || (int)($order['is_cancel'] ?? 0) !== 0) {
            throw new ApiException('order_not_payable');
        }
        if ((int)($order['store_id'] ?? 0) > 0 && (int)$order['store_id'] !== $storeId) {
            throw new ApiException('order_store_mismatch');
        }
        if (!$this->moneyEquals($order['pay_price'], $rule['package_price'])) {
            throw new ApiException('order_pay_price_mismatch');
        }

        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $cartInfos = $cartInfoServices->getOrderCartInfo((int)$order['id']);
        $matched = false;
        foreach ($cartInfos as $item) {
            $cart = $item['cart_info'] ?? [];
            $cartProductId = (int)($cart['productInfo']['id'] ?? $item['product_id'] ?? 0);
            $cartSkuUnique = (string)($cart['productInfo']['attrInfo']['unique'] ?? $cart['attrInfo']['unique'] ?? $cart['productAttrUnique'] ?? '');
            if ($cartProductId === $productId && $cartSkuUnique === $skuUnique) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new ApiException('order_product_sku_mismatch');
        }

        return [
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['order_id'],
            'pay_price' => $this->normalizeMoney($order['pay_price']),
            'paid' => (int)$order['paid'],
            'store_id' => (int)$order['store_id'],
        ];
    }

    private function formatPurchase(array $row): array
    {
        $row['expected_pay_price'] = $this->normalizeMoney($row['expected_pay_price'] ?? '0.00');
        $row['order_pay_price'] = $this->normalizeMoney($row['order_pay_price'] ?? '0.00');
        $row['route_snapshot'] = $this->jsonDecode($row['route_snapshot'] ?? '');
        $row['validation_snapshot'] = $this->jsonDecode($row['validation_snapshot'] ?? '');
        return $row;
    }
}
