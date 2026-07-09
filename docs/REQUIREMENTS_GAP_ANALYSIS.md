# 需求差距分析

- 产品依据：`docs/PRODUCT_REQUIREMENTS.md` 与原始 DOCX
- 代码依据：`docs/PROJECT_INVENTORY.md`
- 结论类型：基于真实仓库的开发前判断，不代表已完成开发。

## 2026-07-09 Product Quota V1 Update

- Product Quota / Return Goods Quota V1 P1 idempotency closure has been implemented on `codex/yfth-product-quota-ledger-v1`.
- Headquarters grant creation no longer accepts a missing or empty idempotency key; the backend normalizes the client operation key by write scene and admin id, returns an existing grant for same-key/same-payload replay, and rejects mismatched replay.
- Manual adjustment no longer accepts a missing or empty dedupe key; the backend rechecks dedupe after locking the quota account row, preventing duplicate HTTP retry or double click from applying the same balance change twice.
- `yfth_product_quota_grant_order.idempotency_key` and `yfth_product_quota_adjustment.dedupe_key` are now mandatory non-null strings with unique indexes rather than nullable optional columns.
- The headquarters admin product quota page now generates operation keys for grant and adjustment writes and guards duplicate local submits.
- This closure does not implement purchase offset, reward conversion, product quota payment, settlement, distribution, production deployment, or production database migration.

- `产品额度 / 返货额度台账 V1` 已在功能分支 `codex/yfth-product-quota-ledger-v1` 进入实现阶段。
- 本轮只建立独立 YFTH 产品等价额度账户、不可变流水、总部人工授予/确认/驳回/反冲/纠偏/冻结/解冻/关闭，以及加盟商/店长只读展示。
- 本轮不实现采购单自动抵扣、额度预占/消耗/释放、推荐奖励兑换、开店自动授予、采购售后返还、线上支付、提现、结算或分账。
- 本轮不写 CRMEB 余额、积分、佣金、分销、订单、支付、退款、商品库存、SKU 库存或销量字段。
- 详细架构见 `docs/YFTH_PRODUCT_QUOTA_ARCHITECTURE.md`。

## 0. 安全前置阻塞

本轮已将“凭据、证书、私钥进入 Git 当前版本及历史”升级为安全阻塞项，并建立安全基线、示例配置和凭据轮换清单。仓库治理已完成后仍不等同于外部平台凭据已轮换。

在外部平台凭据完成轮换并验证前，不得开始 5980 套餐、十个月权益、预约核销、加盟、产品额度或奖励台账等业务功能开发。

## 1. V1.0 映射

