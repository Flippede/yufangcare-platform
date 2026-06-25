# 御方通和 5980 套餐权益模块真实 MySQL 隔离验收

## 1. 验收范围

- 验收分支：`feature/yfth-package-benefits-v1`
- 验收起点：`c200ef37f6cbf168a79aa3c493995373ed09521b`
- 验收对象：5980 套餐购买、成交快照、支付后激活、十个月权益计划、生命周期/退款联动、补偿恢复、幂等和并发唯一性。
- 验收边界：只使用本地隔离 MySQL 测试库；不连接生产库，不读取或写入生产 `.env`，不部署，不合并主分支，不强推。

## 2. 隔离运行环境

- PHP：便携 PHP 7.4.33，启用 `pdo_mysql`、`openssl`、`curl`、`mbstring`、`gd2`、`fileinfo`。
- MySQL：MySQL Community Server 8.0.46 Windows ZIP，来源为 MySQL 官方下载页和官方 CDN。
  - 下载页：<https://dev.mysql.com/downloads/mysql/8.0.html>
  - ZIP：<https://cdn.mysql.com/Downloads/MySQL-8.0/mysql-8.0.46-winx64.zip>
  - 官方 MD5：`003f527d5df61b663ff191038cd676bd`
- 临时端口：`127.0.0.1:33306`
- 临时库：`yufangcare_validation_20260625_c200ef3`
- 临时用户：`yfth_val_c200ef3`
- 临时验证副本：`.codex/tools/yfth-validation/package-benefits-c200ef3-20260625`

本轮验收明确排除 MariaDB。`SELECT VERSION()` 返回 `8.0.46`，脚本同时校验版本字符串不包含 `MariaDB`。

## 3. CRMEB 基线导入

- 基线 SQL：`crmeb/public/install/crmeb.sql`
- 基线导入结果：154 张 CRMEB 原始表。
- MySQL 8.0 严格模式下，原始安装 SQL 存在历史默认时间字段兼容问题。本轮未修改全局 SQL mode，只在导入基线 SQL 的会话内使用 `sql_mode='NO_ENGINE_SUBSTITUTION'` 完成安装数据导入。
- 服务器全局 SQL mode 保持默认严格组合：`ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`。

## 4. 迁移验收结果

真实 MySQL 上执行 `php think migrate:run`、回滚到 0、再次执行 `php think migrate:run` 均完成。

已验证的御方通和迁移：

- `20260624090000 CreateYfthFoundationTables`
- `20260624090010 SeedYfthFoundationMenus`
- `20260624130000 CreateYfthPackageBenefitTables`
- `20260624130010 SeedYfthPackageBenefitMenus`
- `20260624170000 HardenYfthPackagePurchaseSnapshots`
- `20260624170010 SeedYfthPackageRecoveryMenus`

迁移后结果：

- 总表数：178
- `eb_yfth_*` 表数：23
- `eb_migrations` 御方通和记录：6
- `yfth-%` 菜单权限：37
- `unique_auth` 重复记录：0

关键索引已验证存在：

- `eb_yfth_benefit_period.idx_yfth_benefit_period_expire_guard`
- `eb_yfth_benefit_period.idx_yfth_benefit_period_open_guard`
- `eb_yfth_package_purchase.uniq_yfth_pkg_purchase_order_key`
- `eb_yfth_package_purchase.uniq_yfth_pkg_purchase_order_sn_key`
- `eb_yfth_package_purchase_benefit_snapshot.uniq_yfth_pkg_benefit_snapshot_rule`
- `eb_yfth_package_purchase_snapshot.uniq_yfth_pkg_snapshot_purchase`

回滚后结果：

- `eb_yfth_*` 表数：0
- `yfth-%` 菜单权限：0
- `eb_migrations` 御方通和记录：0

## 5. 真实闭环验收

执行命令：

```bash
YFTH_REAL_FLOW_EXECUTE=1 YFTH_REAL_FLOW_ISOLATED_DB=1 YFTH_REAL_FLOW_RUN_ID=RFVAL09 php tests/yfth_package_benefit_real_flow_check.php
```

执行结果：

- MySQL 厂商与版本校验通过：`8.0.46`，非 MariaDB。
- 必需表、索引、服务类和监听器解析通过。
- 真实种子数据写入隔离库：CRMEB 用户、门店、商品、SKU、经营主体、门店主体、能力、收款路由、套餐模板、规则、10 个月权益规则和商品绑定。
- `createIntent -> createOrderFromIntent -> CRMEB StoreCart/StoreOrderCreate -> PackagePaySuccessListener` 真实下单激活闭环通过。
- 支付后生成：1 条购买记录、1 个套餐实例、1 条购买快照、10 条权益快照、1 个权益计划、10 个权益周期、10 个权益项。
- `member_5980` 身份授予通过。
- 重复支付事件幂等通过，无重复实例。
- 激活失败后快照修复与重试通过。
- 已支付未激活购买补偿恢复通过，重复补偿不产生重复数据。
- 10 进程并发绑定同一 CRMEB 订单通过，数据库唯一键最终只保留 1 条购买记录和 1 条快照。
- 冻结、退款中状态禁止开启周期通过。
- 退款取消、部分履约退款关闭、未履约全额退款联动通过。
- 已发布/已引用规则不可原地修改，复制新版本并复制 10 条月度规则通过。

最终输出：

```text
[OK] YFTH package benefit real application checks verified on MySQL 8.0.46.
```

## 6. 本轮修复的问题

