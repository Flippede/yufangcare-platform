# 御方通和总部统一商城 Stage 1B Read-only Surface

## Final Merge Closure

- Final independent Architecture Auditor conclusion: **A, passed**; Blocker, P1, P2 and P3 are all clear. The reviewed commit is `6402456db8687c90aec57ba21350dacbdb88ff61`.
- Main before merge was `328f5b658d1e260d9bd84bbe851f4c0b24980346`. Stage 1B was fast-forwarded into `main` with `git merge --ff-only codex/yfth-hq-mall-stage1b-readonly-surface`.
- The local and remote feature branches remain preserved at the reviewed commit. The GET-only APIs, explicit permissions, DTO allowlists, headquarters pages, user page and trusted-store pages are complete.
- Final isolated evidence covered 192 real HTTP requests with unchanged full-row hashes for the four authority current/event tables and `yfth_idempotency_record`.
- Production source allowlist remains empty and production referral qualification remains fail closed. No production MySQL/Redis connection, production migration, deployment or WeChat upload occurred.
- This merge closes Stage 1B only. Permanent membership, real referral, 9800 transactions, dynamic codes, rewards and all later business stages remain unauthorized.

## Historical Snapshot - Second Review Final P1 Closure

The second independent architecture review conclusion remains **B, conditionally passed**. The remaining P1 came from filtering referral current rows to `status=active` before consistency validation. A closed, paused or invalid current row whose latest event still said active could therefore disappear from validation and be reported as `has_active_referral=false`.

`activeReferralSummary()` now queries all referral current rows for the authenticated referred UID within the trusted store scope. It validates every related row through `HqAuthorityConsistencyValidator`; any mismatch fails closed. Only after every row is consistent does it calculate whether one has `status=active`. User/store responses expose no event, reason, table or technical detail. Headquarters ordinary governance and separately authorized audit behavior are unchanged.

No validator, Stage 1A writer, schema, migration, permission, route, Controller or frontend surface changed. Stage 1B remains GET-only, unmerged and pending a targeted independent review.

## First Audit Findings Closure

The first independent architecture review conclusion remains **B, conditionally passed**. The following implementation closes the identified findings for another independent review; it does not claim an A result and does not authorize merging `main`.

- `HqAuthorityConsistencyValidator` is the single side-effect-free consistency authority shared by Stage 1A write services and Stage 1B read services. It never creates placeholders, changes current/events, writes idempotency, repairs data, or falls back to legacy CRM/referral/5980 facts.
- Attribution checks freeze legal current state/store/reason/version combinations, pristine version-zero shape, exact event count, strict contiguous `1..N` versions, current ID and UID ownership, event chain continuity, first-event semantics, and latest store/status/reason/source content.
- Referral checks freeze relation/current identity, referrer/referred/store ownership, exact event count, strict contiguous `1..N` versions, event chains, current active UID semantics, latest status/event type, and `membership_activated` close semantics.
- User and store surfaces fail closed on any inconsistency. Headquarters ordinary readers receive only a minimal `data_inconsistent=true` governance DTO; independently authorized audit readers may inspect the structured event timeline. No read path repairs data.
- `HqAuthorityReadParameterServices` performs strict positive decimal ID/page/limit parsing, caps `limit` at 50, validates exact `Y-m-d` dates and a maximum ordered 366-day range, and rejects client sort/order fields.
- The seven-permission migration now validates complete signatures and fails closed on duplicate `unique_auth`, wrong signatures, or recorded incomplete state. It fills only missing permissions in a no-record compatible partial state, does not overwrite existing rows or role rules, and down removes only exact verified rows.
- Admin, store and user pages use request generations plus clear-before-load/failure rules. Permission, audit capability, tab, filter, page, role, store, context, user and lifecycle changes invalidate stale responses and clear sensitive state.

Executable evidence is recorded in `YFTH_HQ_MALL_STAGE1B_RUNTIME_VALIDATION.md`. Stage 1B remains GET-only, unmerged and pending independent read-only architecture review.

## 1. 阶段定位

