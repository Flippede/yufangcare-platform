# 御方通和总部管理后台产品化整合 V1

## 目标

本轮只产品化总部 Web 管理后台，不新增加盟商端、门店端、店员端、服务导师端 Web 后台。门店、店员和加盟商继续复用已有后台权限、门店上下文以及后续 uni-app 工作台规划。

## 后台入口

- 登录页、浏览器标题、PWA manifest 和布局 logo fallback 统一为“御方通和总部运营管理平台”语境。
- 首页由旧 CRMEB 图表页调整为总部运营工作台，页面入口仍复用现有 `admin-index-index` 菜单权限；全局统计接口单独登记 API 权限。
- 新增只读接口：`GET home/yfth`，控制器为 `app\adminapi\controller\Common::yfthWorkbench()`。
- 该接口已登记 API 权限 `yfth-hq-workbench-read`，显示名称为“查看总部经营工作台”，`api_url = home/yfth`，`methods = GET`，`auth_type = 2`，父级为 `admin-home`。
- `Common::yfthWorkbench()` 在读取任何全局统计前调用 `SystemRoleServices::assertApiAuthForAdmin($this->adminInfo ?: [], 'home/yfth', 'GET')` 做服务端纵深校验，普通角色必须显式授权，超管继续按 CRMEB 既有机制自动拥有。
- 该接口不写业务数据，不触发预约、核销、支付、退款或权益状态变更。

## 工作台数据

工作台统计只从真实表读取，不返回假数据。可选的 `yfth_` 扩展表在本地未迁移或缺失时返回 0；CRMEB 核心表或非缺表数据库错误继续抛出，避免掩盖真实数据库异常。

- 经营主体：`yfth_business_subject`
- 服务门店：`system_store`
- 用户总数：`user`
- 5980 套餐实例：`yfth_package_instance`
- 今日预约与待确认预约：`yfth_service_appointment`
- 今日核销：`yfth_service_writeoff_record`
- 今日支付订单：`store_order`，按 `pay_time` 当日、`paid = 1`、`refund_status = 0`、`pid = 0`、`is_del = 0` 统计主订单数量。
- 今日成交金额：`store_order.pay_price`，与“今日支付订单”共用同一查询集合，只对上述已支付未退款主订单汇总金额。

待办只展示真实计数大于 0 的预约/核销事项。快捷入口只指向已经存在的 CRMEB 或 YFTH 路由，并由前端按当前账号 `unique_auth` 过滤。

## 菜单与权限

- 菜单仍使用 CRMEB `system_menus`、角色和 `unique_auth` 体系。
- 新增迁移 `20260704110000_productize_yfth_hq_admin_menus.php` 更新总部后台一级菜单、YFTH 根菜单、YFTH 子菜单和 YFTH API 权限树的中文名称，不删除、不重建已有权限标识。
- 新增权限迁移 `20260704150000_add_yfth_hq_workbench_permission.php` 幂等插入或修复 `yfth-hq-workbench-read`，防止 `GET home/yfth` 因未登记 API 权限而被普通后台账号读取全局总部统计。
- 一级菜单产品化为“首页工作台、加盟与门店、客户与会员、商品与供货、御方通和康养服务、订单与售后、推荐与营销、内容与装修、内容管理、系统管理、系统维护”等业务分组；客服、财务、应用等既有中文分组保留。
- YFTH 根菜单显示为“御方通和康养服务”，子菜单为“业务基础域”“套餐与权益”“服务预约与核销”，YFTH API 权限菜单同步中文化。
- 迁移只更新 `menu_name` 和必要的 `sort`，保留 `unique_auth`、菜单 ID、API URL、请求方法和角色规则兼容性。
- 原 CRMEB 商品、SKU、库存、订单、支付、退款、物流、用户、门店、装修、内容、上传、客服、管理员、权限和日志能力继续保留。

## 前端范围

- `template/admin/src/pages/index/index.vue`：总部运营工作台。
- `template/admin/src/pages/account/login/index.vue`：登录页默认标题与版权 fallback。
- `template/admin/src/layout/logo/index.vue`：无配置 logo 时显示御方通和文字 fallback。
- `template/admin/src/router/modules/yfth.js`：YFTH 路由标题中文化。
- `template/admin/src/pages/yfth/*`：本轮触达的基础域、套餐权益和服务预约页面可见操作文案中文化。

