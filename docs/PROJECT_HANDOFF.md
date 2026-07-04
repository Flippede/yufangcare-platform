# 项目交接文档

- 项目名称：御方通和加盟 APP / 微信小程序
- 当前代码基础：CRMEB 开源商城 PHP 版 v5.6 系列
- 本地路径：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform`
- GitHub 仓库：`https://github.com/Flippede/yufangcare-platform.git`
- 当前分支：`main`
- 当前最新提交：`f9a0d963ac4c92120111983c9b489433f1ab0dca`
- 当前稳定 main：`f9a0d963ac4c92120111983c9b489433f1ab0dca`
- origin/main：`f9a0d963ac4c92120111983c9b489433f1ab0dca`
- 本轮开始基线：`7413627250bd057474fd2a4ea04068fae5f2ec9c`
- 当前开发阶段：服务预约、容量锁定、5980 服务权益锁定与最终消耗、签到、动态码和核销 V1 已完成最终审核，并已通过 `git merge --ff-only` 合并、推送至 `main`。
- 当前工作区和推送状态：`main` 与 `origin/main` 已同步，工作区干净；功能分支 `feature/yfth-service-appointment-writeoff-v1` 已保留。
- 当前禁止事项和冻结模块：不得在本阶段开发核销撤销/反冲、权益恢复、评价、自动爽约、提醒消息、独立付费服务订单、跨店核销、离线码、打印码、员工排班资源、家庭成员预约、推荐奖励、配送、库存补货、产品额度、加盟合同、真实分账或生产部署；不得修改 5980 套餐支付激活、CRMEB 订单/支付/退款、后台权限核心流程或生产部署配置。
- 产品文档目录：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\项目文档`
- 完整产品依据：`御方通和加盟小程序项目需求与产品设计文档_V1.0.docx`

## 1. 项目目标

在 CRMEB 成熟商城和后台能力基础上，开发御方通和加盟 APP / 微信小程序，覆盖公共用户端、C端家庭康养会员、B端加盟商/门店工作台、A端服务导师、总部 Web 管理后台、商品商城、5980 家庭康养套餐、十个月权益、预约核销、加盟经营、推荐关系、奖励台账、内容活动、报表和审计。

当前阶段目标是完成服务预约到店履约闭环 V1 收口：服务项目、门店服务授权、排班规则、特殊日期规则、可预约时段查询、预约创建、自动确认、门店人工确认/拒绝、用户取消、门店/总部取消、同门店同项目改期、真实容量锁定/占用、5980 套餐具体服务权益锁定/释放、预约事件时间线、用户动态二维码/数字码、同店店员/店长扫码或输码核销、总部例外核销、权益最终消耗、核销记录、审计和用户/后台/门店最小页面均已落地。不得把尚未实现的撤销反冲、权益恢复、评价、自动爽约、独立付费服务订单、配送履约、奖励台账、库存补货或真实分账执行误认为已完成。

## 2. 架构概览

- 后端：`crmeb/`，ThinkPHP 6，多应用结构。
- 用户端 API：`crmeb/app/api`。
- 管理后台 API：`crmeb/app/adminapi`。
- 服务层：`crmeb/app/services`。
- DAO/模型：`crmeb/app/dao`、`crmeb/app/model`。
- 管理后台：`template/admin`，Vue2 + ElementUI。
- 移动端：`template/uni-app`，uni-app，覆盖 H5、小程序、APP。
- 初始化数据库：`crmeb/public/install/crmeb.sql`，154 张 `eb_` 表。
- 部署模板：`docker-compose/`，含 Nginx、PHP、MySQL、Redis。

## 3. 当前已完成模块

基于真实代码，当前 CRMEB 已包含：

- 登录、注册、手机号绑定、微信/小程序授权。
- 后台管理员、角色、菜单、权限和日志。
- 商品、分类、SKU、库存、购物车。
- 普通订单、支付、退款、物流、自提、核销。
- 优惠券、积分、付费会员、会员等级、营销活动。
- 分销员、推广关系、佣金、提现、分销等级。
- 门店档案、店员、门店核销订单。
- 文章、图文、页面装修、客服、消息、短信、文件上传。
- 队列、定时任务、Workerman 长连接、数据库备份、文件校验。
- 御方通和业务基础域、5980 套餐实例、十个月权益计划、真实 CRMEB 下单/支付/激活闭环、成交快照、退款生命周期、激活补偿、订单异常恢复和后台敏感操作权限校验。
- 服务项目定义、门店服务授权、周排班、特殊日期、可预约时段查询、预约创建、自动确认、门店人工确认、门店拒绝、用户取消、门店/总部取消、同门店同服务项目改期、真实时段容量锁定与占用、5980 套餐具体服务权益锁定与释放、预约事件时间线。
- 用户端预约创建、列表、详情、取消和改期最小真实页面；后台预约列表、详情、确认、拒绝和取消；真实后台 Token 门店权限；统一审计与幂等；MySQL 8.0.46 migration run、rollback、rerun 和真实预约流程验证。
- 用户端动态二维码/数字码生成与刷新、动态码状态查询、门店扫码/输码预检与核销、同店店员/店长核销权限、总部例外核销、预约到店签到时间、服务核销时间、服务完成时间、5980 服务权益最终消耗、核销记录、核销事件时间线、统一审计和幂等防重复核销。

## 4. 当前未完成模块

当前仍未完成或仅预留边界的御方通和专属模块：

- 康养中心底部导航和页面结构。
- 核销撤销/反冲和权益恢复。
- 服务评价。
- 自动爽约处理。
- 独立服务完成/重开操作；当前核销成功会在同一事务内自动完成服务。
- 独立付费服务订单。
- 微信订阅消息和短信提醒。
- 更完整的门店工作台、客户归属和经营待办。
- 加盟申请、加盟合同、筹备任务和开店验收。
- 产品额度/返货额度台账。
- 服务导师线索、邀约、活动和帮扶任务。
- 只读奖励台账、规则版本、观察期、有效新客校验和冲正。
- 推荐、奖励、配送、库存补货、产品额度、加盟合同和真实分账。
- 生产部署和生产数据库迁移。

## 5. 冻结模块

后续开发应保护以下成熟模块，优先扩展而非重写：

- 登录、微信授权和 token 体系。
- 商品、SKU、库存和商品编辑器。
- 普通订单、支付回调、退款和售后。
- 后台权限、角色、菜单和操作日志。
- 门店、店员和订单核销基础能力。
- 文件上传、云存储、客服、消息和队列。
- 分销模块只可参考，不应直接承载御方通和奖励规则。

## 6. 已知问题

- 版本标识不一致：README、`.version`、移动端 manifest、后台 package 标识不同，统一认定为 v5.6 系列。
- 阻塞：仓库当前版本和历史曾包含生产 `.env`、微信支付证书/私钥、运行时 PEM、前端 AppSecret/地图 Key 类字段和压缩包；本轮已做仓库治理，但外部平台凭据仍需轮换并验证。
- 御方通和业务域已使用 `crmeb/database/migrations` 管理迁移；CRMEB 原始安装仍以 `crmeb/public/install/crmeb.sql` 为基线。
- `vendor` 和大量静态/构建相关文件仍进入仓库，后续需评估仓库体积和部署方式；本轮未移除 `vendor/`，避免改变服务器部署方式。
- 移动端配置仍有 CRMEB demo/default 配置。
- 关键产品域仍有缺口，不能把需求文档规划误写为已完成能力。
- 5980 套餐权益相关后端脚本和真实 MySQL 8.0 闭环已验证；整站本地完整启动、真实支付沙箱和生产灰度仍未验证。

## 7. 当前开发阶段

阶段：服务预约、容量锁定、5980 服务权益锁定与最终消耗、到店签到、动态码与服务权益核销 V1 已完成最终审核，并已快进合并、推送至 `main`。

本轮变化：

- 服务项目定义、门店服务授权、周排班规则、特殊日期规则、只读公开 API 和后台配置页面已经落地。
- Booking V1 已新增真实预约、可锁定时段实例、服务权益锁和预约事件时间线；支持用户创建、自动确认、人工确认、门店拒绝、用户取消、门店/总部取消、同门店同服务项目改期。
- 时段采用“周规则实时计算 + 特殊日期覆盖 + 预约写入时创建/复用锁定实例”，公开时段查询会叠加 `occupied_count`、`locked_count` 和 `remaining_capacity` 的真实配置内余量。
- 核销 V1 已新增动态码和核销记录模型；用户仅可在确认预约且处于到店窗口时生成二维码 token 与 6 位数字码，服务端只持久化哈希，刷新会废弃旧码。
- 同店 `store_staff`、`store_manager`、`franchisee` 可核销本店预约；总部/超管只能通过显式例外核销入口处理异常。服务端按真实后台 token 和 `AdminStoreContextServices` 校验门店范围，不依赖前端传入 `store_id`。
- 核销成功在一个事务内完成预约签到、服务权益最终消耗、权益锁 consumed、核销记录、动态码 used、预约 completed 和 `checked_in`/`benefit_written_off`/`completed` 事件写入；重复核销返回已核销结果，不重复扣减权益。
- 审计统一使用 `AuditEventServices::recordSafely()` 写入 `yfth_audit_event`，业务域为 `yfth_service_appointment`；预约状态时间线写入 `yfth_service_appointment_event`；没有使用 `yfth_sensitive_operation_log`，也没有拆分写入第二套审计表。
- 当前不支持跨日时段；尚未实现核销撤销/反冲、权益恢复、服务评价、自动爽约处理、独立付费服务订单、消息提醒、跨店核销、离线码、打印码、员工排班资源或家庭成员预约。

历史安全治理记录仍需保留，用于生产切换上下文：

- 停止跟踪生产 `.env`、微信支付证书/私钥、运行时 PEM、前端 `.env*` 和移动端压缩包。
- 新增 `crmeb/.env.example`、`template/admin/.env.example`、`template/uni-app/.env.example`。
- 将安装模板改为 `crmeb/public/install/.env.example`，安装/升级流程继续生成运行时 `crmeb/.env`。
- 清空移动端 manifest 中 AppSecret、地图 Key 类可打包敏感字段。
- 完善 `.gitignore`，覆盖环境文件、证书私钥、runtime 和备份压缩包。
- 新增 `docs/SECURITY_BASELINE.md` 与 `docs/CREDENTIAL_ROTATION_CHECKLIST.md`。
- 记录服务器影响：历史改写后服务器不得直接普通 `git pull`，后续部署需重新绑定干净历史或重新克隆并恢复本地配置。
- 新增 `docs/PRODUCTION_SECURITY_SWITCH_PREP.md`，记录生产服务器只读核验、安全备份、干净仓库克隆、配置字段兼容性、凭据轮换清单和正式切换预案。
- 生产旧目录 `/www/wwwroot/CRMEB-master` 当前保持不变；Nginx 仍指向旧目录的 `crmeb/public`。
- 服务器 SSH 克隆 GitHub 仓库失败，原因是生产服务器 GitHub SSH 身份未获授权；已通过 HTTPS 兜底克隆到 `/www/wwwroot/yufangcare-platform-clean-https` 并确认 commit 为 `9e194629da7a2bd1b4d00d4d489d9b139d43675d`。
- 安全备份目录为 `/root/yufangcare-security-backup/20260623-171035`，权限为 `root:root 700`，未放入 Web 根目录或 Git 仓库。

## 8. 下一步建议

当前服务预约与动态核销 V1 已正式收口并进入稳定 `main`。下一业务模块由项目主控根据完整产品流程另行决定，不再继续写“架构审核”或“等待合并 main”。

生产服务器仍需保持干净克隆和凭据轮换要求：正式切换前应确认 GitHub Deploy Key 或受控 SSH 凭据可用，并确认生产 `.env`、微信支付证书、运行时 PEM 和前端环境变量均不进入 Git。

后续开发可在项目主控确认模块顺序后，再围绕核销异常反冲策略、自动爽约、消息提醒、更完整门店工作台、权益领取配送履约、推荐关系与只读奖励台账、库存补货、产品额度、加盟合同和真实分账执行等未完成能力创建独立分支。

建议先明确：

- 新增业务表和迁移方式。
- 身份模型、门店隔离和当前身份切换。
- 5980 套餐实例、权益计划和权益状态机。
- 预约、签到、权益核销、服务权益最终消耗与订单核销的边界。
- 推荐事件、规则版本、观察期、只读台账和冲正。
- 支付成功、退款成功、订单取消后的业务事件处理。

完成该任务后，建议进行一次架构审核。

## 9. 2026-06-24 业务基础域 V1 落地状态

- 当前开发分支：`feature/yfth-foundation-domain-v1`。
- 新增迁移目录 `crmeb/database/migrations`，建立 9 张 `yfth_*` 业务基础表和 `yfth-foundation-*` 后台权限点。
- 新增 `app/services/yfth`、`app/dao/yfth`、`app/model/yfth`，覆盖多身份、门店角色、经营主体、门店主体、资质、能力、收款路由、审计、幂等。
- 新增用户端基础域 API：身份列表、当前业务上下文、门店能力校验。
- 新增后台基础域管理页：`template/admin/src/pages/yfth/foundation/index.vue`。
- 修复订单核销跨店风险：店员只能核销订单原门店；重复核销返回幂等结果，不重复扣减。
- 新增文档：`YFTH_FOUNDATION_ARCHITECTURE.md`、`YFTH_FOUNDATION_DATA_MODEL.md`、`YFTH_MIGRATION_GUIDE.md`。

后续 5980 套餐、十个月权益、预约、采购、库存、奖励、支付路由执行和分账等业务，应复用本轮基础域，不得直接塞入订单备注、用户余额、分销字段或未审计 JSON。

## 10. 2026-06-24 本地工作区目录治理

- 正式本地工作区统一为：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform`。
- 旧 `testclone` 为正式仓库来源，完成目录清理后重命名为 `yufangcare-platform`。
- 旧 `yufangcare-platform` 不是顶层 Git 仓库；其中同名产品 DOCX 与正式仓库一致，补充合并唯一 Markdown：`项目文档/御方通和加盟小程序项目需求与产品设计文档_V1.0.md`。
- 旧 `yufangcare-platform-backup-20260623` 为空目录，无唯一资料。

