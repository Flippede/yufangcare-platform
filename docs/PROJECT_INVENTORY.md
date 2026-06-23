# 项目真实代码盘点

- 盘点日期：2026-06-23
- 盘点仓库：`C:\Users\zhangxu\Desktop\御方通和\testclone`
- 远端仓库：`https://github.com/Flippede/yufangcare-platform.git`
- 开始分支：`main`
- 盘点分支：`chore/project-inventory-baseline`
- 开始 commit：`273b5faa25502dd59fab5ccd70253b2ec4f70cf2`
- 盘点范围：只读真实代码、安装 SQL、配置示例、README、Docker 配置和产品文档；未连接线上数据库，未执行迁移，未实现业务功能。

## 1. 总体结论

当前仓库是一次性导入的 CRMEB 开源商城 PHP 版代码基线。根 README 标识 Edition 5.6.1，`crmeb/.version` 标识 `CRMEB-KY v5.6.3`，移动端 `manifest.json` 标识 5.6.2，后台 `package.json` 标识 5.6.0；因此可确认属于 CRMEB v5.6 系列，但具体发行包存在多处版本标识差异，不能只认定为单一精确版本号。

项目包含后端 ThinkPHP 6、多端 uni-app、Vue2 管理后台、PC 接口、H5 入口、Docker Compose 部署模板和安装 SQL。它可以作为御方通和项目的技术基础，尤其适合复用用户、商品、SKU、订单、支付、退款、物流、自提核销、优惠券、积分、付费会员、分销、内容、客服、后台权限和文件上传等成熟商城能力。

项目尚未形成御方通和专属的 5980 家庭康养套餐、十个月权益计划、预约时段、服务签到、动态权益核销、加盟合同、筹备验收、产品额度、导师线索、规则版本、奖励观察期和审计化冲正台账等业务域。

## 2. 目录结构

| 路径 | 实际用途 |
| --- | --- |
| `README.md` | CRMEB 开源商城根说明，含版本、技术栈、运行环境和功能介绍。 |
| `crmeb/` | PHP 后端主工程，含 ThinkPHP 入口、应用、配置、安装 SQL、vendor 和运行目录。 |
| `crmeb/app/adminapi` | 总部/管理后台 API，按 controller、route、validate 分层。 |
| `crmeb/app/api` | 用户端、移动端、PC、微信、小程序等 API。 |
| `crmeb/app/services` | 服务层，承载订单、支付、商品、用户、分销、门店、客服、统计等业务逻辑。 |
| `crmeb/app/dao` | DAO 层，配合模型访问数据库。 |
| `crmeb/app/model` | 模型层，按业务域组织。 |
| `crmeb/app/jobs` | 队列任务，含订单、退款、库存、消息等异步任务。 |
| `crmeb/app/listener` | 事件监听，含登录、支付、订单、用户等事件。 |
| `crmeb/public/install/crmeb.sql` | 初始化数据库 SQL，包含 154 张 `eb_` 表。 |
| `template/admin` | Vue2 + ElementUI 管理后台源码。 |
| `template/uni-app` | uni-app 移动端源码，覆盖 H5、小程序、APP 能力。 |
| `template/uni-app_y8tSE.zip` | 移动端压缩包，属于历史/打包产物。 |
| `docker-compose/` | Docker Compose、Nginx、PHP、Redis、MySQL 运行模板。 |
| `readme/` | README 引用的图片、Nginx 配置等资料。 |
| `openclaw_installer.sh` | 安装脚本，未纳入本轮执行。 |
| `安装必读.docx` | CRMEB 安装说明文档。 |

未发现仓库内原有 `docs/`、`AGENTS.md` 或 `CLAUDE.md`。

## 3. 技术栈

| 层 | 现状 |
| --- | --- |
| 后端语言 | PHP，`composer.json` 要求 `php >=7.1.0`，README 建议 PHP 7.1-7.4。 |
| 后端框架 | ThinkPHP 6，多应用结构，`topthink/think-multi-app`。 |
| 后端依赖 | overtrue/wechat、firebase/php-jwt、think-queue、workerman、workerman/crontab、OSS/COS/Qiniu/AWS SDK、PhpSpreadsheet、Alipay EasySDK 等。 |
| 管理后台 | Vue 2、Vue Router、Vuex、ElementUI、axios、vxe-table、wangeditor、xlsx、sass。 |
| 移动端 | uni-app，`manifest.json` 标识 CRMEB 标准版，支持 H5、微信小程序、APP；配置了支付、分享、OAuth、扫码、相机、地图能力。 |
| PC 端 | 后端存在 `app/api/controller/pc` 和 `app/services/pc`，根路由支持 `/home` PC 入口；仓库未发现独立 PC 前端源码目录。 |
| 数据库 | MySQL，README 建议 5.7-8.0；SQL 表前缀 `eb_`。 |
| 缓存 | Redis 可选，文件缓存可用。 |
| 队列/定时 | `php think queue:listen --queue`、`php think timer start --d`、`php think workerman start --d`。 |
| 部署 | 支持宝塔、手动部署、Docker Compose；Web 根目录为 `crmeb/public`。 |

