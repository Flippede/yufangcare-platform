<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;
use think\facade\Db;

class HqAcceptanceFixtureServices
{
    private const FIXTURE_KEY = 'YFTH_ACCEPTANCE_V1';
    private const MARKER = '[YFTH-ACCEPTANCE-TEST-V1]';
    private const STORE_NAME = 'TEST 隔离测试 B1 门店';
    private const STORE_PHONE = '19999100000';
    private const SUBJECT_CREDIT_CODE = 'YFTHTESTB1SUBJECT01';
    private const PACKAGE_CODE = 'YFTH-TEST-PACKAGE-V1';
    private const PACKAGE_PURCHASE_NO = 'YFTH-TEST-PURCHASE-V1';
    private const PACKAGE_INSTANCE_NO = 'YFTH-TEST-INSTANCE-V1';

    private const ACCOUNTS = [
        'franchisee_uid' => ['account' => 'yfth_test_b1_owner', 'phone' => '19999100001', 'nickname' => 'TEST B1 加盟商', 'role' => 'franchisee'],
        'manager_uid' => ['account' => 'yfth_test_b1_manager', 'phone' => '19999100002', 'nickname' => 'TEST B1 店长', 'role' => 'store_manager'],
        'staff_uid' => ['account' => 'yfth_test_b1_staff', 'phone' => '19999100003', 'nickname' => 'TEST B1 店员', 'role' => 'store_staff'],
        'member_uid' => ['account' => 'yfth_test_c1_member', 'phone' => '19999100004', 'nickname' => 'TEST C1 永久会员', 'role' => 'customer'],
        'customer_uid' => ['account' => 'yfth_test_c2_customer', 'phone' => '19999100005', 'nickname' => 'TEST C2 普通顾客', 'role' => 'customer'],
    ];

    private $adminScope;
    private $roles;
    private $membership;
    private $audit;

    public function __construct(
        AdminStoreContextServices $adminScope,
        HqUserRoleManagementServices $roles,
        PackageMembershipServices $membership,
        AuditEventServices $audit
    ) {
        $this->adminScope = $adminScope;
        $this->roles = $roles;
        $this->membership = $membership;
        $this->audit = $audit;
    }

    public function summary(array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        return $this->summaryDto($this->fixture(), $this->enabled());
    }

    public function generate(array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $this->assertEnabled();
        $reason = $this->reason($data);
        $password = $this->loadOrCreatePassword();

        $fixture = Db::transaction(function () use ($reason, $password, $adminId, $adminInfo) {
            $users = [];
            $currentFixture = $this->fixture();
            foreach (self::ACCOUNTS as $field => $account) {
                if ($field === 'customer_uid') {
                    $account = $this->customerAccountForGeneration($currentFixture, $account);
                }
                $users[$field] = $this->ensureUser($account, $password);
                if ($field === 'customer_uid'
                    && !empty($currentFixture['customer_uid'])
                    && (int)$currentFixture['customer_uid'] !== (int)$users[$field]) {
                    Db::name('user')->where('uid', (int)$currentFixture['customer_uid'])
                        ->where('mark', self::MARKER)->update(['status' => 0]);
                }
            }
            $storeId = $this->ensureStore();
            $subjectId = $this->ensureSubject($adminId);
            $this->ensureStoreSubject($storeId, $subjectId, $adminId);
            $this->ensureQualifications($storeId, $subjectId, $adminId);

            foreach (['franchisee_uid', 'manager_uid', 'staff_uid'] as $field) {
                $account = self::ACCOUNTS[$field];
                $this->roles->grant((int)$users[$field], [
                    'store_id' => $storeId,
                    'role_code' => $account['role'],
                    'reason' => $reason,
                    'request_id' => 'acceptance-fixture-role-' . $account['role'] . '-' . $storeId,
                ], $adminId, $adminInfo);
            }

            [$templateId, $ruleId] = $this->ensurePackageRule($adminId);
            [$purchaseId, $instanceId] = $this->ensurePermanentMember(
                (int)$users['member_uid'],
                $storeId,
                $templateId,
                $ruleId
            );
            $referralRuleId = $this->ensureReferralRule($adminId);

            $now = time();
            $row = [
                'fixture_key' => self::FIXTURE_KEY,
                'status' => 'active',
                'store_id' => $storeId,
                'subject_id' => $subjectId,
                'franchisee_uid' => (int)$users['franchisee_uid'],
                'manager_uid' => (int)$users['manager_uid'],
                'staff_uid' => (int)$users['staff_uid'],
                'member_uid' => (int)$users['member_uid'],
                'customer_uid' => (int)$users['customer_uid'],
                'package_template_id' => $templateId,
                'package_rule_id' => $ruleId,
                'package_purchase_id' => $purchaseId,
                'package_instance_id' => $instanceId,
                'referral_rule_id' => $referralRuleId,
                'updated_admin_id' => $adminId,
                'last_reason' => $reason,
                'disabled_at' => 0,
                'update_time' => $now,
            ];
            $existing = $this->fixture();
            if ($existing) {
                Db::name('yfth_acceptance_fixture')->where('id', (int)$existing['id'])->update($row);
            } else {
                $row['created_admin_id'] = $adminId;
                $row['add_time'] = $now;
                Db::name('yfth_acceptance_fixture')->insert($row);
            }
            return $this->fixture();
        });

        $this->writeAccountFile($password, $fixture);
        $this->audit->recordSafely(
            'yfth_acceptance_fixture',
            'acceptance_fixture',
            (string)($fixture['id'] ?? 0),
            'generate',
            [],
            $this->auditDto($fixture),
            $adminId,
            'headquarters_admin',
            (int)$fixture['store_id'],
            $reason,
            $this->requestId($data, 'fixture-generate')
        );
        return $this->summaryDto($fixture, true);
    }

