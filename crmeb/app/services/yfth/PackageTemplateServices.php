<?php

namespace app\services\yfth;

use app\dao\product\product\StoreProductDao;
use app\dao\product\sku\StoreProductAttrValueDao;
use app\dao\yfth\YfthPackageProductBindingDao;
use app\dao\yfth\YfthPackageRuleVersionDao;
use app\dao\yfth\YfthPackageTemplateDao;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;

class PackageTemplateServices extends PackageBenefitBaseServices
{
    public function __construct(YfthPackageTemplateDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'package_code' => $where['package_code'] ?? '',
            'package_type' => $where['package_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'sort desc,id desc', function ($row) {
            return $this->withCurrentRule($row);
        });
    }

    public function publicList(array $where = []): array
    {
        $where = $this->cleanWhere([
            'status' => 'published',
            'package_type' => $where['package_type'] ?? '',
        ]);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $list = $this->dao->selectList($where, '*', $page, $limit, 'sort desc,id desc', [], false)->toArray();
        $list = array_map(function ($row) {
            return $this->publicTemplateRow($row);
        }, $list);
        return ['list' => $list, 'count' => $this->dao->getCount($where)];
    }

    public function publicDetail(int $templateId): array
    {
        $template = $this->requirePublishedTemplate($templateId);
        $detail = $this->publicTemplateRow($template);
        $rule = $this->currentRule($templateId);
        $detail['rule'] = $this->publicRuleRow($rule);
        $detail['bindings'] = $this->activeBindings((int)$template['id'], (int)$rule['id']);
        $detail['benefits'] = $this->ruleBenefits((int)$rule['id']);
        return $detail;
    }

    public function rulePreview(int $templateId, int $ruleVersionId = 0): array
    {
        $template = $this->requirePublishedTemplate($templateId);
        $rule = $ruleVersionId > 0 ? $this->ruleById($ruleVersionId) : $this->currentRule($templateId);
        if ((int)$rule['template_id'] !== $templateId || $rule['status'] !== 'published') {
            throw new ApiException('published_rule_version_not_found');
        }
        return [
            'template' => $this->publicTemplateRow($template),
            'rule' => $this->publicRuleRow($rule),
            'benefits' => $this->ruleBenefits((int)$rule['id']),
            'benefit_hash' => $this->benefitHash((int)$rule['id']),
        ];
    }

    public function saveTemplate(array $data, int $operatorUid = 0)
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeTemplate($data, $id);
        $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
        $objectId = $id ?: (int)$result->id;
        $after = $id ? $this->dao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
        $this->recordPackageAudit('package_template', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin');
        return $result;
    }

    public function saveRuleVersion(array $data, int $operatorUid = 0)
    {
        /** @var YfthPackageRuleVersionDao $ruleDao */
        $ruleDao = app()->make(YfthPackageRuleVersionDao::class);
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $ruleDao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeRuleVersion($data, $id, $operatorUid, $before ? $before->toArray() : []);

        return $this->transaction(function () use ($ruleDao, $id, $data, $before, $operatorUid) {
            $this->assertOnePublishedRule($data, $id);
            $result = $id ? $ruleDao->update($id, $data) : $ruleDao->save($data);
            $objectId = $id ?: (int)$result->id;
            if ($data['status'] === 'published') {
                $this->dao->update((int)$data['template_id'], [
                    'status' => 'published',
                    'current_rule_version_id' => $objectId,
                    'base_price' => $data['package_price'],
                    'benefit_months' => $data['month_count'],
                    'publish_time' => time(),
                    'update_time' => time(),
                ]);
            }
            $after = $id ? $ruleDao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
            $this->recordPackageAudit('package_rule_version', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin');
            return $result;
        });
    }

    public function saveProductBinding(array $data, int $operatorUid = 0)
    {
        /** @var YfthPackageProductBindingDao $bindingDao */
        $bindingDao = app()->make(YfthPackageProductBindingDao::class);
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $bindingDao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeProductBinding($data, $id);

        return $this->transaction(function () use ($bindingDao, $id, $data, $before, $operatorUid) {
            $this->assertOneActiveBinding($data, $id);
            $result = $id ? $bindingDao->update($id, $data) : $bindingDao->save($data);
            $objectId = $id ?: (int)$result->id;
            $after = $id ? $bindingDao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
            $this->recordPackageAudit('package_product_binding', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin');
            return $result;
        });
    }

    public function currentRule(int $templateId): array
    {
        /** @var YfthPackageRuleVersionDao $ruleDao */
        $ruleDao = app()->make(YfthPackageRuleVersionDao::class);
        $rule = $ruleDao->search([])
            ->where('template_id', $templateId)
            ->where('status', 'published')
            ->where(function ($query) {
                $query->where('effective_time', '=', 0)->whereOr('effective_time', '<=', time());
            })
            ->where(function ($query) {
                $query->where('expire_time', '=', 0)->whereOr('expire_time', '>', time());
            })
            ->order('version_no desc,id desc')
            ->find();
        return $this->requireRow($rule, 'published_rule_version_not_found');
    }

    public function ruleById(int $ruleVersionId): array
    {
        /** @var YfthPackageRuleVersionDao $ruleDao */
        $ruleDao = app()->make(YfthPackageRuleVersionDao::class);
        return $this->requireRow($ruleDao->get($ruleVersionId), 'rule_version_not_found');
    }

    public function activeBinding(int $templateId, int $ruleVersionId, int $productId, string $productAttrUnique): array
    {
        /** @var YfthPackageProductBindingDao $bindingDao */
        $bindingDao = app()->make(YfthPackageProductBindingDao::class);
        $binding = $bindingDao->getOne([
            'template_id' => $templateId,
            'rule_version_id' => $ruleVersionId,
            'product_id' => $productId,
            'product_attr_unique' => $productAttrUnique,
            'binding_status' => 'active',
        ]);
        return $this->requireRow($binding, 'active_package_product_binding_not_found');
    }

    public function activeBindings(int $templateId, int $ruleVersionId): array
    {
        /** @var YfthPackageProductBindingDao $bindingDao */
        $bindingDao = app()->make(YfthPackageProductBindingDao::class);
        $rows = $bindingDao->selectList([
            'template_id' => $templateId,
            'rule_version_id' => $ruleVersionId,
            'binding_status' => 'active',
        ], '*', 0, 0, 'id desc', [], false)->toArray();
        return array_map(function ($row) {
            $row['product_snapshot'] = $this->jsonDecode($row['product_snapshot'] ?? '');
            return $row;
        }, $rows);
    }

    public function ruleBenefits(int $ruleVersionId): array
    {
        /** @var BenefitTemplateServices $benefitServices */
        $benefitServices = app()->make(BenefitTemplateServices::class);
        return $benefitServices->rulesForVersion($ruleVersionId);
    }

    public function benefitHash(int $ruleVersionId): string
    {
        return hash('sha256', $this->jsonEncode($this->ruleBenefits($ruleVersionId)));
    }

    public function requirePublishedTemplate(int $templateId): array
    {
        $template = $this->requireRow($this->dao->get($templateId), 'package_template_not_found');
        if ($template['status'] !== 'published') {
            throw new ApiException('package_template_not_published');
        }
        return $template;
    }

    private function normalizeTemplate(array $data, int $id): array
    {
        $data['package_code'] = trim((string)($data['package_code'] ?? ''));
        $data['package_name'] = trim((string)($data['package_name'] ?? ''));
        if ($data['package_code'] === '' || $data['package_name'] === '') {
            throw new AdminException('package_code_and_name_required');
        }
        $data['package_title'] = trim((string)($data['package_title'] ?? $data['package_name']));
        $data['package_type'] = trim((string)($data['package_type'] ?? 'health_package')) ?: 'health_package';
        $data['base_price'] = $this->normalizeMoney($data['base_price'] ?? '0.00');
        $data['currency'] = trim((string)($data['currency'] ?? 'CNY')) ?: 'CNY';
        $data['benefit_months'] = (int)($data['benefit_months'] ?? 0);
        $data['service_summary'] = (string)($data['service_summary'] ?? '');
        $data['agreement_title'] = trim((string)($data['agreement_title'] ?? ''));
        $data['agreement_content'] = (string)($data['agreement_content'] ?? '');
        $data['status'] = trim((string)($data['status'] ?? 'draft')) ?: 'draft';
        $data['current_rule_version_id'] = (int)($data['current_rule_version_id'] ?? 0);
        $data['publish_time'] = $this->parseTime($data['publish_time'] ?? 0);
        $data['sort'] = (int)($data['sort'] ?? 0);
        return $this->withTimestamps($data, $id === 0);
    }

    private function normalizeRuleVersion(array $data, int $id, int $operatorUid, array $before): array
    {
        $data['template_id'] = (int)($data['template_id'] ?? 0);
        $template = $this->requireRow($this->dao->get($data['template_id']), 'package_template_not_found');
        $data['version_no'] = (int)($data['version_no'] ?? 0);
        if ($data['version_no'] <= 0) {
            $data['version_no'] = $this->nextRuleVersionNo($data['template_id']);
        }
        $data['rule_code'] = trim((string)($data['rule_code'] ?? ('RULE-' . $data['template_id'] . '-' . $data['version_no'])));
        $data['status'] = trim((string)($data['status'] ?? 'draft')) ?: 'draft';
        $data['package_price'] = $this->normalizeMoney($data['package_price'] ?? $template['base_price']);
        $data['month_count'] = (int)($data['month_count'] ?? $template['benefit_months']);
        if ($data['month_count'] <= 0) {
            throw new AdminException('month_count_required');
        }
        $data['benefit_rule_snapshot'] = $this->jsonEncode($data['benefit_rule_snapshot'] ?? []);
        $agreementContent = (string)($data['agreement_content'] ?? $template['agreement_content'] ?? '');
        $data['agreement_title'] = trim((string)($data['agreement_title'] ?? $template['agreement_title'] ?? ''));
        $data['agreement_content_summary'] = $this->summaryText($agreementContent);
        $data['agreement_content_hash'] = hash('sha256', $agreementContent);
        unset($data['agreement_content']);
        $data['created_uid'] = (int)($before['created_uid'] ?? 0) ?: $operatorUid;
        $data['publish_uid'] = $data['status'] === 'published' ? $operatorUid : (int)($before['publish_uid'] ?? 0);
        $data['publish_time'] = $data['status'] === 'published' ? time() : (int)($before['publish_time'] ?? 0);
        $data['effective_time'] = $this->parseTime($data['effective_time'] ?? 0);
        $data['expire_time'] = $this->parseTime($data['expire_time'] ?? 0);
        $data['active_key'] = $data['status'] === 'published' ? $data['template_id'] . ':published' : null;
        return $this->withTimestamps($data, $id === 0);
    }

    private function normalizeProductBinding(array $data, int $id): array
    {
        foreach (['template_id', 'rule_version_id', 'product_id'] as $field) {
            $data[$field] = (int)($data[$field] ?? 0);
        }
        $data['product_attr_unique'] = trim((string)($data['product_attr_unique'] ?? ''));
        if ($data['template_id'] <= 0 || $data['rule_version_id'] <= 0 || $data['product_id'] <= 0 || $data['product_attr_unique'] === '') {
            throw new AdminException('template_rule_product_and_sku_required');
        }
        $template = $this->requireRow($this->dao->get($data['template_id']), 'package_template_not_found');
        $rule = $this->ruleById($data['rule_version_id']);
        if ((int)$rule['template_id'] !== (int)$template['id']) {
            throw new AdminException('rule_version_template_mismatch');
        }

        [$product, $sku] = $this->assertProductSkuActive($data['product_id'], $data['product_attr_unique']);
        $data['sku_price_snapshot'] = $this->normalizeMoney($data['sku_price_snapshot'] ?? $sku['price']);
        if (!$this->moneyEquals($data['sku_price_snapshot'], $rule['package_price'])) {
            throw new AdminException('sku_price_must_equal_rule_price');
        }
        $data['product_snapshot'] = $this->jsonEncode([
            'product_id' => (int)$product['id'],
            'product_name' => (string)$product['store_name'],
            'product_image' => (string)($product['image'] ?? ''),
            'sku_unique' => (string)$sku['unique'],
            'sku_name' => (string)($sku['suk'] ?? ''),
            'sku_price' => $this->normalizeMoney($sku['price']),
            'rule_version_id' => (int)$rule['id'],
        ]);
        $data['binding_status'] = trim((string)($data['binding_status'] ?? 'active')) ?: 'active';
        $data['active_key'] = $data['binding_status'] === 'active' ? $data['product_id'] . ':' . $data['product_attr_unique'] : null;
        return $this->withTimestamps($data, $id === 0);
    }

    public function assertProductSkuActive(int $productId, string $productAttrUnique): array
    {
        /** @var StoreProductDao $productDao */
        $productDao = app()->make(StoreProductDao::class);
        /** @var StoreProductAttrValueDao $skuDao */
        $skuDao = app()->make(StoreProductAttrValueDao::class);
        $product = $this->requireRow($productDao->get($productId), 'product_not_found');
        if ((int)($product['is_del'] ?? 0) !== 0 || (int)($product['is_show'] ?? 0) !== 1) {
            throw new ApiException('product_not_active');
        }
        $sku = $this->requireRow($skuDao->getOne(['product_id' => $productId, 'unique' => $productAttrUnique, 'type' => 0]), 'sku_not_found');
        if ((int)($sku['is_show'] ?? 1) !== 1) {
            throw new ApiException('sku_not_active');
        }
        return [$product, $sku];
    }

    private function assertOnePublishedRule(array $data, int $id): void
    {
        if (!$data['active_key']) {
            return;
        }
        /** @var YfthPackageRuleVersionDao $ruleDao */
        $ruleDao = app()->make(YfthPackageRuleVersionDao::class);
        $existing = $ruleDao->getOne(['active_key' => $data['active_key']]);
        if ($existing && (int)$existing['id'] !== $id) {
            throw new AdminException('published_rule_version_exists');
        }
    }

    private function assertOneActiveBinding(array $data, int $id): void
    {
        if (!$data['active_key']) {
            return;
        }
        /** @var YfthPackageProductBindingDao $bindingDao */
        $bindingDao = app()->make(YfthPackageProductBindingDao::class);
        $existing = $bindingDao->getOne(['active_key' => $data['active_key']]);
        if ($existing && (int)$existing['id'] !== $id) {
            throw new AdminException('active_package_product_binding_exists');
        }
    }

    private function nextRuleVersionNo(int $templateId): int
    {
        /** @var YfthPackageRuleVersionDao $ruleDao */
        $ruleDao = app()->make(YfthPackageRuleVersionDao::class);
        return (int)$ruleDao->search([])->where('template_id', $templateId)->max('version_no') + 1;
    }

    private function withCurrentRule(array $row): array
    {
        if ((int)($row['current_rule_version_id'] ?? 0) > 0) {
            try {
                $row['current_rule'] = $this->publicRuleRow($this->ruleById((int)$row['current_rule_version_id']));
            } catch (\Throwable $e) {
                $row['current_rule'] = null;
            }
        }
        return $row;
    }

    private function publicTemplateRow(array $row): array
    {
        $row['base_price'] = $this->normalizeMoney($row['base_price'] ?? '0.00');
        unset($row['agreement_content']);
        return $this->withCurrentRule($row);
    }

    private function publicRuleRow(array $row): array
    {
        $row['package_price'] = $this->normalizeMoney($row['package_price'] ?? '0.00');
        $row['benefit_rule_snapshot'] = $this->jsonDecode($row['benefit_rule_snapshot'] ?? '');
        return $row;
    }
}
