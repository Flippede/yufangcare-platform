# 御方通和总部统一商城 Stage 1A 运行验证

## 1. 环境

- 验证日期：2026-07-11
- 分支：`codex/yfth-hq-mall-stage1a-authority-foundation`
- 开始基线：`ec1eeb61ad1755de35b5c8744bee6d51fe779b70`
- PHP：7.4.33 portable，启用 `pdo_mysql` 和 `proc_open`
- MySQL：Community Server 8.0.46
- 临时端口：33317
- 完整迁移库：`yfth_hq_authority_validation`
- 旧客户 CRM 空库夹具：`yfth_hq_authority_legacy_validation`
- 缓存：file；未连接 Redis

只在本机临时环境使用空密码 root 和临时 `.env`。验证结束后原 `.env` 已恢复，恢复后的 SHA-256 为 `1FEAB6EE35F27EFB592701D08B54C0ABF826EBA3D5BA60351F1B425E45CA0452`。

## 2. Migration lifecycle

在 MySQL 8.0.46 完整执行并通过：

1. CRMEB/YFTH 全迁移 run。
2. Stage 1A migration 直接 down/up。
3. 无 record、无 schema 创建。
4. 有 record、完整 schema duplicate-up no-op。
5. 有 record、schema 不完整时阻断并要求 forward repair。
6. 无 record、兼容 partial schema 补齐缺表和安全索引。
7. 删除一个安全索引后由 up 恢复。
8. `migrate:rollback -t 0`。
9. 全量 rerun。

结果：四张表、字段签名、`source_unique_key` 的 ascii/ascii_bin 定义和全部索引均符合预期；down 未修改旧 YFTH 表或 `system_menus`。

## 3. Stage 1A 测试

执行：

```text
php crmeb/tests/yfth_hq_authority_foundation_contract_check.php
php crmeb/tests/yfth_hq_authority_foundation_source_guard.php
php crmeb/tests/yfth_hq_authority_foundation_migration_check.php
YFTH_HQ_AUTHORITY_REAL_FLOW_EXECUTE=1 YFTH_REAL_FLOW_ISOLATED_DB=1 php crmeb/tests/yfth_hq_authority_foundation_real_flow_check.php
```

最终结果全部通过，覆盖：

- 非法、空白、未知和客户端 source key 拒绝。
- 不存在 UID 拒绝、占位 version 0、首次归属 version 1。
- 同请求幂等回放、跨店抢占拒绝、事件唯一冲突回滚 current。
- paused、历史 unassigned、closed 不能普通重绑。
- 生产推荐资格 fail closed。
- 推荐创建及 pause/resume/close/invalid 事件版本完整。
- source digest 不进入通用审计。
- `NULL` source key 可容纳多个不适用记录。

## 4. 两进程并发

并发 worker 使用两个独立 PHP 进程和独立 MySQL 连接，最终全部通过：

- 同一 UID 两门店竞争：一个成功，一个 `attribution_store_conflict`，只写一个版本 1 事件。
- 两推荐人竞争同一被推荐人：一个成功，`active_referred_uid` 唯一约束保持。
- 并发 A→B 与 B→A：一个成功，另一方被 direct reverse guard 拒绝。
- 同一推荐人并发绑定两个不同用户：两条均成功。
- 真实 lock wait timeout：同一 processing ownership 下第 2 次事务成功，幂等 begin 仍为 1 次。
- 真实 deadlock：一个 worker 第 2 次事务成功，两个操作均完成且各自 begin 仅 1 次。

## 5. 旧模块回归

以下 contract/source guard 全部通过：

- foundation
- package benefit
- referral reward
- franchise customer
- service appointment
- store workbench
- monthly fulfillment
- supply chain
- product quota
- franchise opening

以下真实流程在 MySQL 8.0.46 隔离环境全部通过：

- package benefit real-flow
- referral reward real-flow，包括 CRMEB funding boundary
- franchise customer real-flow，包括本店/跨店来源绑定、权限和 DTO 脱敏
- service appointment real-flow，包括排班、容量、权益锁定、动态码和核销

客户 CRM 脚本按其设计在空隔离库自建最小运行表；预约脚本在完整迁移库运行。环境调试阶段曾因未启用脚本自带本地 API server、以及把空库夹具误跑到完整 CRMEB 表约束上而失败；按脚本规定的 `ISOLATED_DB`、`START_SERVER` 和数据库形态修正后，不修改旧测试或旧业务源码即完整通过。

## 6. 边界检查

`git diff`、contract 和 source guard 确认本阶段没有修改：

- CRMEB 商品、SKU、库存、订单、支付、退款和分销。
- 5980 package、`member_5980`、旧 referral candidate/ledger。
- `yfth_customer_relation`、预约、动态码、核销和旧业务入口。
- Controller、route、Command、Listener、Job、菜单、API 权限和前端。

未执行 admin build、H5 build、mp-weixin compile、浏览器验证或 Redis 验证，因为本阶段没有前端、HTTP 入口或 Redis 依赖。

## 7. 清理结果

- 已删除 `yfth_hq_authority_validation`、`yfth_hq_authority_legacy_validation` 和调试过程中产生的旧临时库名。
- MySQL 33317 实例已正常关闭，专用进程已退出。
- 临时 MySQL data dir、导入 SQL 和 HTTP 测试日志已删除。
- 临时 `.env` 已替换为哈希校验通过的原文件。
- 未连接生产数据库或生产 Redis，未读取生产数据，未执行生产迁移。
- 未部署生产服务器，未上传微信平台，未使用生产 AppID、私钥或上传密钥。

## 8. 结论与下一门禁

Stage 1A 的结构、事务、事件、幂等、并发、迁移恢复和旧域兼容性验证通过。当前仍无生产入口，功能分支未合并 main。唯一下一步是独立只读架构审核；未经审核和项目主控授权，不得合并、启动 Stage 1B 或开放真实业务来源。
