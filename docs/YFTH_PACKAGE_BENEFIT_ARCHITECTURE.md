# 御方通和 5980 套餐与十个月权益 V1 架构说明

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
