# YFTH Headquarters Mall Stage 0 Data Model Proposal

> 本文件是阶段零设计建议，不是已批准 migration。表名、字段名和状态枚举必须经过独立只读架构审核后才能进入实现。

## 1. 设计原则

1. CRMEB 商品、SKU、购物车、总部商城订单、支付、退款、物流、售后和页面装修主链不改。
2. 新业务使用独立 YFTH 域；旧 5980、旧推荐、客户 CRM 和预约核销保持原语义并存。
3. 不原地改释旧字段、角色代码或状态，不删除历史；关闭 active 行时清空可空唯一键并保留原行。
4. UID、门店、角色、能力、规则、金额和业务状态只从服务端可信上下文与权威记录推导。
5. 成交时固化不可变规则、金额、归属、推荐、凭证摘要和操作人快照。
6. 所有写操作先取得统一幂等记录，业务事务内使用行锁和数据库唯一约束兜底。
7. 跨店冲突不能“后写覆盖”；同店重放返回原结果，异店必须拒绝。
8. 审计使用 `AuditEventServices`；关系/状态历史另写 append-only 业务事件。
9. 金额统一使用整数分；禁止浮点、余额、积分、佣金、CRMEB 分销字段或订单备注承载。
10. JSON 只保存不可变快照和最小私有凭证元数据，权威关系、状态、金额和唯一键必须结构化。

## 2. 权威业务对象

下表中的名称为建议名称。

| 权威对象 | 建议表 | 核心状态/唯一约束 | 与旧表关系 |
| --- | --- | --- | --- |
| 永久 B 商家归属 | `yfth_hq_customer_attribution` | `active/paused/closed`; unique nullable `active_key = uid:{uid}` | 不复用 `yfth_customer_relation`；后者仅 CRM/投影 |
| active 一级推荐 | `yfth_hq_active_referral` | `active/paused/closed/invalid`; unique nullable `active_key = referred:{uid}` | 不迁旧 90 天 candidate |
| 通用线下成交 | `yfth_offline_sale` | `pending_confirmation/confirmed/refund_pending/refunded/cancelled/invalid`; unique sale_no 和业务幂等键 | 不复用 package purchase/CRMEB order |
| 会员产品规则 | `yfth_membership_rule_version` | `draft/published/disabled/archived`; template/version 与 published active key | 不复用旧 package rule |
| 永久会员实例 | `yfth_permanent_membership` | `active/refund_pending/refunded/revoked`; unique nullable `active_key = uid:{uid}` | 与 `member_5980` 分离，可投影新身份代码 |
| 客户身份码 | 统一 `yfth_business_dynamic_code` scene=`customer_identity` | `issued/used/expired/invalidated`; scene+business active key | 不复用预约动态码 |
| 会员确认码 | 同表 scene=`membership_confirmation` | 每 pending sale 一个 active code | 绑定 pending membership sale |
| 套餐成交确认码 | 同表 scene=`package_sale_confirmation` | 一个生成操作一个 active code；一个码一笔 sale | 套餐 sale 与会员分离 |
| 退款/撤销申请 | `yfth_offline_sale_refund_request` | `submitted/approved/rejected/cancelled`; unique request_no/idempotency | 不接 CRMEB refund |
| 归属与关系事件历史 | `yfth_hq_relationship_event` | append-only；event_no/idempotency unique | 与 unified audit 并行，不覆盖旧历史 |

所有对象应包含 `id`, 结构化业务键, 状态, `add_time/update_time`（事件表只需 add_time）、`request_id` 或幂等关联。敏感凭证只保存私有附件 ID/哈希/最小摘要，API DTO 白名单输出。

### 2.1 对象级权威边界

