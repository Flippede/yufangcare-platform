# 御方通和 5980 套餐与十个月权益 V1 架构说明

> [!IMPORTANT]
> **Compatibility Implementation Notice**
>
> 本文描述仓库已实现的线上 5980 套餐与十个月权益兼容能力，必须保留且不得擅自删除，但不代表最新“总部统一商城 + 线下会员 + 线下套餐 + 循环三三制”产品方案。新业务开发须以 [YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md](YFTH_RELEASE_SCOPE_HQ_MALL_MEMBERSHIP_REFERRAL.md) 为产品依据，不得直接把旧线上套餐或旧字段含义改造成新方案模型。快照、审计、幂等和冲正模式是否复用，由后续架构设计决定。

## 1. 范围

本轮实现 5980 套餐实例、协议快照、支付后激活、十个月权益计划生成、月度权益开启/过期、退款同步和 `member_5980` 身份派生。服务项目、预约、动态核销、配送履约、奖励台账、库存补货和分账执行不在本轮范围。

## 2. 分层

- 数据库迁移：`crmeb/database/migrations/20260624130000_create_yfth_package_benefit_tables.php`
- 菜单权限迁移：`crmeb/database/migrations/20260624130010_seed_yfth_package_benefit_menus.php`
- 模型：`crmeb/app/model/yfth/YfthPackage*.php`、`YfthBenefit*.php`
- DAO：`crmeb/app/dao/yfth/YfthPackage*Dao.php`、`YfthBenefit*Dao.php`
- 服务：`crmeb/app/services/yfth/Package*Services.php`、`Benefit*Services.php`
- 后置事件：`crmeb/app/listener/yfth/Package*Listener.php`
- 用户端 API：`crmeb/app/api/controller/v1/yfth/PackageBenefitController.php`
- 后台 API：`crmeb/app/adminapi/controller/v1/yfth/PackageBenefit.php`
- 后台页面：`template/admin/src/pages/yfth/packageBenefit/index.vue`
- 移动端页面：`template/uni-app/pages/yfth/package/*`

## 3. 核心链路

1. 总部配置套餐模板、规则版本、商品/SKU 绑定、权益模板和月度权益规则。
2. 用户查看公开套餐详情、服务门店和规则预览。
3. 用户确认手机号、服务门店、协议版本、规则版本、价格、权益 hash 和 CRMEB 订单。
4. 服务端校验商品/SKU、订单金额、门店主体、门店能力、收款路由和协议快照。
5. CRMEB 原支付成功后触发后置监听；套餐激活服务用 `package_activate:{order_id}` 做幂等保护。
6. 激活服务创建套餐实例、权益计划、10 个权益周期和权益项，并派生 `member_5980` 身份。
7. 定时任务或后台手动触发 `openDuePeriods`，把到期周期和权益项开放；超过过期时间后关闭可用权益。
8. 退款申请、取消、成功、失败通过监听器同步套餐购买、实例、计划、周期、权益项和身份状态。

## 4. 冻结边界

本轮不改写 CRMEB 登录、token、商品、SKU、购物车、普通订单创建、支付回调、退款主流程、文件上传和队列机制。套餐权益只通过独立 `yfth_*` 表、服务层和事件监听扩展。

严禁把套餐权益写入：

- 订单备注或订单扩展说明。
- 用户余额、积分、佣金、推广关系或分销字段。
- 单个无结构 JSON 字段。
- `eb_user` 新增大量套餐字段。

## 5. 权限与门店校验

购买前校验复用业务基础域：

- `StoreAccessServices` 校验服务门店启用。
- `StoreCapabilityServices` 校验 `package_sale`、`online_payment` 等能力。
- `StoreSubjectServices` 校验销售、收款、履约、退款主体。
- `StorePaymentRouteServices` 校验 `package_5980` 收款路由并写入快照。

## 6. 幂等与审计

套餐激活使用 `yfth_idempotency_record`，同一订单重复支付回调只返回已有实例，不重复生成计划或权益项。

服务层状态变化通过 `recordAudit` 写入基础域审计。高风险后台状态变更要求确认词和原因，保留历史快照。


## 7. 2026-06-24 支付激活一致性整改

本轮把套餐购买从“用户输入已有 CRMEB 订单”改为正式购买闭环：

1. 用户确认套餐、门店和协议后，服务端创建 `yfth_package_purchase_intent`。
2. intent 内固化服务端校验快照，随后调用 CRMEB 真实购物车确认和 `StoreOrderCreateServices` 创建订单。
3. 订单创建成功后，在事务内绑定购买记录、订单唯一键和关系型成交快照。
4. 支付成功 listener 仅以真实订单、购买记录和快照激活套餐。
5. 若 listener 异常，补偿命令或后台人工重试仍走同一 `PackageActivationServices` 幂等激活链路。

关键边界：

