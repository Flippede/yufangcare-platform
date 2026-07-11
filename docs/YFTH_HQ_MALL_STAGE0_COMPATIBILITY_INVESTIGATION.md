# YFTH Headquarters Mall Stage 0 Compatibility Investigation

> Status: Stage 0 read-only investigation. This document records repository facts and compatibility recommendations. It does not approve migrations or Stage 1 implementation.

## 1. 调查结论

- 调查分支：`codex/yfth-hq-mall-stage0-investigation`。
- 调查基线：`main` / `origin/main` / start HEAD 均为 `de3b2f04e231e7c3115f33b1ef1450ccf2fbb084`。
- 最新产品依据：`docs/YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md`；`PROJECT_HANDOFF.md` 顶部事实快照次之，旧产品文档只作历史或兼容证据。
- 本轮未修改 PHP、Vue、JavaScript、migration、路由、测试、数据库或既有入口；只输出兼容调查、数据模型建议和交接快照。
- 总体可行性：**有条件可行**。CRMEB 商城主链、YFTH 基础上下文、审计、幂等、快照和行锁模式均可复用；永久 B 商家归属、长期 active 一级推荐、线下会员/套餐成交和新动态确认码必须独立建模。
- 主要冲突：旧 `member_5980` 是线上套餐实例派生身份；旧推荐候选固定 90 天且按场景绑定业务对象；`yfth_customer_relation` 是门店 CRM 经营关系；预约动态码表绑定预约核销状态机。四者都不能改释为新方案的权威对象。
- 架构审核 P1 整改已把永久归属冻结为每数值 UID 一行 `yfth_hq_customer_attribution_current` + 独立 attribution event，把 active 推荐冻结为 `yfth_hq_active_referral_current` + 独立 referral event；不再使用独立 guard 表、字符串归属 active key 或共用 relationship event。
- 实施门禁已拆分为 Stage 1A Authority Foundation 和后续单独授权的 Stage 1B Read-only Surface。永久会员权威完成前，生产推荐资格必须 fail closed，任何阶段均不得开放真实推荐绑定。
- 不得删除的稳定模块：线上 5980 套餐订单/支付/退款/恢复、十个月权益与月度履约、预约/容量/权益锁定/动态核销、旧推荐奖励兼容链路、客户 CRM、总部与门店工作台、统一审计和幂等。
- 阶段零未读取生产数据库，无法证明生产数据为空。任何初始数据处理必须先做生产前只读盘点和人工核验，禁止依据代码仓库推断可自动迁移。

## 2. 旧 5980 套餐入口清单

