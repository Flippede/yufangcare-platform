# YFTH Headquarters Mall Stage 0 Data Model Proposal

> 本文件是阶段零架构审核 P1/P2 整改后的冻结设计，不是已批准 migration，也不表示 Stage 1A 或 Stage 1B 已获开发授权。实现前仍须再次通过阶段零只读架构复核并取得项目主控的分阶段授权。

## 1. 设计原则

1. CRMEB 商品、SKU、购物车、总部商城订单、支付、退款、物流、售后和页面装修主链不改。
2. 新业务使用独立 YFTH 域；旧 5980、旧推荐、客户 CRM 和预约核销保持原语义并存。
3. 不原地改释旧字段、角色代码或状态，不删除历史，不使用“最后写入者获胜”。
4. UID、门店、角色、能力、规则和业务状态只从服务端可信上下文与权威记录推导。
5. current authority 表示当前事实；独立 append-only authority event 表示权威历史；两者必须同事务更新。
6. 所有写操作先取得统一幂等记录，业务事务内使用稳定行锁顺序和数据库唯一约束兜底。
7. 跨店冲突不能覆盖：同店重放返回原结果，异店必须拒绝。
8. authority event 与 `AuditEventServices` 职责分离；通用安全审计不能替代权威业务事件。
9. 金额统一使用整数分；禁止余额、积分、佣金、CRMEB 分销字段或订单备注承载新业务。
10. JSON 只保存不可变快照和最小私有凭证元数据，权威关系、状态、金额和唯一键必须结构化。

## 2. Stage 1A 冻结权威模型

Stage 1A 只创建四张新表：

1. `yfth_hq_customer_attribution_current`
2. `yfth_hq_customer_attribution_event`
3. `yfth_hq_active_referral_current`
4. `yfth_hq_active_referral_event`

不创建独立 attribution guard 表，不创建通用 relationship event 表，不扩展任何旧表。

### 2.1 `yfth_hq_customer_attribution_current`

每个 CRMEB 用户 UID 永久最多一行 current authority。该行同时承担当前归属权威、UID 级并发锁载体以及当前无店、暂停和关闭状态。

建议字段：

- `id`
- `uid`：与 CRMEB `eb_user.uid` 兼容的无符号数值类型
- `store_id`
- `status`
- `authority_version`
- `source_type`
- `source_id`
- `bound_at`
- `paused_at`
- `closed_at`
- `close_reason`
- `last_event_id`
- `add_time`
- `update_time`

唯一约束使用 `UNIQUE(uid)`，不使用字符串 `uid:{uid}` 或 nullable `active_key`。同一 UID 不通过新增第二条 current 行保存历史，也不物理删除后重新绑定。

#### 首次建行规则

1. 首次需要处理某 UID 时，在业务事务外或事务开始阶段通过确定性的 insert-first/原子 upsert 语义确保 current 行存在。
2. 初始占位行固定为 `status=unassigned`, `store_id=0`, `authority_version=0`。
3. 并发唯一冲突表示其他请求已经创建该行；当前请求必须重新读取，不能当成业务失败或覆盖。
4. 后续业务失败时保留无害占位行，不删除。
5. 进入业务事务后按 UID 锁定 current 行；不得依赖对不存在记录执行 `FOR UPDATE` 来串行化首次绑定。
6. 文档不预设超出现有框架能力的具体 SQL，Stage 1A 实现须用仓库可验证的 insert-first/冲突重读方式完成上述语义。

#### 状态语义

| 状态 | store_id | 权威语义 | 允许的普通业务行为 |
| --- | ---: | --- | --- |
| `active` | `> 0` | 当前有效 B 商家归属 | 同店幂等复用；异店拒绝 |
| `paused` | `> 0` | 保留原归属，但因无接管、风控或受控原因暂停部分能力 | 继续占用该 UID authority；禁止其他门店/推荐人抢占 |
| `unassigned` | `0` | 当前没有 B 商家归属；可为初始空状态或未来受控无店状态 | 后续可信首次归属可转 active |
| `closed` | `0` | 受控纠错、账号关闭或总部流程关闭 | 普通入口不得恢复；未来总部流程决定是否重开 |

