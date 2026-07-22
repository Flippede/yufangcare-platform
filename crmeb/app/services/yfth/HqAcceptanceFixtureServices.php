<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;
use think\facade\Db;

class HqAcceptanceFixtureServices
{
    private const FIXTURE_KEY = 'YFTH_ACCEPTANCE_V1';
    public const MARKER = '[YFTH-ACCEPTANCE-TEST-V1]';
    private const STORE_NAME = 'TEST 隔离测试 B1 门店';
    private const STORE_PHONE = '19999100000';
    private const SUBJECT_CREDIT_CODE = 'YFTHTESTB1SUBJECT01';
    public const PACKAGE_CODE = 'YFTH-MEMBER-PACKAGE-V1';
    public const MEMBER_PACKAGE_PRICE = '9800.00';
    private const PACKAGE_PURCHASE_NO = 'YFTH-TEST-PURCHASE-V1';
    private const PACKAGE_INSTANCE_NO = 'YFTH-TEST-INSTANCE-V1';

    private const ACCOUNTS = [
        'franchisee_uid' => ['account' => 'yfth_stg_b1_franchisee', 'phone' => '19999100001', 'nickname' => 'TEST B1 县级合伙人', 'role' => 'county_partner'],
        'manager_uid' => ['account' => 'yfth_stg_b1_manager', 'phone' => '19999100002', 'nickname' => 'TEST B1 店长', 'role' => 'store_manager'],
        'staff_uid' => ['account' => 'yfth_stg_b1_staff', 'phone' => '19999100003', 'nickname' => 'TEST B1 店员', 'role' => 'store_staff'],
        'member_uid' => ['account' => 'yfth_stg_c1_member', 'phone' => '19999100004', 'nickname' => 'TEST C1 永久会员', 'role' => 'customer'],
        'customer_uid' => ['account' => 'yfth_stg_c2_customer', 'phone' => '19999100005', 'nickname' => 'TEST C2 普通顾客', 'role' => 'customer'],
    ];
    private const PARTNER_ACCOUNTS = [
        'prefecture_partner_uid' => ['account' => 'yfth_stg_partner_prefecture', 'phone' => '19999100006', 'nickname' => 'TEST 地级合伙人', 'role' => 'prefecture_partner'],
        'province_partner_uid' => ['account' => 'yfth_stg_partner_province', 'phone' => '19999100007', 'nickname' => 'TEST 省级合伙人', 'role' => 'province_partner'],
        'regional_director_uid' => ['account' => 'yfth_stg_partner_regional', 'phone' => '19999100008', 'nickname' => 'TEST 大区总监', 'role' => 'regional_director'],
        'platform_director_uid' => ['account' => 'yfth_stg_partner_platform', 'phone' => '19999100009', 'nickname' => 'TEST 平台董事', 'role' => 'platform_director'],
    ];
    private const LEGACY_ACCOUNTS = [
        'franchisee_uid' => 'yfth_test_b1_owner',
        'manager_uid' => 'yfth_test_b1_manager',
        'staff_uid' => 'yfth_test_b1_staff',
        'member_uid' => 'yfth_test_c1_member',
        'customer_uid' => 'yfth_test_c2_customer',
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
        $passwords = $this->loadOrCreatePasswords();

        $fixture = Db::transaction(function () use ($reason, $passwords, $adminId, $adminInfo) {
            $users = [];
            $currentFixture = $this->fixture();
            foreach (self::ACCOUNTS as $field => $account) {
                if ($field === 'customer_uid') {
                    $account = $this->customerAccountForGeneration($currentFixture, $account);
                }
                $preferredUid = (int)($currentFixture[$field] ?? 0);
                if ($field === 'customer_uid' && $preferredUid > 0) {
                    $current = Db::name('user')->where('uid', $preferredUid)->find();
                    if (!$current || (string)$current['account'] !== (string)$account['account']) {
                        $preferredUid = 0;
                    }
                }
                $users[$field] = $this->ensureUser($account, $passwords[$field], $preferredUid);
                if ($field === 'customer_uid'
                    && !empty($currentFixture['customer_uid'])
                    && (int)$currentFixture['customer_uid'] !== (int)$users[$field]) {
                    $archivedUid = (int)$currentFixture['customer_uid'];
                    $this->archiveFixtureCustomer($archivedUid);
                }
            }
            $storeId = $this->ensureStore();
            $subjectId = $this->ensureSubject($adminId);
            $this->ensureStoreSubject($storeId, $subjectId, $adminId);
            $this->ensureQualifications($storeId, $subjectId, $adminId);

            foreach (['franchisee_uid', 'manager_uid', 'staff_uid'] as $field) {
                $account = self::ACCOUNTS[$field];
                if ($field === 'franchisee_uid') {
                    continue;
                }
                $this->roles->grant((int)$users[$field], [
                    'store_id' => $storeId,
                    'role_code' => $account['role'],
                    'reason' => $reason,
                    'request_id' => 'acceptance-fixture-role-' . $account['role'] . '-' . $storeId,
                ], $adminId, $adminInfo);
            }
            $this->ensurePartnerHierarchyFixture((int)$users['franchisee_uid'], $storeId, $passwords);

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

        $this->writeAccountFile($passwords, $fixture);
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

    public function resetPasswords(array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $this->assertEnabled();
        $reason = $this->reason($data);
        $fixture = $this->fixture();
        if (!$fixture || (string)$fixture['status'] !== 'active') {
            throw new AdminException('acceptance_fixture_not_active');
        }
        $this->assertFixtureOwnership($fixture);
        $passwords = [];
        foreach ($this->allFixtureAccounts() as $field => $account) {
            $passwords[$field] = 'Yfth!' . bin2hex(random_bytes(7));
        }
        Db::transaction(function () use ($fixture, $passwords) {
            foreach ($this->allFixtureAccounts() as $field => $account) {
                $uid = isset($fixture[$field]) ? (int)$fixture[$field] : (int)Db::name('user')->where('account', $account['account'])->value('uid');
                Db::name('user')->where('uid', $uid)->where('mark', self::MARKER)
                    ->update(['pwd' => md5($passwords[$field]), 'status' => 1]);
            }
        });
        $this->writeAccountFile($passwords, $fixture);
        $this->audit->recordSafely(
            'yfth_acceptance_fixture',
            'acceptance_fixture',
            (string)$fixture['id'],
            'password_reset',
            [],
            ['account_count' => count($this->allFixtureAccounts())],
            $adminId,
            'headquarters_admin',
            (int)$fixture['store_id'],
            $reason,
            $this->requestId($data, 'fixture-password-reset')
        );
        $result = $this->summaryDto($fixture, true);
        $result['temporary_passwords_once'] = [];
        foreach ($this->fixtureAccounts($fixture) as $account) {
            $field = $account['fixture_role'] . '_uid';
            $result['temporary_passwords_once'][] = [
                'account' => $account['account'],
                'password' => $passwords[$field],
            ];
        }
        return $result;
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
            Db::name('yfth_customer_relation')->where('uid', (int)$fixture['customer_uid'])
                ->where('store_id', (int)$fixture['store_id'])->where('status', 'active')->update([
                    'status' => 'disabled', 'active_key' => null, 'update_time' => time(),
                ]);
            $this->expireTestInvites((int)$fixture['member_uid']);
            foreach (['franchisee_uid', 'manager_uid', 'staff_uid'] as $field) {
                $rows = Db::name('yfth_user_store_role')
                    ->where('uid', (int)$fixture[$field])
                    ->where('store_id', (int)$fixture['store_id'])
                    ->where('status', 'active')->select()->toArray();
                foreach ($rows as $role) {
                    if (!in_array((string)$role['role_code'], YfthConstants::storeRoles(), true)) {
                        continue;
                    }
                    $this->roles->revoke((int)$role['id'], [
                        'reason' => $reason,
                        'request_id' => 'acceptance-fixture-revoke-' . (int)$role['id'],
                    ], $adminId, $adminInfo);
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
            $partnerUids = Db::name('user')->whereIn('account', array_column(self::PARTNER_ACCOUNTS, 'account'))->where('mark', self::MARKER)->column('uid');
            $partnerUids[] = (int)$fixture['franchisee_uid'];
            $partnerUids = array_values(array_unique(array_filter(array_map('intval', $partnerUids))));
            if ($partnerUids) {
                Db::name('yfth_partner_relation')->whereIn('partner_uid', $partnerUids)->where('status', 'active')->update([
                    'status' => 'disabled', 'active_key' => null, 'end_time' => time(), 'reason' => $reason, 'update_time' => time(),
                ]);
                Db::name('yfth_partner_profile')->whereIn('uid', $partnerUids)->where('status', 'active')->update([
                    'status' => 'paused', 'active_key' => null, 'update_time' => time(),
                ]);
                Db::name('user')->whereIn('uid', $partnerUids)->where('mark', self::MARKER)->update(['status' => 0]);
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

    private function ensureUser(array $account, string $password, int $preferredUid = 0): int
    {
        $row = Db::name('user')->where('account', $account['account'])->whereOr('phone', $account['phone'])->find();
        $preferred = $preferredUid > 0 ? Db::name('user')->where('uid', $preferredUid)->find() : [];
        if ($preferred && (string)($preferred['mark'] ?? '') !== self::MARKER) {
            throw new AdminException('acceptance_fixture_preferred_user_not_owned');
        }
        if ($row && $preferred && (int)$row['uid'] !== (int)$preferred['uid']) {
            throw new AdminException('acceptance_test_account_conflicts_with_fixture_user:' . $account['account']);
        }
        if (!$row && $preferred) {
            $row = $preferred;
        }
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
        if (!$template) throw new AdminException('formal_member_package_missing_run_migration_first');
        $rule = Db::name('yfth_package_rule_version')->where('template_id', (int)$template['id'])
            ->where('status', 'published')->order('version_no desc,id desc')->find();
        if (!$rule || (int)($rule['grants_permanent_membership'] ?? 0) !== 1) {
            throw new AdminException('formal_member_package_rule_missing');
        }
        return [(int)$template['id'], (int)$rule['id']];
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
                'expected_pay_price' => self::MEMBER_PACKAGE_PRICE,
                'order_pay_price' => self::MEMBER_PACKAGE_PRICE,
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
                        'order_pay_price' => (string)($purchase['order_pay_price'] ?? self::MEMBER_PACKAGE_PRICE),
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
            $actualAccount = (string)($user['account'] ?? '');
            $currentMatches = $field === 'customer_uid'
                ? strpos($actualAccount, $account['account']) === 0
                : $actualAccount === $account['account'];
            $legacyMatches = $field === 'customer_uid'
                ? strpos($actualAccount, self::LEGACY_ACCOUNTS[$field]) === 0
                : $actualAccount === self::LEGACY_ACCOUNTS[$field];
            $accountMatches = $currentMatches || $legacyMatches;
            if (!$user || (string)($user['mark'] ?? '') !== self::MARKER || !$accountMatches) {
                throw new AdminException('acceptance_fixture_user_marker_invalid:' . $field);
            }
        }
    }

    private function loadOrCreatePasswords(): array
    {
        $path = $this->accountFile();
        $values = [];
        $legacy = '';
        if (is_file($path)) {
            $content = (string)file_get_contents($path);
            if (preg_match('/^PASSWORD=(.+)$/m', $content, $matches)) {
                $legacy = trim($matches[1]);
            }
            foreach ($this->allFixtureAccounts() as $field => $account) {
                $key = strtoupper(str_replace('_uid', '', $field)) . '_PASSWORD';
                if (preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $content, $matches)) {
                    $values[$field] = trim($matches[1]);
                }
            }
        }
        foreach ($this->allFixtureAccounts() as $field => $account) {
            if (strlen((string)($values[$field] ?? '')) < 12) {
                $values[$field] = strlen($legacy) >= 12 ? $legacy : 'Yfth!' . bin2hex(random_bytes(8));
            }
        }
        return $values;
    }

    private function writeAccountFile(array $passwords, array $fixture): void
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
        ];
        foreach ($this->fixtureAccounts($fixture) as $account) {
            $key = strtoupper($account['fixture_role']);
            $field = $account['fixture_role'] . '_uid';
            $lines[] = $key . '_ACCOUNT=' . $account['account'];
            $lines[] = $key . '_PHONE=' . $account['phone'];
            $lines[] = $key . '_PASSWORD=' . $passwords[$field];
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
            $membership = $account['uid'] > 0 ? $this->membership->effectiveMembership((int)$account['uid']) : ['is_member' => false];
            $attribution = $account['uid'] > 0 ? Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$account['uid'])->find() : [];
            $referral = $account['uid'] > 0 ? Db::name('yfth_hq_active_referral_current')->where('referred_uid', (int)$account['uid'])->find() : [];
            $accounts[] = [
                'uid' => (int)$account['uid'],
                'fixture_role' => $account['fixture_role'],
                'role_code' => $account['role_code'],
                'nickname' => $account['nickname'],
                'account' => $account['account'],
                'phone_masked' => substr($account['phone'], 0, 3) . '****' . substr($account['phone'], -4),
                'login_ready' => $account['uid'] > 0 && (int)Db::name('user')->where('uid', (int)$account['uid'])->value('status') === 1,
                'permanent_member' => (bool)($membership['is_member'] ?? false),
                'attribution_status' => (string)($attribution['status'] ?? 'unassigned'),
                'attribution_store_id' => (int)($attribution['store_id'] ?? 0),
                'referral_status' => (string)($referral['status'] ?? 'none'),
                'invited_count' => (int)Db::name('yfth_hq_active_referral_current')->where('referrer_uid', (int)$account['uid'])->where('status', 'active')->count(),
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
            'user_count' => count($this->allFixtureAccounts()),
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
                $phoneOwner = Db::name('user')->where('phone', $candidate['phone'])->find();
                if ($version === 1 && $phoneOwner && (string)($phoneOwner['mark'] ?? '') === self::MARKER) {
                    $authority = Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$phoneOwner['uid'])->find();
                    if ($authority && !((string)$authority['status'] === 'unassigned' && (int)$authority['authority_version'] === 0)) {
                        $this->archiveFixtureCustomer((int)$phoneOwner['uid']);
                        return $candidate;
                    }
                }
                return $candidate;
            }
            if ((string)($user['mark'] ?? '') !== self::MARKER) {
                continue;
            }
            $authority = Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$user['uid'])->find();
            if (!$authority || ((string)$authority['status'] === 'unassigned' && (int)$authority['authority_version'] === 0)) {
                return $candidate;
            }
            if ($version === 1) {
                $this->archiveFixtureCustomer((int)$user['uid']);
                return $candidate;
            }
        }
        throw new AdminException('acceptance_fixture_customer_pool_exhausted');
    }