| 产品模块 | 需求目标 | 当前已有能力 | 可复用代码/模块 | 缺失能力 | 改造范围 | 风险等级 | 建议版本 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 统一用户与多角色身份 | 同一账号支持顾客、会员、加盟商、店员、导师等身份切换 | 用户表、登录、微信授权、后台角色、用户字段含代理/员工状态 | `user`、`wechat`、`system_admin`、`system_role` | 前台多身份切换、身份状态机、导师身份 | 新增身份域，扩展用户资料和前端入口 | 高 | V1.0 |
| 门店和店员关系 | 门店、店长、店员、客户归属和门店隔离 | 门店、店员、自提核销已存在 | `eb_system_store`、`eb_system_store_staff`、`merchant` 路由 | 客户归属、门店工作台、最小权限、跨店隔离规则 | 扩展门店域和权限条件 | 高 | V1.0 |
| 公共首页 | 发现、康养、套餐、门店、活动入口 | CRMEB 首页、Diy、商品/活动组件 | `template/uni-app/pages/index`、Diy | 御方通和首页信息架构和内容 | 移动端页面和后台配置 | 中 | V1.0 |
| 康养中心 | 底部导航第二项，承载服务和产品分类 | 商品分类页存在 | `pages/goods_cate`、商品分类 | 康养六大分类、服务项目与附近门店组合 | 新建康养页面，复用商品分类 | 高 | V1.0 |
| 商品商城 | 商品交易、购物车、支付、物流、自提、售后 | 基本完整 | 商品、SKU、购物车、订单、支付、退款 | 御方通和分类、会员价、合规文案 | 配置与小改造 | 中 | V1.0 |
| 5980 家庭康养套餐 | 购买后生成会员身份和十个月权益计划 | 普通商品、付费会员、其他订单 | 商品订单、`member_ship`、支付 | 套餐模板、套餐实例、权益计划、电子协议 | 新建套餐/权益域，复用支付订单 | 高 | V1.0 |
| 十个月权益计划 | 按月开放、领取、配送、服务、历史 | 付费会员权益展示、优惠券、订单 | `member_right`、优惠券、订单 | 月度权益批次、状态机、恢复/撤销 | 新建权益履约模型 | 高 | V1.0 |
| 权益领取、配送和历史 | 可按月领取、核销或配送并追溯 | 订单、物流、自提、用户账单 | 订单、物流、核销、用户账单 | 权益锁定、领取记录、过期、异常处理 | 新建权益记录并串联订单/门店 | 高 | V1.0 |
| 服务项目 | 康养服务详情、适用场景、预约 | 未发现独立服务项目 | 可参考商品、文章、门店 | 服务项目模型和后台维护 | 新建服务项目域 | 高 | V1.0 |
| 门店预约 | 门店、日期、时段、容量、取消 | 门店列表、自提核销 | `system_store`、订单核销 | 预约时段、容量、签到、取消恢复 | 新建预约域 | 高 | V1.0 |
| 签到和扫码核销 | 动态码、一次性核销、权限校验 | 订单核销码、店员核销 | `StoreOrderWriteOffServices` | 动态权益码、服务签到、权益恢复 | 扩展核销域，不改坏订单核销 | 高 | V1.0 |
| 会员中心 | 套餐、权益、预约、核销、订单、协议 | 个人中心、订单、会员卡、分销 | `pages/user`、`pages/users/user_vip` | 套餐履约首页和协议历史 | 移动端新增/改造 | 中 | V1.0 |
| B端基础工作台 | 店长/店员看今日经营、客户、核销、订单 | 移动端有 admin 订单/核销页面 | `pages/admin`、店员、订单 | 客户、套餐开卡、预约、待办、门店隔离 | 新建工作台，复用订单/核销 | 高 | V1.0 |
| 总部基础后台 | 配置商品、门店、套餐、权益、审核、审计 | 管理后台较完整 | `template/admin`、`adminapi` | 御方通和业务菜单和审核流 | 新增后台模块 | 高 | V1.0 |
| 合作介绍和合作申请 | 加盟/店中店/城市合作申请 | 代理商申请、分销员申请部分存在 | `division_agent_apply`、`spread_apply` | 合作类型、资料、进度、补材料 | 建议新建合作申请域 | 中 | V1.0/V1.1 |
| 推荐关系 | 只记录一级推荐、候选绑定、有效校验 | 分销推广关系、二维码、海报 | `user_spread`、`spread` API | 候选绑定、有效新客、规则版本 | 扩展/新建推荐事件 | 高 | V1.0 |
| 有效状态 | 订单完成、观察期、退款失效 | 订单状态、退款状态 | 订单、退款、用户账单 | 观察期、校验规则、状态快照 | 新建推荐事件状态机 | 高 | V1.0 |
| 只读奖励台账 | 待确认、已结算、冲正，只读展示 | 佣金、提现、账单已有 | `user_brokerage`、`user_bill` | 不可提现奖励台账、规则版本、冲正 | 新建台账，不混用余额 | 高 | V1.0 |
| 审计和异常处理 | 全过程留痕、状态变更、规则快照 | 后台日志、订单状态、账单 | `system_log`、`store_order_status` | 业务审计日志、规则快照、冲正原因 | 新建审计表/服务 | 高 | V1.0 |

V1.0 复用比例估算：约 45%-55%。估算依据是基础商城交易链路可复用，但会员履约、预约核销、奖励台账和门店工作台需要新增业务域。

## 2. V1.1 映射