明确禁止：

- `store_id=0` 却解释为 active 商家。
- paused 时清除归属或允许新门店抢占。
- 新建第二条 current 行保存历史。
- 物理删除 current 行后重新绑定。
- 普通业务入口换店、覆盖或恢复 closed。

#### 归属变化规则

每次状态、门店或来源变化必须：

1. 锁定 current 行。
2. 校验当前版本和状态转换。
3. `authority_version` 严格加一。
4. 更新 current 行。
5. 同事务写入 `yfth_hq_customer_attribution_event`。
6. event 成功后回写/确认 `last_event_id`。
7. event 写入失败时整体回滚，不允许只有 current 变化。

### 2.2 `yfth_hq_customer_attribution_event`

归属事件是 append-only 权威历史，不与推荐事件共表，不使用 `AuditEventServices::recordSafely()` 代替。

建议字段：

- `id`, `event_no`
- `attribution_current_id`, `uid`, `authority_version`
- `event_type`
- `before_store_id`, `after_store_id`
- `before_status`, `after_status`
- `source_type`, `source_id`, `source_unique_key`
- `operator_uid`, `operator_role_code`
- `reason`, `request_id`, `add_time`

约束与索引：

- `UNIQUE(event_no)`
- `UNIQUE(attribution_current_id, authority_version)`
- nullable `UNIQUE(source_unique_key)`
- `INDEX(uid, add_time)`
- `INDEX(event_type, add_time)`
- `INDEX(source_type, source_id)`

事件只追加，不更新原事件，不物理删除。`source_unique_key` 用于阻止同一可信来源重复改变归属；不适用时必须为 `NULL`，不能用空字符串制造全局冲突。

### 2.3 `yfth_hq_active_referral_current`

该表的一行表示一段当前或历史推荐关系。closed/invalid 后如未来允许建立新的合法关系，应创建新 relation 行；paused 恢复则更新原行。

建议字段：

- `id`, `relation_no`
- `referrer_uid`, `referred_uid`, `store_id`
- `attribution_current_id`
- `status`, `active_referred_uid`
- `source_type`, `source_id`, `source_unique_key`
- `started_at`, `paused_at`, `closed_at`, `close_reason`
- `relation_version`, `request_id`
- `add_time`, `update_time`

约束与索引：

- `UNIQUE(relation_no)`
- nullable `UNIQUE(active_referred_uid)`
- nullable `UNIQUE(source_unique_key)` 或语义等价的来源唯一约束
- `INDEX(referrer_uid, status)`
- `INDEX(referred_uid, status)`
- `INDEX(store_id, status)`
- `INDEX(status, update_time)`

`active_referred_uid` 使用与 UID 一致的可空无符号数值类型，不使用字符串 `referred:{uid}`。

#### 状态语义

| 状态 | active_referred_uid | 语义 |
| --- | --- | --- |
| `active` | `referred_uid` | 长期有效一级推荐，不设置 90 天到期 |
| `paused` | `referred_uid` | 关系保留并继续占位；暂停期间禁止其他推荐人抢占 |
| `closed` | `NULL` | 正常业务关闭，例如未来 `membership_activated`；历史保留 |
| `invalid` | `NULL` | 风控、无效绑定或总部纠错；历史保留 |

冻结规则：

- paused -> active 更新原 relation 行并增加 `relation_version`。
- active -> paused/closed/invalid 同事务写 referral event。
- 因 `membership_activated` 关闭的原关系永不恢复。
- closed/invalid 后只有未来明确合法入口才可创建新 relation。
- 与旧 90 天 `yfth_referral_candidate` 完全并行，不自动转换。

### 2.4 `yfth_hq_active_referral_event`