1. **永久 B 商家归属**：建议新表，因为 CRM relation 缺少接管/无店/事件语义。权威字段为 UID、store、source、当前状态、前后归属和 operator；current 行由 UID active key 唯一。关闭只清 active key，历史由原行和 relationship event 保留。所有入口共用审计与业务幂等。
2. **active 一级推荐**：建议新表，因为旧 candidate 固定 90 天且按 scene 唯一。权威字段为 referrer/referred/store/attribution/membership source/起止时间/关闭原因；referred UID active key 唯一。关闭或暂停保留原行和事件，不覆盖旧 candidate。
3. **通用线下成交**：建议新表，因为旧 package purchase 强绑定 CRMEB order/payment。权威字段为 sale_no、business_type、目标 UID、门店、整数分金额、线下付款、私有凭证、operator、规则/归属/推荐快照和状态；sale_no、来源码和业务幂等唯一。原成交不可修改，退款另建申请/冲正记录。
4. **会员产品规则**：建议新 version 表，因为旧 package rule 生成十个月权益且不是永久会员规则。权威字段为 rule_code/version/status/price_cent/term_type/benefit policy/confirmation text/effective range；template+version 唯一、published active key 唯一。成交保存规则快照；发布、停用和复制写统一审计，幂等发布不能生成重复版本。
5. **永久会员实例**：建议新表，因为 `member_5980` 会随旧套餐实例重算。权威字段为 membership_no/UID/store/sale/rule/status/effective/refund/revoke 信息和福利快照；UID active key 唯一。退款/撤销关闭 active key但保留实例、事件和审计；身份表只作投影。
6. **客户身份码**：建议新通用动态码表的独立 scene，因为预约码绑定 writeoff。权威字段为 hash、target UID、store、purpose、TTL、状态、used_by 和 business key；scene+hash、场景 active key 唯一。明文不落库，消费/失效/过期有审计和幂等。
7. **会员确认码**：使用新动态码底座独立 scene，绑定 pending membership sale/target/store。pending sale active key 唯一；消费与会员激活同事务，重放返回原会员/成交，不重复审计业务结果。
8. **套餐成交确认码**：使用新动态码底座独立 scene，绑定 target/expected store/规则与金额快照 key。来源码只能生成一个 sale；过期、异店、跨 scene 和重复消费拒绝，且不调用预约码或旧 package 服务。
9. **退款/撤销申请**：建议新表，因为资金由 B 线下收取，CRMEB refund 没有该支付事实。权威字段为 request_no/sale_id/type/amount_cent/reason/private evidence/operator/status/auditor/times；request_no 和业务幂等唯一，不使用 active key。审批结果追加 sale/refund event 和审计，原 sale/奖励记录不物理修改。
10. **归属与关系事件历史**：建议 append-only 新表，字段为 event_no/domain/object/event/source/before/after store/referrer/status/operator/reason/request/time；事件号与来源幂等唯一，无 active key。它提供业务时间线，`yfth_audit_event` 继续提供通用操作审计，两者不重复充当 current authority。

## 3. 永久商家归属建议

### 3.1 建议字段

- `uid`, `store_id`, `status`, `source_type`, `source_id`, `source_sale_id`。
- `bound_at`, `paused_at`, `closed_at`, `close_reason`, `previous_attribution_id`, `takeover_batch_id`。
- `rule_version`, `source_snapshot`, `operator_uid`, `operator_role_code`, `request_id`。
- `active_key`：仅 active/paused 且仍有门店归属的当前行写 `uid:{uid}`；closed 行置 `NULL`。

### 3.2 一致性规则

- 数据库唯一 `active_key` 保证同一 UID 同一时刻至多一个当前归属；服务层先锁“UID 归属保护行”。为避免首次无行无法锁，建议另建轻量 `yfth_hq_attribution_guard(uid unique)`，或使用等价的确定性用户级 guard。单靠查询空结果 `FOR UPDATE` 不足以稳定串行化首次绑定。
- 直推绑定、会员确认和套餐成交三个入口必须调用同一个 `AttributionAuthorityServices::ensureAttribution(uid, storeId, source)`（建议类名），不能各自复制先查后写逻辑。
- 同店已有归属返回原行并记录幂等结果；异店已有归属返回统一 `customer_attribution_store_conflict`，不得覆盖。
- 总部接管（阶段七）应锁 guard 和旧归属，关闭旧行并清空 active key，再创建新行，写 takeover event；同一事务内保持“最多一个 current”。
- 无店状态建议保留一条关闭后的历史归属并在独立 current-state/guard 上表达 `unassigned`，不要用 `store_id=0` 伪造 active 商家。是否需要独立 `yfth_hq_attribution_current` 表由架构审核决定。
- `yfth_hq_relationship_event` 保存 `attribution_created/paused/closed/taken_over/unassigned/restored`，含 before/after store、source、operator、reason、batch。

### 3.3 与客户 CRM

- `yfth_customer_relation` 不参与永久归属唯一性，也不作为成交校验权威。
- 阶段一 API 可组合查询 attribution + CRM 状态，不写自动投影。
- 后续若需要同步：以 attribution event 为输入，幂等关闭原店 CRM active row、为新店创建独立 CRM row；旧 follow record 不移动。自动投影策略必须单独审核。