| 业务对象 | 真实文件或入口 | 当前作用与引用 | 当前状态 | 保留理由 | 推荐处理 |
| --- | --- | --- | --- | --- | --- |
| 套餐模板/规则 | `20260624130000_create_yfth_package_benefit_tables.php:33-91`; `PackageTemplateServices` | 总部维护套餐、规则版本、商品/SKU 绑定和不可变规则快照 | 有后台 API 和页面 | 历史实例、购买快照、权益计划均引用 | 后台继续开放；不得改释为新 9800 线下产品 |
| 购买意图 | `YfthPackagePurchaseIntent` / DAO; `PackagePurchaseServices::createIntent()` | 在线购买入口、并发认领、超时与孤儿订单恢复 | 有真实用户 API | 保护 CRMEB 下单与异常恢复 | 旧在线套餐受控保留；新线下成交不得调用 |
| 订单尝试 | `20260626090000_create_yfth_package_order_attempts.php`; `YfthPackageOrderAttempt` | 记录创建 CRMEB 订单尝试并扫描未绑定订单 | 恢复链在用 | 支付成功但业务绑定失败时可追踪 | 只读/恢复入口必须保留 |
| 购买与快照 | `yfth_package_purchase`; `20260624170000_harden_yfth_package_purchase_snapshots.php`; `PackagePurchaseServices` | 绑定 `store_order`、支付事实、协议和成交快照 | 真实订单链在用 | 退款、激活、推荐事件均依赖 | 必须保留；禁止线下 9800 复用 |
| 套餐实例 | `yfth_package_instance`; `PackageActivationServices`; `PackageInstanceServices` | 支付后激活套餐，派生 `member_5980` | 稳定业务权威对象 | 权益、预约、核销、退款与身份重算依赖 | 继续开放“我的套餐”和历史详情 |
| 权益计划/周期/项目 | `yfth_benefit_plan`, `yfth_benefit_period`, `yfth_benefit_item`; `PackageBenefitStateMachine` | 十个月权益生命周期 | 稳定运行 | 月度领取、预约权益锁定和核销消费依赖 | 继续开放；不得迁为新永久会员福利 |
| 支付监听 | `PackagePaySuccessListener::handle()`; `app/event.php` | CRMEB 支付成功后激活或登记恢复异常 | 真实事件入口 | 防止“订单已付、套餐未激活” | 必须保留 |
| 退款监听 | `PackageRefundApplyListener`, `PackageRefundCancelListener`, `PackageCustomEventListener` | 退款申请、取消、成功/失败驱动生命周期 | 真实事件入口 | 保证权益、身份和推荐冲正一致 | 必须保留 |
| 生命周期/恢复 | `PackageLifecycleServices`; `PackageActivationRecoveryServices`; `PackagePurchaseServices::scanUnboundPackageIntentOrders()` | 锁购买/实例并补偿激活、退款和孤儿订单 | 有后台/命令恢复用途 | 删除会破坏历史资金与权益一致性 | 后台恢复入口必须保留 |
| 用户 API | `crmeb/app/api/route/v1.php:123-133,383-386`; `PackageBenefitController` | 公开套餐、购买、我的套餐、权益和协议 | 路由真实存在 | 历史查询、支付结果、权益使用需要 | 继续开放历史/权益；新方案上线前是否隐藏“新购”另立专项 |
| 后台 API | `crmeb/app/adminapi/route/yfth.php:25-44`; `adminapi/.../PackageBenefit.php` | 套餐配置、购买/实例、生命周期与异常恢复 | 路由真实存在 | 运维、退款与恢复必须可操作 | 配置和恢复继续保留 |
| 用户页面 | `template/uni-app/pages/yfth/package/*`; `pages.json:72-120` | 详情、协议确认、支付、我的套餐、时间线 | 页面真实注册 | 既有用户履约与历史查询 | 保留；购买入口隐藏需后续专项验证 |
| 后台页面 | `template/admin/src/pages/yfth/packageBenefit/index.vue`; router/API | 总部套餐管理与恢复 | 真实菜单页面 | 运维现有实例 | 继续开放给既有权限角色 |
| 命令 | `crmeb/config/console.php:24`; `crmeb/command/YfthPackage.php` | 套餐扫描、周期推进或恢复命令入口 | 代码真实存在 | 异常补偿与定时处理 | 必须保留 |
| 验证 | `yfth_package_benefit_contract_check.php`, `real_flow_check.php`, `runtime_check.php` | 约束、真实 MySQL 流程和运行时契约 | 稳定回归基线 | 新方案必须证明未回归旧链 | 不得删除，后续纳入回归 |

### 2.1 真实耦合结论

1. `yfth_package_purchase.order_id/order_sn` 明确绑定 CRMEB `store_order`，支付监听再进入套餐激活；新线下成交没有 CRMEB 订单，因此不能复用购买表或激活服务。
2. `PackageLifecycleServices` 在事务中锁套餐实例和购买记录，并触发身份重算与旧推荐负向事件；修改旧状态语义会同时影响退款、奖励和权益。
3. 预约的服务权益锁、核销最终消费和月度配送均引用既有套餐实例/权益项目。即使新购买入口未来隐藏，历史履约入口仍必须存在。
4. 旧购买意图、订单尝试和恢复服务解决真实“下单后半状态”，不能因新线下产品上线而删除。

