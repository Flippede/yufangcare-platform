<?php

namespace app\services\yfth;

use app\dao\system\admin\SystemAdminDao;
use app\dao\user\UserDao;
use app\dao\yfth\YfthAuditEventDao;
use app\dao\yfth\YfthFranchiseApplicationDao;
use app\dao\yfth\YfthFranchiseFollowRecordDao;
use app\Request;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class FranchiseApplicationServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_franchise_application';
    private const USER_SOURCE = 'miniapp_cooperation_center';
    private const IMPLEMENTED_STATUSES = ['draft', 'submitted', 'contacting', 'communicating', 'inspecting', 'pending_contract'];
    private const RESERVED_STATUSES = ['signed', 'preparing', 'opened', 'terminated'];
    private const FOLLOW_TYPES = ['phone', 'wechat', 'meeting', 'inspection', 'other'];
    private const STATUS_TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['contacting'],
        'contacting' => ['communicating'],
        'communicating' => ['inspecting'],
        'inspecting' => ['pending_contract'],
    ];

    public function __construct(YfthFranchiseApplicationDao $dao)
    {
        $this->dao = $dao;
    }

    public function submit(Request $request, array $data): array
    {
        if (!empty($data['_forbidden_user_fields_submitted'])) {
            throw new ApiException('franchise_application_user_field_forbidden');
        }
        $uid = (int)$request->uid();
        if ($uid <= 0) {
            throw new ApiException('user_not_login');
        }
        $payload = $this->normalizeSubmitPayload($data);
        $now = time();
        $payload = array_merge($payload, [
            'application_no' => $this->makeApplicationNo($uid),
            'applicant_uid' => $uid,
            'source' => self::USER_SOURCE,
            'status' => 'submitted',
            'assigned_uid' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return Db::transaction(function () use ($payload, $uid) {
            $row = $this->dao->save($payload);
            $application = is_array($row) ? $row : $row->toArray();
            $this->audit('franchise_application', (int)$application['id'], 'submit', [], $application, $uid, 'customer', 0, 'miniapp_submit');
            return ['application' => $this->formatApplication($application, false)];
        });
    }

    public function myList(Request $request, array $where): array
    {
        $uid = (int)$request->uid();
        if ($uid <= 0) {
            throw new ApiException('user_not_login');
        }
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $status = $this->normalizeStatusFilter((string)($where['status'] ?? ''));

        $buildQuery = function () use ($uid, $status) {
            $query = $this->dao->search([])->where('applicant_uid', $uid);
            if ($status !== '') {
                $query->where('status', $status);
            }
            return $query;
        };

        $count = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->field('id,application_no,applicant_uid,name,phone,city,region,intention_area,budget,source,status,assigned_uid,remark,create_time,update_time')
            ->page($page, $limit)
            ->order('id desc')
            ->select()
            ->toArray();

        $adminMap = $this->adminMap(array_column($rows, 'assigned_uid'));
        $list = array_map(function ($row) use ($adminMap) {
            return $this->formatApplication($row, false, [], $adminMap);
        }, $rows);

        return compact('list', 'count');
    }

    public function myDetail(Request $request, int $id): array
    {
        $uid = (int)$request->uid();
        if ($uid <= 0) {
            throw new ApiException('user_not_login');
        }
        $application = $this->requireApplication($id);
        if ((int)$application['applicant_uid'] !== $uid) {
            throw new ApiException('franchise_application_not_found');
        }
        $adminMap = $this->adminMap([(int)$application['assigned_uid']]);
        return [
            'application' => $this->formatApplication($application, false, [], $adminMap),
            'follow_records' => $this->followRecords((int)$application['id'], false),
        ];
    }

    public function adminList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $filters = $this->normalizeAdminFilters($where);

        $buildQuery = function () use ($filters) {
            $query = $this->dao->search([]);
            if ($filters['status'] !== '') {
                $query->where('status', $filters['status']);
            }
            if ($filters['assigned_uid'] > 0) {
                $query->where('assigned_uid', $filters['assigned_uid']);
            }
            if ($filters['applicant_uid'] > 0) {
                $query->where('applicant_uid', $filters['applicant_uid']);
            }
            if ($filters['city'] !== '') {
                $query->where('city', $filters['city']);
            }
            if ($filters['keyword'] !== '') {
                $keyword = $filters['keyword'];
                $query->where(function ($query) use ($keyword) {
                    $query->whereLike('application_no|name|phone|city|intention_area', '%' . $keyword . '%');
                    if (ctype_digit($keyword)) {
                        $query->whereOr('id', (int)$keyword)->whereOr('applicant_uid', (int)$keyword);
                    }
                });
            }
            return $query;
        };

        $count = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->field('id,application_no,applicant_uid,name,phone,city,region,intention_area,budget,source,status,assigned_uid,remark,create_time,update_time')
            ->page($page, $limit)
            ->order('id desc')
            ->select()
            ->toArray();

        $userMap = $this->userMap(array_column($rows, 'applicant_uid'));
        $adminMap = $this->adminMap(array_column($rows, 'assigned_uid'));
        $list = array_map(function ($row) use ($userMap, $adminMap) {
            return $this->formatApplication($row, true, $userMap, $adminMap);
        }, $rows);

        return compact('list', 'count');
    }

    public function adminDetail(int $id, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $application = $this->requireApplication($id);
        return [
            'application' => $this->formatApplication($application, true, $this->userMap([(int)$application['applicant_uid']]), $this->adminMap([(int)$application['assigned_uid']])),
            'follow_records' => $this->followRecords((int)$application['id'], true),
            'audit_events' => $this->auditEvents((int)$application['id']),
        ];
    }

    public function assignOwner(int $id, int $assignedUid, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        if ($assignedUid <= 0) {
            throw new ApiException('franchise_application_assigned_uid_required');
        }
        $owner = app()->make(SystemAdminDao::class)->getInfo(['id' => $assignedUid, 'is_del' => 0]);
        if (!$owner) {
            throw new ApiException('franchise_application_owner_not_found');
        }
        $ownerInfo = is_array($owner) ? $owner : $owner->toArray();
        $this->assertHeadquarterAdmin($ownerInfo);

        return Db::transaction(function () use ($id, $assignedUid, $adminId) {
            $before = $this->requireApplication($id);
            $after = $before;
            $after['assigned_uid'] = $assignedUid;
            $after['update_time'] = time();

            $this->dao->update($id, [
                'assigned_uid' => $assignedUid,
                'update_time' => $after['update_time'],
            ]);
            $this->audit('franchise_application', $id, 'assign_owner', $before, $after, $adminId, 'headquarter_admin', 0, 'admin_assign');
            return ['application' => $this->formatApplication($after, true, $this->userMap([(int)$after['applicant_uid']]), $this->adminMap([$assignedUid]))];
        });
    }

    public function changeStatus(int $id, string $targetStatus, string $reason, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $targetStatus = trim($targetStatus);
        if (in_array($targetStatus, self::RESERVED_STATUSES, true)) {
            throw new ApiException('franchise_application_status_reserved_for_later');
        }
        if (!in_array($targetStatus, self::IMPLEMENTED_STATUSES, true)) {
            throw new ApiException('franchise_application_status_invalid');
        }

        $before = $this->requireApplication($id);
        $current = (string)$before['status'];
        if ($current === $targetStatus) {
            return ['application' => $this->formatApplication($before, true, $this->userMap([(int)$before['applicant_uid']]), $this->adminMap([(int)$before['assigned_uid']]))];
        }
        if (!in_array($targetStatus, self::STATUS_TRANSITIONS[$current] ?? [], true)) {
            throw new ApiException('franchise_application_status_transition_forbidden');
        }

        return Db::transaction(function () use ($id, $targetStatus, $reason, $adminId, $before) {
            $after = $before;
            $after['status'] = $targetStatus;
            $after['update_time'] = time();
            $this->dao->update($id, [
                'status' => $targetStatus,
                'update_time' => $after['update_time'],
            ]);
            $this->audit('franchise_application', $id, 'status_change', $before, $after, $adminId, 'headquarter_admin', 0, $reason ?: 'admin_status_change');

            return ['application' => $this->formatApplication($after, true, $this->userMap([(int)$after['applicant_uid']]), $this->adminMap([(int)$after['assigned_uid']]))];
        });
    }

    public function addFollow(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $application = $this->requireApplication($id);
        $content = trim((string)($data['content'] ?? ''));
        if ($content === '') {
            throw new ApiException('franchise_follow_content_required');
        }
        if (mb_strlen($content) > 1000) {
            throw new ApiException('franchise_follow_content_too_long');
        }
        $type = $this->normalizeFollowType((string)($data['type'] ?? 'phone'));
        return Db::transaction(function () use ($application, $adminId, $type, $content, $data) {
            $now = time();
            $record = app()->make(YfthFranchiseFollowRecordDao::class)->save([
                'application_id' => (int)$application['id'],
                'operator_uid' => $adminId,
                'type' => $type,
                'content' => $content,
                'next_time' => $this->parseTime($data['next_time'] ?? 0),
                'create_time' => $now,
            ]);
            $record = is_array($record) ? $record : $record->toArray();
            $this->dao->update((int)$application['id'], ['update_time' => $now]);
            $this->audit('franchise_follow_record', (int)$record['id'], 'create', [], $record, $adminId, 'headquarter_admin', 0, 'admin_follow');
            return ['follow_record' => $this->formatFollow($record, true)];
        });
    }

    public function statuses(): array
    {
        return [
            'implemented' => array_map(function ($status) {
                return ['value' => $status, 'label' => $this->statusText($status)];
            }, self::IMPLEMENTED_STATUSES),
            'reserved' => self::RESERVED_STATUSES,
            'transitions' => self::STATUS_TRANSITIONS,
        ];
    }

    private function normalizeSubmitPayload(array $data): array
    {
        $payload = [
            'name' => trim((string)($data['name'] ?? '')),
            'phone' => trim((string)($data['phone'] ?? '')),
            'city' => trim((string)($data['city'] ?? '')),
            'region' => trim((string)($data['region'] ?? '')),
            'intention_area' => trim((string)($data['intention_area'] ?? '')),
            'budget' => $data['budget'] ?? 0,
            'remark' => trim((string)($data['remark'] ?? '')),
        ];
        if ($payload['name'] === '' || mb_strlen($payload['name']) > 64) {
            throw new ApiException('franchise_application_name_invalid');
        }
        if ($payload['phone'] === '' || mb_strlen($payload['phone']) > 32 || !preg_match('/^[0-9+\-\s]{6,32}$/', $payload['phone'])) {
            throw new ApiException('franchise_application_phone_invalid');
        }
        if ($payload['city'] === '' || mb_strlen($payload['city']) > 64) {
            throw new ApiException('franchise_application_city_invalid');
        }
        if ($payload['region'] !== '' && mb_strlen($payload['region']) > 64) {
            throw new ApiException('franchise_application_region_invalid');
        }
        if ($payload['intention_area'] === '' || mb_strlen($payload['intention_area']) > 128) {
            throw new ApiException('franchise_application_area_invalid');
        }
        if (mb_strlen($payload['remark']) > 1000) {
            throw new ApiException('franchise_application_remark_too_long');
        }
        $budget = is_numeric($payload['budget']) ? (float)$payload['budget'] : -1;
        if ($budget < 0 || $budget > 99999999) {
            throw new ApiException('franchise_application_budget_invalid');
        }
        $payload['budget'] = sprintf('%.2f', $budget);
        return $payload;
    }

    private function normalizeAdminFilters(array $where): array
    {
        return [
            'keyword' => trim((string)($where['keyword'] ?? '')),
            'status' => $this->normalizeStatusFilter((string)($where['status'] ?? '')),
            'assigned_uid' => (int)($where['assigned_uid'] ?? 0),
            'applicant_uid' => (int)($where['applicant_uid'] ?? 0),
            'city' => trim((string)($where['city'] ?? '')),
        ];
    }

    private function normalizeStatusFilter(string $status): string
    {
        $status = trim($status);
        return in_array($status, array_merge(self::IMPLEMENTED_STATUSES, self::RESERVED_STATUSES), true) ? $status : '';
    }

    private function normalizeFollowType(string $type): string
    {
        $type = trim($type);
        return in_array($type, self::FOLLOW_TYPES, true) ? $type : 'other';
    }

    private function makeApplicationNo(int $uid): string
    {
        for ($i = 0; $i < 5; $i++) {
            $no = 'FA' . date('YmdHis') . str_pad((string)$uid, 6, '0', STR_PAD_LEFT) . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (!$this->dao->getOne(['application_no' => $no])) {
                return $no;
            }
        }
        throw new ApiException('franchise_application_no_conflict');
    }

    private function requireApplication(int $id): array
    {
        if ($id <= 0) {
            throw new ApiException('franchise_application_id_required');
        }
        $application = $this->dao->get($id);
        if (!$application) {
            throw new ApiException('franchise_application_not_found');
        }
        return is_array($application) ? $application : $application->toArray();
    }

    private function assertHeadquarterAdmin(array $adminInfo): void
    {
        if (!$adminInfo || (int)($adminInfo['id'] ?? 0) <= 0) {
            throw new ApiException('headquarter_admin_required');
        }
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
    }

    private function formatApplication(array $row, bool $admin, array $userMap = [], array $adminMap = []): array
    {
        $applicantUid = (int)($row['applicant_uid'] ?? 0);
        $assignedUid = (int)($row['assigned_uid'] ?? 0);
        $applicant = $userMap[$applicantUid] ?? [];
        $owner = $adminMap[$assignedUid] ?? [];
        if (!$owner && $assignedUid > 0) {
            $ownerMap = $this->adminMap([$assignedUid]);
            $owner = $ownerMap[$assignedUid] ?? [];
        }

        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'application_no' => (string)($row['application_no'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($row['phone'] ?? '')),
            'city' => (string)($row['city'] ?? ''),
            'region' => (string)($row['region'] ?? ''),
            'intention_area' => (string)($row['intention_area'] ?? ''),
            'budget' => (string)($row['budget'] ?? '0.00'),
            'source' => (string)($row['source'] ?? ''),
            'source_text' => $this->sourceText((string)($row['source'] ?? '')),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->statusText((string)($row['status'] ?? '')),
            'assigned_name' => $this->adminDisplayName($owner),
            'submit_time' => (int)($row['create_time'] ?? 0),
            'latest_follow' => $this->latestFollow((int)($row['id'] ?? 0), $admin),
            'next_step' => $this->nextStep((string)($row['status'] ?? ''), $assignedUid),
        ];

        if ($admin) {
            $payload['applicant_uid'] = $applicantUid;
            $payload['applicant_nickname'] = (string)($applicant['nickname'] ?? '');
            $payload['phone'] = (string)($row['phone'] ?? '');
            $payload['assigned_uid'] = $assignedUid;
            $payload['remark'] = (string)($row['remark'] ?? '');
            $payload['create_time'] = (int)($row['create_time'] ?? 0);
            $payload['update_time'] = (int)($row['update_time'] ?? 0);
            return $payload;
        }

        if ((string)($row['remark'] ?? '') !== '') {
            $payload['remark'] = (string)$row['remark'];
        }
        return $payload;
    }

    private function formatFollow(array $row, bool $admin): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'type' => (string)($row['type'] ?? ''),
            'type_text' => $this->followTypeText((string)($row['type'] ?? '')),
            'content' => (string)($row['content'] ?? ''),
            'next_time' => (int)($row['next_time'] ?? 0),
            'follow_time' => (int)($row['create_time'] ?? 0),
        ];
        if ($admin) {
            $payload['application_id'] = (int)($row['application_id'] ?? 0);
            $payload['operator_uid'] = (int)($row['operator_uid'] ?? 0);
            $payload['operator_name'] = $this->adminDisplayName($this->adminMap([(int)($row['operator_uid'] ?? 0)])[(int)($row['operator_uid'] ?? 0)] ?? []);
        }
        return $payload;
    }

    private function latestFollow(int $applicationId, bool $admin): array
    {
        if ($applicationId <= 0) {
            return [];
        }
        $row = app()->make(YfthFranchiseFollowRecordDao::class)->search([])
            ->where('application_id', $applicationId)
            ->order('create_time desc,id desc')
            ->find();
        if (!$row) {
            return [];
        }
        return $this->formatFollow(is_array($row) ? $row : $row->toArray(), $admin);
    }

    private function followRecords(int $applicationId, bool $admin): array
    {
        $rows = app()->make(YfthFranchiseFollowRecordDao::class)->search([])
            ->where('application_id', $applicationId)
            ->field('id,application_id,operator_uid,type,content,next_time,create_time')
            ->order('create_time desc,id desc')
            ->select()
            ->toArray();
        return array_map(function ($row) use ($admin) {
            return $this->formatFollow($row, $admin);
        }, $rows);
    }

    private function auditEvents(int $applicationId): array
    {
        $rows = app()->make(YfthAuditEventDao::class)->search([])
            ->where('business_domain', self::DOMAIN)
            ->where(function ($query) use ($applicationId) {
                $query->where('object_id', (string)$applicationId)->whereOr('after_state', 'like', '%"application_id":' . $applicationId . '%');
            })
            ->field('id,business_domain,object_type,object_id,action,operator_uid,role_code,store_id,reason,create_time')
            ->order('id desc')
            ->limit(30)
            ->select()
            ->toArray();
        return array_map(function ($row) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'object_type' => (string)($row['object_type'] ?? ''),
                'object_id' => (string)($row['object_id'] ?? ''),
                'action' => (string)($row['action'] ?? ''),
                'operator_uid' => (int)($row['operator_uid'] ?? 0),
                'role_code' => (string)($row['role_code'] ?? ''),
                'reason' => (string)($row['reason'] ?? ''),
                'create_time' => (int)($row['create_time'] ?? 0),
            ];
        }, $rows);
    }

    private function userMap(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids) {
            return [];
        }
        $rows = app()->make(UserDao::class)->search([])
            ->whereIn('uid', $uids)
            ->field('uid,nickname,avatar,phone')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = $row;
        }
        return $map;
    }

    private function adminMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = app()->make(SystemAdminDao::class)->search([])
            ->whereIn('id', $ids)
            ->field('id,real_name,account')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }
        return $map;
    }

    private function adminDisplayName(array $admin): string
    {
        if (!$admin) {
            return '总部招商顾问待分配';
        }
        return (string)($admin['real_name'] ?: ($admin['account'] ?? '总部招商顾问'));
    }

    private function audit(string $objectType, int $objectId, string $action, array $before, array $after, int $operatorUid, string $roleCode, int $storeId, string $reason): void
    {
        app()->make(AuditEventServices::class)->recordSafely(
            self::DOMAIN,
            $objectType,
            (string)$objectId,
            $action,
            $this->sanitizeState($before),
            $this->sanitizeState($after),
            $operatorUid,
            $roleCode,
            $storeId,
            $reason,
            ''
        );
    }

    private function statusText(string $status): string
    {
        $map = [
            'draft' => '草稿',
            'submitted' => '已提交',
            'contacting' => '联系中',
            'communicating' => '沟通中',
            'inspecting' => '考察中',
            'pending_contract' => '待进入合同阶段',
            'signed' => '已签约',
            'preparing' => '筹备中',
            'opened' => '已开业',
            'terminated' => '已终止',
        ];
        return $map[$status] ?? $status;
    }

    private function sourceText(string $source): string
    {
        $map = [
            self::USER_SOURCE => '小程序合作中心',
            'user_apply' => '用户提交',
            'headquarters_import' => '总部导入',
        ];
        return $map[$source] ?? $source;
    }

    private function followTypeText(string $type): string
    {
        $map = [
            'phone' => '电话沟通',
            'wechat' => '微信沟通',
            'meeting' => '面谈',
            'inspection' => '考察',
            'other' => '其他',
        ];
        return $map[$type] ?? $type;
    }

    private function nextStep(string $status, int $assignedUid): string
    {
        if ($assignedUid <= 0) {
            return '等待总部招商顾问分配';
        }
        $map = [
            'submitted' => '总部招商顾问将与您联系',
            'contacting' => '保持电话或微信沟通',
            'communicating' => '确认合作意向和基础条件',
            'inspecting' => '推进门店考察和资料核对',
            'pending_contract' => '等待进入合同与付款流程',
        ];
        return $map[$status] ?? '等待总部更新进度';
    }
}