## 4. active 一级推荐建议

### 4.1 建议字段

- `referrer_uid`, `referred_uid`, `store_id`, `attribution_id`, `status`。
- `source_type`, `source_id`, `started_at`, `paused_at`, `closed_at`, `close_reason`。
- `referrer_membership_id`, `rule_version`, `relation_snapshot`, `request_id`, `active_key`。
- `active_key = referred:{referred_uid}` 仅 active/paused 当前关系写值；closed/invalid 置 `NULL`。

### 4.2 规则

- 长期有效，不写固定 expire_time；只有会员激活、总部纠错/风控、无店暂停或明确无效事件改变状态。
- 创建前校验 referrer 是 active 永久会员、具有直推资格，且 referrer 当前永久归属与目标将形成的归属门店一致。
- 被推荐人一个 active 关系，数据库唯一兜底；禁止自推荐、循环（至少禁止 referrer=referred，并查询直接反向关系；更深环检测策略待审核）。
- 会员激活事务内关闭关系，`close_reason=membership_activated`，不自动恢复。
- 商家无接管时保留当前行但置 `paused`，active key 是否继续占用应由架构审核决定。建议继续占用，防止暂停期间被其他推荐人抢占；恢复时原行转 active。
- 与旧 candidate 完全并存：不复制其 90 天 expire_time，不把旧 active candidate 自动升级。

## 5. 通用线下成交建议

### 5.1 建议字段

- `sale_no`（不可变、唯一）、`business_type` (`membership/package`)。
- `target_uid`, `store_id`, `attribution_id`, `active_referral_id`（可空）。
- `amount_cent`, `currency`, `payment_method`, `paid_at`, `receipt_no`。
- `private_voucher_attachment_id`, `voucher_hash`；不得在公开 DTO 返回私有凭证。
- `operator_uid`, `operator_role_code`, `rule_version_id`, `membership_rule_version_id`（适用时）。
- `attribution_snapshot`, `referral_snapshot`, `rule_snapshot`, `sale_snapshot`。
- `status`, `confirmed_at`, `confirmed_by_uid`, `refund_status`, `request_id`, `idempotency_key`。
- unique `sale_no`; unique `(business_type, store_id, idempotency_key)`；奖励候选后续 unique `(source_type='offline_sale', source_id=sale_id)`。

### 5.2 两类成交差异

- 会员：B 通过客户身份码得到可信 `target_uid`，登记真实线下收款后先创建 `pending_confirmation`；只有 pending 成功后才生成 `membership_confirmation` 码。目标用户本人确认后原子创建/复用归属、关闭 active 推荐、创建永久会员并确认成交。
- 套餐：目标 C 用户选择预期门店并确认线下购买声明，先生成 `package_sale_confirmation` 码；B 在可信门店上下文扫描后，事务内创建 `confirmed` package sale 和首次归属。它不创建会员、不关闭 active 推荐、不创建 CRMEB order、不消费服务权益。
- 两者共用结构化 sale 权威表，但用不同业务服务、动态码 scene、权限和状态转换。共享底层不可等于共享控制器入口。

## 6. 永久会员实例建议

- 新表 `yfth_permanent_membership`，字段建议：`membership_no`, `uid`, `store_id`, `sale_id`, `rule_version_id`, `status`, `effective_at`, `refund_pending_at`, `invalidated_at`, `invalid_reason`, `benefit_snapshot`, `active_key`。
- 第一版永久有效且不续费，不设置自然 expire_at；可预留 `term_type`/`scheduled_expire_at` 但第一版固定 `permanent`/0，禁止开放期限型状态机。
- active key 按 UID 唯一；唯一冲突后重读，已有 active 返回 already_member，不创建第二实例。
- 直推资格由 active permanent membership 派生，可投影到 `yfth_user_identity` 的新角色代码，但会员表才是权威。身份投影失败需要可重试补偿，不能反向把身份表当会员权威。
- 全额退款审核通过后会员失效、失去直推资格，但不恢复原上级 active relation。既有下级关系处置仍是业务门禁，未冻结前不得实现完整退款上线。
- 与 `member_5980` 完全分离；旧身份继续由 package instance 重算。
- 福利只保存已发布规则快照；具体内容尚未确认，不在阶段一实现。

## 7. 动态码统一底座建议

### 7.1 方案比较