## 3. `member_5980` 引用清单

| 分类 | 证据 | 当前语义 |
| --- | --- | --- |
| 常量 | `crmeb/app/services/yfth/YfthConstants.php:19,112` | 角色代码 `member_5980` 与套餐场景常量 |
| 创建/重算 | `PackageInstanceServices::recomputeMemberIdentity()` | 只根据当前有效期内且 `status=active` 的套餐实例写入或关闭身份 active key |
| 激活 | `PackageActivationServices::activateByPaidOrder()` | 支付并激活实例后触发身份重算 |
| 退款/关闭/到期 | `PackageLifecycleServices` | 退款、关闭、到期后重算；不是永久身份 |
| API/DTO | `PackageInstanceServices::myPackages()/userDetail()`; `FranchiseCustomerServices::hasPackage()` | 我的套餐与门店 CRM 客户套餐状态 |
| 预约/核销 | `ServiceAppointmentBookingServices`; `ServiceAppointmentWriteoffServices` | 通过具体套餐实例和 benefit item 锁定/消费服务权益，不只看角色字符串 |
| 推荐 | `ReferralRewardServices` 的 `package_5980` 场景 | 套餐激活/退款/关闭/冻结产生可信正负事件 |
| 前端 | `template/uni-app/libs/yfthContext.js:7`; 工作台客户页；推荐页默认场景 | 身份标签、客户套餐状态、兼容推荐入口 |
| 后台 | `template/admin/src/pages/yfth/foundation/index.vue`; dashboard | 身份统计和基础域管理 |
| 测试 | package/referral/customer/appointment contract 与 real-flow checks | 大量直接字符串和状态契约 |

结论：

- `member_5980` 只由当前有效期内且 `status=active` 的线上套餐实例派生。套餐进入 `refunding`, `refunded`, `closed`, `expired` 等非 active 状态时，生命周期服务触发重新计算；只有仍存在 active 套餐实例时该身份继续有效。新 9800 永久会员第一版不续费，且由目标用户确认的线下成交激活，生命周期和资金事实完全不同。
- 新永久会员不得原地复用 `member_5980`，也不得把旧实例批量改成新会员。建议建立独立会员实例权威表，并在身份投影中使用新的角色代码（建议名待架构审核，例如 `permanent_member`），`member_5980` 只继续表示历史线上套餐会员。
- 不能只隐藏旧入口后删除底层判断：预约、权益领取、核销、客户状态、推荐事件、退款恢复和测试仍依赖旧对象。

## 4. 旧 referral/reward 实现清单

| 对象 | 证据与语义 | 对新方案的结论 |
| --- | --- | --- |
| `yfth_referral_code` | migration `:64-83`; `ReferralRewardServices::userCreateCode()`；`code` 明文唯一保存 | 不能作为新敏感短时码；可保留旧兼容推荐码 |
| `yfth_referral_candidate` | migration `:87-113`; `userBindCandidate():106-156` | `expire_time = bind_time + 90 * 86400`；不能作为长期 active 一级推荐 |
| candidate active key | `candidateActiveKey(scene, uid, phoneHash)`; unique `active_key` | 唯一性含 scene，保护“场景内候选”，不是“全局一个 referred_uid active 关系” |
| `yfth_referral_event` | `recordBusinessEvent():554-597`; unique `(scene,event_type,idempotency_key)` | 可复用可信事件、快照和幂等模式，不复用事件场景枚举 |
| `yfth_referral_attribution` | unique `(scene,business_type,business_id)` | 最终归因绑定具体 business object，不是长期人与人关系 |
| rule/version/item | migration `:169-218`; `currentRule()` | 发布规则版本和多个规则项 | 快照/版本模式可复用，旧表不应承载新循环序列 |
| ledger | migration `:219-259`; `createLedgerForAttribution()` | 一次 attribution 遍历所有 active rule item，逐项创建 ledger |
| ledger unique key | `ledgerUniqueKey()` | 由 attribution/rule item 维度生成；一个事件可有多行，不是 C1 的全局递增循环序号 |
| 状态 | `observing -> valid -> pending_settlement -> settled`; `invalid/reversed` | 状态与扫描/人工标记结算稳定，可复用思想，不应改变旧行含义 |
| snapshot/adjustment | `yfth_reward_ledger_snapshot`, `yfth_reward_adjustment`; `adjustment()` | append-only 快照和冲正模式可复用 |
| settlement record | `yfth_reward_settlement_record` | 当前是人工标记结算，不是钱包/自动打款 |
| 可信事件 | `recordPackageActivatedEvent()` / negative; `recordFranchiseOpenedEvent()` / negative | 来源只允许线上 `package_purchase/package_instance` 和 `franchise_application` |
| scan/reconcile | `adminScan()`、`revalidateLedgerBusiness()`、相关测试 | 可复用扫描和重算证据模式 |
| API/UI | user/admin referral controllers, routes, `pages/yfth/referral/*`, admin `referralReward` | 真实入口存在，默认 `package_5980` 场景 | 保留兼容，未来隐藏需专项 |