推荐事件是独立 append-only 权威历史，不与 attribution event 共表。

建议字段：

- `id`, `event_no`
- `referral_current_id`, `relation_no`, `relation_version`
- `referrer_uid`, `referred_uid`, `store_id`
- `event_type`, `before_status`, `after_status`
- `source_type`, `source_id`, `source_unique_key`
- `operator_uid`, `operator_role_code`
- `reason`, `request_id`, `add_time`

约束与索引：

- `UNIQUE(event_no)`
- `UNIQUE(referral_current_id, relation_version)`
- nullable `UNIQUE(source_unique_key)`
- `INDEX(referrer_uid, add_time)`
- `INDEX(referred_uid, add_time)`
- `INDEX(event_type, add_time)`

关系状态变化和 event 写入必须同事务；事件失败则关系变化回滚。幂等重放返回原业务结果，不重复写 authority event。

## 3. Authority Event 与统一审计边界

- attribution event 和 referral event 分表，是 current 状态变化的业务权威历史。
- authority event 必须在 current 状态变化的同一数据库事务中强制写入；不得用 `recordSafely()` 作为唯一事件路径。
- `AuditEventServices::recordSafely()` 继续用于通用操作审计、观察和追责，但不成为 current authority 或关系时间线权威。
- unified audit 写入失败不能删除、覆盖或回滚已在业务事务中成功持久化的 authority event；具体失败处置沿用统一审计策略。
- 幂等重放不写新的 authority event。是否记录 replay audit 由现有统一审计策略决定，但不得伪造新业务状态事件。
- authority event 的 before/after 字段应结构化，敏感完整快照不得通过普通用户或门店 DTO 输出。

## 4. 永久归属核心规则

- 直推绑定、未来会员确认和未来套餐成交必须调用同一归属 authority 服务，不复制先查后写逻辑。
- `active` 同店请求幂等返回 current；异店请求拒绝。
- `paused` 不允许普通入口重新归属，即使请求门店与原店不同；恢复只由未来受控流程更新同一行。
- `unassigned` 可由可信首次归属转为 active；current 行不新增，`authority_version + 1` 并写事件。
- `closed` 不允许 Stage 1A/1B 或普通业务入口恢复。
- Stage 1A 不实现暂停、恢复、关闭、接管或总部人工改绑的生产入口；这些状态仅冻结模型和内部状态机边界。

## 5. 推荐资格策略与 fail-closed

Stage 1A 必须定义独立资格策略边界，建议名称 `ReferralQualificationPolicy`，或采用符合现有项目命名的等价接口。

生产实现必须：

1. 只查询未来永久会员权威实例。
2. 永久会员权威尚未实现时 fail closed，返回“推荐资格不可用/尚未具备”。
3. 不得以 `member_5980`、`yfth_user_identity` 投影、旧 candidate、CRM relation 或前端角色代替资格。
4. 不得提供 `force_create_referral`, `skip_membership_check`、环境变量、命令、后台菜单或 HTTP 绕过入口。

测试允许在测试代码或明确隔离的 test-only provider 中注入资格结果，但该 provider：

- 不注册到生产容器。
- 不暴露 HTTP、后台菜单、命令或运行时开关。
- 只用于验证模型、锁顺序、唯一约束和状态机。

因此 Stage 1A 可以实现和测试 referral authority 内部模型，但不能开放真实生产推荐关系创建。真实推荐绑定必须等永久会员权威完成并经过后续独立授权。

## 6. Stage 1A 统一锁顺序与并发规则

### 6.1 双 UID 统一锁序

所有同时读取或修改 referrer/referred 关系的操作必须遵循：

