# 御方通和自动佣金、统一余额与门店提现 V1 运行验证

## 1. 验证环境

- 分支：`codex/yfth-auto-commission-balance-withdrawal-v1`
- 基线：`e0dbcdee4b87b2955a964c385a3fd8a4a61e7bc1`，开发开始时与 `origin/main` 一致。
- PHP：便携 PHP 7.4.33。
- 数据库：隔离 MySQL Community 8.0.46，数据库名 `yfth_auto_commission_validation_20260720`，未连接生产数据库。
- 结算加密：仅测试进程注入隔离测试密钥，未写入 Git。

## 2. Migration

Migration `20260720200000_create_yfth_automatic_commission_accounts_v1.php` 已在隔离库执行：

1. `migrate:run`：成功创建 10 张新表、套餐观察期字段、关键唯一索引和 10 个权限点。
2. 定向 `migrate:rollback`：仅回滚本阶段 migration；新表、字段和权限均移除。
3. 再次 `migrate:run`：全部恢复，签名和索引检查通过。

只读命令 `php think yfth:commission-legacy-report` 执行成功，输出旧候选/结算的类型、状态、行数和金额汇总，未输出个人字段且未写表。该命令是生产 migration 前的人工对账门禁，不会迁移或结算旧 pending/confirmed。

## 3. PHP 与契约

以下直接相关检查通过：

- 所有新增/修改 PHP 文件 PHP 7.4 语法检查。
- `yfth_automatic_commission_contract_check.php`。
- `yfth_package_membership_referral_contract_check.php`。
- `yfth_reward_settlement_contract_check.php`。
- `yfth_mall_consumption_reward_contract_check.php`。
- `yfth_hq_authority_foundation_contract_check.php`。
- `yfth_hq_authority_foundation_source_guard.php`。
- Stage 3 `yfth_mall_consumption_reward_real_flow_check.php`。

历史 Stage 2 全流程脚本仍断言“候选保持 pending、再由人工确认”的旧产品语义，因此不作为自动入账 V1 的通过门禁；其现有静态契约继续通过。新的自动佣金真实流程覆盖套餐 15%/25%/60%、幂等与自动入账语义。该差异已明确保留给独立架构审核确认，未伪报旧脚本通过。

## 4. 隔离真实流程

`yfth_automatic_commission_real_flow_check.php` 实际通过：

- 100 元普通订单、C1 10%、B1 5%、0 天观察期：C1 10 元、B1 自身 5 元、B1 代发 10 元，总部可提现增加 15 元。
- 重复支付、完成和到期任务不重复应计或入账。
- 自购无 C1、仍有 B1；无 C1 有 B1；无 B1安全跳过。
- 7 天观察期未到期不入账，到期任务按成交快照入账。
- 关系在观察期内关闭，既有订单仍按快照给原 C1；关闭后的新订单不再给原 C1，只给 B1。
- 混合订单先对全部订单项分摊优惠，再按商品规则计佣。
- 部分退款按订单项精确冲正且重复通知幂等；全额退款追加三类负数流水；观察期内全额退款取消应计。
- 套餐第 1/2/3 个有效奖励分别为 15%/25%/60%，只增加 C1 和 B1 代发，不增加 B1 自身。
- C1 任意金额提现、店长完成、店员完成、重复完成幂等、跨店拒绝。
- B1 缺少结算账户阻止；账户保存后自动携带加密快照。
- B1 在“自身 100 元 + 代发 300 元”条件下分别申请 50、180、400 元，FIFO 分配精确为 `50+0`、`100+80`、`100+300`。
- B1 向总部提现不减少 C1 余额或本店 C1 待付；总部完成后冻结转累计提现。
- 两个并发 B1 提现请求仅一个成功，另一个在死锁有限重试后以余额不足结束；冻结总额不超可用余额。
- 人工负调整可形成负余额，不能提现，后续正入账优先抵扣。
- CRMEB `now_money`、`brokerage_price` 和 `user_bill` 在佣金流程前后保持不变。
- 财务操作写入统一审计。

## 5. 前端构建

- Admin production build：通过。仅有既有 CSS 顺序、体积和 Browserslist 提示。
- H5 production build：通过。仅有既有缺失导出和体积提示。
- mp-weixin production compile：通过。仅有既有 `:key`、分包和缺失导出提示。
- `git diff --check`：通过。

构建使用本机既有 Node 18.20.8 和 HBuilderX `uniapp-cli`。未上传微信平台。

## 6. 结论与门禁

本阶段核心资金路径、整数金额、不可变流水、退款冲正、门店隔离、结算账户加密、FIFO 与并发超提保护已形成可复核证据。当前状态是“开发完成，等待独立架构审核”，不是生产发布结论。

未执行：合并 main、生产 migration、生产回填、生产部署、真实提现、真实支付、短信或微信操作。