## 11. 2026-06-24 Blocker hardening handoff

- Branch remains `feature/yfth-foundation-domain-v1`; this round fixes the architecture-audit blockers without starting package, equity, reservation, procurement, or inventory business work.
- Store context is now server-confirmed. Client `store_id` is only a candidate; store-scoped roles must resolve through `yfth_user_store_role` and an active `system_store` row. Non-store roles return `store_id = 0`.
- `franchisee` is now a store-scoped role together with `store_manager` and `store_staff`.
- Active store subject uniqueness is `store_id + subject_role`; history is preserved by disabling rows and clearing `active_key`.
- Active payment route uniqueness is `store_id + business_scene`; `resolveRoute` fails clearly when no route or historical duplicate active routes exist.
- Idempotency begin is insert-first and handles unique conflicts, payload mismatch, processing replay, expired processing recovery, succeeded replay, and failed retry visibility.
- Store order writeoff now locks the order row during confirmation. Only the first writer triggers fulfillment side effects; later writers return `is_repeat_writeoff = 1`.
- Audit and backend list output now mask `verify_code`, `credit_code`, merchant refs, certificate/id-like fields, and secret/token/password/key-like fields. Audit write failures are logged.
- Menu seed is idempotent and keeps root -> page -> API permission parent-child relationships.
- Validation completed with portable PHP 7.4.33, isolated MariaDB 10.11.18 runtime checks, PHP syntax checks, targeted frontend ESLint, and admin production build.

