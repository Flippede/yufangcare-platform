# 御方通和总部统一商城 Stage 1A 运行验证

## 最终合并闭环

- 第一次独立架构审核：B，有条件通过。
- 审核发现项整改及最终审核提交：`50b9f59d78509dfdcdb326d622325dcc4e5dba6b`。
- 第二次独立架构复核：A，通过；原 Blocker/P1/P2/P3 均已关闭。
- 复核依据包括此前在审核提交上完成的 PHP 7.4.33、MySQL Community 8.0.46 migration lifecycle、错误索引反例、真实双进程竞争、lock-wait/deadlock、Stage 1A contract/source guard/real-flow 和全部要求的旧模块回归。
- 审核提交已通过 `git merge --ff-only codex/yfth-hq-mall-stage1a-authority-foundation` 快进进入 `main`，没有 merge commit、squash、rebase、cherry-pick 或历史改写。
- 本轮合并闭环没有重新执行完整 MySQL、migration lifecycle 或两进程测试。合并前实际重新执行的轻量检查只有：审核范围 `git diff --check`、Stage 1A 变更 PHP 文件语法、Stage 1A contract、Stage 1A source guard 和生产入口/冻结边界负向检查，结果均通过。
- 合并不开放生产 source、qualification 或写入口，也不授权 Stage 1B。未连接生产数据库或 Redis，未执行生产 migration、部署或微信上传。

## 2026-07-12 第一次架构审核发现项整改验证

第一次独立架构审核结论为 B，有条件通过。整改使用 PHP 7.4.33、MySQL Community Server 8.0.46、file cache 和本机隔离端口 33318；未连接生产数据库或 Redis。

### 索引签名反例

`yfth_hq_authority_foundation_migration_check.php` 在真实 MySQL 中构造并验证：

- 同名索引应唯一但实际非唯一；
- 同名索引列错误；
- 同名索引列顺序错误；
- 同名索引缺少列；
- 同名索引多出列；
- migration record 已存在且索引错误；
- 缺失索引且数据满足约束时安全恢复；
- 缺失唯一索引且存在重复数据时阻断，重复行保持不变；
- 完整 schema duplicate-up no-op。

全量 `migrate:run`、Stage 1A direct down/up、`migrate:rollback --target 0`、rerun、无 record/无 schema、无 record/compatible partial、有 record/full 和有 record/incomplete 路径全部通过。

### 门禁与重试反例

Stage 1A real-flow 新增并通过：existing active attribution 的 unknown source 拒绝且不创建幂等记录；existing active referral 的 unknown source 拒绝；合法测试 source 配合生产 fail-closed qualification 仍拒绝 existing relation；严格 attribution/referral replay 不增加事件，referral replay 不重复执行 qualification；paused referral 未通过资格不能 resume。

普通业务异常消息包含 `deadlock`、`1213` 或 `1205` 时 callback 只执行一次。结构化 SQLSTATE `40001` exception chain 可重试；模拟 1205 的总事务尝试严格为三次。真实 MySQL lock-wait 和 deadlock 均在第二次事务成功，每个请求的幂等 `begin()` 仍为一次。

### Package benefit 独立复现

旧 `yfth_package_benefit_real_flow_check.php` 未修改。它要求父进程和十个 worker 共同读取仓库临时 `.env` 中的同一完整迁移数据库，并使用同一 portable PHP 扩展目录；仅设置 Stage 1A 的 `YFTH_REAL_FLOW_DB_*` 变量不能替代该 `.env` 约定。

可复现命令顺序为：

```text
# .env 必须指向本机全新 validation 数据库，CACHE.DRIVER=file
php -c <php-yfth-test.ini> crmeb/think migrate:run
set PHPRC=<php-yfth-test.ini>
set YFTH_REAL_FLOW_EXECUTE=1
set YFTH_REAL_FLOW_ISOLATED_DB=1
php -c <php-yfth-test.ini> crmeb/tests/yfth_package_benefit_real_flow_check.php
```

三次全新完整迁移数据库结果：

- `yfth_package_audit_validation_1`，run `RF1302405ADA66`，exit 0，一个并发 intent、一个 attempt、一个 purchase、一个绑定 CRMEB order。
- `yfth_package_audit_validation_2`，run `RF13033657A1BF`，exit 0，一个并发 intent、一个 attempt、一个 purchase、一个绑定 CRMEB order。
- `yfth_package_audit_validation_3`，run `RF13044045B837`，exit 0，测试执行时全部并发断言通过。

在完成 Stage 1A migration/real-flow 后，同库顺序运行 run `RF130128288D04` 通过；在 referral reward 和 service appointment 回归后的复用库再次运行 `RF130809E73A02` 也通过。五次均通过 `concurrent_intent_creates_only_one_crmeb_order`。

审核方原单次失败在上述执行契约下未复现，现有证据不支持旧 5980 生产并发缺陷，因此未修改 package benefit 业务代码或测试。可确认的 P2 根因是原运行证据缺少可独立复制的完整环境/worker 契约；原审核命令不可得，不能进一步虚构某个具体缺失变量。后续复核必须为每个 real-flow 使用独立数据库，因为部分旧测试会清理其隔离库中的整张业务表，不能把共享数据库的事后状态当作另一测试的证据。

### 本轮总结果

Stage 1A contract、source guard、migration check、real-flow，foundation/package/referral/customer/appointment/store-workbench/monthly/supply-chain/product-quota/franchise-opening contract，以及 package、referral、customer、appointment real-flow 均通过。本轮尚未经过第二次只读架构复核，不代表允许合并。

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

Stage 1A 第一次架构审核发现项已完成整改和开发侧验证。当前仍无生产入口，功能分支未合并 main，也没有第二次复核结论。唯一下一步是独立只读 Architecture Auditor 复核；复核通过前不得合并、启动 Stage 1B 或开放真实业务来源。
