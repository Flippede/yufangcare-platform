# 御方通和业务基础域 V1 架构说明

## 1. 范围

本轮建立业务基础域，不实现 5980 套餐、十个月权益、预约、采购、库存、奖励、支付分账等完整业务。代码仅提供后续业务复用的身份、门店主体、资质、能力、收款路由元数据、审计和幂等底座。

## 2. 分层

- 数据库迁移：`crmeb/database/migrations/*_yfth_*`
- 模型：`crmeb/app/model/yfth`
- DAO：`crmeb/app/dao/yfth`
- 服务：`crmeb/app/services/yfth`
- 用户端 API：`crmeb/app/api/controller/v1/yfth/BusinessContextController.php`
- 后台 API：`crmeb/app/adminapi/controller/v1/yfth/Foundation.php`
- 后台页面：`template/admin/src/pages/yfth/foundation/index.vue`

## 3. 当前业务上下文

`CurrentBusinessContextServices` 负责解析并校验当前用户的业务身份：

- `customer` 可无门店。
- `store_manager`、`store_staff` 必须携带 `store_id`，并命中启用且在有效期内的 `yfth_user_store_role`。
- 其他身份必须命中启用且在有效期内的 `yfth_user_identity`。

用户端接口：

- `GET /api/yfth/identities`
- `GET /api/yfth/context?role_code=...&store_id=...`
- `GET /api/yfth/capability/:capability?role_code=...&store_id=...`

## 4. 资质驱动能力

`StoreQualificationServices` 提交和审核门店资质。审核通过时，`StoreCapabilityServices` 根据资质类型映射启用门店能力；资质暂停、驳回、过期时，来源能力会同步暂停。

能力校验不会只看 `yfth_store_capability` 状态，还会回查 `source_qualification_id` 对应资质是否仍有效。

## 5. 收款路由边界

`yfth_store_payment_route` 只保存路由元数据和商户引用，不保存支付密钥、证书、私钥、API Key。后台列表输出会提供脱敏引用字段，并剔除 secret/private_key/api_key/cert 类字段。

## 6. 审计与幂等

`yfth_audit_event` 用于记录业务基础域状态变更。写入前会对手机号、token、secret、password、key 等字段做脱敏或移除。

`yfth_idempotency_record` 用于后续订单后置事件、权益、预约、奖励等动作的幂等保护。本轮先建立表和服务，不主动接入支付或权益流程。

## 7. 订单核销修复

`StoreOrderWriteOffServices` 不再把订单 `store_id` 改成店员 `store_id`。自提订单核销时，店员必须属于订单原门店；跨门店核销直接失败。已核销订单重复请求会返回当前订单和 `is_repeat_writeoff=1`，不再重复调用履约扣减流程。

## 8. 冻结模块

本轮未改写登录、微信授权、token、商品、SKU、普通订单、支付回调、退款、后台权限、门店档案等成熟模块，仅在订单核销处做最小权限修复。

## 9. 2026-06-24 Blocker hardening architecture

- Trust boundary: `CurrentBusinessContextServices` no longer trusts client-supplied `store_id` for non-store identities. Store identities must pass `UserStoreRoleServices::assertStoreRole()` and `StoreAccessServices::assertStoreActive()`.
- Store-scoped identity set: `franchisee`, `store_manager`, and `store_staff` all require an active store relation. Global roles resolve with `store_id = 0`.
- Store subject relation: `StoreSubjectServices` enforces one active relation per `store_id + subject_role`. Supported subject roles are `sales`, `payment`, `fulfillment`, `invoice`, `refund`, and `host`.
- Payment route: `StorePaymentRouteServices` enforces one active route per `store_id + business_scene`, adds route `version_no` and `priority`, strips secret-like fields, returns masked refs, and declares the order snapshot requirement.
- Idempotency: `IdempotencyRecordServices::begin()` is insert-first. Unique-key conflicts become replay/conflict state instead of a race-prone pre-read.
- Writeoff: `StoreOrderWriteOffServices` uses row locking on confirmation and keeps duplicate confirmations idempotent with no duplicate fulfillment side effects.
- Audit: `AuditEventServices::recordSafely()` isolates audit-write failures from user-facing flows while logging technical details. Sanitization now covers verification codes, credit codes, certificates, identity-like fields, merchant refs, and secret-like keys.
- Admin surface: foundation endpoints now include store subject save/disable and payment route save/disable/resolve. The Vue admin page exposes these operations and displays masked sensitive values by default.