1. 完成幂等 begin。
2. 收集本次操作涉及的全部用户 UID。
3. 去重并按数值 UID 升序排序。
4. 以 insert-first/冲突重读方式确保每个 UID 的 attribution current 行存在。
5. 按 UID 升序逐行锁定 `yfth_hq_customer_attribution_current`。
6. 完成全部归属和门店一致性校验。
7. 再锁 referred_uid 对应的 active referral current 或推荐唯一保护范围。
8. 最后锁 referrer 的永久会员权威，或调用持锁语义明确的资格策略。
9. 写 attribution/referral current。
10. 同事务写对应 authority event。
11. 提交后再执行非权威的安全审计、通知或异步投影。

禁止：

- 先锁 referred referral，再反向锁 referrer attribution。
- 按请求参数顺序锁多个 UID。
- 不同服务分别采用 referrer->referred 与 referred->referrer。
- 持有 referral 锁后调用会反向锁较小 UID attribution 的服务。
- 仅依赖唯一冲突替代稳定锁顺序。

### 6.2 环路门禁

- 必须拒绝 `referrer_uid = referred_uid`。
- 必须查询并拒绝直接反向 current 关系，禁止 A 推荐 B 与 B 同时推荐 A。
- 系统只做一级推荐，更深层关系不产生多级奖励；领域服务仍应提供有限 current 关系环检查。
- 是否扩展完整图遍历列为后续 P2，不阻塞 Stage 1A，但不得放过直接双向环。

### 6.3 deadlock 与 lock wait retry

- 只对数据库 deadlock 或 lock wait timeout 有限重试。
- 最多重试 3 次，每次短随机退避。
- 每次重试重新开启完整事务并重新读取权威状态。
- 普通业务异常、权限异常、唯一业务冲突和数据不一致不得重试。
- 最终失败写技术日志并返回失败，不伪造成功。

### 6.4 并发测试矩阵

Stage 1A 必须覆盖：

- referrer UID 小于 referred UID。
- referrer UID 大于 referred UID。
- 两个用户互相并发推荐。
- 两个 referrer 同时竞争同一 referred。
- 同一 referrer 同时处理多个 referred。
- 两店并发首次绑定同一 referred。
- 同店同来源重放。
- authority event 写入失败回滚 current。

## 7. Stage 1A / Stage 1B 实施范围

### 7.1 Stage 1A - Authority Foundation

允许：

- 本文冻结的四张表。
- 对应 Model、DAO、Repository 或符合现有结构的等价分层。
- 永久归属 current authority 内部领域服务。
- active 一级推荐内部领域服务。
- `ReferralQualificationPolicy` 等价资格边界。
- attribution/referral 一致性校验。
- 幂等、authority event、统一审计。
- migration、权限及 contract/real-flow/concurrency 测试。

禁止：

- 用户端或门店端真实推荐绑定 API。
- 总部 fixture API、后台绕过资格的推荐创建入口或生产开关。
- `customer_identity`, `membership_confirmation`, `package_sale_confirmation`。
- 线下成交、永久会员、奖励金额/序号、收益、退款、结算、接管。
- CRM 投影、旧入口隐藏和生产部署。

### 7.2 Stage 1B - Read-only Surface

Stage 1A 完成并通过独立架构审核后，项目主控才能决定是否授权 Stage 1B。

允许：

- 用户本人 current attribution 只读 API。
- 门店 current attribution 最小只读 API。
- 总部 attribution/referral/event 只读 API。
- 最小只读页面或现有页面中的只读展示。
- 权限、DTO、跨店隔离和 HTTP 验证。

禁止：

- 创建推荐关系或修改归属。
- 暂停、恢复、关闭、接管、总部人工改绑或用户换店。
- 会员、成交、奖励、退款或结算写入。

阶段顺序固定为：Stage 1A 开发 -> 独立审核 -> 项目主控决定 Stage 1B -> Stage 1B 开发 -> 再次审核。永久会员权威完成前，任何阶段都不得开放真实推荐绑定。

## 8. API、权限和隐私矩阵

### 8.1 用户本人 API