## 不变边界

本轮不修改 CRMEB 登录鉴权、token、订单、支付、退款、商品、库存、数据库迁移主流程和服务预约/核销业务状态机。

总部管理后台产品化 V1 已通过最终架构复审，并已通过 `git merge --ff-only feature/yfth-hq-admin-productization-v1` 合并进入 `main`。功能分支保留，不删除；本轮不部署生产，不连接生产数据库，不执行生产迁移。

## 构建产物

服务预约与动态核销 V1 最新管理后台生产构建产物已刷新到 `crmeb/public/admin`。本轮核对 `template/admin/dist` 与 `crmeb/public/admin` 均为 592 个文件、39,427,546 字节且无差异；本轮未修改 Vue 源码，因此未重复执行 npm 构建。服务器后续加载静态产物即可展示相关后台页面，无需在服务器执行 npm 构建。

## 验证收口记录

- 开始状态：`feature/yfth-hq-admin-productization-v1`，P1 修复基线 `cb46de4e895df26bbf5b3def862e5fa6fe8e5f4e`，稳定 `main`/`origin/main` 为 `f6ebce63d1afda54f416de41a3d2036669a0122d`。
- PHP 环境：便携 PHP 7.4.33，临时 `php-verify.ini` 启用 `pdo_mysql`、`mysqli`、`mbstring`、`openssl`、`fileinfo` 和 `curl`。
- 数据库环境：隔离 MySQL 8.0.46，临时库 `yfth_hq_admin_verify` 与 `yfth_hq_p1_verify`；未复制生产 `.env`，未连接生产数据库或服务器。
- 迁移验证：完整迁移 run 通过；`20260704110000_productize_yfth_hq_admin_menus.php` rollback/rerun 通过；目标 `unique_auth` 未产生重复，YFTH 当前权限菜单英文项数量为 0。
- P1 权限迁移验证：`20260704150000_add_yfth_hq_workbench_permission.php` run 前目标权限数量为 0，run 后为 1，rollback 后为 0，rerun 后为 1；目标权限挂在 `admin-home` 下，`api_url = home/yfth`、`methods = GET`、`auth_type = 2`，重复数量为 0。
- API 验证：未登录访问 `GET /adminapi/home/yfth` 返回 `110003`；超管真实登录后返回总部工作台数据；无 `yfth-hq-workbench-read` 的普通管理员返回 `100101`；显式拥有该权限的普通管理员成功；有门店范围但无总部工作台权限的账号返回 `100101`；缺失可选 `yfth_service_writeoff_record` 表时工作台仍成功返回 0。
- 统计口径验证：隔离库插入今天创建未支付、昨天创建今天支付、今天支付主订单、今天支付子订单、今天支付但退款状态非 0、已删除订单、正常今日支付订单后，接口返回“今日支付订单”=3，“今日成交金额”=120，只统计按支付时间落在今日的已支付未退款主订单。
- 写入边界：`GET home/yfth` 未改动 YFTH 业务表、订单表或预约/核销数据；CRMEB 既有 `AdminLogMiddleware` 会记录系统访问日志。
- 浏览器验证：本机 `http://127.0.0.1:18081/admin` 登录页可加载并登录，首页工作台显示中文一级菜单、总部运营卡片和真实空待办；`/admin/yfth/service-appointment` 可加载服务预约与核销页面，主要 JS/CSS/chunk 资源均为 200，无白屏。
- 契约验证：`crmeb/tests/yfth_service_appointment_contract_check.php` 已按中文页面标签更新并通过。
- P2 遗留：菜单自定义名称和排序覆盖策略仍可能受未来 CRMEB 菜单变更影响，暂不阻塞总部后台产品化 V1。
- 合并收口：最终复审结论为 B，P1 已关闭，无 Blocker/P1；总部管理后台产品化 V1 已合并进入 `main`，当前提交以 Git HEAD 和 origin/main 实时值为准。
