<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBusinessSubjectDao;
use crmeb\exceptions\AdminException;

class BusinessSubjectServices extends YfthFoundationBaseServices
{
    public function __construct(YfthBusinessSubjectDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'subject_type' => $where['subject_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->formatSubjectRow($row);
        });
    }

    public function saveSubject(array $data, int $operatorUid = 0)
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->dao->get($id) : null;
        unset($data['id']);
        $data = [
            'subject_type' => trim((string)($data['subject_type'] ?? '')),
            'subject_name' => trim((string)($data['subject_name'] ?? '')),
            'credit_code' => trim((string)($data['credit_code'] ?? '')),
            'legal_person' => trim((string)($data['legal_person'] ?? '')),
            'contact_name' => trim((string)($data['contact_name'] ?? '')),
            'contact_phone' => trim((string)($data['contact_phone'] ?? '')),
            'registered_address' => trim((string)($data['registered_address'] ?? '')),
            'status' => $data['status'] ?? YfthConstants::STATUS_ACTIVE,
        ];
        if ($data['subject_type'] === '' || $data['subject_name'] === '' || $data['credit_code'] === '') {
            throw new AdminException('主体类型、名称和信用代码不能为空');
        }
        $data = $this->withTimestamps($data, $id === 0);
        $result = $id ? $this->dao->update($id, $data) : $this->dao->save($data);
        $objectId = $id ?: (int)$result->id;
        $this->recordAudit('business_subject', (string)$objectId, $id ? 'update' : 'create', $before ? $before->toArray() : [], $data, $operatorUid);
        return $result;
    }

    private function formatSubjectRow(array $row): array
    {
        $row['subject_type_name'] = YfthConstants::subjectTypes()[$row['subject_type']] ?? $row['subject_type'];
        $row['contact_phone_masked'] = $this->maskPhone((string)($row['contact_phone'] ?? ''));
        return $row;
    }

    private function recordAudit(string $objectType, string $objectId, string $action, array $before, array $after, int $operatorUid): void
    {
        /** @var AuditEventServices $audit */
        $audit = app()->make(AuditEventServices::class);
        $audit->record('yfth_foundation', $objectType, $objectId, $action, $before, $after, $operatorUid, 'admin', 0);
    }
}