### 4.1 新循环序列为何独立

- 新三三制按“直推人 C1 的第 N 笔有效会员/套餐成交”循环 `15%/25%/60%`，序号必须在观察期通过后原子分配，冲正不回收序号。
- 旧 ledger 按 `attribution + rule_item` 展开，一笔事件可能对应多条规则项；其 `ledger_unique_key` 不是一个主体单调递增的 sequence。
- 直接扩展旧表会把旧 5980/加盟开业规则项语义与新线下成交循环序列混在一起，并增加历史扫描、结算和冲正回归风险。
- 建议未来独立 `sequence account + candidate/ledger` 权威模型；可复用规则快照、整数分、观察期、append-only adjustment 和 reconciliation scan 模式。是否拆为两表或三表需在奖励阶段单独审核，阶段一不得实现。

## 5. `yfth_customer_relation` 调查

### 5.1 真实结构与行为

- migration：`20260707110000_create_yfth_customer_relation_tables.php:15-55`。
- 服务：`FranchiseCustomerServices`；控制器：`FranchiseCustomerController`；DAO/Model：`YfthCustomerRelationDao/YfthCustomerRelation` 和 follow 对应类。
- active 唯一约束：`active_key` 唯一，当前创建值是 UID 字符串，因此全表同一 UID 最多一个 active CRM relation，而不是 `uid + store_id`。
- 可信创建来源：已付款且有效的本店 CRMEB 主订单、未取消/拒绝的本店预约、成功的本店核销。客户端只能提交 `source + reference_id`，UID/store_id/owner_uid 由服务端解析。
- 创建记录字段含 `uid`, `store_id`, 首次操作人 `owner_uid`, `source`, `reference_id`, `customer_status`, `bind_time`；跟进记录按 relation 和 store 隔离。
- 权限：`franchisee`, `store_manager`, `store_staff` 可在真实 user-token 门店上下文中操作；普通顾客和 `service_mentor` 被拒绝。
- DTO：用户态门店工作台只返回昵称、头像、脱敏手机号、来源、客户状态、套餐/服务状态和跟进摘要；不返回完整手机号、openid、unionid、owner_uid 或支付字段。
- 当前没有公开的关闭、转移、接管状态机；业务服务主要支持绑定、列表、详情和跟进。历史多行虽然表结构允许 inactive 行，但没有永久归属变更的完整事件链。

### 5.2 定位结论

`yfth_customer_relation` 是**门店 CRM 经营关系**，来源是“已经在门店发生可信订单/预约/核销”；它不是永久法律或经营归属权威表，原因如下：

