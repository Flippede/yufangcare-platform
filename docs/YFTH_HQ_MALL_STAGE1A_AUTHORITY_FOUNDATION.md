# 御方通和总部统一商城 Stage 1A 权威基础域

## 1. 阶段边界

Stage 1A 只建立永久客户门店归属和活跃一级推荐关系的内部权威基础。它不提供 Controller、route、Command、Listener、Job、定时任务、菜单、API 权限或前端页面，也不开放任何生产写入口。

本阶段不实现永久会员、9800 成交、动态码、奖励计算、奖励入账、退款反冲、归属接管、CRM 投影或历史数据导入。生产 source allowlist 为空；真实业务来源必须在后续独立阶段冻结并审核。

## 2. 数据模型

迁移 `20260713100000_create_yfth_hq_authority_foundation_tables.php` 按下列顺序创建四张 InnoDB 表：

1. `yfth_hq_customer_attribution_current`
2. `yfth_hq_customer_attribution_event`
3. `yfth_hq_active_referral_current`
4. `yfth_hq_active_referral_event`

回滚顺序固定为 referral event、attribution event、referral current、attribution current。迁移不创建外键，不写菜单、权限或业务种子数据。

### 2.1 归属当前表

`yfth_hq_customer_attribution_current` 以 `UNIQUE(uid)` 建立每个 CRMEB `user.uid` 唯一的权威行和锁边界。主要字段为 `uid`、`store_id`、`status`、`status_reason_code`、`authority_version`、来源摘要和状态时间。

索引包括：

- `uniq_yfth_hq_attr_current_uid(uid)`
- `idx_yfth_hq_attr_store_status_uid(store_id,status,uid)`
- `idx_yfth_hq_attr_status_update(status,update_time)`

当前表不保存 `source_unique_key`。`source_type/source_id` 只描述当前状态来源，不能替代事件幂等。

### 2.2 归属事件表

`yfth_hq_customer_attribution_event` 是追加式权威时间线，保存前后门店、前后状态、前后原因、操作人、角色、请求和来源。核心唯一约束为：

- `event_no`
- `(attribution_current_id, authority_version)`
- `source_unique_key`

事件失败会使同一事务中的 current 更新回滚；通用 `yfth_audit_event` 只用于操作审计，不能替代该权威事件表。

### 2.3 推荐当前表

`yfth_hq_active_referral_current` 保存一级推荐关系及其当前状态。`active`、`paused` 时 `active_referred_uid=referred_uid`；`closed`、`invalid` 时清空该字段。`UNIQUE(active_referred_uid)` 阻止一个用户同时被多个推荐人占用。

主要唯一约束为：

- `relation_no`
- `active_referred_uid`
- `source_unique_key`

当前表的 `source_unique_key` 只保存 `relation_created` 的不可变来源摘要，后续状态事件不得覆盖。

### 2.4 推荐事件表

`yfth_hq_active_referral_event` 是追加式关系事件时间线。核心唯一约束为：

- `event_no`
- `(referral_current_id, relation_version)`
- `source_unique_key`

每次创建或状态变更必须与 current 更新在同一事务内写入对应版本事件。

## 3. 状态与版本规则

归属状态为 `unassigned`、`active`、`paused`、`closed`：

- 初始占位：`unassigned/store_id=0/authority_version=0/initial_placeholder`，且没有事件。
- 首次可信归属：版本从 0 变为 1，同事务写入版本 1 `attribution_created`。
- `active` 和 `paused` 保留原 `store_id>0`；暂停不释放归属。
- 历史无继任门店的 `unassigned` 使用正版本和 `store_terminated_no_successor`，不能走普通首次绑定。
- `closed` 和历史 `unassigned` 均不能被普通路径重新绑定。

推荐状态为 `active`、`paused`、`closed`、`invalid`：

- 新关系从 `relation_version=1` 和 `relation_created` 开始。
- pause/resume/close/invalidate 每次只增加一个版本并写一个匹配事件。
- 会员激活语义使用 `relation_closed` 和 `membership_activated`，不能恢复为 active。
- 禁止自推荐和直接反向关系；并发 A 推荐 B 与 B 推荐 A 只允许一个成功。

服务在每次读锁后校验 current 形态、事件数量和当前版本事件唯一性；不一致数据安全闭合。

## 4. 来源所有权

调用方只提交结构化 `source_type/source_id`，不得提交 `source_unique_key`。服务端生成 lowercase SHA-256，三个 canonical domain 固定为：

- `hq_attribution_event|{event_type}|{source_type}|{source_id}`
- `hq_active_referral_relation|relation_created|{source_type}|{source_id}`
- `hq_active_referral_event|{event_type}|{source_type}|{source_id}`

数据库字段统一为可空 `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin`。不适用来源键时使用 `NULL`，不得使用空字符串。摘要不写入通用审计，也不向角色 DTO 暴露。

生产 `HqAuthoritySourceCanonicalizer` 默认 allowlist 为空，未知或自由拼接来源失败关闭。测试来源只由 `yfth_hq_authority_foundation_test_bootstrap.php` 显式注入，不存在生产环境变量、配置开关或绕过分支。

## 5. 事务、幂等与锁

`HqAuthorityOperationRunner` 对每个请求只调用一次 `IdempotencyRecordServices::begin()`。取得 processing ownership 后，最多执行三次完整数据库事务：

- 仅 deadlock、lock wait timeout、MySQL 1213/1205 或 SQLSTATE 40001 可重试。
- 重试复用同一个 processing 记录，不重新 begin 或 reacquire。
- `complete()` 在业务事务内完成，保证结果与幂等完成状态一致提交。
- 最终失败只调用一次 `fail()`；fail 写入异常会记录日志但不吞掉原业务异常。

所有涉及多个 UID 的操作先去重并按数值升序锁归属 current，再锁推荐关系，避免字符串排序和相反锁序。占位行采用 insert-first，唯一冲突后重新读取；从不依赖锁定不存在的行。

## 6. 推荐资格门禁

生产默认注入 `FailClosedReferralQualificationPolicy`。未来永久会员权威未实现时，创建和恢复推荐关系统一返回 `permanent_membership_authority_unavailable`，不得读取或降级使用：

- `member_5980`
- `member_yfth` 身份投影
- `yfth_customer_relation`
- 旧 referral candidate/ledger
- CRMEB 订单或支付状态

测试资格策略仅存在于测试 bootstrap，不属于生产容器或运行入口。

## 7. 迁移恢复规则

- 无 migration record、无 schema：按依赖顺序创建并校验完整签名。
- 无 record、兼容 partial schema：先校验已有字段签名和唯一数据，再仅补安全缺口。
- 有 record、完整 schema：duplicate-up 直接 no-op。
- 有 record、schema 不完整：抛出 `yfth_hq_authority_forward_repair_required`，禁止删除记录、伪造状态或现场改表。

同名字段签名不兼容、唯一数据冲突或最终 schema 不完整都失败关闭。MySQL DDL 不承诺完整事务回滚，已执行的兼容 DDL 通过上述幂等或独立 forward repair 路径恢复。

## 8. 共存与冻结边界

本阶段零修改并继续保留 CRMEB 商品、SKU、库存、订单、支付、退款、分销，以及旧 YFTH 5980 套餐、`member_5980`、推荐奖励、客户 CRM、预约、动态核销和履约逻辑。四张新表从空数据开始，不自动导入或解释任何旧记录。

Stage 1A 完成后仍不得开放真实归属或推荐入口。下一步只能进行独立只读架构审核；Stage 1B 必须等待 Stage 1A 审核通过、获准合并且由项目主控再次单独授权。