- 认证：CRMEB user-token，只能读取当前登录 UID。
- 允许字段：`has_attribution`, `attribution_status`, 公开门店 display name/logo、现有公开接口允许的区县级位置、`bound_at`, paused/unassigned 安全提示、`has_active_referral`。
- 第一版只显示是否有 active 推荐关系，不显示推荐人个人信息。
- 禁止：`referrer_uid`、推荐人昵称/头像/手机号、`relation_no`, `source_id`, operator, reason, request_id 和原始事件内容。

### 8.2 门店 API

- 仅允许 `franchisee`, `store_manager`。
- 默认拒绝 `store_staff`, `service_mentor`, 普通用户、城市合伙人和无 current store context 账号。
- 服务端从 user-token 与 `CurrentBusinessContextServices` 解析 `current_store_id`，不信任前端 store_id。
- 只返回当前门店归属客户的脱敏用户摘要、attribution status、bound_at、source_type 安全标签、has_active_referral 和必要业务状态摘要。
- 禁止跨店列表、详情和统计。

### 8.3 总部 API

- 必须使用 admin-token、`AdminCheckRoleMiddleware`、显式 `SystemRoleServices::assertApiAuthForAdmin()` 和总部范围断言。
- Stage 1B 只允许 current attribution 列表/详情、active referral 只读列表/详情，以及两类 event 只读时间线。
- 禁止人工改绑、接管、暂停、恢复、关闭、fixture 创建、强制推荐和会员资格绕过。
- 完整事件、操作人和内部 reason 仅允许具有独立明确权限的总部角色读取。

### 8.4 所有 API 禁止输出

- 完整手机号、地址明细、身份证、openid、unionid。
- 私有付款凭证、request hash、幂等键、内部 operator payload、原始审计 JSON。
- 其他门店数据、未经授权的 reason、内部锁/索引/异常细节。

## 9. Stage 1A migration 与索引冻结

### 9.1 `yfth_hq_customer_attribution_current`

- `UNIQUE(uid)`
- `INDEX(store_id, status, uid)`
- `INDEX(status, update_time)`
- UID/store 使用与仓库主键兼容的无符号整数类型
- 不使用字符串 active_key

### 9.2 `yfth_hq_customer_attribution_event`

- `UNIQUE(event_no)`
- `UNIQUE(attribution_current_id, authority_version)`
- nullable `UNIQUE(source_unique_key)`
- `INDEX(uid, add_time)`
- `INDEX(event_type, add_time)`
- `INDEX(source_type, source_id)`

### 9.3 `yfth_hq_active_referral_current`

- `UNIQUE(relation_no)`
- nullable `UNIQUE(active_referred_uid)`
- nullable `UNIQUE(source_unique_key)` 或等价来源唯一约束
- `INDEX(referrer_uid, status)`
- `INDEX(referred_uid, status)`
- `INDEX(store_id, status)`
- `INDEX(status, update_time)`

### 9.4 `yfth_hq_active_referral_event`

- `UNIQUE(event_no)`
- `UNIQUE(referral_current_id, relation_version)`
- nullable `UNIQUE(source_unique_key)`
- `INDEX(referrer_uid, add_time)`
- `INDEX(referred_uid, add_time)`
- `INDEX(event_type, add_time)`

其他迁移要求：

- nullable unique 只用于 referral active/paused 占位和可空 source key。
- 不强制新增数据库外键；引用完整性由服务层、索引和测试保证。
- 使用显式 `up()/down()`；down 只删除本阶段新增权限和四张新表，不修改旧表或旧数据。
- 同名表存在时必须校验列、类型和索引签名；签名不一致立即失败，不能只因 `hasTable()` 为 true 就跳过。
- 必须在 MySQL 8.0.46 strict mode 验证 run、rollback、rerun、duplicate run 和构造半迁移恢复。
- Stage 1A 按当前最高迁移标准实现，不能假定早期 foundation/package/customer `change()` migration 已具备重复执行或半迁移恢复能力。

## 10. 旧数据与 schema migration 门禁