    public function reset(array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $this->assertEnabled();
        $reason = $this->reason($data);
        $fixture = $this->fixture();
        if (!$fixture || (string)$fixture['status'] !== 'active') {
            return $this->summaryDto($fixture, true);
        }
        $this->assertFixtureOwnership($fixture);
        if ($this->membership->effectiveMembership((int)$fixture['customer_uid'])['is_member']) {
            throw new AdminException('test_customer_has_membership_and_cannot_be_reset');
        }

        Db::transaction(function () use ($fixture, $reason, $adminId, $adminInfo) {
            $this->closeTestReferral((int)$fixture['customer_uid'], $adminId, $reason);
            $this->expireTestInvites((int)$fixture['member_uid']);
            foreach (['franchisee_uid', 'manager_uid', 'staff_uid'] as $field) {
                $rows = Db::name('yfth_user_store_role')
                    ->where('uid', (int)$fixture[$field])
                    ->where('store_id', (int)$fixture['store_id'])
                    ->where('status', 'active')->select()->toArray();
                foreach ($rows as $role) {
                    if (in_array((string)$role['role_code'], YfthConstants::storeRoles(), true)) {
                        $this->roles->revoke((int)$role['id'], [
                            'reason' => $reason,
                            'request_id' => 'acceptance-fixture-revoke-' . (int)$role['id'],
                        ], $adminId, $adminInfo);
                    }
                }
            }
            $qualificationIds = Db::name('yfth_store_qualification')
                ->where('store_id', (int)$fixture['store_id'])
                ->whereLike('certificate_no', 'YFTH-TEST-B1-%')->column('id');
            foreach ($qualificationIds as $qualificationId) {
                app()->make(StoreQualificationServices::class)->auditQualification(
                    (int)$qualificationId,
                    YfthConstants::STATUS_PAUSED,
                    $reason,
                    $adminId
                );
            }
            $storeSubjects = Db::name('yfth_store_subject')->where([
                'store_id' => (int)$fixture['store_id'],
                'subject_id' => (int)$fixture['subject_id'],
                'status' => 'active',
            ])->column('id');
            foreach ($storeSubjects as $storeSubjectId) {
                app()->make(StoreSubjectServices::class)->disableStoreSubject((int)$storeSubjectId, $adminId);
            }
            Db::name('system_store')->where('id', (int)$fixture['store_id'])->update(['is_show' => 0]);
            Db::name('yfth_business_subject')->where('id', (int)$fixture['subject_id'])->update([
                'status' => 'disabled', 'update_time' => time(),
            ]);
            foreach (self::ACCOUNTS as $field => $account) {
                Db::name('user')->where('uid', (int)$fixture[$field])->where('mark', self::MARKER)
                    ->update(['status' => 0]);
            }
            $this->disableTestConfiguration($fixture);
            Db::name('yfth_acceptance_fixture')->where('id', (int)$fixture['id'])->update([
                'status' => 'disabled',
                'updated_admin_id' => $adminId,
                'last_reason' => $reason,
                'disabled_at' => time(),
                'update_time' => time(),
            ]);
        });

        $after = $this->fixture();
        $this->audit->recordSafely(
            'yfth_acceptance_fixture',
            'acceptance_fixture',
            (string)$fixture['id'],
            'reset',
            $this->auditDto($fixture),
            $this->auditDto($after),
            $adminId,
            'headquarters_admin',
            (int)$fixture['store_id'],
            $reason,
            $this->requestId($data, 'fixture-reset')
        );
        return $this->summaryDto($after, true);
    }