## 12. 2026-06-24 5980 套餐实例与十个月权益计划 V1

- 当前开发分支：`feature/yfth-package-benefits-v1`，基于 `feature/yfth-foundation-domain-v1` 的 `15f4e164b80d21a24dc721d0191ce428c0677d5b`。
- 本轮已将 `feature/yfth-foundation-domain-v1` 快进合并到 `main` 并推送，再从该基础创建套餐权益开发分支；本轮结束后不得把该功能分支合并回 `main`。
- 新增 11 张 `yfth_*` 套餐权益表，覆盖套餐模板、规则版本、商品/SKU 绑定、协议快照、购买绑定、套餐实例、权益模板、月度规则、权益计划、月度周期和权益项。
- 新增套餐权益服务层，围绕规则快照、购买前校验、支付后幂等激活、十个月计划生成、月度权益开启/过期、退款同步和 `member_5980` 身份重算实现闭环。
- 支付成功和退款相关逻辑通过事件监听器接入，未改写 CRMEB 支付回调、订单创建、退款主流程、购物车、商品 SKU、用户 token 或文件上传等冻结模块。
- 套餐购买必须通过手机号、协议接受、商品/SKU 绑定、金额快照、门店主体、门店能力、收款路由和服务门店权限校验，不把权益写入订单备注、用户余额、积分、佣金或分销字段。
- 后台新增 `yfth/package_benefit/*` API、菜单权限和 Vue 管理页，支持模板、规则、绑定、月度权益规则、购买记录、实例和计划查看、到期周期开启。
- 移动端新增套餐详情、门店选择、协议确认、支付确认/结果、我的套餐、套餐实例、时间轴和当月权益页面，并在 `pages.json` 注册 `pages/yfth` 分包。
- 新增文档：`YFTH_PACKAGE_BENEFIT_ARCHITECTURE.md`、`YFTH_PACKAGE_BENEFIT_DATA_MODEL.md`、`YFTH_PACKAGE_BENEFIT_STATE_MACHINE.md`。
- 新增验证脚本：`crmeb/tests/yfth_package_benefit_contract_check.php` 和 `crmeb/tests/yfth_package_benefit_runtime_check.php`。