| 维度 | A. 一个通用表，scene 隔离 | B. 三个独立表 |
| --- | --- | --- |
| 安全实现 | 哈希、TTL、重放、清理、active key 一处实现 | 容易复制后分叉 |
| 业务隔离 | 需强制 scene + business type + 服务类白名单 | 物理隔离更强 |
| 索引/运维 | 统一扫描和过期清理 | 三套任务/索引/测试 |
| 错误影响 | 通用服务缺陷影响三 scene | 单场景影响较小 |
| 扩展 | 后续可信确认码可受控新增 scene | 每场景新增表 |

推荐 A：新建独立于预约的统一 `yfth_business_dynamic_code`，业务操作仍由三套场景服务调用。理由是三者安全生命周期相同，差异可由 scene policy 隔离；统一实现能减少明文、TTL、重放和清理逻辑分叉。该建议待 Architecture Auditor 决定。

### 7.2 建议字段与约束

- `scene`, `business_type`, `business_id`, `business_key`, `target_uid`, `store_id`。
- `token_hash`（unique 或 scene+hash unique），不保存明文 token。
- `status`, `issued_at`, `expire_at`, `used_at`, `used_by_uid`, `used_by_role`, `invalidated_at`, `invalid_reason`。
- `request_id`, `idempotency_key`, `active_key`。
- active key 建议：
  - `customer_identity:{target_uid}:{store_id}:{purpose}`
  - `membership_confirmation:{pending_sale_id}`
  - `package_sale_confirmation:{target_uid}:{store_id}:{client_operation_id}`
- unique `(scene, token_hash)`；unique nullable `active_key`；unique `(scene, idempotency_key)`（若幂等表已全局保护，仍保留业务索引便于核对）。
- 码消费必须先按 hash+scene 定位并锁行，再校验登录 UID、可信门店、业务对象、TTL 和 status；业务写、used 状态和 active key 清理同事务提交。
- 重放：同幂等键同请求返回原业务结果；相同 token 已 used 且关联业务已成功时返回原结果，不再次写入；请求摘要不同则拒绝。
- 过期：读取时 fail closed；后台扫描批量把 issued 且过期行置 expired 并清 active key。扫描幂等且不物理删历史。
- 跨店：先解析可信上下文，再验证 code.store_id；错误响应不泄漏其他门店码是否存在。客户本人确认场景同时校验 target UID。

## 8. 原子事务与锁顺序

统一原则：先取得 `yfth_idempotency_record`；进入业务事务后，所有涉及同一 UID 的流程按 `attribution_guard -> current attribution -> active referral -> membership` 的相对顺序。业务码/成交是外层业务对象锁，但进入共享关系域后不得逆序。

### 8.1 一级直推绑定创建永久归属

1. 幂等 begin。
2. 锁 `attribution_guard(target_uid)`。
3. 锁 current attribution；同店复用、异店拒绝、无归属创建。
4. 锁 `active referral guard/referred_uid` 并读取 active relation。
5. 校验 referrer membership/attribution；创建 relation 和事件。
6. 审计、幂等 complete。

### 8.2 会员 pending 成交创建

1. 幂等 begin。
2. 锁 `customer_identity` code。
3. 校验目标 UID、可信门店和码未使用。
4. 锁同店同收据/操作幂等键；校验 current attribution（异店拒绝，但此步不创建归属）。
5. 创建 pending sale 与私有凭证摘要，消费身份码。
6. 创建/刷新 membership confirmation code。

### 8.3 会员确认激活

1. 幂等 begin。
2. 锁 membership confirmation code。
3. 锁 pending sale。
4. 锁 attribution guard 和 current attribution；无则创建，同店复用，异店拒绝。
5. 锁 active referral；校验 store 一致。
6. 锁 permanent membership guard/current membership；已有 active 拒绝。
7. 固化快照；预留奖励候选唯一源（阶段四才落奖励表）。
8. 关闭 active referral；创建 membership；确认 sale；消费 code；写事件/审计。

### 8.4 套餐成交确认

1. 幂等 begin。
2. 锁 package confirmation code。
3. 锁 attribution guard/current attribution；同店复用、异店拒绝、无则创建。
4. 锁 active referral并校验门店一致。
5. 按 code/business operation unique key 创建 confirmed package sale 和快照。
6. 消费 code，写事件/审计；不锁/改 membership，不关闭 referral。

### 8.5 并发与重放

