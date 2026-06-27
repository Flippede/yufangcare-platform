<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitTemplateDao;
use app\dao\yfth\YfthServiceProjectDao;
use crmeb\exceptions\AdminException;

class ServiceProjectServices extends ServiceAppointmentBaseServices
{
    public function __construct(YfthServiceProjectDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'service_code' => $where['service_code'] ?? '',
            'service_type' => $where['service_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'sort desc,id desc', function ($row) {
            return $this->formatProjectRow($row);
        });
    }

    public function publicList(array $where = []): array
    {
        $where = $this->cleanWhere([
            'service_type' => $where['service_type'] ?? '',
            'status' => YfthConstants::STATUS_ACTIVE,
        ]);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $list = $this->dao->selectList($where, '*', $page, $limit, 'sort desc,id desc', [], false)->toArray();
        return [
            'status' => 'ok',
            'list' => array_map(function ($row) {
                return $this->publicProjectRow($row);
            }, $list),
            'count' => $this->dao->getCount($where),
        ];
    }

    public function saveProject(array $data, int $operatorUid = 0, array $adminInfo = [])
    {
        $this->assertHeadquarterScope($adminInfo);
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id']);
        $data = $this->normalizeProject($data, $id, $operatorUid);
        return $this->transaction(function () use ($id, $data, $before, $operatorUid) {
            $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
            $objectId = $id ?: (int)$result->id;
            $after = $id ? $this->dao->get($id)->toArray() : array_merge($data, ['id' => $objectId]);
            $this->recordServiceAudit('service_project', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $after, $operatorUid, 'admin', 0, (string)($data['close_reason'] ?? ''));
            return $result;
        });
    }

    public function disableProject(int $projectId, string $reason, int $operatorUid = 0, array $adminInfo = []): void
    {
        $this->assertHeadquarterScope($adminInfo);
        $before = $this->requireRow($this->dao->get($projectId), 'service_project_not_found');
        if ((string)$before['status'] === YfthConstants::STATUS_DISABLED) {
            return;
        }
        $data = [
            'status' => YfthConstants::STATUS_DISABLED,
            'disabled_uid' => $operatorUid,
            'disabled_time' => time(),
            'updated_uid' => $operatorUid,
            'close_reason' => trim($reason) ?: 'admin_disabled',
            'update_time' => time(),
        ];
        $this->dao->update($projectId, $data);
        $after = $this->dao->get($projectId)->toArray();
        $this->recordServiceAudit('service_project', (string)$projectId, 'disable', $before, $after, $operatorUid, 'admin', 0, $data['close_reason']);
    }

    public function requireActiveProject(int $projectId): array
    {
        $project = $this->requireRow($this->dao->get($projectId), 'service_project_not_found');
        if ((string)$project['status'] !== YfthConstants::STATUS_ACTIVE) {
            throw new \crmeb\exceptions\ApiException('service_project_not_active');
        }
        return $this->formatProjectRow($project);
    }

    public function projectById(int $projectId): array
    {
        return $this->formatProjectRow($this->requireRow($this->dao->get($projectId), 'service_project_not_found'));
    }

    private function normalizeProject(array $data, int $id, int $operatorUid): array
    {
        $data['service_code'] = strtoupper(trim((string)($data['service_code'] ?? '')));
        $data['service_name'] = trim((string)($data['service_name'] ?? ''));
        if ($data['service_code'] === '' || $data['service_name'] === '') {
            throw new AdminException('service_code_and_name_required');
        }
        if (!preg_match('/^[A-Z0-9_\-]{2,64}$/', $data['service_code'])) {
            throw new AdminException('invalid_service_code');
        }
        $existing = $this->dao->getOne(['service_code' => $data['service_code']]);
        if ($existing && (int)$existing['id'] !== $id) {
            throw new AdminException('service_code_already_exists');
        }
        $data['service_type'] = trim((string)($data['service_type'] ?? 'health_service')) ?: 'health_service';
        $data['service_desc'] = (string)($data['service_desc'] ?? '');
        $data['suggested_duration_minutes'] = $this->boundedInt($data['suggested_duration_minutes'] ?? 30, 5, 480, 'invalid_service_duration');
        $data['allow_benefit'] = $this->normalizeBool($data['allow_benefit'] ?? 1);
        $data['required_benefit_type'] = trim((string)($data['required_benefit_type'] ?? 'service')) ?: 'service';
        if ($data['allow_benefit'] && $data['required_benefit_type'] !== 'service') {
            throw new AdminException('service_project_requires_service_benefit_type');
        }
        $data['required_benefit_template_ids'] = $this->normalizeBenefitTemplateIds($data['required_benefit_template_ids'] ?? '');
        $data['allow_paid'] = $this->normalizeBool($data['allow_paid'] ?? 0);
        $data['status'] = $this->normalizeStatus((string)($data['status'] ?? YfthConstants::STATUS_ACTIVE));
        $data['sort'] = (int)($data['sort'] ?? 0);
        $data['updated_uid'] = $operatorUid;
        if ($id === 0) {
            $data['created_uid'] = $operatorUid;
        }
        if ($data['status'] === YfthConstants::STATUS_ACTIVE) {
            $data['disabled_uid'] = 0;
            $data['disabled_time'] = 0;
            $data['close_reason'] = '';
        }
        return $this->withTimestamps($data, $id === 0);
    }

    private function normalizeBenefitTemplateIds($value): string
    {
        if (is_array($value)) {
            $ids = $value;
        } else {
            $ids = preg_split('/[,\s]+/', trim((string)$value));
        }
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
        if (!$ids) {
            return '';
        }
        /** @var YfthBenefitTemplateDao $benefitDao */
        $benefitDao = app()->make(YfthBenefitTemplateDao::class);
        $rows = $benefitDao->search([])
            ->whereIn('id', $ids)
            ->select()
            ->toArray();
        if (count($rows) !== count($ids)) {
            throw new AdminException('benefit_template_not_found');
        }
        foreach ($rows as $row) {
            if ((string)$row['benefit_type'] !== 'service') {
                throw new AdminException('only_service_benefits_can_bind_service_project');
            }
        }
        sort($ids);
        return implode(',', $ids);
    }

    public function formatProjectRow(array $row): array
    {
        $row['required_benefit_template_id_list'] = $this->idList($row['required_benefit_template_ids'] ?? '');
        $row['allow_benefit'] = (int)($row['allow_benefit'] ?? 0);
        $row['allow_paid'] = (int)($row['allow_paid'] ?? 0);
        return $row;
    }

    public function publicProjectRow(array $row): array
    {
        $row = $this->formatProjectRow($row);
        return [
            'id' => (int)$row['id'],
            'service_code' => (string)$row['service_code'],
            'service_name' => (string)$row['service_name'],
            'service_type' => (string)$row['service_type'],
            'service_desc' => (string)($row['service_desc'] ?? ''),
            'suggested_duration_minutes' => (int)$row['suggested_duration_minutes'],
            'allow_benefit' => (int)$row['allow_benefit'],
            'required_benefit_type' => (string)$row['required_benefit_type'],
            'allow_paid' => (int)$row['allow_paid'],
        ];
    }

    private function idList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }
}