| 产品模块 | 当前状态 | 建议 |
| --- | --- | --- |
| 门店库存 | 仅有商品全局库存，未见门店 SKU 库存体系 | 新建门店库存表和入出库流水。 |
| 补货 | 普通订单和积分订单存在 | 新建补货单，支持现金和产品额度两种来源。 |
| 产品额度 | 未发现 | 独立台账，不使用用户余额或佣金。 |
| 签收入库 | 未发现 | 与补货单和门店库存串联。 |
| 盘点 | 未发现 | 新建盘点单和差异审核。 |
| 门店活动 | CRMEB 营销活动存在，但不是门店执行任务 | 复用内容/活动素材，新建门店任务。 |
| 店员权限 | 店员存在，但权限维度较粗 | 按门店动作细化权限。 |
| 客户回访 | 客服/客户备注部分存在 | 新建回访计划和记录。 |
| 门店经营报表 | 全局统计存在 | 新建按 store_id 聚合报表。 |
| 加盟申请 | 代理申请部分存在 | 建议新建合作申请，不套用分销代理。 |
| 合同 | 协议文本存在，合同流程未发现 | 新建合同模板、签署、附件、状态。 |
| 筹备任务 | 未发现 | 新建任务清单和验收证据。 |
| 开店验收 | 未发现 | 新建验收流程和结果证据。 |

V1.1 复用比例估算：约 25%-35%。主要可复用后台、用户、门店、商品、订单和附件能力，业务流程基本需要新增。

## 3. V2.0 映射

| 产品模块 | 当前状态 | 建议 |
| --- | --- | --- |
| B端推荐加盟结算 | 分销和事业部存在，但不符合只读、审计、产品额度边界 | 不直接复用多级分销，先做专项设计。 |
| 服务导师 | 未发现导师域 | 新建导师身份、线索、活动、帮扶。 |
| 线索和邀约 | 未发现完整 CRM 线索 | 新建轻量线索域。 |
| 活动和帮扶 | 营销活动存在，门店帮扶不存在 | 新建活动执行和帮扶任务。 |
| 培训 | 未发现培训课程和考试 | 可后续新建学习中心。 |
| 区域运营 | 事业部存在但规则不匹配 | 先架构审核，再决定复用/新建。 |
| 复杂晋升和分红 | CRMEB 分销支持多级/代理，但产品文档要求暂缓 | V2.0 前必须业务、财务、法务和架构专项审核。 |

V2.0 复用比例估算：约 15%-25%。现有分销/事业部只能提供技术参考，不应直接承载复杂分红。

## 4. 关键技术判断

- 多角色与门店隔离：当前能支持后台角色、用户状态、店员和门店，但缺少前台多身份切换、store_id 全链路隔离和门店客户归属。
- 5980 套餐与十个月权益：不应简单做成普通商品或付费会员。建议复用订单支付，新增套餐实例和权益计划。
- 预约与核销：订单核销可扩展，但服务预约、时段容量、签到和权益恢复必须独立建模。
- 推荐与奖励：现有分销可参考绑定、二维码、佣金展示，但御方通和只读台账、观察期、规则版本和冲正必须独立。
- 审计与历史：现有日志不足以覆盖业务争议，需要新增规则快照、状态变更和冲正流水。

## 5. 推荐开发顺序

1. 建立御方通和业务基础域：身份、门店归属、套餐实例、权益计划、审计事件和迁移规范。
2. 改造移动端底部导航和公共首页/康养中心，只接入真实可用的商城与门店能力。
3. 打通 5980 套餐购买、支付回调、会员生效和十个月权益计划生成。
4. 实现月度权益领取、预约、签到、动态核销和历史记录。
5. 建立 B 端基础门店工作台：客户、预约、核销、订单、待办。
6. 建立推荐关系与只读奖励台账，不接入复杂提现或多级分红。
7. 扩展总部后台业务配置、审核、异常处理和报表。
8. 进入 V1.1 门店库存、补货、产品额度、加盟合同与开店验收。

## 6. 推荐第一项开发任务

第一项开发任务建议为：**御方通和业务基础域与迁移规范设计/落地**。

原因：

- 它是 5980 套餐、十个月权益、预约核销、门店工作台、推荐台账共同依赖的底座。
- 可以明确哪些字段放在 CRMEB 原表扩展，哪些必须新建独立业务表。
- 可以先保护订单、支付、商品、退款、分销等成熟模块，避免后续把权益塞进订单备注、余额或 JSON 字段。
- 完成后建议进行一次架构审核，重点审查数据模型、权限隔离、审计链路、支付/退款后置事件和迁移方式。

## 7. 2026-06-24 差距变化

已从“未落地”推进为“基础域 V1 已落地”的项目：