- 并发首次归属：两店同时请求都锁同一 UID guard；先提交者建立归属，后者重读，同店返回原归属，异店拒绝。唯一 active key 是最终兜底。
- 同一目标重复扫码：code 行锁串行化；第二请求重读 used 状态并返回原 sale/membership。
- 同一成交重放：幂等表 request hash 必须一致；sale unique key 和 code->business_id 双重保护。
- 锁多个 UID（未来接管批次）时按 UID 升序；锁多个业务对象按表序和主键升序，禁止请求顺序决定锁序。

## 9. 唯一约束和幂等键建议

| 目标 | 数据库约束/键建议 |
| --- | --- |
| 一个 UID 一个 active 永久归属 | unique nullable `yfth_hq_customer_attribution.active_key = uid:{uid}` + guard.uid unique |
| 一个 referred_uid 一个 current active/paused 推荐 | unique nullable `yfth_hq_active_referral.active_key = referred:{uid}` |
| 一个 UID 一个 active 永久会员 | unique nullable `yfth_permanent_membership.active_key = uid:{uid}` |
| 一个 pending 会员成交一个有效确认码 | dynamic code unique `active_key = membership_confirmation:{sale_id}` |
| 一个套餐码一个成交 | code unique token hash + sale unique `source_code_id` |
| 一个 source sale 一个奖励候选 | future unique `(source_type, source_id, reward_scene)` |
| 一个业务请求一个幂等结果 | existing unique `(business_domain, action_type, idempotency_key)` + request hash |
| 接管批次 | future unique `takeover_batch_no`; detail unique `(batch_id, uid)` |
| 线下成交 | unique `sale_no`; unique `(business_type, store_id, idempotency_key)` |
| 关系事件 | unique `event_no` 或 `(event_type, source_type, source_id, idempotency_key)` |

## 10. 新旧模型共存方案

- 旧线上 5980 不迁入新线下会员；旧 package tables、订单监听、退款、恢复、权益和履约保持运行。
- `member_5980` 不转换为新永久会员；两个身份代码和两个权威实例并存。
- 旧 referral candidate 不转换为长期 active relation；旧 `package_5980/franchise_opening` 事件继续进入旧 reward ledger。
- 旧 reward ledger 不充当循环奖励序列；新奖励阶段建立独立 sequence authority。
- `yfth_customer_relation` 不升级为永久归属；继续作为 CRM 关系，必要时通过后续受控投影同步。
- 预约 `yfth_service_dynamic_code` 继续只服务预约核销；新确认码使用新表。
- 新入口只调用新 attribution/referral authority services；不得调用 `PackagePurchaseServices`, `PackageActivationServices`, `ReferralRewardServices::userBindCandidate()` 或预约 writeoff 服务完成新业务。
- 旧入口未来可受控隐藏 UI 和“新建”权限，但历史查询、退款、恢复、权益履约和 reconciliation API 必须保留。隐藏前应做入口契约和回滚专项。

## 11. 初始数据策略建议

- 仓库证明代码、migration、测试和历史 closure 存在，但没有生产库只读证据；不能断言生产无 package/customer/referral 数据。
- 第一版建议**零自动迁移启动新模型**：新 attribution/referral/membership 表初始为空，只由新方案上线后的可信事件写入。
- 现有 `yfth_customer_relation` 可生成“待人工核验候选报告”，但不得自动成为永久归属；核验至少需要 UID、门店主体有效性、来源记录、门店经营状态、冲突 relation 和用户授权证据。
- 旧 referral candidate 即使未过期，也不得自动成为长期 active relation；若业务要求承接，应另立数据重建专项和用户确认/纠错机制。
- 旧 `member_5980`、package purchase/instance、reward ledger、appointment/writeoff 和 fulfillment 数据只保留兼容读取与原生命周期处理。
- 迁移前只读预检必须输出：同 UID 多来源冲突、失效门店、无效 UID、旧 active relation、旧 candidate、现有会员/套餐与隐私证据缺口。冲突数据不得自动删除或“最后写入者获胜”。

## 12. migration 与 rollback 边界建议

