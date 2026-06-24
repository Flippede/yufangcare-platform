# 项目交接文档

- 项目名称：御方通和加盟 APP / 微信小程序
- 当前代码基础：CRMEB 开源商城 PHP 版 v5.6 系列
- 本地路径：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform`
- GitHub 仓库：`https://github.com/Flippede/yufangcare-platform.git`
- 当前分支：`feature/yfth-foundation-domain-v1`
- 开始 commit：`273b5faa25502dd59fab5ccd70253b2ec4f70cf2`
- 最近业务开发 commit：`038e288`；本轮目录治理提交见当前分支最新 Git 提交
- 产品文档目录：`C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\项目文档`
- 完整产品依据：`御方通和加盟小程序项目需求与产品设计文档_V1.0.docx`

## 1. 项目目标

在 CRMEB 成熟商城和后台能力基础上，开发御方通和加盟 APP / 微信小程序，覆盖公共用户端、C端家庭康养会员、B端加盟商/门店工作台、A端服务导师、总部 Web 管理后台、商品商城、5980 家庭康养套餐、十个月权益、预约核销、加盟经营、推荐关系、奖励台账、内容活动、报表和审计。

当前阶段目标是完成真实代码盘点、需求映射和文档基线，不实现产品功能。

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

## 4. 当前未完成模块

尚未发现御方通和专属实现：

- 康养中心底部导航和页面结构。
- 5980 家庭康养套餐实例。
- 十个月权益计划、月度权益批次和履约状态。
- 服务项目、门店预约时段、容量、签到。
- 动态权益核销码、权益恢复、权益历史。
- B端门店经营工作台、客户归属、经营待办。
- 加盟申请、合同、筹备任务、开店验收。
- 产品额度/返货额度台账。
- 服务导师线索、邀约、活动和帮扶任务。
- 只读奖励台账、规则版本、观察期、有效新客校验和冲正。

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
- 未发现独立迁移目录，当前以安装 SQL 为主。
- `vendor` 和大量静态/构建相关文件仍进入仓库，后续需评估仓库体积和部署方式；本轮未移除 `vendor/`，避免改变服务器部署方式。
- 移动端配置仍有 CRMEB demo/default 配置。
- 关键产品域缺失，不能把需求文档规划误写为已完成能力。
- 自动化测试和本地完整启动未验证。

## 7. 当前开发阶段

阶段：生产服务器安全切换准备与凭据使用核验。

本轮变化：

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

安全整改完成并完成外部平台凭据轮换验证前，不得开始 5980 套餐、十个月权益、预约核销、加盟、产品额度或奖励台账等业务开发。

当前唯一下一步：先为生产服务器配置 GitHub Deploy Key 或受控 SSH 凭据，使 `git@github.com:Flippede/yufangcare-platform.git` 能按预案克隆到 `/www/wwwroot/yufangcare-platform-clean`，再进入正式维护窗口准备。

安全前置项完成后，第一项开发任务建议为：御方通和业务基础域与迁移规范设计/落地。

建议先明确：

- 新增业务表和迁移方式。
- 身份模型、门店隔离和当前身份切换。
- 5980 套餐实例、权益计划和权益状态机。
- 预约、签到、权益核销与订单核销的边界。
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