Stage 1B 只为 Stage 1A 已落库的总部商城权威数据提供受控只读表面。它不创建归属或推荐关系，不执行状态迁移，也不承担旧数据承接。

当前实现覆盖四类读取主体：

- CRMEB 用户 Token：仅查看本人当前归属摘要。
- 用户 Token 门店工作台：`franchisee`、`store_manager` 仅查看当前可信门店的客户归属。
- Admin Token 总部普通查询：查看全局归属和推荐关系。
- Admin Token 总部审计查询：在独立审计权限下查看结构化 authority event 时间线。

## 2. 只读服务边界

查询职责由以下服务分离承担：

- `HqAuthorityReadServices`：DAO 查询、固定字段、分页、筛选和一致性检查。
- `HqAuthorityUserReadServices`：当前用户本人视图。
- `HqAuthorityStoreReadServices`：可信门店上下文和角色边界。
- `HqAuthorityAdminReadServices`：总部普通查询和总部范围断言。
- `HqAuthorityAuditReadServices`：总部审计事件读取和独立范围断言。
- `HqAuthorityDtoServices`：集中 DTO 白名单、脱敏和安全标签。

上述服务直接读取 Stage 1A current/event 表，不引用 Stage 1A 写服务，不调用 ensure/create/bind/pause/resume/close/invalidate/takeover，不创建 version-0 placeholder，也不自动修复数据。

## 3. API 与权限矩阵

| 主体 | Method / Route | 认证与范围 | 允许 | 禁止 |
| --- | --- | --- | --- | --- |
| 用户本人 | `GET /api/yfth/hq_authority/me` | `AuthTokenMiddleware`，UID 只取 `Request::uid()` | 当前登录用户 | 客户端 UID、目标用户查询 |
| 门店客户列表 | `GET /api/yfth/store_workbench/customer_attribution` | 用户 Token + `CurrentBusinessContextServices` | `franchisee`、`store_manager` | 顾客、`store_staff`、`service_mentor`、跨店 |
| 门店客户详情 | `GET /api/yfth/store_workbench/customer_attribution/:id` | 同上，服务端再次按 current store 校验 | 本店 active/paused | 客户端 store_id、跨店 ID、closed/historical unassigned |
| 总部归属列表/详情 | `GET /adminapi/yfth/hq_authority/attribution[/:id]` | Admin Token、Role Middleware、显式 API 权限、总部范围 | 有对应普通权限的总部账号 | 门店范围账号、无权限账号 |
| 总部推荐列表/详情 | `GET /adminapi/yfth/hq_authority/referral[/:id]` | 同上 | 有对应普通权限的总部账号 | 门店范围账号、无权限账号 |
| 总部归属事件 | `GET /adminapi/yfth/hq_authority/attribution/:id/events` | 独立审计权限 + 总部范围 | 有归属审计权限的总部账号 | 仅普通查询权限账号 |
| 总部推荐事件 | `GET /adminapi/yfth/hq_authority/referral/:id/events` | 独立审计权限 + 总部范围 | 有推荐审计权限的总部账号 | 仅普通查询权限账号 |

Admin API 同时经过 `AdminAuthTokenMiddleware`、`AdminCheckRoleMiddleware` 和 `AdminLogMiddleware`。Controller 使用 `SystemRoleServices::assertApiAuthForAdmin()` 做显式服务端权限断言；普通查询与审计查询不共享权限。

Stage 1B 权限 migration 新增七个 `unique_auth`：

- `yfth-hq-authority-readonly-index`
- `yfth-hq-authority-attribution-list`
- `yfth-hq-authority-attribution-detail`
- `yfth-hq-authority-referral-list`
- `yfth-hq-authority-referral-detail`
- `yfth-hq-authority-attribution-audit`
- `yfth-hq-authority-referral-audit`

所有 API 权限均为 `methods=GET`。

## 4. DTO 白名单与隐私

### 4.1 用户本人 DTO

允许：归属是否存在、状态与安全标签、绑定/暂停/关闭时间、公开门店摘要、active referral 布尔值、安全提示。