- 统一用户与多角色身份：新增 `yfth_user_identity` 和当前身份 API。
- 门店和店员关系：新增 `yfth_user_store_role`，店长/店员按门店校验。
- 门店主体与资质：新增经营主体、门店主体、门店资质表和后台提交/审核入口。
- 门店能力：新增能力表，能力由资质驱动，资质失效会关闭来源能力。
- 后台基础域：新增后台路由、权限点和 Vue 管理页。
- 审计与幂等：新增审计事件和幂等记录基础服务。
- 订单核销隔离：修复跨店核销风险，重复核销返回幂等结果。

仍未实现且不得误认为完成的项目：

- 5980 套餐实例、十个月权益计划和权益状态机。
- 服务项目、预约时段、签到、动态权益核销码和权益恢复。
- 加盟申请、合同、筹备任务、开店验收、采购、库存、补货、产品额度。
- 奖励台账、规则版本、观察期、冲正和结算。
- 支付路由真实执行、分账、退款后置业务事件。

## 8. 2026-06-24 Blocker hardening delta

- Foundation V1 remains a prerequisite layer only. It still does not implement 5980 package instances, ten-month equity plans, reservation, procurement, inventory, package writeoff, reward ledger, or payment execution/splitting.
- The previous blocker set is closed for the foundation layer:
  - Server-side store context no longer trusts client `store_id`.
  - `franchisee` is store-bound.
  - Store subject and payment route active uniqueness is enforced at service and database-contract level.
  - Idempotency begins by insert-first unique-key handling.
  - Store order writeoff confirmation is row-lock protected and duplicate confirmation is idempotent.
  - Menu seed is idempotent and rollback-safe.
  - Backend subject and audit outputs now mask sensitive data.
- Remaining business gaps are unchanged and must be designed on top of this foundation rather than bypassing it through order remarks, user balance, distribution fields, or unaudited JSON.
- Runtime confidence improved: portable PHP 7.4.33 plus isolated MariaDB 10.11.18 checks now cover the foundation constraints and can be rerun before the next feature layer starts.

## 9. 2026-06-24 5980 套餐与十个月权益 V1 差距变化

已从“未实现”推进为“V1 闭环已落地”的项目：

- 5980 套餐实例：新增套餐模板、规则版本、商品/SKU 绑定、购买绑定、协议快照和套餐实例。
- 十个月权益计划：新增权益模板、月度权益规则、权益计划、月度周期和权益项，支付后按规则快照一次性生成 10 个月计划。
- 支付后置事件：通过 `OrderPaySuccessListener` 后置监听生成套餐实例和权益计划，并用 `yfth_idempotency_record` 的 `package_activate:*` 键保护重复回调。
- 退款同步：通过退款申请、取消、成功、失败事件同步套餐购买、实例、计划、周期和权益项状态，保留历史，不物理删除。
- 会员身份：`member_5980` 来源于 active 套餐实例，退款、关闭、过期后重算，不覆盖用户其他身份。
- 用户端页面：套餐公开详情、服务门店、协议确认、支付确认、支付结果、我的套餐、计划时间线、当月权益。
- 总部后台：套餐模板、规则、商品绑定、权益模板、月度规则、购买记录、实例状态和计划查看。

仍未完成或仅预留边界的项目：

- 权益领取、配送、服务预约、签到、动态权益核销码、权益恢复和履约消费流水尚未实现。
- 门店 B 端工作台、客户归属、今日待办、预约容量和门店经营报表尚未实现。
- 推荐关系、有效新客观察期、只读奖励台账、冲正和结算尚未实现。
- 支付路由仍是业务校验与快照元数据，不代表已完成微信/第三方分账执行。
- 5980/10 个月是通过模板、规则、SKU 绑定和月度规则配置落库，不应在后续代码中写成散落硬编码。

## 10. 2026-06-24 支付激活阻塞项关闭情况

已关闭：

- 同一 CRMEB 订单重复套餐购买：数据库唯一键和服务层唯一冲突回查已补齐。
- 已发布/已引用规则原地修改：规则、月度规则、权益模板增加不可变检查，后台提供复制新版本入口。
- 成交快照缺失：新增 intent、购买快照和权益关系快照。
- 激活读取实时配置：激活服务改为只读取订单、购买记录和成交快照。
- 激活失败永久卡死：幂等记录支持失败/超时重新抢占，购买记录保存失败原因和重试时间。
- 已支付未激活补偿：新增后台扫描、人工重试和 console 命令。
- 关闭/冻结/退款后继续开放未来月份：`openDuePeriods` 增加批量上限和 instance/plan 状态二次校验。
- 退款失败原订单映射：退款服务从真实事件字段和退款单回查原订单。
- 全额退款和部分履约退款混淆：引入 `closed_after_partial_refund` 和 `partial_fulfillment_refunded`。
- uni-app 手工输入订单/SKU：购买页改为 intent + CRMEB 真实下单 + 支付组件。