- 不使用订单备注保存套餐数据。
- 不信任前端价格、规则版本、商品/SKU、主体或权益内容。
- 不修改普通商品下单、普通支付和退款主流程。
- 已发布或已被 intent/purchase/snapshot 引用的规则、月度规则和权益内容不可原地修改，只能复制新草稿版本。
- 部分履约后退款使用 `closed_after_partial_refund`/`partial_fulfillment_refunded` 语义，不再把所有退款统一写成 `refunded`。

## 8. 2026-06-25 intent 并发建单与人工恢复整改

intent 到 CRMEB 订单的边界收口为“先抢占、后建单、再绑定”：

1. `createOrderFromIntent()` 先锁定 `yfth_package_purchase_intent`，只有 `created` 或允许重试的 `failed` intent 能更新为 `creating` 并写入 `creating_request_id`。
2. 抢占失败的并发请求不得调用 CRMEB 建单；已 `bound` 的 intent 返回已有订单，`creating` 的 intent 返回处理中状态。
3. CRMEB 订单创建后，服务层必须用同一个 `creating_request_id` 在事务内绑定 intent、purchase、购买快照和权益快照。
4. 如果订单已创建但绑定失败，系统记录 `orphan_order_id/sn` 和错误信息，并调用 CRMEB 取消订单能力补偿，不物理删除订单。
5. `php think yfth:package scan-orphan-orders --close` 可扫描未绑定套餐 SKU 订单，并在人工确认后关闭仍可关闭的孤儿订单。

激活恢复拆分自动与人工两条幂等链路：

- 支付回调和自动补偿继续使用 `activate` / `package_activate:{order_id}`，达到最大重试次数后自动补偿跳过并等待人工处理。
- 后台人工重试使用 `manual_activate` / `package_activate_manual:{purchase_id}`，必须记录操作人、原因、请求号、次数和结果。
- 人工重试仍调用同一套成交快照激活服务，只是绕开自动失败幂等记录的最大次数限制；并发人工重试只能有一个请求真正执行激活。

## 9. 2026-06-26 后台权限与孤儿订单恢复架构

### 9.1 后台权限链

- `AdminCheckRoleMiddleware` 仍负责真实后台请求的第一层校验：未登录拒绝，超管放行，普通管理员进入角色权限校验。
- `SystemRoleServices::verifyAuth()` 只对已登记 API 执行强制权限校验。未登记 API 继续兼容旧 CRMEB 行为；已登记但角色未授权时抛出 `AuthException(100101)`。
- API rule 和 method 会统一小写、去空格并规范 `<id>`/`:id` 动态段；`<param>` 规则也能匹配真实路径片段，避免动态路由误放行或误拒绝。
- 人工激活、激活恢复和 orphan 扫描属于敏感入口，Controller 侧必须再次调用 `assertApiAuthForAdmin()`，并只信任当前后台登录上下文，不信任前端传入的操作人 ID。

### 9.2 订单来源与 attempt

- 新表 `yfth_package_order_attempt` 记录每次 intent 调用 CRMEB 建单的 attempt。字段包括 intent、UID、门店、request id、商品/SKU、CRMEB `orderKey`、订单 ID/SN、状态、超时和恢复错误。
- 来源标记使用 CRMEB 建单确认阶段生成的 `orderKey`，落在 `store_order.unique`，同时保存 `source_token_hash`。该值由服务端生成、不可枚举、普通用户不可编辑，可在崩溃后从订单反查 attempt。
- 普通 CRMEB 订单没有 attempt 记录，不进入套餐 orphan 扫描；扫描不依赖订单备注、展示备注、UID/SKU/时间窗口猜测。

### 9.3 creating 超时和 orphan 收口

- `creating` 超过 `CREATING_TIMEOUT_SECONDS` 后先查 attempt/order，再决定恢复动作。
- 无订单：intent 记录 `order_creation_timeout_no_order` 并回到可重试的 `failed`。
- 未支付订单：标记 orphan，调用 CRMEB 原生取消能力；取消成功后 intent 才允许重新建单。
- 已支付订单：不取消，不重建订单；intent/attempt 进入 `orphan_paid_pending`，后续由扫描或后台受控恢复创建唯一 purchase、快照、实例和权益计划。
- 已关闭订单：记录关闭结果并允许安全重试。
- 旧请求延迟返回：如果 intent 已被新请求绑定，旧 attempt 只能收口旧订单，不覆盖新 purchase 绑定；已支付且 intent 已绑定的旧 attempt 转人工处理，不自动生成第二条 purchase。

### 9.4 Listener 和扫描入口

- `PackagePaySuccessListener` 对缺少 purchase 的套餐来源 paid order 记录高优先级错误日志和 YFTH 审计，不抛出异常破坏 CRMEB 支付成功主流程。
- `scan-orphan-orders` 默认只读 dry-run；`--close-unpaid` 和 `--recover-paid` 是显式动作开关。后台 `orphan/scan` API 使用同一服务层能力和独立权限点。