1. 新永久归属允许通过 active 直推、会员确认和套餐成交形成，现有 source 枚举不包含这些原子入口。
2. 新归属需要总部受控接管、无店状态、历史事件、跨店唯一保护和暂停推荐联动；现服务无这些状态机。
3. `owner_uid` 是首次绑定操作人，不是商家主体或归属权利人。
4. 直接改释会把已有订单/预约/核销形成的 CRM 客户误认为已完成永久归属。

建议关系：新永久归属为权威模型；`yfth_customer_relation` 保持独立 CRM 记录。Stage 1A/1B 完全不写 relation；Stage 1B 只能由查询服务组合展示 attribution 与既有 CRM 摘要。后续可补偿的幂等投影必须单独审核，投影失败不得回滚永久归属；接管后的旧跟进历史保留在原 relation，不搬迁或删除。

## 6. 动态码安全模式调查

### 6.1 可复用模式

- 表：`yfth_service_dynamic_code`；migration `20260703120000_create_yfth_service_writeoff_tables.php:49-74` 与 hardening migration `20260703130000...:9-35`。
- 哈希：只保存 `token_hash` 和 `digital_code_hash`，明文只在生成响应出现；`ServiceAppointmentWriteoffServices::hashSecret()` 使用 SHA-256。
- 短时：`CODE_TTL_SECONDS = 300`，状态 `issued/used/invalidated/expired`。
- 单 active：预约维度 `active_key` 唯一；门店数字码使用 `digital_active_key` 唯一并有限重试冲突。
- 行锁：按 token/digital hash 查询后 `lock(true)`；写核销前再锁预约和权益锁。
- 权限：先解析真实 admin/store scope，再在可信门店范围查询数字码；跨店真实码与随机错误码返回统一安全错误。
- 重放：业务写通过 `IdempotencyRecordServices`，已完成预约返回既有核销结果；成功后清空 active keys。
- 风控：按操作人、可信门店范围、IP 和场景缓存失败次数；预检保持只读。
- 审计：核销记录、预约事件和 `AuditEventServices` 并存，记录操作人、角色、门店、前后状态与原因。

### 6.2 不可复用边界

- 现表强绑定 `appointment_id`、预约门店、核销操作人和 writeoff record；其状态机最终消费服务权益。新身份/会员/套餐码不得写入该表。
- 不复用 6 位数字码的门店输入语义作为客户确认码；新三场景默认使用高熵 token/二维码，并按各自角色与业务对象授权。

### 6.3 新场景所需属性

| scene | 必需绑定 | 消费方与门禁 | active 唯一语义 |
| --- | --- | --- | --- |
| `customer_identity` | 发码 UID、`target_uid`、预期 `store_id`、用途 | 目标用户本人登录生成/展示，可信门店角色扫描；只返回最小身份结果 | 同一目标 UID + 预期门店 + 用途同一时刻一个有效码 |
| `membership_confirmation` | `pending_sale_id`, `target_uid`, `store_id` | 仅目标 UID 登录确认；事务内激活会员 | 一个 pending membership sale 同时一个有效确认码 |
| `package_sale_confirmation` | `target_uid`, `expected_store_id`, package/rule snapshot key | 目标用户生成，预期门店有能力角色扫描 | 一个客户端业务操作/目标/门店同时一个有效码；一个码最多创建一笔 sale |

后续技术方向冻结为“一个新的 `yfth_business_dynamic_code` + 三个独立场景服务”，与预约码表完全分离；`token_hash` 全局唯一，`scene` 只能由服务端设置。该表不在 Stage 1A/1B 创建，具体字段和 migration 仍须在后续动态码阶段审核。

## 7. 前端和菜单入口矩阵