仍未完成的后续域：服务项目、预约时段、动态权益核销码、权益履约消费明细、门店工作台、推荐关系、只读奖励台账、库存补货、产品额度、加盟合同和支付路由真实分账执行。

## 13. 2026-06-24 套餐支付激活一致性整改

- 当前开发分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`b811fc585c774ed7dd42a2c2e1252833b35685c7`。
- 本轮新增纠偏 migration：`20260624170000_harden_yfth_package_purchase_snapshots.php` 和 `20260624170010_seed_yfth_package_recovery_menus.php`。
- 新增 `yfth_package_purchase_intent`、`yfth_package_purchase_snapshot`、`yfth_package_purchase_benefit_snapshot`，成交时把套餐规则、协议、门店主体、支付路由、商品/SKU、月度权益逐行固化为关系型快照。
- `yfth_package_purchase` 增加可空唯一键 `order_unique_key`、`order_sn_unique_key`，用 MySQL 唯一索引保证一个 CRMEB 订单只能绑定一条套餐购买记录；服务层捕获唯一冲突后回查并返回已有购买记录。
- uni-app 套餐购买页已移除手工订单号、商品 ID、SKU unique 输入，改为 `createIntent -> createOrderFromIntent -> CRMEB payment`，支付结果页轮询真实购买激活状态。
- 支付激活只读取已绑定订单、购买记录和成交快照，不再读取实时可编辑模板/权益配置；激活失败会写入重试字段，并可通过幂等记录原子重新抢占。
- 新增自动/人工补偿：后台 `activation/recover`、`purchase/:id/activation_retry`，以及命令 `php think yfth:package recover-activation --limit 50`。
- 新增集中生命周期服务，退款中、全额退款、部分履约后退款关闭、人工关闭/冻结均通过统一状态机联动 purchase、instance、plan、period、item 和 `member_5980` 身份。
- `openDuePeriods` 改为批量上限、逐条锁定、计划/实例 active 二次校验；冻结、退款中、已退款、关闭状态不再开放未来月份或延迟权益项。
- 退款事件映射改为优先解析 `store_order_id/store_order_sn`，找不到时通过真实退款单回查原订单；映射失败写技术日志和审计待补偿记录。
- 新增真实应用验证脚本 `crmeb/tests/yfth_package_benefit_real_flow_check.php`，用于 MySQL 5.7/8.0 测试库上的真实迁移、表/索引、Service、Listener 和可选下单激活闭环验证；旧 runtime 脚本仍仅可作为轻量回归，不可替代最终验收。

## 14. 2026-06-25 5980 套餐权益真实 MySQL 隔离验收