仍未实现，不得误认为完成：

- 服务预约、签到、动态权益核销码、配送履约、库存、采购、奖励台账。
- 支付路由的真实分账执行；当前仅做业务校验和成交快照。
- 真实 MySQL 5.7/8.0 完整闭环需要隔离测试库与种子数据，使用 `crmeb/tests/yfth_package_benefit_real_flow_check.php` 执行，不得用 MariaDB 或临时简化表替代最终验收。

## 11. 2026-06-25 5980 套餐权益真实 MySQL 验收差距变化

已从“需要真实 MySQL 最终验收”推进为“真实 MySQL 8.0 隔离验收通过”：

- 使用官方 MySQL Community Server 8.0.46，在隔离测试库完成 CRMEB 基线导入、YFTH migration run/rollback/re-run、表/索引/权限点校验。
- 套餐购买闭环已在真实 CRMEB 订单链路上通过：`createIntent -> createOrderFromIntent -> StoreCart/StoreOrderCreate -> PackagePaySuccessListener`。
- 支付后激活、成交快照、十个月权益计划、`member_5980` 身份、重复支付幂等、失败激活重试、已支付未激活补偿恢复、并发唯一绑定和退款生命周期已在真实 MySQL 上通过。
- 已补齐真实 MySQL 暴露的实现缺口：迁移兼容、菜单字段范围、YFTH 模型时间戳策略、购买记录并发恢复、active 身份去重和规则引用判断。

仍未完成且不得误认为完成：

- 服务预约、签到、动态权益核销码、配送履约、权益消费明细和权益恢复。
- 门店 B 端工作台、客户归属、今日待办、预约容量和门店经营报表。
- 推荐关系、有效新客观察期、只读奖励台账、冲正和结算。
- 库存、采购、补货、产品额度、加盟合同、开店验收。
- 支付路由真实分账执行；当前仍只是业务校验和成交快照元数据。

## 12. 2026-06-25 intent 并发建单与人工激活恢复差距变化

已关闭的 P1 缺口：

- 同一套餐 intent 并发下单会生成多个 CRMEB 待支付订单：已改为 intent 行锁抢占和 `creating_request_id` 绑定校验，同一 intent 并发只允许一个请求创建 CRMEB 订单。
- CRMEB 订单创建成功但 intent 绑定失败会形成不可追踪订单：已记录 orphan 订单 ID/订单号、关闭状态和错误，并提供扫描与关闭命令，不物理删除订单。
- 自动激活重试达到上限后人工重试仍被失败幂等记录卡住：已拆分 `manual_activate` 独立幂等键，人工重试可在记录操作人和原因后覆盖自动上限。
- 后台缺少人工恢复审计信息：购买记录新增人工重试次数、最近时间、操作人、原因、请求号和结果展示。
- 验收脚本未覆盖同一 intent 的真实 CRMEB 并发下单：真实 MySQL 8.0 脚本已增加 10 进程同 intent 下单、orphan 为 0、自动上限跳过和人工并发激活验证。

## 13. 2026-06-26 后台权限与未记录 orphan 差距变化

本轮关闭最终架构复核确认的两个 P1：

- 后台人工激活/恢复权限实际失效：`verifyAuth()` 已改为对已登记 API 强制拒绝未授权角色，人工激活、激活恢复和 orphan 扫描增加 Controller 纵深校验；真实中间件链路已验证未登录、超管、授权角色、查看角色、无权角色和原 CRMEB 代表 API 边界。
- `creating` 崩溃后产生未记录但可支付 CRMEB 孤儿订单：新增 `yfth_package_order_attempt`，用服务端 `orderKey` 和 `store_order.unique` 建立可反查来源；扫描不依赖订单备注、前端标记或 UID/SKU/时间窗口猜测。

新增恢复能力：