    private function archiveFixtureCustomer(int $uid): void
    {
        Db::name('user')->where('uid', $uid)->where('mark', self::MARKER)->update([
            'account' => 'yfth_archived_c2_' . $uid,
            'phone' => '19777' . str_pad((string)$uid, 10, '0', STR_PAD_LEFT),
            'status' => 0,
        ]);
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
        foreach (self::PARTNER_ACCOUNTS as $field => $fallback) {
            $user = Db::name('user')->where('account', $fallback['account'])->where('mark', self::MARKER)->find() ?: [];
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

    private function allFixtureAccounts(): array
    {
        return array_merge(self::ACCOUNTS, self::PARTNER_ACCOUNTS);
    }

    private function ensurePartnerHierarchyFixture(int $countyUid, int $storeId, array $passwords): void
    {
        $uids = ['county_partner' => $countyUid];
        foreach (self::PARTNER_ACCOUNTS as $field => $account) {
            $uids[$account['role']] = $this->ensureUser($account, $passwords[$field]);
        }
        $chain = [
            ['rank' => 'platform_director', 'parent' => 0],
            ['rank' => 'regional_director', 'parent' => $uids['platform_director']],
            ['rank' => 'province_partner', 'parent' => $uids['regional_director']],
            ['rank' => 'prefecture_partner', 'parent' => $uids['province_partner']],
            ['rank' => 'county_partner', 'parent' => $uids['prefecture_partner']],
        ];
        $now = time();
        $ruleId = (int)Db::name('yfth_partner_rule_version')->where('status', 'published')->order('version_no desc')->value('id');
        foreach ($chain as $node) {
            $uid = (int)$uids[$node['rank']];
            $profile = Db::name('yfth_partner_profile')->where('uid', $uid)->find();
            $data = [
                'rank_code' => $node['rank'], 'primary_store_id' => $storeId,
                'source_type' => 'acceptance_fixture', 'source_id' => 0,
                'status' => 'active', 'start_time' => $profile && (string)$profile['rank_code'] === $node['rank'] ? (int)$profile['start_time'] : $now, 'end_time' => 0,
                'active_key' => 'partner:' . $uid, 'update_time' => $now,
            ];
            if ($node['rank'] === 'county_partner') {
                $data['legacy_franchisee_role_id'] = 0;
            }
            if ($profile) {
                Db::name('yfth_partner_profile')->where('id', (int)$profile['id'])->update($data);
            } else {
                $data['uid'] = $uid;
                if (!isset($data['legacy_franchisee_role_id'])) {
                    $data['legacy_franchisee_role_id'] = 0;
                }
                $data['create_time'] = $now;
                Db::name('yfth_partner_profile')->insert($data);
            }
            if (!Db::name('yfth_partner_rank_event')->where('partner_uid', $uid)->where('reason', self::MARKER)->find()) {
                Db::name('yfth_partner_rank_event')->insert([
                    'partner_uid' => $uid, 'from_rank' => '', 'to_rank' => $node['rank'], 'action' => 'fixture_grant',
                    'rule_version_id' => $ruleId, 'reason' => self::MARKER, 'evidence_snapshot' => json_encode(['fixture' => self::FIXTURE_KEY], JSON_UNESCAPED_UNICODE),
                    'operator_uid' => 0, 'create_time' => $now,
                ]);
            }
            if ((int)$node['parent'] > 0) {
                $relation = Db::name('yfth_partner_relation')->where('partner_uid', $uid)->where('status', 'active')->find();
                if (!$relation || (int)$relation['parent_uid'] !== (int)$node['parent']) {
                    Db::name('yfth_partner_relation')->where('partner_uid', $uid)->where('status', 'active')->update([
                        'status' => 'disabled', 'active_key' => null, 'end_time' => $now, 'update_time' => $now,
                    ]);
                    Db::name('yfth_partner_relation')->insert([
                        'partner_uid' => $uid, 'parent_uid' => (int)$node['parent'], 'source_application_id' => 0,
                        'status' => 'active', 'start_time' => $now, 'end_time' => 0, 'reason' => self::MARKER,
                        'operator_uid' => 0, 'active_key' => 'partner:' . $uid, 'create_time' => $now, 'update_time' => $now,
                    ]);
                }
            }
        }
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