- Stage 1A 新权威表采用零自动迁移（zero automatic migration），创建后为空是预期状态，不阻塞 schema migration。
- 现有 `yfth_customer_relation`、旧 candidate、`member_5980` 或旧 ledger 的冲突不阻塞创建空新表。
- 旧数据冲突报告用于阻塞旧数据导入、人工迁移、真实写入口开放和生产灰度。
- 只有以下情况阻塞 schema migration：
  - 新表同名但结构签名不一致。
  - 新表内部已有不符合新唯一约束的数据。
  - 半迁移状态无法安全恢复。
  - 菜单权限或 migration 记录存在不可恢复冲突。
- Stage 1A 不开发人工导入工具，不导入 customer relation、candidate、`member_5980` 或旧 ledger。
- 生产前只读报告后仍需独立数据迁移专项和架构审核；不得自动删除、关闭或覆盖冲突数据。

## 11. 后续通用线下成交与会员模型

以下对象不是 Stage 1A/1B 范围，仅冻结后续边界：

### 11.1 `yfth_offline_sale`

- 支持 `membership/package`，使用不可变 `sale_no`、target UID、store、整数分金额、线下付款、私有凭证、operator、规则/归属/推荐快照、状态和幂等键。
- 会员成交先 `pending_confirmation`，目标用户确认后激活；套餐成交由 C 生成目标门店确认码，B 在可信门店上下文确认。
- 不复用 CRMEB order 或旧 package purchase，不创建 store_order，不消费预约权益。

### 11.2 会员规则与永久会员

- 会员规则使用独立 version authority；成交固化规则、价格和福利快照。
- `yfth_permanent_membership` 是永久会员权威，同一 UID 至多一个 active 实例。
- 第一版永久、不续费；退款不恢复原上级 active referral。
- `member_5980` 继续只表示旧线上套餐身份，不能作为新会员或推荐资格。

### 11.3 退款/撤销

- 使用独立 `yfth_offline_sale_refund_request`，因为线下资金不在 CRMEB refund 主链。
- 原 sale 不物理删除；退款、审核和后续冲正追加事件/调整。
- 会员退款后既有下级关系处置和部分退款比例仍是业务门禁。

## 12. 新动态码后续冻结

- 未来采用一个新的 `yfth_business_dynamic_code`，与预约 `yfth_service_dynamic_code` 完全分离。
- `token_hash` 全局唯一；不保存明文 token。
- scene 只能由服务端场景服务设置，固定为 `customer_identity`, `membership_confirmation`, `package_sale_confirmation`。
- 三个 scene 使用独立服务、权限、DTO 和必填字段策略，不跨 scene 消费。
- 统一底座负责 hash、TTL、active 占位、行锁消费、幂等重放、过期清理和安全错误。
- 该表不在 Stage 1A/1B 创建；其字段和 migration 仍需后续阶段审核。

## 13. 永久会员身份投影冻结

- 永久会员实例是权威，`yfth_user_identity` 只是可重建投影。
- 新 role_code/identity_code 使用与价格和期限无关的 `member_yfth`。
- 同一用户允许同时拥有 `member_5980` 与 `member_yfth`。
- 推荐资格只查询永久会员权威，不查询身份投影。
- 投影失败通过事件与幂等 reconciliation 补偿，不反向回滚永久会员权威。
- Stage 1A/1B 不实现永久会员或身份投影。

## 14. CRM 投影冻结

- Stage 1A/1B 完全不写 `yfth_customer_relation`。
- Stage 1B 可组合读取 attribution 与已有 CRM 摘要，但不能创建、迁移或覆盖 relation。
- 后续 CRM 投影使用异步或可补偿的幂等投影；投影失败不得回滚永久归属。
- 接管后旧 follow history 留在旧 relation，不搬迁。
- CRM 投影需后续单独架构审核。

## 15. 未来循环奖励权威边界