    private function ensureUser(array $account, string $password): int
    {
        $row = Db::name('user')->where('account', $account['account'])->whereOr('phone', $account['phone'])->find();
        if ($row && (string)($row['mark'] ?? '') !== self::MARKER) {
            throw new AdminException('acceptance_test_account_conflicts_with_real_user:' . $account['account']);
        }
        $data = [
            'account' => $account['account'],
            'pwd' => md5($password),
            'nickname' => $account['nickname'],
            'phone' => $account['phone'],
            'mark' => self::MARKER,
            'status' => 1,
            'is_del' => 0,
        ];
        if ($row) {
            Db::name('user')->where('uid', (int)$row['uid'])->update($data);
            return (int)$row['uid'];
        }
        $data['user_type'] = 'h5';
        $data['avatar'] = '';
        $data['add_time'] = time();
        $data['last_time'] = time();
        $data['last_ip'] = '127.0.0.1';
        return (int)Db::name('user')->insertGetId($data);
    }

    private function ensureStore(): int
    {
        $row = Db::name('system_store')->where('phone', self::STORE_PHONE)->find();
        if ($row && (string)($row['name'] ?? '') !== self::STORE_NAME) {
            throw new AdminException('acceptance_test_store_phone_conflict');
        }
        $data = [
            'name' => self::STORE_NAME,
            'introduction' => self::MARKER . ' 仅用于受控验收',
            'phone' => self::STORE_PHONE,
            'address' => '北京市测试区',
            'detailed_address' => self::MARKER . ' 虚构地址 1 号',
            'valid_time' => '00:00 - 23:59',
            'day_time' => '周一至周日',
            'is_show' => 1,
            'is_del' => 0,
        ];
        if ($row) {
            Db::name('system_store')->where('id', (int)$row['id'])->update($data);
            return (int)$row['id'];
        }
        $data['add_time'] = time();
        return (int)Db::name('system_store')->insertGetId($data);
    }

    private function ensureSubject(int $adminId): int
    {
        $row = Db::name('yfth_business_subject')->where('credit_code', self::SUBJECT_CREDIT_CODE)->find();
        $data = [
            'subject_type' => 'franchise_company',
            'subject_name' => 'TEST 隔离测试 B1 经营主体',
            'credit_code' => self::SUBJECT_CREDIT_CODE,
            'legal_person' => 'TEST 法定代表人',
            'contact_name' => 'TEST 联系人',
            'contact_phone' => self::STORE_PHONE,
            'registered_address' => self::MARKER . ' 虚构注册地址',
            'status' => 'active',
        ];
        if ($row) {
            $data['id'] = (int)$row['id'];
        }
        $saved = app()->make(BusinessSubjectServices::class)->saveSubject($data, $adminId);
        return $row ? (int)$row['id'] : (int)$saved->id;
    }

    private function ensureStoreSubject(int $storeId, int $subjectId, int $adminId): void
    {
        $row = Db::name('yfth_store_subject')->where([
            'store_id' => $storeId, 'subject_id' => $subjectId, 'subject_role' => 'host',
        ])->find();
        $data = [
            'store_id' => $storeId,
            'subject_id' => $subjectId,
            'store_type' => 'franchise',
            'subject_role' => 'host',
            'is_sales_subject' => 1,
            'is_service_subject' => 1,
            'is_payment_subject' => 1,
            'is_fulfillment_subject' => 1,
            'is_invoice_subject' => 1,
            'is_refund_subject' => 1,
            'is_host_subject' => 1,
            'status' => 'active',
            'effective_time' => time(),
            'expire_time' => 0,
        ];
        if ($row) {
            $data['id'] = (int)$row['id'];
        }
        app()->make(StoreSubjectServices::class)->saveStoreSubject($data, $adminId);
    }