禁止：UID、推荐人身份、relation/source 主键、版本、event、operator、request/idempotency、原始 reason、完整用户隐私。

### 4.2 门店 DTO

允许：安全归属 ID、昵称、头像、脱敏手机号、归属状态、安全来源标签、绑定/暂停时间、active referral 布尔值。

禁止：UID、完整手机号、地址、证件、openid/unionid、source_id、版本、event/operator、请求标识、支付信息。

手机号脱敏复用 `YfthFoundationBaseServices::maskPhone()`，没有新增第二套脱敏规则。

### 4.3 总部普通 DTO

允许：归属/推荐安全标识、必要 UID、脱敏用户摘要、公开门店摘要、当前状态、时间、安全来源/关闭标签和一致性异常标记。

禁止：`source_id`、`source_unique_key`、版本、event/operator、request/idempotency、原始 reason/JSON、完整手机号和私有身份/支付数据。

### 4.4 总部审计 DTO

独立权限下允许结构化 event_no、版本、event/source 类型与 source_id、operator、规范化 request_id、结构化 reason code、前后门店/状态和时间。

即使是审计 DTO，也永不输出 `source_unique_key`、幂等键、request hash、原始审计 JSON、Token、凭证或完整个人隐私。

普通 DTO 的 source 只经集中白名单映射为安全标签；未知 source 显示“系统来源”，不透传原值。测试 source 不在生产标签映射中。

## 5. 空表、状态与一致性

- 用户没有 current 行：返回安全未归属空态，不创建 placeholder。
- pristine unassigned：显示未归属，不暴露 version/reason。
- historical unassigned：显示需总部处理，不允许被解释为可重新绑定。
- active/paused：仅在 current/event 一致且门店摘要存在时返回。
- closed：用户显示关闭提示；门店列表不再将其视为当前客户。
- 没有 active referral：只返回 `false`，不推导或创建关系。
- current/event 不一致：用户与门店 fail closed；总部普通查询只显示异常治理标记，不自动修复。

Stage 1B 不从 `yfth_customer_relation`、旧 referral candidate、`member_5980`、reward ledger、订单或支付数据自动承接权威关系。

## 6. 前端表面

### 总部后台

`template/admin/src/pages/yfth/hqAuthority/index.vue` 提供归属和推荐两个只读标签、分页筛选、最小详情与按独立权限展示的事件时间线。页面没有新增、编辑、绑定、暂停、恢复、关闭、失效、接管或导入操作。

### 用户端

`template/uni-app/pages/yfth/authority/index.vue` 提供“我的归属”最小状态页，并在现有用户中心 YFTH 区域增加入口，不替换 CRMEB 商城、装修、订单或原用户中心。

### 门店工作台

`template/uni-app/pages/yfth/workbench/customer_attribution/index.vue` 提供本店客户归属只读列表与最小详情。入口仅对 `franchisee`、`store_manager` 展示，服务端仍执行可信上下文和角色校验。

## 7. Migration

`20260714100000_add_yfth_hq_authority_readonly_permissions.php` 只维护 Stage 1B 页面和 GET API 权限：

- 不创建或修改 Stage 1A 四张业务表。
- 不 seed attribution/referral fixture 或任何业务数据。
- duplicate up 幂等。
- 无记录/无权限与无记录/部分权限均可补齐。
- down 仅删除七个 Stage 1B `unique_auth`，不删除其他 YFTH 菜单。
- 严格模式下菜单名称长度符合真实字段限制。

## 8. 冻结边界

本阶段没有写 API、Command、Job、Listener、定时任务或 fixture API；没有生产 source allowlist；生产 qualification 继续 fail closed。

未实现：永久会员、`member_yfth` 投影、9800 成交、真实推荐绑定、动态业务码、奖励候选/金额、退款冲正结算、总部纠错/接管、CRM 投影、旧数据导入或生产发布。

CRMEB 登录/Token、微信授权、页面装修、商品/SKU、购物车、订单、支付、退款、物流、分销，以及既有 5980、预约核销、月度履约、额度、供应链和加盟状态机均未改变。