- 菜单种子 `sort=320` 超出 CRMEB `eb_system_menus.sort` 的 `tinyint` 范围，已调整为 `32`。
- Phinx/Think migration 的 `Table::hasIndexByName()` 调用不兼容，已改为通过 adapter 查询索引。
- Phinx `Table::drop()` 返回值为 `void`，回滚代码已改为直接 drop。
- 御方通和模型使用整型时间戳字段，但全局 ThinkORM 自动时间戳会尝试按日期字符串解析，已新增 `YfthBaseModel` 并关闭 YFTH 模型自动写时间戳。
- `PackagePurchaseServices::createPurchase()` 旧路径存在未定义 `$purchase->id`，已改为使用写入结果 ID。
- 并发订单绑定的唯一键冲突/死锁恢复逻辑不足，已增加可重试写入与加锁回查。
- `member_5980` 多活实例重算时会重复插入 active 身份，已改为按 `active_key` 更新。
- 规则引用检查使用 `count($where, false)` 会绕过常规 where 过滤，已改为 `getCount()`，避免误判阻塞草稿编辑/复制。
- 真实闭环脚本原先只接受 `8.x` 简化版本格式，已调整为兼容 `8.0.x`。

## 7. 辅助验证

- PHP 语法检查：本轮新增/修改的服务、迁移、测试脚本和所有 YFTH 模型均通过 `php -l`。
- 契约检查：`crmeb/tests/yfth_package_benefit_contract_check.php` 通过。
- 旧 runtime 检查：在隔离 MySQL DSN 下通过；正式工作区未放置 `.env`，不使用默认 root/root 连接。
- Composer：`composer validate` 通过但保留上游警告；隔离副本中 `composer install --no-scripts`、`php think service:discover` 通过。
- 前端构建：`template/admin` 执行 `NODE_OPTIONS=--openssl-legacy-provider npm run build` 成功，存在上游 CSS 顺序、包体积和 Browserslist 陈旧警告。
- 前端 ESLint：项目默认 `.eslintignore` 忽略 `src/`，显式文件扫描会被跳过；强制 `--no-ignore` 后失败项全部为既有 CRLF 行尾 Prettier 问题，本轮不把前端批量格式化混入后端验收提交。

## 8. 清理要求

本轮隔离验收结束后必须清理：

- 删除临时 MySQL 数据库 `yufangcare_validation_20260625_c200ef3`。
- 删除临时 MySQL 用户 `yfth_val_c200ef3`。
- 停止本轮临时 `mysqld` 进程。
- 删除临时验证副本和其中的 `.env`、临时 secret 文件。
- 删除临时 MySQL data dir。

不得把临时 `.env`、密码、测试库凭据、验证副本或 MySQL data dir 提交到仓库。

本轮收尾已完成上述清理；提交内容不包含临时 `.env`、测试密码、验证副本或 MySQL data dir。

## 9. 仍未完成的业务边界

本轮只完成 5980 套餐权益模块在真实 MySQL 上的隔离验收和阻塞修复。以下内容仍不得误认为已经完成：

- 服务预约、签到、动态权益核销码、配送履约、权益消费流水。
- 门店 B 端工作台、预约容量、客户归属、门店经营报表。
- 推荐关系、有效新客观察期、只读奖励台账、冲正和结算。
- 库存、采购、补货、产品额度、加盟合同、开店验收。
- 支付路由真实分账执行；当前只完成业务校验和成交快照。

## 10. 2026-06-25 P1 整改回归验证

本轮整改起点为 `527aacbd10b8c3a5346d713f5a41d37951b0811f`，目标是关闭套餐 intent 并发建单和自动激活重试上限后的人工恢复缺口。

真实 MySQL 验证环境：

- MySQL：MySQL Community Server 8.0.46，隔离端口 `127.0.0.1:33322`。
- 基线：重新导入 `crmeb/public/install/crmeb.sql`，再执行全部 YFTH migration。
- 新迁移：`20260625170000_serialize_yfth_package_intent_ordering_and_manual_recovery.php`。
- 迁移验证：新迁移在真实 MySQL 8.0 上完成 run、rollback、再次 run。
- 真实闭环命令：`YFTH_REAL_FLOW_EXECUTE=1 YFTH_REAL_FLOW_ISOLATED_DB=1 YFTH_REAL_FLOW_RUN_ID=RFIM02 php tests/yfth_package_benefit_real_flow_check.php`。

本轮新增验证点：

- `yfth_package_purchase_intent` 新增抢占、绑定、错误和 orphan 字段，并验证 `idx_yfth_pkg_intent_claim`、`idx_yfth_pkg_intent_bound_order`、`idx_yfth_pkg_intent_orphan`。
- `yfth_package_purchase` 新增 `manual_retry_*` 字段，用于人工激活重试审计。
- 10 个进程并发请求同一 intent 时，只生成 1 个 CRMEB 套餐 SKU 订单、1 条购买记录、1 条购买快照和 10 条权益快照。
- 并发建单测试确认最终 intent 为 `bound`，且可支付 orphan 订单数量为 0。
- 自动激活幂等失败达到最大次数后，自动补偿返回 `activation_auto_retry_limit_exceeded`。
- 人工重试要求操作人和原因，使用 `package_activate_manual:{purchase_id}` 独立幂等键覆盖自动失败上限并完成激活。
- 并发人工重试只允许一个 worker 进入激活处理，最终只生成 1 个套餐实例，人工重试次数和操作人记录保持一致。

最终输出：

```text
[OK] YFTH package benefit real application checks verified on MySQL 8.0.46.
```