    private function ensureQualifications(int $storeId, int $subjectId, int $adminId): void
    {
        foreach (['business_license', 'health_service', 'franchise_authorization'] as $type) {
            $certificate = 'YFTH-TEST-B1-' . strtoupper($type);
            $row = Db::name('yfth_store_qualification')->where('certificate_no', $certificate)->find();
            $data = [
                'store_id' => $storeId,
                'subject_id' => $subjectId,
                'qualification_type' => $type,
                'certificate_no' => $certificate,
                'scope' => ['test_marker' => self::MARKER],
                'start_time' => time(),
                'expire_time' => 0,
                'status' => 'pending',
            ];
            if ($row) {
                $data['id'] = (int)$row['id'];
            }
            $saved = app()->make(StoreQualificationServices::class)->saveQualification($data, $adminId);
            $id = $row ? (int)$row['id'] : (int)$saved->id;
            app()->make(StoreQualificationServices::class)->auditQualification($id, 'active', self::MARKER, $adminId);
        }
    }

    private function ensurePackageRule(int $adminId): array
    {
        $template = Db::name('yfth_package_template')->where('package_code', self::PACKAGE_CODE)->find();
        $templateData = [
            'package_code' => self::PACKAGE_CODE,
            'package_name' => 'TEST 隔离测试永久会员套餐',
            'package_title' => 'TEST 验收专用套餐（禁止真实支付）',
            'package_type' => 'health_package',
            'base_price' => '0.01',
            'currency' => 'CNY',
            'benefit_months' => 10,
            'service_summary' => self::MARKER,
            'agreement_title' => 'TEST 验收协议',
            'agreement_content' => self::MARKER . ' 仅用于隔离验收，不产生真实支付。',
            'status' => 'published',
            'sort' => -1000,
        ];
        if ($template) {
            $templateData['id'] = (int)$template['id'];
        }
        $savedTemplate = app()->make(PackageTemplateServices::class)->saveTemplate($templateData, $adminId);
        $templateId = $template ? (int)$template['id'] : (int)$savedTemplate->id;

        $rule = Db::name('yfth_package_rule_version')->where('template_id', $templateId)
            ->where('status', 'published')->find();
        if (!$rule) {
            $version = (int)Db::name('yfth_package_rule_version')->where('template_id', $templateId)->max('version_no') + 1;
            $savedRule = app()->make(PackageTemplateServices::class)->saveRuleVersion([
                'template_id' => $templateId,
                'version_no' => $version,
                'rule_code' => 'YFTH-TEST-PACKAGE-RULE-V' . $version,
                'status' => 'published',
                'package_price' => '0.01',
                'month_count' => 10,
                'grants_permanent_membership' => 1,
                'benefit_rule_snapshot' => ['test_marker' => self::MARKER],
                'agreement_title' => 'TEST 验收协议',
                'agreement_content' => self::MARKER,
            ], $adminId);
            $ruleId = (int)$savedRule->id;
        } else {
            $ruleId = (int)$rule['id'];
        }
        return [$templateId, $ruleId];
    }