## 4. 后端架构

后端入口在 `crmeb/think` 与 `crmeb/public/index.php`。根路由 `crmeb/route/route.php` 根据路径分发后台、客服、APP、PC、移动端静态入口。

主要后端模块：

- 管理后台接口：`crmeb/app/adminapi/route/*.php` 与 `crmeb/app/adminapi/controller/v1/*`。
- 用户端接口：`crmeb/app/api/route/v1.php`、`v2.php`、`pc.php`。
- 公共服务层：`crmeb/app/services`，按 activity、agent、article、diy、order、pay、product、shipping、system、user、wechat 等域组织。
- DAO/模型：`crmeb/app/dao` 与 `crmeb/app/model` 基本一一对应。
- 中间件：后台 token、后台角色权限、后台日志；用户端 token、站点开关、请求拦截。
- 异步与事件：`crmeb/app/jobs`、`crmeb/app/listener` 覆盖订单、退款、库存、支付、登录、访问记录等。

后台 API 和用户端 API 已分离：`adminapi` 面向管理后台，`api` 面向 H5/小程序/APP/PC。

## 5. 管理后台

`template/admin` 是 Vue2 管理后台。README 中列出的模块与真实目录基本一致：

- 商品：`product`
- 订单：`order`
- 会员：`user`
- 门店/核销/店员：`setting/systemStore`、`setting/storeList`、`setting/clerkList`、`setting/verifyOrder`
- 分销：`agent`、`division`
- 营销：优惠券、积分、拼团、砍价、秒杀、预售、抽奖等
- 财务：充值、余额、提现、流水
- 统计：用户、交易、订单、商品、余额、积分
- 内容：文章、文章分类、页面装修、Diy
- 设置：系统配置、权限规则、管理员、角色、外部接口、定时任务、文件校验、数据库备份
- 客服：PC/移动客服、话术、反馈、自动回复

构建命令：

- `npm run dev`
- `npm run build`
- `npm run eslint`
- `npm run prettier`

本轮未安装 node 依赖，未运行前端构建。

## 6. 移动端、H5、小程序、APP

`template/uni-app` 是移动端源码，`pages.json` 主页面包含：

- `pages/index/index`：首页
- `pages/goods_cate/goods_cate`：商品分类
- `pages/order_addcart/order_addcart`：购物车
- `pages/user/index`：个人中心

分包覆盖商品、订单、积分商城、用户、分销、客服、管理订单、核销、门店列表等功能。移动端 API 封装在 `template/uni-app/api/*.js`，请求封装在 `template/uni-app/utils/request.js`，使用 `Authori-zation: Bearer <token>`。

`manifest.json` 配置了 APP Payment、Share、OAuth、Barcode、Camera；微信支付/分享/OAuth、高德地图等信息存在硬编码配置。该文件包含可被滥用的 appsecret/appkey 类字符串，后续应替换为环境化配置并做密钥轮换评估。

当前底部导航仍是 CRMEB 商城结构，不符合产品文档要求的“首页、康养、商城、合作中心、我的”。

## 7. 数据库结构

安装 SQL：`crmeb/public/install/crmeb.sql`，共 154 张 `eb_` 表。关键表包括：

- 用户与账户：`eb_user`、`eb_user_address`、`eb_user_level`、`eb_user_money`、`eb_user_bill`、`eb_user_recharge`、`eb_user_extract`、`eb_user_spread`、`eb_user_brokerage`、`eb_user_brokerage_frozen`。
- 付费会员：`eb_member_ship`、`eb_member_right`、`eb_member_card`、`eb_member_card_batch`。
- 商品与 SKU：`eb_store_product`、`eb_store_product_attr`、`eb_store_product_attr_value`、`eb_store_product_attr_result`、`eb_store_category`、`eb_store_product_cate`。
- 订单与售后：`eb_store_order`、`eb_store_order_cart_info`、`eb_store_order_status`、`eb_store_order_refund`、`eb_store_order_invoice`。
- 门店/核销：`eb_system_store`、`eb_system_store_staff`，订单表含门店自提与核销码相关字段。
- 营销：优惠券、积分商品、拼团、砍价、秒杀、预售、抽奖、活动。
- 分销/代理：`eb_agent_level`、`eb_agent_level_task`、`eb_agent_level_task_record`、`eb_spread_apply`、`eb_division_agent_apply`。
- 内容与装修：文章、Diy、页面链接、页面分类。
- 系统：管理员、角色、菜单、配置、日志、附件、事件、定时任务、文件校验、外部接口、短信等。
- 微信生态：`eb_wechat_*`、`eb_routine_scheme`。