| 入口 | 真实位置 | 当前可达性 | 建议 | 说明 |
| --- | --- | --- | --- | --- |
| CRMEB 首页装修/商城 | uni-app 首页和页面装修体系 | 可访问 | 继续开放 | 新方案明确复用总部统一商城 |
| 5980 套餐详情/购买 | `pages/yfth/package/detail.vue`, agreement/payment/store pages; `v1.php` public/auth routes | 页面与 API 存在 | 新方案上线前评估隐藏“新购”，不删除 | 历史查询、支付结果、退款恢复仍需底层链 |
| 我的套餐/权益时间线 | `my_packages.vue`, `package_detail.vue`, `timeline.vue`, `current_month.vue` | 可访问 | 继续开放 | 既有用户履约入口 |
| 月度权益领取 | `pages/yfth/monthly_benefit/*` | 可访问 | 继续开放 | 依赖旧 benefit item，不能随购买入口隐藏 |
| 服务预约 | `pages/yfth/appointment/*` | 可访问 | 继续开放 | 依赖旧服务权益，也可服务未来独立权益 |
| 动态核销 | 门店工作台 writeoff 页面和后台记录 | 可访问 | 继续开放 | 既有预约履约 |
| 推荐中心/码/台账 | `pages/yfth/referral/*`; API 默认 `package_5980` | 可访问 | 新方案上线前建议隐藏旧“新绑定/新码”入口，历史 ledger 只读保留 | 不能让用户误用 90 天模型；本轮不实施 |
| 门店工作台 | `pages/yfth/workbench/*`; user-token APIs | 可访问 | 继续开放 | 新线下业务未来复用上下文入口 |
| 客户 CRM | workbench customer pages; `/api/yfth/customer/*` | 可访问 | 继续开放，标注 CRM 关系 | 不得显示为永久归属权威 |
| 总部套餐后台 | admin packageBenefit 页面/API/菜单 | 可访问 | 继续开放配置、历史与恢复 | 隐藏新售入口不等于停运后台 |
| 总部推荐后台 | admin referralReward 页面/API/菜单 | 可访问 | 历史查询/扫描/冲正继续开放；旧规则发布权限后续评估 | 兼容旧 ledger |
| 新永久归属/active 推荐 | 尚无 | 不可达 | Stage 1A 只建内部 authority；Stage 1B 才可增加只读展示 | 永久会员完成前不得开放真实推荐绑定 |
| 新会员/线下成交/确认码 | 尚无 | 不可达 | 后续阶段新增 | Stage 1A/1B 明确不做 |

`template/uni-app/api/yfth.js` 和 `template/admin/src/api/yfth.js` 已有 package/referral/customer/appointment/monthly API 封装；`pages.json` 已注册相关用户页，admin router 已注册套餐和推荐页。入口隐藏必须同时检查页面跳转、菜单、深链、API 权限、退款/恢复和历史查询，不可只删页面按钮。

## 8. migration 与数据库兼容事实

- YFTH 迁移普遍使用整数 Unix 时间；旧套餐金额多为 `DECIMAL(12,2)`，后续奖励/额度模块使用整数分。新线下成交和奖励建议统一整数分，避免浮点与多域换算。
- 可空唯一 `active_key` 是现有标准模式：active 行写确定字符串，关闭后置 `NULL`，利用 MySQL 允许多个 `NULL` 保存历史。
- 统一审计表 `yfth_audit_event` 包含 domain/object/action/before/after/operator/role/store/request/reason/IP；统一幂等表在 `(business_domain, action_type, idempotency_key)` 上唯一。
- 快照以 text JSON 保存，但权威关系、金额、状态和外键均为结构化列；新模型应沿用“结构化核心字段 + 不可变规则/凭证摘要快照”，不能用 JSON 代替关系约束。
- migration 事实分层：较新的高风险迁移已形成 `hasTable/hasColumn`、权限 `unique_auth` upsert、精确 `down()` 和半迁移恢复模式；早期 foundation、package、customer 等 migration 部分仍使用一次性 `change()`，不能假设全部具备重复执行或半迁移恢复能力。Stage 1A 必须按当前最高标准重新实现，并在 MySQL 8 strict mode 验证名称/索引、run/rollback/rerun/duplicate run 和半迁移恢复。
- 不应扩展旧表改变语义：`yfth_package_*`, `yfth_referral_candidate`, `yfth_referral_attribution`, `yfth_reward_ledger`, `yfth_customer_relation`, `yfth_service_dynamic_code`。
- 若为兼容查询增加投影字段或关联，必须在具体阶段独立论证；阶段零建议优先新增表，rollback 只撤销新表和新菜单，不触碰旧历史数据。