    private function ensurePermanentMember(int $uid, int $storeId, int $templateId, int $ruleId): array
    {
        $effective = $this->membership->effectiveMembership($uid);
        $purchase = Db::name('yfth_package_purchase')->where('purchase_no', self::PACKAGE_PURCHASE_NO)->find();
        if (!$purchase) {
            $purchaseId = (int)Db::name('yfth_package_purchase')->insertGetId([
                'purchase_no' => self::PACKAGE_PURCHASE_NO,
                'uid' => $uid,
                'store_id' => $storeId,
                'template_id' => $templateId,
                'rule_version_id' => $ruleId,
                'expected_pay_price' => '0.01',
                'order_pay_price' => '0.01',
                'payment_scene' => 'package_order',
                'route_snapshot' => json_encode(['test_marker' => self::MARKER], JSON_UNESCAPED_UNICODE),
                'validation_snapshot' => json_encode(['test_marker' => self::MARKER], JSON_UNESCAPED_UNICODE),
                'purchase_status' => 'paid',
                'activation_status' => 'pending',
                'idempotency_key' => 'yfth-acceptance-member-c1-v1',
                'source' => 'controlled_acceptance_fixture',
                'add_time' => time(),
                'update_time' => time(),
            ]);
            $purchase = Db::name('yfth_package_purchase')->where('id', $purchaseId)->find();
        } else {
            $purchaseId = (int)$purchase['id'];
        }
        $instance = Db::name('yfth_package_instance')->where('instance_no', self::PACKAGE_INSTANCE_NO)->find();
        if (!$instance) {
            $instanceId = (int)Db::name('yfth_package_instance')->insertGetId([
                'instance_no' => self::PACKAGE_INSTANCE_NO,
                'purchase_id' => $purchaseId,
                'uid' => $uid,
                'store_id' => $storeId,
                'template_id' => $templateId,
                'rule_version_id' => $ruleId,
                'status' => 'active',
                'refund_status' => 'none',
                'start_time' => time(),
                'end_time' => strtotime('+10 months'),
                'activated_time' => time(),
                'rule_snapshot' => json_encode(['test_marker' => self::MARKER, 'grants_permanent_membership' => 1], JSON_UNESCAPED_UNICODE),
                'store_snapshot' => json_encode(['store_id' => $storeId, 'test_marker' => self::MARKER], JSON_UNESCAPED_UNICODE),
                'add_time' => time(),
                'update_time' => time(),
            ]);
        } else {
            $instanceId = (int)$instance['id'];
        }
        if (!$effective['is_member']) {
            Db::transaction(function () use ($purchase, $instanceId) {
                app()->make(PackageMembershipActivationCoordinator::class)->activateInTransaction(
                    $purchase,
                    [
                        'order_pay_price' => '0.01',
                        'currency' => 'CNY',
                        'paid_time' => time(),
                        'grants_permanent_membership' => 1,
                    ],
                    $instanceId
                );
            });
        } else {
            $member = $effective['member'] ?: [];
            if ((int)($member['store_id'] ?? 0) !== $storeId) {
                throw new AdminException('acceptance_test_member_store_conflict');
            }
        }
        Db::name('yfth_package_purchase')->where('id', $purchaseId)->update([
            'activation_status' => 'activated', 'instance_id' => $instanceId, 'update_time' => time(),
        ]);
        return [$purchaseId, $instanceId];
    }

    private function ensureReferralRule(int $adminId): int
    {
        $active = Db::name('yfth_direct_referral_rule_version')->where('status', 'published')
            ->where('active_key', 'published')->find();
        if ($active) {
            return (int)$active['id'];
        }
        $service = app()->make(DirectReferralRewardServices::class);
        $rule = $service->saveRule([
            'package_ratio_first_bps' => 1500,
            'package_ratio_second_bps' => 2500,
            'package_ratio_third_bps' => 6000,
            'mall_consumption_enabled' => 1,
            'mall_consumption_ratio_bps' => 1000,
        ], $adminId);
        $ruleId = (int)$rule['id'];
        Db::name('yfth_direct_referral_rule_version')->where('id', $ruleId)->update([
            'rule_no' => 'YFTH-TEST-REFERRAL-RULE-V' . (int)$rule['version_no'],
            'update_time' => time(),
        ]);
        return (int)$service->publishRule($ruleId, $adminId)['id'];
    }

    private function closeTestReferral(int $uid, int $adminId, string $reason): void
    {
        $relation = Db::name('yfth_hq_active_referral_current')->where('referred_uid', $uid)->where('status', 'active')->find();
        $attribution = Db::name('yfth_hq_customer_attribution_current')->where('uid', $uid)->where('status', 'active')->find();
        if ($relation) {
            $source = HqAuthoritySource::fromTrusted('package_membership_referral_invite', (int)$relation['id']);
            $mutation = new HqAuthorityMutation(
                $source,
                $adminId,
                'admin',
                $reason,
                'acceptance-fixture-referral-close-' . (int)$relation['id'],
                'acceptance-fixture-referral-close-' . (int)$relation['id']
            );
            app()->make(HqActiveReferralServices::class)->close(
                (int)$relation['id'],
                (int)$relation['relation_version'],
                'headquarters_correction_closed',
                $mutation
            );
        }
        if ($attribution) {
            $source = HqAuthoritySource::fromTrusted('package_membership_referral_invite', (int)$attribution['id']);
            $mutation = new HqAuthorityMutation(
                $source,
                $adminId,
                'admin',
                $reason,
                'acceptance-fixture-attribution-close-' . (int)$attribution['id'],
                'acceptance-fixture-attribution-close-' . (int)$attribution['id']
            );
            app()->make(HqCustomerAttributionServices::class)->close(
                $uid,
                (int)$attribution['authority_version'],
                'headquarters_correction_closed',
                $mutation
            );
        }
    }