- 新循环奖励完全独立于旧 `yfth_reward_ledger`，不共表、不直接继承旧服务类语义。
- 后续独立建模：rule/version、sequence account、observing candidate、finalized ledger、append-only adjustment/reversal。
- observing candidate 不占序号；最终确认时锁 sequence account 分配 `sequence_no`。
- 冲正不回收序号；source sale 必须唯一化候选。
- 只复用旧 scan、快照、观察和冲正模式。
- Stage 1A/1B 不创建奖励表、不计算金额、不分配序号。

## 16. 新旧模型共存方案

- 旧线上 5980 不迁入新线下会员；旧 package tables、监听、退款、恢复、权益和履约保持运行。
- `member_5980` 不转换为 `member_yfth`；两个身份可并存。
- 旧 referral candidate 不转换为新 active referral；旧事件继续进入旧 reward ledger。
- 旧 reward ledger 不充当循环 sequence authority。
- `yfth_customer_relation` 保持 CRM 记录，Stage 1A/1B 只允许组合读取。
- 预约动态码继续只服务预约核销；新业务码未来使用独立新表。
- 新入口不得调用旧 `PackagePurchaseServices`、`ReferralRewardServices::userBindCandidate()` 或预约 writeoff 服务完成新业务。
- 旧入口未来只可受控隐藏 UI/新建权限；历史查询、退款、恢复、权益履约和 reconciliation 必须保留。

## 17. Stage 1A / 1B 验收建议

### Stage 1A

- MySQL 8.0.46 strict migration run/rollback/rerun/duplicate run 和半迁移恢复。
- current UID 唯一、active_referred_uid 唯一、source_unique_key 幂等。
- 双 UID 升序锁及全部并发矩阵。
- active referral 与 attribution store 一致。
- 资格策略在永久会员缺失时 fail closed。
- current 与 authority event 原子一致，event 失败回滚。
- unified audit 不能替代 authority event。
- 无生产推荐创建入口或绕过开关。
- 旧 package/referral/customer/appointment contract 回归，CRMEB 主链 diff 为零。

### Stage 1B

- user/store/admin token 和权限矩阵。
- 用户本人、门店本店、总部范围隔离。
- DTO 白名单和敏感字段负向断言。
- 跨店枚举、前端 store_id 注入和无权限访问拒绝。
- 所有 API 均只读，无状态变化、事件或审计业务写入。

## 18. 仍待项目主控确认的业务参数

| 参数 | 阻塞阶段 |
| --- | --- |
| B 商家总部商城收益比例、C 普通商品子分配比例 | 后续商城收益阶段 |
| 三三制观察期默认天数 | 奖励真实解冻与上线 |
| 会员全额退款后既有下级 active 关系处置 | 完整退款与奖励阶段 |
| 部分退款冲正比例 | 退款与奖励冲正 |
| 会员福利具体内容 | 会员规则发布 |
| 无接管状态恢复条件 | 接管阶段 |
| 行政区 code 来源 | 接管候选阶段 |
| 隐私授权规则 | 对应敏感页面/API 上线前 |
| 结算凭证标准 | 结算阶段 |

这些参数不得由开发人员写入默认常量。阶段零整改不授权 Stage 1A；Stage 1A 也不得提前实现上述业务结果。

## 19. 后续架构审核节点

1. 本文整改后再次执行阶段零只读架构复核。
2. 复核通过并完成文档合并后，由项目主控单独决定是否授权 Stage 1A。
3. Stage 1A 完成后进行独立架构审核。
4. 只有 Stage 1A 审核通过，项目主控才可单独授权 Stage 1B。
5. Stage 1B 完成后再次审核。
6. 永久会员权威完成并审核通过前，不开放真实推荐绑定。

更深层推荐环完整图遍历是后续 P2 技术事项，不阻塞 Stage 1A 的直接双向环防护。除此之外，current authority、事件分表、锁序、API 隐私和 migration 结构已按本文件冻结，不再作为并列备选方案。