## 9. 可直接复用的公共能力

1. `AuditEventServices::recordSafely()` 与 `yfth_audit_event`。
2. `IdempotencyRecordServices` 与 `yfth_idempotency_record`。
3. `CurrentBusinessContextServices`：从 user-token 解析 UID、角色和可信门店，不信任客户端 store_id。
4. `StoreCapabilityServices` / `StoreAccessServices`：门店状态和业务能力门禁。
5. 规则版本与成交时不可变快照模式。
6. MySQL 行锁、稳定锁顺序、唯一冲突后重读。
7. `random_bytes`/高熵 token、SHA-256 哈希存储、有限冲突重试。
8. append-only 调整/冲正，不修改原成交或原奖励事实。
9. referral/package recovery 中的 reconciliation scan、dry-run、可重复扫描模式。
10. DTO 白名单、手机号脱敏、跨店统一安全错误和服务端权限二次断言。

## 10. 禁止直接复用的旧模型

- `member_5980`：仅表示旧线上套餐实例派生身份。
- `yfth_package_purchase/instance`：强绑定 CRMEB 订单支付、退款和十个月权益。
- `yfth_referral_candidate`：固定 90 天且 active key 含 scene。
- `yfth_referral_attribution`：绑定单个业务对象，不是长期人与人关系。
- `yfth_reward_ledger`：规则项展开语义，不是循环 sequence account。
- `yfth_customer_relation`：门店 CRM 关系，不是永久归属。
- `yfth_service_dynamic_code`：预约核销专用，不能承载身份、会员或套餐确认。
- CRMEB spread/brokerage/balance/points/order remark：不得承载新归属、奖励或线下成交。

## 11. 不得删除的稳定代码清单

| 模块 | 具体资产 | 保留原因 |
| --- | --- | --- |
| 5980 套餐 | package migrations、全部 Model/DAO/Service/Controller/listener/command | 历史支付、激活、退款、恢复和权益权威链 |
| 套餐路由 | `v1.php` package routes、admin YFTH package routes | 历史查询、支付结果、后台恢复 |
| 套餐页面 | uni-app package 全部页面、admin packageBenefit | 既有用户履约与运维 |
| 套餐测试 | contract/real-flow/runtime checks | 后续新模型回归门禁 |
| 权益与履约 | benefit plan/period/item、monthly fulfillment | 十个月权益与已领取/配送历史 |
| 预约与核销 | booking/query/writeoff services、动态码/核销 migrations 和 tests | 已确认预约与权益最终消费 |
| 旧推荐 | referral/reward migrations、services、routes、UI、tests | 旧 5980/加盟开业归因、观察、结算和冲正 |
| 客户 CRM | customer relation/follow 全链 | 门店经营跟进历史 |
| 基础域 | context/capability/audit/idempotency services 和 migrations | 新旧所有 YFTH 模块共同基础 |
| CRMEB 主链 | 商品/SKU/购物车/order/payment/refund/logistics/page decoration | 总部统一商城正式主链，禁止重写 |

## 12. 旧入口建议

### 继续开放

- CRMEB 总部商城与页面装修。
- 我的套餐、权益时间线、月度领取、预约、核销结果。
- 门店工作台、客户 CRM。
- 总部套餐实例/生命周期/异常恢复，旧推荐历史 ledger/扫描/冲正。

### 新方案上线前建议暂时隐藏

- 面向新用户的旧 5980 在线购买入口。
- 旧 `package_5980` 推荐码创建与候选绑定入口。

