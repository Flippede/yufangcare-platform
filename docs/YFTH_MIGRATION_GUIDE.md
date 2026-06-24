# 御方通和基础域迁移指南

## 1. 前置约束

不要在生产库直接执行本轮迁移。应先在隔离数据库完成 `run -> rollback -> run` 验证，再进入正式维护窗口。

本轮迁移目录：

```bash
crmeb/database/migrations
```

## 2. 本地或隔离库验证

在 `crmeb/` 目录执行：

```bash
php think list
php think migrate:run
php think migrate:rollback -t 20260624090000
php think migrate:run
```

如当前环境没有可用 MySQL，请至少执行：

```bash
php -l database/migrations/20260624090000_create_yfth_foundation_tables.php
php -l database/migrations/20260624090010_seed_yfth_foundation_menus.php
php tests/yfth_foundation_contract_check.php
```

## 3. 验证点

- 9 张 `eb_yfth_*` 表均创建成功。
- 菜单权限 `yfth-foundation-*` 可回滚并重新插入。
- `active_key` 唯一约束可以阻止启用态重复身份、重复门店角色、重复门店能力。
- `yfth_idempotency_record` 唯一约束可以阻止相同动作重复入库。
- `yfth_store_payment_route` 不包含支付密钥、证书、私钥、API Key。
- 资质审核通过会生成或更新门店能力。
- 资质暂停、驳回或过期会暂停来源能力。
- 店员跨门店核销订单失败。
- 同门店正常核销成功。
- 已核销订单重复请求返回幂等结果，不重复扣减或履约。

## 4. 生产切换建议

1. 维护窗口前备份生产数据库。
2. 确认外部凭据轮换和干净仓库部署路径已完成。
3. 只在确认部署代码版本与迁移版本一致后执行迁移。
4. 迁移后由管理员进入后台 `御方通和 / 基础域` 检查菜单、权限和列表。
5. 抽样校验门店店员核销跨店订单会失败。

## 5. 回滚说明

表结构迁移使用 `change()`，支持自动回滚。菜单权限迁移使用 `up/down`，回滚时按 `unique_auth` 删除本轮新增权限点。

## 6. 2026-06-24 Blocker hardening verification

- Runtime verification used portable PHP 7.4.33 and MariaDB 10.11.18 in an isolated local data directory under the Codex tool cache.
- `crmeb/tests/yfth_foundation_runtime_check.php` creates temporary InnoDB tables and verifies active store subject uniqueness, active payment route uniqueness, insert-first idempotency conflicts, and row-lock writeoff side-effect idempotency.
- `crmeb/tests/yfth_foundation_contract_check.php` verifies service contracts for store context trust boundaries, `franchisee` store binding, route/idempotency/audit masking rules, and menu seed idempotency.
- Current migration contracts require:
  - `yfth_store_subject.active_key = store_id:subject_role` for active rows.
  - `yfth_store_payment_route.active_key = store_id:business_scene` for active rows.
  - `yfth_idempotency_record` unique key on `business_domain + action_type + idempotency_key`.
  - `system_menus` seed upsert by `unique_auth`, with root -> page -> API permission parentage.
- Before production migration, run both PHP checks plus `php -l` on changed migration/service files. Do not apply this migration directly to production without the existing backup and maintenance-window process.