未发现独立迁移目录；虽然依赖包含 `topthink/think-migration`，当前结构以安装 SQL 为主。后续新增御方通和业务表时，必须先确定迁移方式，不能只改安装 SQL。

## 8. 已有功能分类

### 已存在且基本可复用

- 用户注册、登录、手机号绑定、微信/小程序授权。
- 后台管理员、角色、菜单、权限校验、后台操作日志。
- 商品、分类、SKU、规格、库存、商品标签、商品参数。
- 购物车、普通订单、支付回调、退款/售后、物流、发货、收货。
- 微信支付、余额支付、线下付款、充值。
- 门店档案、店员、到店自提、订单核销。
- 优惠券、积分、付费会员、会员等级、秒杀、拼团、砍价、预售、积分商城。
- 分销员、推广关系、佣金、提现、分销二维码/海报、分销等级任务。
- 文章、图文、页面装修、客服、消息、短信、文件上传、云存储。
- 队列、定时任务、Workerman 长连接、系统日志、数据库备份、文件校验。

### 已存在但需要验证

- PC 端入口与构建产物：后端有 PC 接口和 `/home` 路由，但未发现独立 PC 前端源码。
- APP 构建：uni-app manifest 已配置 APP 能力，但未执行 HBuilderX/CLI 构建。
- Docker 一键运行：存在多平台 Compose 模板，但未在本机启动验证。
- 支付、短信、云存储、物流查询：代码和配置存在，真实可用性依赖商户密钥与外部账号。
- 分销/事业部：代码存在，但规则与御方通和需求差异大，不能直接套用。

### 部分存在

- 多身份：`eb_user` 已含推广员、事业部、代理、员工等字段，后台有角色权限，但缺少同一用户在 C/B/A 身份间清晰切换的产品化模型。
- 门店隔离：存在门店、店员和自提核销，但不是完整的 B 端门店经营隔离、客户归属、库存、活动、报表体系。
- 付费会员：存在付费会员和权益展示，但没有“5980 套餐实例 + 十个月权益计划 + 月度履约状态”。
- 核销：存在订单核销码与店员核销校验，但没有服务预约、签到、动态权益核销码和权益恢复流程。
- 审计：有后台日志、订单状态、用户账单、资金流水，但缺少规则快照、奖励观察期、冲正台账和业务状态变更历史。

### 未发现

- 康养中心专属页面与底部导航。
- 5980 家庭康养套餐模板、套餐实例、十个月权益批次。
- 服务项目、门店时段、容量、预约、签到。
- 加盟申请、合同、筹备任务、开店验收完整闭环。
- 产品额度/返货额度台账。
- 服务导师线索、邀约、活动、帮扶任务。
- 只读奖励台账、规则版本、有效新客校验、观察期、退款冲正。
- 御方通和专属合规文案、协议、隐私授权和健康内容边界。

## 9. 可直接复用、扩展复用、建议新建

| 类型 | 模块 |
| --- | --- |
| 直接复用 | 登录、微信授权、后台权限、商品/SKU、购物车、普通订单、支付、退款、物流、文件上传、文章、页面装修、基础营销、客服、系统日志。 |
| 扩展复用 | 付费会员、优惠券、门店/店员、自提核销、分销关系、佣金账单、统计报表、后台配置、站内信和订阅通知。 |
| 新建独立业务域 | 康养服务项目、5980 套餐实例、十个月权益计划、预约时段、服务签到、权益核销、加盟合同/筹备/验收、产品额度、导师线索、奖励规则版本、审计冲正台账。 |
| 暂不建议复用 | 多级分销/事业部团队分佣直接套用御方通和奖励；用户余额直接承载返货额度；订单备注或 JSON 字段承载十个月权益。 |

## 10. 必须保护和冻结的成熟模块