- `creating` 超时会先查 attempt/order，再区分无订单、未支付订单、已支付订单、已关闭订单和旧请求延迟返回。
- 未支付 orphan 只能在显式扫描动作中调用 CRMEB 取消能力关闭，关闭成功后才允许重试建单。
- 已支付 orphan 不关闭、不重建订单，进入 `orphan_paid_pending`，在 UID、门店、SKU、价格、协议和快照一致后受控恢复唯一 purchase、快照、实例和权益计划。
- 支付 listener 对套餐来源 paid order 缺少 purchase 的场景写高优先级安全日志、审计和恢复记录，并保持 CRMEB 支付主流程不中断。

验证状态：

- MySQL 8.0.46 隔离库完成 migration run/rollback/run；新增 attempt 表、索引和 orphan 扫描权限点可回滚。
- 真实闭环脚本已覆盖权限中间件、未支付 orphan、已支付 orphan、无订单超时、旧请求延迟、同 intent 并发和并发人工恢复。
- 后续预约、签到、动态核销、配送履约、奖励台账、库存补货、产品额度、加盟合同和真实分账仍未完成，不得在产品交付中宣称已具备。

仍未完成且不得误认为完成：

- 服务预约、签到、动态权益核销码、配送履约、权益消费明细和权益恢复仍未实现。
- 门店 B 端工作台、客户归属、今日待办、预约容量和门店经营报表仍未实现。
- 推荐关系、有效新客观察期、只读奖励台账、冲正和结算仍未实现。
- 库存、采购、补货、产品额度、加盟合同、开店验收仍未实现。
- 支付路由真实分账执行仍未实现；当前仍只是业务校验和成交快照元数据。
## 2026-07-09 Franchise Contract Preparation Opening V1 Gap Update

- Franchise application is now extended after `pending_contract` by the new opening workflow foundation.
- New covered capabilities: offline contract record, applicant confirmation, headquarters confirmation/signing, offline payment proof, finance confirmation/reject, store preparation profile, fixed preparation tasks, opening acceptance, and controlled store-bound identity grant.
- New data tables: `yfth_franchise_contract`, `yfth_franchise_payment_proof`, `yfth_franchise_store_profile`, `yfth_franchise_preparation_task`, `yfth_franchise_preparation_task_record`, `yfth_store_opening_acceptance`, `yfth_store_opening_acceptance_item`, and `yfth_franchise_identity_grant`.
- P1 hardening after architecture review: read-only acceptance detail no longer creates acceptance records; acceptance submit/pass now require signed contract, finance-confirmed payment, complete fixed required tasks, and headquarters pass additionally requires a bound active CRMEB store.
- Remaining gaps: real electronic signing, online franchise fee payment, settlement, revenue sharing, recommendation rewards, product quota, procurement payment, purchase after-sale reversal, and production deployment.
- Boundary retained: this module does not create CRMEB `store_order`, does not modify CRMEB payment/refund/order flows, and does not mutate CRMEB product or SKU stock.

## 2026-07-10 Referral Relationship And Read-only Reward Ledger V1 Gap Update

- Recommendation reward has moved from an unimplemented gap to an independent YFTH V1 foundation on `codex/yfth-referral-reward-ledger-v1`.
- New covered capabilities: referral code, referral candidate, idempotent referral event, C-side package attribution, B-side franchise-opening attribution, immutable reward rule version, rule item, observing-period ledger, ledger snapshot, offline settlement marker, append-only reverse/adjustment, user-side read-only pages, and headquarters referral reward management page.
- New data tables: `yfth_referral_code`, `yfth_referral_candidate`, `yfth_referral_event`, `yfth_referral_attribution`, `yfth_reward_rule_version`, `yfth_reward_rule_item`, `yfth_reward_ledger`, `yfth_reward_ledger_snapshot`, `yfth_reward_adjustment`, and `yfth_reward_settlement_record`.
- Boundary retained: the module does not write CRMEB `user_spread`, `user_brokerage`, `user_bill`, `now_money`, points, balance, commission, withdrawal, CRMEB orders, CRMEB payment/refund state, or CRMEB product/SKU stock.
- Remaining gaps: automatic cash payment, withdrawal, online settlement, revenue sharing, product quota return, complex multi-level reward, full package/franchise event listener integration, production deployment, and production database migration.