- 当前开发分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`c200ef37f6cbf168a79aa3c493995373ed09521b`。
- 本轮使用 MySQL Community Server 8.0.46 官方 ZIP 在本地隔离端口 `127.0.0.1:33306` 验收，不使用 MariaDB，不连接生产库，不读取生产 `.env`。
- `crmeb/public/install/crmeb.sql` 已导入真实 CRMEB 基线库；导入时仅会话级放宽历史 SQL 兼容项，全局 MySQL 严格模式保持不变。
- 御方通和 6 个 migration 完成真实 MySQL 上的 run、rollback 到 0、再次 run：迁移后 178 张表、23 张 `eb_yfth_*` 表、37 个 `yfth-%` 后台权限点；回滚后 YFTH 表和权限点均清零。
- 修复真实 MySQL 暴露的阻塞问题：菜单 `sort` 越界、Phinx 索引/回滚 API 兼容、YFTH 整型时间戳被 ThinkORM 自动时间戳误解析、套餐购买旧路径未定义变量、并发唯一冲突恢复、`member_5980` active 身份重复写入、规则引用计数误判。
- 真实闭环脚本 `crmeb/tests/yfth_package_benefit_real_flow_check.php` 已扩展为隔离执行模式，覆盖真实下单激活、重复支付幂等、失败激活重试、补偿恢复、10 进程并发绑定、冻结/退款生命周期、规则不可变和复制新版本。
- 最终真实闭环验证输出：`[OK] YFTH package benefit real application checks verified on MySQL 8.0.46.`
- 新增验收文档：`docs/YFTH_PACKAGE_BENEFIT_RUNTIME_VALIDATION.md`。
- 前端 `template/admin` 生产构建通过；强制绕过 ignore 的 ESLint 仅暴露既有 CRLF 行尾 Prettier 问题，本轮未批量格式化前端文件。
- 本轮结束后不得合并到 `main`，也不得部署到生产；需先完成后续架构审核，再决定是否进入发布准备。

## 15. 2026-06-25 套餐 intent 并发建单与人工激活恢复整改

- 当前开发分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`527aacbd10b8c3a5346d713f5a41d37951b0811f`。
- intent 下单增加 `creating_started_at`、`creating_request_id`、`bound_order_id/sn`、`orphan_order_id/sn`、`last_error_code/message`、`retry_count` 等字段和索引；同一 intent 通过数据库行锁抢占串行化 CRMEB 建单。
- `createOrderFromIntent()` 只允许抢占成功者调用 CRMEB 订单创建；重复请求返回已绑定订单或处理中状态，不再产生多个待支付 CRMEB 订单。
- 订单创建成功但绑定失败时，记录孤儿订单并调用 CRMEB 取消订单能力补偿，不做物理删除；新增 `scan-orphan-orders --close` 命令用于扫描和人工收口。
- 自动激活失败达到幂等最大次数后，自动补偿不再反复抢占；人工重试必须提供操作人和原因，走独立 `manual_activate` 幂等键并记录人工重试次数、时间、操作人和结果。
- 后台套餐购买列表新增自动重试次数/上限、是否可人工重试、最近人工重试操作人与结果展示；人工重试入口增加原因输入和二次确认。
- 真实 MySQL 8.0.46 隔离验证已覆盖：新 migration run/rollback/run、同一 intent 10 进程并发只生成 1 个 CRMEB 订单、孤儿可支付订单为 0、自动重试上限跳过、人工重试覆盖上限并激活、人工并发只生成 1 个实例。
- 本轮仍不代表服务预约、签到、动态权益核销码、配送履约、奖励台账、库存补货、产品额度、加盟合同或真实支付分账执行已经完成。

## 16. 2026-06-26 后台权限强制校验与未记录孤儿订单恢复整改

- 当前分支：`feature/yfth-package-benefits-v1`；本轮开始 commit：`0b309ddab919850836f22e0ff2671f86b4a4503d`。
- 后台权限 P1 已关闭：`SystemRoleServices::verifyAuth()` 对已登记 API 不再默认放行，未授权普通角色抛出 `AuthException(100101)`；未登记 API、CRUD 和超管保持兼容放行。
- 敏感入口增加纵深校验：人工激活重试、激活恢复、orphan 扫描均在 Controller 侧通过当前管理员身份再次调用 `assertApiAuthForAdmin()`，空后台身份不会被误判为超管。
- 新增 `yfth_package_order_attempt`，在 CRMEB 建单前用服务端生成的 `orderKey` 持久化 attempt，并通过 `store_order.unique` 反查 intent/attempt；不使用订单备注、展示备注、前端标记或 UID/SKU/时间猜测来源。
- `creating` 超时恢复覆盖无订单、未支付 orphan、已支付 orphan、已关闭订单和旧请求延迟返回。未支付 orphan 只在 CRMEB 原生取消成功后允许重试；已支付 orphan 进入 `orphan_paid_pending` 并走受控恢复，不创建第二张订单。
- `scan-orphan-orders` 默认 dry-run；显式 `--close-unpaid` 才关闭未支付 orphan，显式 `--recover-paid` 才恢复已支付 orphan。后台新增 orphan 扫描入口，仍受独立权限控制。
- 支付 listener 发现套餐来源订单缺少 purchase 时写 `Log::error` 和 YFTH 审计/恢复记录，日志只包含脱敏订单号、order id、intent/attempt、request id 和安全错误码，不影响 CRMEB 支付成功主流程。
- 真实 MySQL 8.0.46 隔离验证通过：临时端口 `127.0.0.1:33326`，临时库 `yufangcare_validation_20260626_orphanfix`；migration 完成 run/rollback/run，回滚后 YFTH 表/菜单/迁移记录均为 0，第二次 run 后 179 张表、24 张 `eb_yfth_*` 表、8 条 YFTH migration、38 个 `yfth-%` 权限点。
- `crmeb/tests/yfth_package_benefit_real_flow_check.php` 已覆盖真实权限中间件、未登录/超管/授权/未授权角色、真实 CRMEB 下单、未支付 orphan 关闭、已支付 orphan 恢复、无订单超时重试、旧请求延迟保护、10 进程 intent 并发和并发人工激活。
- 本轮验证后已删除临时 MySQL 库、用户、data dir 和验证副本；未提交临时 `.env`、测试密码或验证数据。
## 17. 2026-06-26 Service Appointment Domain V1 / 服务项目与门店预约时段基础域 V1