| 模块 | 成熟度 | 后续允许方式 | 不应直接重写的核心 |
| --- | --- | --- | --- |
| 登录与微信授权 | 高 | 增加身份绑定和状态，不替换 token 体系。 | `LoginController`、`UserAuthServices`、微信授权服务。 |
| 商品与 SKU | 高 | 增加康养分类、价格策略、权益可用范围。 | 商品表、SKU、库存扣减、商品编辑器。 |
| 订单 | 高 | 增加套餐订单类型与业务事件，避免改坏普通订单。 | 下单、支付成功、库存扣减、订单状态机。 |
| 支付 | 高 | 复用支付网关和回调幂等，新增业务后置处理。 | `PayNotifyServices`、支付驱动、回调入口。 |
| 退款/售后 | 中高 | 扩展权益恢复和冲正事件。 | 退款单、退款审核、订单退款状态。 |
| 门店自提/核销 | 中 | 扩展为服务核销和权益核销。 | 店员身份、核销码、订单核销校验。 |
| 后台权限 | 高 | 新增菜单、权限点和审计要求。 | 管理员、角色、菜单、中间件。 |
| 页面装修 | 中高 | 可用于首页运营位，康养核心流程应独立开发。 | Diy 数据结构和移动端渲染链路。 |
| 分销 | 中 | 仅复用推荐关系和只读展示思路，奖励规则需独立化。 | 多级团队分佣和余额提现链路不应直接改为本项目规则。 |

## 11. 部署与运行

README 要求：

- Web 根目录设置为 `crmeb/public`。
- 一键安装入口会读取 `crmeb/public/install/crmeb.sql`。
- 队列：`php think queue:listen --queue`。
- 定时任务：`php think timer start --d`。
- 长连接：`php think workerman start --d`。
- Docker Compose：`docker-compose up -d`，模板包含 Nginx、PHP、MySQL、Redis。

本轮未启动服务，未导入数据库，未验证安装流程。

## 12. 风险与阻塞

| 等级 | 风险 | 证据 | 影响 | 首轮开发前处理建议 |
| --- | --- | --- | --- | --- |
| 阻塞 | 生产 `.env`、微信支付证书/私钥、运行时 PEM、前端 AppSecret/地图 Key 类字段和压缩包进入 Git 当前版本及历史 | `crmeb/.env`、`crmeb/public/install/.env`、`crmeb/public/WXCertUtil/cert/`、`crmeb/runtime/pem/`、`template/uni-app/manifest.json`、`template/uni-app_y8tSE.zip` | 可能泄露数据库、Redis、微信支付、地图和第三方平台凭据；文件删除不等于外部平台已轮换 | 本轮已建立安全基线、移出跟踪并执行历史净化；外部平台凭据仍需按清单轮换并验证。 |
| 高 | 未发现迁移目录，数据库以安装 SQL 为主 | `crmeb/public/install/crmeb.sql`、未发现 `crmeb/database` | 后续业务表如果只手改 SQL，会难以升级和回滚 | 第一项开发前先确定迁移规范。 |
| 高 | 需求关键业务域缺失 | 未发现套餐实例、权益计划、预约、产品额度等模型 | 若直接塞入订单备注/余额/分销表，会形成不可审计技术债 | 下一轮先做业务边界最小数据模型。 |
| 高 | 分销/事业部能力与产品合规边界冲突 | `crmeb/app/adminapi/route/agent.php`、`crmeb/app/api/route/v1.php` | 直接复用多级分佣可能偏离“只读台账、一层推荐、规则版本” | 分销仅作参考，奖励台账独立设计。 |
| 中 | 多端配置需要由环境注入 | `template/uni-app/config/app.js`、`template/uni-app/.env.example` | 未配置构建环境变量时 APP/小程序请求地址为空 | 按 `docs/SECURITY_BASELINE.md` 配置本地和服务器环境。 |
| 中 | 当前仓库仍包含 vendor 和大量静态/构建相关文件 | `crmeb/vendor`、`crmeb/public/admin`、`readme/` | 仓库体积大、升级和 diff 噪声高 | 本轮暂不清理 `vendor/`，避免改变服务器部署方式；后续架构审核判断保留策略。 |
| 中 | PC 端源码不完整或未确认 | 仅发现 PC 后端接口和路由 | 总部 PC/H5 规划可能与真实端能力有偏差 | 后续确认是否需要独立 PC 前端。 |
| 中 | 自动化测试未发现有效执行入口 | 前后端包存在测试配置但未安装依赖/未运行 | 改动回归主要依赖人工和静态检查 | 第一轮开发同步建立最小测试/验收脚本。 |