    private function expireTestInvites(int $uid): void
    {
        Db::name('yfth_direct_referral_invite')->where('owner_uid', $uid)->where('status', 'active')->update([
            'status' => 'invalidated', 'active_key' => null, 'invalidated_at' => time(), 'update_time' => time(),
        ]);
    }

    private function disableTestConfiguration(array $fixture): void
    {
        $template = Db::name('yfth_package_template')->where('id', (int)$fixture['package_template_id'])->find();
        if ($template && (string)$template['package_code'] === self::PACKAGE_CODE) {
            Db::name('yfth_package_template')->where('id', (int)$template['id'])->update(['status' => 'disabled', 'update_time' => time()]);
            Db::name('yfth_package_rule_version')->where('template_id', (int)$template['id'])->update([
                'status' => 'disabled', 'active_key' => null, 'update_time' => time(),
            ]);
        }
        $rule = Db::name('yfth_direct_referral_rule_version')->where('id', (int)$fixture['referral_rule_id'])->find();
        if ($rule && strpos((string)$rule['rule_no'], 'YFTH-TEST-REFERRAL-RULE-') === 0) {
            Db::name('yfth_direct_referral_rule_version')->where('id', (int)$rule['id'])->update([
                'status' => 'disabled', 'active_key' => null, 'update_time' => time(),
            ]);
        }
    }

    private function assertFixtureOwnership(array $fixture): void
    {
        if ((string)$fixture['fixture_key'] !== self::FIXTURE_KEY) {
            throw new AdminException('acceptance_fixture_marker_invalid');
        }
        $store = Db::name('system_store')->where('id', (int)$fixture['store_id'])->find();
        if (!$store || (string)$store['name'] !== self::STORE_NAME || (string)$store['phone'] !== self::STORE_PHONE) {
            throw new AdminException('acceptance_fixture_store_marker_invalid');
        }
        foreach (self::ACCOUNTS as $field => $account) {
            $user = Db::name('user')->where('uid', (int)$fixture[$field])->find();
            $accountMatches = $field === 'customer_uid'
                ? strpos((string)($user['account'] ?? ''), $account['account']) === 0
                : (string)($user['account'] ?? '') === $account['account'];
            if (!$user || (string)($user['mark'] ?? '') !== self::MARKER || !$accountMatches) {
                throw new AdminException('acceptance_fixture_user_marker_invalid:' . $field);
            }
        }
    }

    private function loadOrCreatePassword(): string
    {
        $path = $this->accountFile();
        if (is_file($path)) {
            $content = (string)file_get_contents($path);
            if (preg_match('/^PASSWORD=(.+)$/m', $content, $matches) && strlen(trim($matches[1])) >= 12) {
                return trim($matches[1]);
            }
        }
        return 'Yfth!' . bin2hex(random_bytes(8));
    }

