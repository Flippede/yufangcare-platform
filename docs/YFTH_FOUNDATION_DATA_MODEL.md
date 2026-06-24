# 御方通和业务基础域 V1 数据模型

## 1. 表清单

所有新表通过 ThinkPHP migration 创建，逻辑名为 `yfth_*`，在默认表前缀下落为 `eb_yfth_*`。

| 表 | 用途 |
| --- | --- |
| `yfth_user_identity` | 同一用户的多业务身份 |
| `yfth_user_store_role` | 用户在门店内的角色和权限范围 |
| `yfth_business_subject` | 总部、加盟商、门店、供应商等经营主体 |
| `yfth_store_subject` | 门店与真实经营主体关系 |
| `yfth_store_qualification` | 门店/主体资质提交、审核、暂停、过期 |
| `yfth_store_capability` | 由资质或授权产生的门店业务能力 |
| `yfth_store_payment_route` | 门店收款、退款、开票路由元数据 |
| `yfth_audit_event` | 业务基础域审计事件 |
| `yfth_idempotency_record` | 幂等动作记录 |

## 2. 唯一性和隔离

- `yfth_user_identity.active_key`：限制同一用户、身份、来源在启用态不能重复。
- `yfth_user_store_role.active_key`：限制同一用户在同一门店同一角色的启用态不能重复。
- `yfth_store_subject.active_key`：限制同一门店主体角色的启用态关系不能重复。
- `yfth_store_capability.active_key`：限制同一门店同一能力的启用态记录不能重复。
- `yfth_idempotency_record`：`business_domain + action_type + idempotency_key` 唯一。

`active_key` 在非启用态为 `NULL`，允许保留历史记录。

## 3. 状态约定

基础状态：

- `active`：启用或审核通过。
- `pending`：待审核。
- `rejected`：已驳回。
- `paused`：已暂停。
- `disabled`：已禁用。
- `expired`：已过期。

门店资质只有通过状态才能驱动能力；资质过期或暂停后，来源能力必须暂停。

## 4. 角色约定

本轮预置角色编码：

- `customer`
- `family_member`
- `store_manager`
- `store_staff`
- `franchisee`
- `supplier`
- `headquarter_operator`

`store_manager` 和 `store_staff` 必须绑定门店；普通用户可无门店。

## 5. 能力约定

本轮预置能力编码：

- `retail_sale`
- `package_sale`
- `reservation_service`
- `order_writeoff`
- `store_purchase`
- `online_payment`

能力只表达门店是否具备资格，不直接创建套餐、预约、采购、库存或支付交易。

## 6. 敏感字段

支付路由表不包含密钥、证书、私钥或 API Key。后台输出时商户引用字段提供脱敏值。审计事件写入前会对手机号和 secret/token/password/key 类字段做处理。

## 7. 2026-06-24 Blocker hardening data model updates

- `yfth_user_store_role`: active rows bind a user to a concrete store and role. `franchisee` is store-scoped; do not model it as a global identity when a store workbench is required.
- `yfth_store_subject`: active uniqueness is `active_key = store_id:subject_role`. `subject_id` is intentionally not part of the active key, so a store can have only one active sales/payment/fulfillment/invoice/refund/host subject at a time. Disabled/expired/history rows use `active_key = NULL`.
- `yfth_store_subject` role coverage now includes `sales`, `payment`, `fulfillment`, `invoice`, `refund`, and `host`, plus boolean flags for compatibility and querying.
- `yfth_store_payment_route`: new `version_no`, `priority`, and `active_key` columns. Active uniqueness is `active_key = store_id:business_scene`; route resolution orders by `priority desc, version_no desc, id desc` and fails if historical data creates more than one active match.
- `yfth_store_payment_route` stores metadata and external merchant references only. It must not store payment secrets, private keys, API keys, certificates, or raw credential material.
- `yfth_idempotency_record`: unique key remains `business_domain + action_type + idempotency_key`. The service now inserts first and treats duplicate-key conflicts as the replay boundary.
- `yfth_audit_event`: before/after payloads must be sanitized. Full verification codes, full credit codes, certificate/id-like numbers, merchant refs, tokens, passwords, secrets, API keys, and private keys must not be persisted in clear text.