- 阶段一建议新增：attribution guard/current/history（具体拆分待审核）、active referral、relationship event；不扩展旧业务表。
- 后续阶段再新增 offline sale、membership rule/version、permanent membership、business dynamic code、refund request；不得在阶段一预建未使用表。
- 明确禁止改变：旧 package/referral/customer/service dynamic code 的 status、active_key、scene、金额和外键语义；禁止重命名 `member_5980/package_5980`。
- rollback 只删除本阶段新增菜单权限和新表；不修改、清理或回填旧表。
- 菜单/API 权限按 `unique_auth` 幂等 seed，down 精确删除本模块权限；服务端仍须显式角色、门店和 capability 断言。
- migration 必须支持 MySQL 8.0 strict mode `run -> rollback -t 0 -> rerun -> duplicate run`，并验证半迁移恢复。
- 上线前先运行唯一冲突只读报告；发现冲突立即阻断 migration，不自动关闭或删除用户数据。
- active key 长度需控制在现有索引安全范围；金额 `BIGINT/INTEGER` 分、时间按仓库 Unix integer 规范；私有凭证避免大 JSON。

## 13. 阶段一建议范围

只允许：

1. 永久 B 商家归属权威模型、UID guard、事件历史。
2. active 一级推荐权威模型及其与归属的一致性服务。
3. 共用核心服务：current attribution query、同店幂等、异店拒绝、可信上下文和审计。
4. current attribution API 与最小只读展示。
5. 必要 migration、权限、contract/real-flow/MySQL 并发测试。

明确不做：会员码、客户身份码、会员成交、永久会员、套餐成交、套餐确认码、三三制金额、奖励候选/序号、总部商城收益、结算、退款、接管、CRM 投影自动同步、旧入口隐藏和生产部署。

## 14. 阶段一验收建议

- MySQL 8 strict `run/rollback/rerun/duplicate run` 和半迁移恢复。
- 同 UID active attribution 唯一、referred UID active relation 唯一。
- 两进程并发跨店首次绑定：一店成功、另一店明确拒绝，无双 active。
- 同店并发/重复幂等：返回同一 attribution/relation，不重复事件。
- active referral.store_id 与 attribution.store_id 始终一致。
- 普通顾客只读本人 current attribution；写入口仅限产品冻结的可信流程/角色，不能提交 owner/store/role。
- DTO 白名单，不泄漏手机号、openid、unionid、私有凭证、内部 operator 或审计 payload。
- 所有状态变更写 relationship event 和 unified audit；幂等重放不重复审计业务事件。
- 全量旧 package/referral/customer/appointment contract 回归，证明旧 schema、服务和入口不变。
- CRMEB 商品、订单、支付、退款、库存和分销主链 diff 为零。

## 15. 待架构审核决策

以下是技术架构决策，不交给业务用户临场选择：

1. 首次归属并发 guard 采用独立 `attribution_guard`，还是“current authority 单行 + 历史 event”双表模型。
2. 无店状态是否保留 active key；建议用 guard/current state 表达 unassigned，避免 `store_id=0` active 归属。
3. paused active referral 是否继续占用 referred UID active key；建议占用以防抢占。
4. relationship event 是 attribution/referral 共表还是各自事件表；需权衡查询隔离与统一追踪。
5. 通用动态码表的 scene policy 是否足够隔离，还是三表物理隔离；本建议倾向统一新表。
6. permanent membership 是否投影新 `yfth_user_identity` role，以及投影失败补偿边界。
7. `yfth_customer_relation` 的只读组合查询和未来投影同步契约，尤其接管后跟进历史归属。
8. 循环奖励未来使用 `sequence account + candidate + ledger/adjustment` 的表边界，且是否复用旧 scan 基础类而不共表。
9. 阶段一 current attribution API 的授权与隐私 DTO，是否允许未绑定用户看到推荐人最小展示信息。
10. migration 表名、索引长度、锁顺序和两进程并发验证方案。

## 16. 仍待项目主控确认的业务参数及阻塞阶段

| 参数 | 阻塞阶段 |
| --- | --- |
| B 商家总部商城收益比例、C 普通商品子分配比例 | 阶段五规则与上线 |
| 三三制观察期默认天数 | 阶段四真实解冻与上线 |
| 会员全额退款后既有下级 active 关系处置 | 阶段二完整退款、阶段四奖励 |
| 部分退款冲正比例 | 阶段二退款与阶段四冲正 |
| 会员福利具体内容 | 阶段二会员规则发布 |
| 无接管状态恢复条件 | 阶段七接管 |
| 行政区 code 来源 | 阶段七接管候选 |
| 隐私授权规则 | 对应敏感页面/API 上线前 |
| 结算凭证标准 | 阶段六结算 |

这些参数不得由开发人员写入默认常量。阶段一归属与 active 推荐底座不得提前实现其业务结果。
