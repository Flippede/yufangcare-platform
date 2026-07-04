# 御方通和总部管理后台产品化整合 V1

## 目标

本轮只产品化总部 Web 管理后台，不新增加盟商端、门店端、店员端、服务导师端 Web 后台。门店、店员和加盟商继续复用已有后台权限、门店上下文以及后续 uni-app 工作台规划。

## 后台入口

- 登录页、浏览器标题、PWA manifest 和布局 logo fallback 统一为“御方通和总部运营管理平台”语境。
- 首页由旧 CRMEB 图表页调整为总部运营工作台，入口仍复用现有 `admin-index-index` 页面权限。
- 新增只读接口：`GET home/yfth`，控制器为 `app\adminapi\controller\Common::yfthWorkbench()`。
- 该接口不写业务数据，不触发预约、核销、支付、退款或权益状态变更。

## 工作台数据

工作台统计只从真实表读取，表不存在或本地未迁移时返回 0，不返回假数据。

- 经营主体：`yfth_business_subject`
- 服务门店：`system_store`
- 用户总数：`user`
- 5980 套餐实例：`yfth_package_instance`
- 今日预约与待确认预约：`yfth_service_appointment`
- 今日核销：`yfth_service_writeoff_record`
- 今日商城订单：`store_order`

待办只展示真实计数大于 0 的预约/核销事项。快捷入口只指向已经存在的 CRMEB 或 YFTH 路由，并由前端按当前账号 `unique_auth` 过滤。

## 菜单与权限

- 菜单仍使用 CRMEB `system_menus`、角色和 `unique_auth` 体系。
- 新增迁移 `20260704110000_productize_yfth_hq_admin_menus.php` 只更新 YFTH 菜单名称和排序，不删除、不重建已有权限标识。
- YFTH 根菜单显示为“御方通和”，子菜单为“业务基础域”“套餐与权益”“服务预约与核销”。
- 原 CRMEB 商品、SKU、库存、订单、支付、退款、物流、用户、门店、装修、内容、上传、客服、管理员、权限和日志能力继续保留。

## 前端范围

- `template/admin/src/pages/index/index.vue`：总部运营工作台。
- `template/admin/src/pages/account/login/index.vue`：登录页默认标题与版权 fallback。
- `template/admin/src/layout/logo/index.vue`：无配置 logo 时显示御方通和文字 fallback。
- `template/admin/src/router/modules/yfth.js`：YFTH 路由标题中文化。
- `template/admin/src/pages/yfth/*`：本轮触达的基础域、套餐权益和服务预约页面可见操作文案中文化。

## 不变边界

本轮不修改 CRMEB 登录鉴权、token、订单、支付、退款、商品、库存、数据库迁移主流程和服务预约/核销业务状态机。

本轮不部署生产，不连接生产数据库，不删除功能分支，不合并 `main`。完成后只推送功能分支，等待后续审核和受控合并。

## 构建产物

本轮完成后需执行 `template/admin` 的 `npm run build`，并将构建产物刷新到 `crmeb/public/admin`。服务器后续加载 `main` 合并后的静态产物即可展示总部后台产品化界面，无需在服务器执行 npm 构建。
