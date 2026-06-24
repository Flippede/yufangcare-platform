<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitTemplateDao;
use app\dao\yfth\YfthMonthlyBenefitRuleDao;
use app\dao\yfth\YfthPackagePurchaseBenefitSnapshotDao;
use app\dao\yfth\YfthPackagePurchaseIntentDao;
use app\dao\yfth\YfthPackageRuleVersionDao;
use crmeb\exceptions\AdminException;

class BenefitTemplateServices extends PackageBenefitBaseServices
{
    public function __construct(YfthBenefitTemplateDao $dao)
    {
        $this->dao = $dao;
    }

    public function templateList(array $where): array
    {
        $where = $this->cleanWhere([
            'benefit_code' => $where['benefit_code'] ?? '',
            'benefit_type' => $where['benefit_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'sort desc,id desc');
    }

    public function saveBenefitTemplate(array $data, int $operatorUid = 0)
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        if ($before && $this->isBenefitTemplateImmutable($id)) {
            throw new AdminException('benefit_template_is_published_or_referenced_copy_new_template');
        }
        unset($data['id']);
        $data = $this->normalizeBenefitTemplate($data, $id);
        $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
        $objectId = $id ?: (int)$result->id;
        $after = $id ? $this->dao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
        $this->recordPackageAudit('benefit_template', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin');
        return $result;
    }

    public function monthlyRuleList(array $where): array
    {
        /** @var YfthMonthlyBenefitRuleDao $ruleDao */
        $ruleDao = app()->make(YfthMonthlyBenefitRuleDao::class);
        $where = $this->cleanWhere([
            'template_id' => (int)($where['template_id'] ?? 0) ?: '',
            'rule_version_id' => (int)($where['rule_version_id'] ?? 0) ?: '',
            'month_no' => (int)($where['month_no'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
        ]);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        return [
            'list' => $ruleDao->selectList($where, '*', $page, $limit, 'month_no asc,id asc', [], false)->toArray(),
            'count' => $ruleDao->getCount($where),
        ];
    }

    public function saveMonthlyRule(array $data, int $operatorUid = 0)
    {
        /** @var YfthMonthlyBenefitRuleDao $ruleDao */
        $ruleDao = app()->make(YfthMonthlyBenefitRuleDao::class);
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $ruleDao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeMonthlyRule($data, $id);
        $this->assertRuleVersionMutable((int)$data['rule_version_id'], $id > 0 ? ($before ? $before->toArray() : []) : []);
        $result = $id ? $ruleDao->update($id, $data) : $ruleDao->save($data);
        $objectId = $id ?: (int)$result->id;
        $after = $id ? $ruleDao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
        $this->recordPackageAudit('monthly_benefit_rule', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin');
        return $result;
    }

    public function rulesForVersion(int $ruleVersionId): array
    {
        /** @var YfthMonthlyBenefitRuleDao $ruleDao */
        $ruleDao = app()->make(YfthMonthlyBenefitRuleDao::class);
        $rows = $ruleDao->selectList(['rule_version_id' => $ruleVersionId, 'status' => 'active'], '*', 0, 0, 'month_no asc,id asc', [], false)->toArray();
        $benefitIds = array_values(array_unique(array_map('intval', array_column($rows, 'benefit_template_id'))));
        $templates = $benefitIds ? $this->dao->selectList(['id' => $benefitIds], '*', 0, 0, 'id asc', [], false)->toArray() : [];
        $templates = array_column($templates, null, 'id');
        foreach ($rows as &$row) {
            $template = $templates[(int)$row['benefit_template_id']] ?? [];
            $row['fulfillment_type'] = (string)($template['fulfillment_type'] ?? '');
            $row['unit'] = (string)($template['unit'] ?? '');
            $row['benefit_description'] = (string)($template['description'] ?? '');
        }
        return $rows;
    }

    private function normalizeBenefitTemplate(array $data, int $id): array
    {
        $data['benefit_code'] = trim((string)($data['benefit_code'] ?? ''));
        $data['benefit_name'] = trim((string)($data['benefit_name'] ?? ''));
        if ($data['benefit_code'] === '' || $data['benefit_name'] === '') {
            throw new AdminException('benefit_code_and_name_required');
        }
        $data['benefit_type'] = trim((string)($data['benefit_type'] ?? 'service')) ?: 'service';
        $data['fulfillment_type'] = trim((string)($data['fulfillment_type'] ?? 'manual')) ?: 'manual';
        $data['unit'] = trim((string)($data['unit'] ?? 'item')) ?: 'item';
        $data['description'] = (string)($data['description'] ?? '');
        $data['status'] = trim((string)($data['status'] ?? 'active')) ?: 'active';
        $data['sort'] = (int)($data['sort'] ?? 0);
        return $this->withTimestamps($data, $id === 0);
    }

    private function normalizeMonthlyRule(array $data, int $id): array
    {
        foreach (['template_id', 'rule_version_id', 'month_no', 'benefit_template_id', 'available_offset_days', 'expire_offset_days'] as $field) {
            $data[$field] = (int)($data[$field] ?? 0);
        }
        if ($data['template_id'] <= 0 || $data['rule_version_id'] <= 0 || $data['month_no'] <= 0 || $data['benefit_template_id'] <= 0) {
            throw new AdminException('template_rule_month_and_benefit_are_required');
        }

        /** @var YfthPackageRuleVersionDao $ruleVersionDao */
        $ruleVersionDao = app()->make(YfthPackageRuleVersionDao::class);
        $ruleVersion = $this->requireRow($ruleVersionDao->get($data['rule_version_id']), 'rule_version_not_found');
        if ((int)$ruleVersion['template_id'] !== $data['template_id']) {
            throw new AdminException('rule_version_template_mismatch');
        }
        if ($data['month_no'] > (int)$ruleVersion['month_count']) {
            throw new AdminException('month_no_exceeds_rule_month_count');
        }

        $benefit = $this->requireRow($this->dao->get($data['benefit_template_id']), 'benefit_template_not_found');
        $data['benefit_code'] = $benefit['benefit_code'];
        $data['benefit_name'] = $benefit['benefit_name'];
        $data['benefit_type'] = $benefit['benefit_type'];
        $data['quantity'] = $this->normalizeMoney($data['quantity'] ?? '1.00');
        $data['per_limit'] = $this->normalizeMoney($data['per_limit'] ?? '0.00');
        $data['service_capability'] = trim((string)($data['service_capability'] ?? ''));
        $data['status'] = trim((string)($data['status'] ?? 'active')) ?: 'active';
        return $this->withTimestamps($data, $id === 0);
    }

    private function assertRuleVersionMutable(int $ruleVersionId, array $before = []): void
    {
        /** @var YfthPackageRuleVersionDao $ruleVersionDao */
        $ruleVersionDao = app()->make(YfthPackageRuleVersionDao::class);
        $ruleVersion = $this->requireRow($ruleVersionDao->get($ruleVersionId), 'rule_version_not_found');
        if ((string)$ruleVersion['status'] === 'published' || $this->isRuleReferenced($ruleVersionId)) {
            throw new AdminException('published_or_referenced_monthly_rule_is_immutable_copy_new_rule_version');
        }
        if ($before && (int)$before['rule_version_id'] !== $ruleVersionId && $this->isRuleReferenced((int)$before['rule_version_id'])) {
            throw new AdminException('referenced_monthly_rule_is_immutable_copy_new_rule_version');
        }
    }

    private function isRuleReferenced(int $ruleVersionId): bool
    {
        /** @var YfthPackagePurchaseIntentDao $intentDao */
        $intentDao = app()->make(YfthPackagePurchaseIntentDao::class);
        if ($intentDao->count(['rule_version_id' => $ruleVersionId], false) > 0) {
            return true;
        }
        /** @var YfthPackagePurchaseBenefitSnapshotDao $snapshotDao */
        $snapshotDao = app()->make(YfthPackagePurchaseBenefitSnapshotDao::class);
        return $snapshotDao->count(['rule_version_id' => $ruleVersionId], false) > 0;
    }

    private function isBenefitTemplateImmutable(int $benefitTemplateId): bool
    {
        /** @var YfthMonthlyBenefitRuleDao $ruleDao */
        $ruleDao = app()->make(YfthMonthlyBenefitRuleDao::class);
        /** @var YfthPackageRuleVersionDao $ruleVersionDao */
        $ruleVersionDao = app()->make(YfthPackageRuleVersionDao::class);
        $rules = $ruleDao->selectList(['benefit_template_id' => $benefitTemplateId], 'rule_version_id', 0, 0, 'id asc', [], false)->toArray();
        foreach ($rules as $rule) {
            $ruleVersion = $ruleVersionDao->get((int)$rule['rule_version_id']);
            if ($ruleVersion && ((string)$ruleVersion['status'] === 'published' || $this->isRuleReferenced((int)$rule['rule_version_id']))) {
                return true;
            }
        }
        /** @var YfthPackagePurchaseBenefitSnapshotDao $snapshotDao */
        $snapshotDao = app()->make(YfthPackagePurchaseBenefitSnapshotDao::class);
        return $snapshotDao->count(['benefit_template_id' => $benefitTemplateId], false) > 0;
    }
}