- 当前开发分支：`feature/yfth-service-appointment-writeoff-v1`；基于 `main` 的 `7413627250bd057474fd2a4ea04068fae5f2ec9c` 开始。
- 本历史阶段新增服务项目、门店服务授权、周排班规则、特殊日期规则和只读可预约时段查询；当时没有实现预约提交、确认/取消/改期、签到、动态码、扫码核销、独立付费服务订单、消息通知或推荐奖励。该段为 2026-06-26 历史记录，不代表当前 Booking V1 状态。
- 新增迁移：`20260626130000_create_yfth_service_appointment_tables.php` 和 `20260626130010_seed_yfth_service_appointment_menus.php`。
- 新增表：`yfth_service_project`、`yfth_store_service`、`yfth_store_service_schedule_rule`、`yfth_store_service_special_day`。服务项目保持独立业务对象，不复用普通商品；门店服务授权通过 `store_id + service_project_id` 的 active key 防重复；周规则和特殊日期均保留历史停用记录。
- 历史时段方案：基础域 V1 采用“周规则实时计算 + 特殊日期覆盖”，当时不预生成 slot 表，也不写虚假的已预约人数。Booking V1 已在预约写入时创建/复用 `yfth_service_appointment_slot` 并叠加真实锁定/占用。
- 权限边界：后台 API 继续走 CRMEB 菜单/API 权限；服务层再按服务端 `adminInfo` 的门店范围校验。总部可维护服务项目和门店授权；店长可在本店范围维护排班和特殊日期；店员不能配置服务项目、门店服务、排班或容量。
- 门店可用性：只读查询会校验门店存在且启用、门店拥有 `reservation_service` 能力、服务项目 active、门店服务授权 active 且 appointment enabled。当前未建设完整资质中心，继续复用既有 `StoreCapabilityServices` 作为扩展点，不把资质硬编码为永远通过。
- 后台入口：`template/admin/src/pages/yfth/serviceAppointment/index.vue`，支持服务项目、门店服务授权、排班规则、特殊日期和时段预览。
- 小程序端历史状态：基础域 V1 仅新增 `template/uni-app/api/yfth.js` 的只读 API 封装；Booking V1 已新增预约创建、列表、详情、取消和改期最小真实页面。
- 新增只读公开接口：`yfth/service/project`、`yfth/service/project/:id`、`yfth/service/project/:id/stores`、`yfth/service/project/:id/dates`、`yfth/service/project/:id/slots`。
- 审计：服务项目、门店授权、排班规则和特殊日期的新增、更新、停用均写入 `yfth_audit_event`，业务域为 `yfth_service_appointment`。
- 历史下一轮建议：基础域 V1 后续“预约创建、取消、改期和权益锁定”应复用查询和绑定服务；该能力已由 Booking V1 完成。当前下一轮应复用 `ServiceAppointmentBookingServices`、`ServiceAppointmentQueryServices`、`StoreServiceAppointmentServices`、`yfth_service_appointment`、`yfth_service_appointment_slot` 和 `yfth_service_benefit_lock` 进入签到、动态码、扫码核销和最终权益消耗。
- 不得重复开发的稳定能力：5980 套餐购买、CRMEB 订单/支付/退款、成交快照、权益计划、激活补偿、订单异常恢复、后台权限强校验、门店能力/资质扩展点和 YFTH 审计能力。
- 已知限制：当前不支持跨日服务时段；特殊关闭按整日关闭处理；Booking V1 已有真实预约、时段占用和权益锁，但尚未实现签到、核销、服务完成、爽约或最终权益消耗；服务项目的权益模板范围先以服务类 benefit template id 列表表达，后续如范围复杂化可拆独立关系表。