隐藏须另立实现任务，覆盖深链/API/菜单/缓存/回滚；本轮未执行。

### 只读保留

- 历史旧推荐候选、归因、ledger、snapshot、adjustment、settlement。
- 已关闭/退款/过期套餐和协议/成交快照。

### 后台恢复入口必须保留

- 支付成功激活补偿、孤儿订单扫描与恢复。
- 套餐退款生命周期处理。
- 推荐 ledger reconciliation/反向事件处理。

### 等待后续专项决定

- 旧套餐配置是否允许继续发布。
- 旧推荐规则发布/结算入口何时冻结。
- 旧购买和推荐入口的产品隐藏时间、角色和灰度策略。

## 13. 阶段一前的真实风险

1. 若把 CRM relation 当永久归属，会把历史本店订单/预约/核销客户直接“永久绑定”。
2. 若复用 90 天 candidate，会产生自动过期、跨 scene 重复关系和会员关闭语义错误。
3. 若复用 `member_5980`，退款/到期重算会错误撤销永久会员，或永久会员污染旧套餐权益判断。
4. 若复用预约码表，会把身份确认与核销权限、门店范围和状态机混杂。
5. 仅靠服务层先查后写无法防并发跨店抢占；Stage 1A 已冻结为每 UID 一行 attribution current、`UNIQUE(uid)`、insert-first/冲突重读和数值 UID 升序行锁。
6. 自动迁移现有 customer relation/referral candidate 会产生无法证明的法律归属；必须默认零自动迁移。
7. 新旧前端入口并存时，用户可能误走旧购买/推荐链；隐藏需单独审核且不能破坏历史查询和恢复。
8. 待定业务参数不得进入默认常量：商城收益比例、C 子分配比例、观察期天数、会员退款下级处置、部分退款冲正、会员福利、无店恢复、行政区 code、隐私和结算凭证。

## 14. 调查证据

关键证据索引（行号以调查基线为准）：

- 最新范围：`docs/YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md:47-76,77-99,101-138,140-171,183-208,216-276,278-395,398-453`。
- package schema：`crmeb/database/migrations/20260624130000_create_yfth_package_benefit_tables.php:33-169,214-276`。
- package snapshot/recovery：`20260624170000_harden_yfth_package_purchase_snapshots.php`; `20260626090000_create_yfth_package_order_attempts.php`。
- package routes：`crmeb/app/api/route/v1.php:123-133,383-386`; `crmeb/app/adminapi/route/yfth.php:25-44`。
- package listeners：`crmeb/app/event.php:38-39`; `crmeb/app/listener/yfth/PackagePaySuccessListener.php:14-31` 及 refund listeners。
- member identity：`PackageInstanceServices::recomputeMemberIdentity()`；`YfthConstants.php:19,112`。
- referral 90 days：`ReferralRewardServices.php:106-156`，尤其 `:149`。
- referral trusted event：`ReferralRewardServices.php:554-640,643-714,811-909`。
- referral schema：`20260710100000_create_yfth_referral_reward_tables.php:64-323`。
- customer CRM：`FranchiseCustomerServices.php:29-68,117-185,188-243,246-317,337-392`; migration `20260707110000...:15-55`。
- dynamic code：`ServiceAppointmentWriteoffServices.php:20-28,304-428,464-611,634-705,763-908`; migrations `20260703120000...:49-114`, `20260703130000...:9-56`。
- user/admin UI registration：`template/uni-app/pages.json`, `template/uni-app/api/yfth.js`, `template/admin/src/router/modules/yfth.js`, `template/admin/src/api/yfth.js`。
- regression assets：`crmeb/tests/yfth_package_benefit_*`, `yfth_referral_reward_*`, `yfth_franchise_customer_*`, `yfth_service_appointment_*`。

本调查没有运行 migration、MySQL、PHP 业务测试、前端构建、H5 构建或 mp-weixin 编译。