    private function writeAccountFile(string $password, array $fixture): void
    {
        $path = $this->accountFile();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AdminException('acceptance_account_directory_unavailable');
        }
        @chmod($dir, 0700);
        $lines = [
            'YFTH CONTROLLED ACCEPTANCE ACCOUNTS',
            'FIXTURE=' . self::FIXTURE_KEY,
            'STORE_ID=' . (int)$fixture['store_id'],
            'STORE_NAME=' . self::STORE_NAME,
            'PASSWORD=' . $password,
        ];
        foreach ($this->fixtureAccounts($fixture) as $account) {
            $lines[] = strtoupper($account['fixture_role']) . '=' . $account['account'] . ' (' . $account['phone'] . ')';
        }
        if (file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new AdminException('acceptance_account_file_write_failed');
        }
        @chmod($path, 0600);
    }

    private function summaryDto(array $fixture, bool $enabled): array
    {
        $accounts = [];
        foreach ($this->fixtureAccounts($fixture) as $account) {
            $accounts[] = [
                'uid' => (int)$account['uid'],
                'fixture_role' => $account['fixture_role'],
                'role_code' => $account['role_code'],
                'nickname' => $account['nickname'],
                'account' => $account['account'],
                'phone_masked' => substr($account['phone'], 0, 3) . '****' . substr($account['phone'], -4),
            ];
        }
        return [
            'enabled' => $enabled,
            'exists' => !empty($fixture),
            'status' => (string)($fixture['status'] ?? 'not_generated'),
            'fixture_key' => self::FIXTURE_KEY,
            'store' => [
                'id' => (int)($fixture['store_id'] ?? 0),
                'name' => self::STORE_NAME,
            ],
            'accounts' => $accounts,
            'credential_file' => $this->accountFile(),
            'password_exposed' => false,
            'updated_at' => (int)($fixture['update_time'] ?? 0),
        ];
    }

    private function auditDto(array $fixture): array
    {
        return [
            'fixture_key' => (string)($fixture['fixture_key'] ?? ''),
            'status' => (string)($fixture['status'] ?? ''),
            'store_id' => (int)($fixture['store_id'] ?? 0),
            'user_count' => count(self::ACCOUNTS),
        ];
    }

    private function fixture(): array
    {
        $row = Db::name('yfth_acceptance_fixture')->where('fixture_key', self::FIXTURE_KEY)->find();
        return $row ?: [];
    }

    private function customerAccountForGeneration(array $fixture, array $base): array
    {
        if ((string)($fixture['status'] ?? '') === 'active' && (int)($fixture['customer_uid'] ?? 0) > 0) {
            $current = Db::name('user')->where('uid', (int)$fixture['customer_uid'])->find();
            if ($current && (string)($current['mark'] ?? '') === self::MARKER) {
                $authority = Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$current['uid'])->find();
                $activeReferral = Db::name('yfth_hq_active_referral_current')->where('referred_uid', (int)$current['uid'])
                    ->whereIn('status', ['active', 'paused'])->find();
                $reusable = !$authority
                    || ((string)$authority['status'] === 'unassigned' && (int)$authority['authority_version'] === 0)
                    || ((string)$authority['status'] === 'active' && $activeReferral);
                if ($reusable) {
                    return array_merge($base, [
                        'account' => (string)$current['account'],
                        'phone' => (string)$current['phone'],
                        'nickname' => (string)$current['nickname'],
                    ]);
                }
            }
        }
        for ($version = 1; $version <= 999; $version++) {
            $candidate = $base;
            if ($version > 1) {
                $candidate['account'] = $base['account'] . '_v' . $version;
                $candidate['phone'] = '1988800' . str_pad((string)$version, 4, '0', STR_PAD_LEFT);
                $candidate['nickname'] = $base['nickname'] . ' #' . $version;
            }
            $user = Db::name('user')->where('account', $candidate['account'])->find();
            if (!$user) {
                return $candidate;
            }
            if ((string)($user['mark'] ?? '') !== self::MARKER) {
                continue;
            }
            $authority = Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$user['uid'])->find();
            if (!$authority || ((string)$authority['status'] === 'unassigned' && (int)$authority['authority_version'] === 0)) {
                return $candidate;
            }
        }
        throw new AdminException('acceptance_fixture_customer_pool_exhausted');
    }

    private function fixtureAccounts(array $fixture): array
    {
        $accounts = [];
        foreach (self::ACCOUNTS as $field => $fallback) {
            $user = !empty($fixture[$field]) ? Db::name('user')->where('uid', (int)$fixture[$field])->find() : [];
            $accounts[] = [
                'uid' => (int)($user['uid'] ?? 0),
                'fixture_role' => str_replace('_uid', '', $field),
                'role_code' => $fallback['role'],
                'nickname' => (string)($user['nickname'] ?? $fallback['nickname']),
                'account' => (string)($user['account'] ?? $fallback['account']),
                'phone' => (string)($user['phone'] ?? $fallback['phone']),
            ];
        }
        return $accounts;
    }

    private function enabled(): bool
    {
        return filter_var(config('yfth.acceptance_fixture_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new AdminException('acceptance_fixture_disabled');
        }
    }

    private function assertHeadquarters(array $adminInfo): void
    {
        $this->adminScope->assertHeadquarterScope($adminInfo);
    }

    private function reason(array $data): string
    {
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            throw new AdminException('acceptance_fixture_reason_required');
        }
        return mb_substr($reason, 0, 255);
    }

    private function requestId(array $data, string $prefix): string
    {
        $value = trim((string)($data['request_id'] ?? ''));
        return $value !== '' ? substr($value, 0, 64) : $prefix . '-' . date('YmdHis');
    }

    private function accountFile(): string
    {
        return (string)config('yfth.acceptance_account_file');
    }
}