## Current Fact Snapshot - 2026-06-27 Service Appointment P1 Hardening

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Current latest commit: this P1 hardening commit; use Git HEAD on this branch after commit.
- Current stage: service project, store service authorization, weekly schedule rules, special-day rules, and read-only slot query foundation are complete; next round remains appointment creation, cancellation, reschedule, and benefit locking.
- Backend service appointment writes now resolve headquarters/store scope from real CRMEB admin tokens through `AdminAuthTokenMiddleware`, `AdminStoreContextServices`, and `yfth_admin_store_scope`.
- Client-injected `store_id`, `store_ids`, or role-like fields are not trusted for store write permission.
- Store managers/franchisees can write only scoped active-store schedules/special days; store staff and no-scope admins cannot configure service projects, store authorization, schedules, or capacity.
- Strict service date parsing rejects invalid `YYYYMMDD` and `YYYY-MM-DD` calendar dates, including non-leap `20260229`, and accepts leap day `20280229`.
- Existing `yfth_store_service` identity is immutable after creation: `store_id` and `service_project_id` cannot be changed on update.
- Public service project detail uses a whitelist and does not expose backend maintenance fields.
- Frozen modules: no appointment creation, benefit lock/release, check-in, dynamic code, writeoff, paid service order, notification, reward, delivery, settlement, production deployment, or production database operation was started in this round.
- Push status: this P1 round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Service Appointment Booking V1

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Start commit for this round: `7a3a8ef64bb193e4a52fc623e4e877b1c247c595`.
- Current latest commit: this booking V1 commit; use Git HEAD on this branch after commit.
- Current stage: service project, store service authorization, weekly schedule/special-day slot foundation, appointment creation, manual confirm, reject, cancel, reschedule, true slot capacity locking, and 5980 service-benefit locking are implemented on the feature branch.
- Completed Booking V1 capabilities include auto confirm, store/headquarters cancel, appointment event timeline, real backend token store permission, unified audit/idempotency, user appointment create/list/detail/cancel/reschedule pages, admin appointment list/detail/confirm/reject/cancel page, and MySQL 8.0.46 migration run/rollback/rerun plus real booking flow validation.
- New tables in this round: `yfth_service_appointment`, `yfth_service_appointment_slot`, `yfth_service_benefit_lock`, and `yfth_service_appointment_event`.
- Slot strategy remains `weekly rule realtime calculation + special-day overlay`; booking writes create/reuse lockable slot instances only for selected slots.
- User APIs/pages now cover available service benefits, create appointment, my appointment list, detail, cancel, reschedule-slot query, and reschedule submission.
- Admin APIs/page now cover appointment list/detail, pending appointment confirmation, rejection, and cancellation.
- Audit remains unified through `AuditEventServices::recordSafely()` into `yfth_audit_event`, business domain `yfth_service_appointment`; appointment history also writes `yfth_service_appointment_event`.
- Idempotency uses existing `yfth_idempotency_record` via `IdempotencyRecordServices`.
- Frozen modules remain: check-in, dynamic QR/code, scan writeoff, final service consumption, no-show/completion operations, paid service order, messages, rewards, delivery, settlement, production deployment, and production database operations.
- Next round should reuse `ServiceAppointmentBookingServices`, `ServiceAppointmentQueryServices`, `StoreServiceAppointmentServices`, `yfth_service_appointment`, `yfth_service_appointment_slot`, and `yfth_service_benefit_lock` to implement check-in, dynamic code, writeoff, and final benefit consumption.
- Push status: this booking V1 round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Service Check-in And Writeoff V1

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Start commit for this round: `1db8fbc2fabd609e9fce8b4258b1639c9bbe1eec`.
- Current latest commit: this check-in/writeoff V1 commit; use Git HEAD on this branch after commit.
- Completed capabilities: user dynamic QR token and 6-digit digital code generation/refresh/status, hash-only code persistence, old-code invalidation, code expiry, store precheck, store QR writeoff, store digital writeoff, headquarters exception writeoff, same-store staff/manager/franchisee writeoff, appointment check-in/writeoff/completion timestamps, final service-benefit consumption, writeoff record list/detail, user writeoff result display, mobile store scan/input page, and admin writeoff status/record visibility.
- New persistence: `yfth_service_dynamic_code`, `yfth_service_writeoff_record`, appointment writeoff columns, and benefit-lock consumed/writeoff columns.
- Service classes added: `ServiceAppointmentWriteoffServices` and `ServiceBenefitConsumptionServices`; Booking V1 continues to own creation, confirmation, cancellation, reschedule, slot capacity, and service-benefit lock creation/release.
- Dynamic code rules: only appointment owners can generate codes; appointments must be `confirmed`, uncompleted, within the default check-in window of 30 minutes before start to 120 minutes after end, and have an active service-benefit lock. QR tokens and numeric codes are returned only at generation time; stored values are SHA-256 hashes.
- Digital-code hardening: numeric-code precheck is read-only; numeric-code lookup first resolves real backend admin store scope and then searches only that trusted scope; random missing codes, other-store real codes, expired codes, invalidated codes, and rate-limited attempts share the same safe error semantics; failed numeric attempts are limited by administrator/store-scope/IP short-window counters instead of globally consuming a real code row.
- Active same-store numeric code uniqueness is protected by `yfth_service_dynamic_code.digital_active_key` and `uniq_yfth_svc_code_store_digital_active`; code generation retries finite same-store numeric collisions and clears active keys on refresh, expiry, invalidation, and successful writeoff.
- Headquarters exception writeoff now requires a service-side non-empty reason and persists it to writeoff record, appointment event, and unified YFTH audit paths.
- Writeoff transaction: locks appointment, dynamic code when present, benefit lock, benefit item and parent rows; then writes one successful writeoff record, marks the benefit item `used`, marks the benefit lock `consumed`, marks the appointment `completed`, records `checked_in`, `benefit_written_off`, and `completed` events, and records unified YFTH audit entries.
- Repeat writeoff behavior: a completed appointment returns `already_written_off` or replayed idempotent result and does not create a second writeoff record or consume the benefit item again.
- P2 hardening in this round: user appointment list/detail responses now use a whitelist and no longer expose raw `events`, raw `benefit_lock`, request ids, idempotency keys, snapshots, or backend operator fields; user reschedule now locks old/new slots by stable slot id order with finite deadlock retry.
- Audit remains unified through `AuditEventServices::recordSafely()` into `yfth_audit_event`, business domain `yfth_service_appointment`; appointment history also writes `yfth_service_appointment_event`.
- Frozen modules remain: writeoff reversal/refund recovery, service review, automatic no-show, notification messages, paid service order, cross-store/offline/printed codes, staff resource scheduling, family-member booking, rewards, delivery, inventory, settlement, production deployment, and production database operations.
- Current service appointment and dynamic writeoff V1 has passed final architecture review and is allowed to merge into `main`. The next business module must be selected separately by the project owner from the full product flow.
- Push status: this check-in/writeoff V1 round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Digital Writeoff Code Hardening

- Current branch: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main remains: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Start commit for this round: `25c6ac45aa3a41c197964c006bfa3ef60a888e07`.
- Current latest commit: this digital-code hardening commit; use Git HEAD on this branch after commit.
- Fixed security issues: digital-code precheck no longer mutates dynamic-code status or attempt counters; digital precheck/writeoff resolves trusted backend store scope before querying any numeric code; random missing codes and other-store real codes return the same safe error; cross-store input does not consume the real user's code attempts or state.
- Brute-force protection now uses a short-lived request counter keyed by administrator id, trusted store scope, request IP, and digital writeoff scene. The first through configured maximum failed attempts are allowed to execute; the next request is temporarily limited with the same safe error semantics. Successful digital writeoff resets the counter.
- Added persistence hardening: `20260703130000_harden_yfth_service_dynamic_codes.php` adds `digital_active_key`, `uniq_yfth_svc_code_store_digital_active`, `idx_yfth_svc_code_store_digital`, and writeoff-record `reason`.
- Same-store active numeric collisions are blocked by the database and generation retries with a finite limit. The same 6-digit code may exist in different stores, but lookup is always restricted to the real operator scope.
- Headquarters exception writeoff requires a non-empty service-side reason; missing, blank, or too-short reasons are rejected before any writeoff transaction. Valid reasons are written to the writeoff record, appointment events, and YFTH audit.
- Added negative real-flow coverage for read-only precheck, cross-store true code, random-code equivalence, rate-limit boundary, same-store active-code unique constraint, different-store same-code allowance, and headquarters exception reason validation.
- Still not implemented: writeoff reversal/refund recovery, automatic no-show, notification messages, paid service order, offline/printed codes, cross-store writeoff, production deployment, or production database migration.
- Digital-code security hardening targeted review result: B, conditionally passed. The original digital-code P1 is closed; there are no current Blocker/P1 issues for service appointment and dynamic writeoff V1.
- Service appointment and dynamic writeoff V1 is allowed to merge into `main`; the project may enter the next business module after merge, but the module must be selected separately by the project owner.
- Push status: this hardening round is local only unless a later operator explicitly pushes the feature branch.

## Current Fact Snapshot - 2026-07-03 Final Service Appointment And Writeoff V1 Closure

- Current branch after merge: `main`.
- Document closure commit and current stable main: `f9a0d963ac4c92120111983c9b489433f1ab0dca`.
- Current origin/main: `f9a0d963ac4c92120111983c9b489433f1ab0dca`.
- Feature branch retained: `feature/yfth-service-appointment-writeoff-v1`.
- Stable main before merge: `7413627250bd057474fd2a4ea04068fae5f2ec9c`.
- Final review conclusion: digital-code security hardening targeted review result is B, conditionally passed; the original digital-code P1 is closed; there are no current Blocker/P1 issues.
- Merge result: service appointment and dynamic writeoff V1 was merged into `main` with `git merge --ff-only`; `main` and the feature branch pointed to the same commit at merge time, and `origin/main` was pushed successfully to `f9a0d963ac4c92120111983c9b489433f1ab0dca`.
- Completed stable capabilities: service project definition, store service authorization, weekly schedule and special days, available dates and slots, appointment creation, auto/manual confirmation, rejection, cancellation, same-store same-project reschedule, true capacity locking/occupation, 5980 service-benefit lock/release/final consumption, check-in, dynamic QR token, 6-digit digital writeoff code, same-store staff/manager/franchisee QR or digital writeoff, headquarters exception writeoff, appointment completion, writeoff records, events, unified audit, idempotency, and minimum real user/store/admin pages.
- Validation basis: MySQL 8.0.46 migration run, rollback, rerun, and real-flow validation were completed in the feature branch before this final documentation closure.
- Digital-code hardening facts: precheck is read-only; backend admin token and trusted store scope are resolved before numeric-code lookup; other-store real codes, random wrong codes, expired codes, and invalidated codes share safe error semantics; failure throttling is keyed by administrator, trusted store scope, IP, and business scene; failed attempts 1 through 5 execute, and the 6th request is temporarily limited; same-store active numeric codes are protected by `digital_active_key`; generation retries finite collisions; headquarters exception writeoff reason is required on the service side.
- Non-blocking P2: expired digital codes may keep occupying `digital_active_key` until refresh or cleanup is triggered.
- Non-blocking P2: when Cache/Redis has an exception, the digital-code entry fails closed, but the degraded response and operator experience can still be improved.
- Non-blocking P3: no real wait-300-seconds TTL recovery test was executed; a future test can use injectable time or cache fake support.
- Still not implemented: writeoff reversal/reversal accounting, benefit recovery, automatic no-show, service review, WeChat subscription messages, SMS reminders, independent paid service orders, fuller store workstation, delivery fulfillment, recommendation/reward ledger, inventory replenishment, product quota, franchise contracts, real settlement, production deployment, and production database migration.
- Next state: current service appointment and dynamic writeoff V1 has passed final architecture review and is already merged and pushed to `main`. The next business module must be determined separately by the project owner from the complete product flow.
- Production status: no production deployment was performed, and no production database was connected during the merge closure.
