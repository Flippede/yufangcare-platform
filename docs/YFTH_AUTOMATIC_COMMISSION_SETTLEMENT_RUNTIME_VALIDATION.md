# 御方通和自动佣金与门店结算 V1 运行验证

## 1. 验证范围

- 当前分支：`codex/yfth-auto-commission-balance-withdrawal-v1`。
- 开始提交：`ed48bde621094f477432b12214a03a261f1c0448`。
- 本轮只验证自动佣金、B1 结算批次、C1 向 B1 线下结算及直接受影响页面。
- 不连接生产数据库，不执行真实微信分账，不部署生产，不合并 main。

## 2. 需要形成的证据

- PHP 7.4 相关语法检查。
- 自动佣金 settlement contract。
- 隔离 MySQL Community 8.0.46 migration run / targeted rollback / rerun。
- 0 天与非 0 天观察期、普通商城佣金、套餐 15%/25%/60%、退款冲正真实流程。
- B1 未结算到结算中、异常、已结算的批次状态变化及回调幂等。
- C1 申请、店长/店员完成、跨店拒绝和重复提交幂等。
- B1 页面不存在提现入口和提现 API。
- CRMEB 余额、积分、旧佣金、分销和 `user_bill` 不被写入。
- Admin、H5、mp-weixin 受影响构建。
- `git diff --check` 与敏感信息扫描。

## 3. 实际结果

### 3.1 PHP 与契约

- PHP 7.4.33 对本轮新增、修改的 PHP 文件逐一执行 `php -l`，全部退出码为 0。
- `php crmeb/tests/yfth_automatic_commission_contract_check.php` 通过。
- 直接受影响的套餐会员推荐、商城消费收益和奖励结算契约均通过。

### 3.2 隔离 MySQL 真实流程

- 使用仓库外便携 MySQL Community 8.0.46，监听 `127.0.0.1:33420`，数据库为一次性隔离库；未连接生产数据库。
- migration run、定向 rollback 到前一版本、rerun 均成功。
- rerun 后确认 12 张本阶段数据表、关键唯一索引和 10 个结算权限点均存在；rollback 后相应表、字段和权限被移除。
- `php crmeb/tests/yfth_automatic_commission_real_flow_check.php` 通过，覆盖：
  - 普通商城 C1/B1 佣金、0 天立即入账和非 0 天观察期；
  - 套餐 15%/25%/60% 快照；
  - 全额退款冲正与重复事件幂等；
  - C1 向 B1 申请结算，店长和店员完成本店线下结算；
  - 跨店处理拒绝、重复申请和重复完成幂等；
  - B1 批次生成、唯一台账分配、结算中、异常、成功回调和回调幂等；
  - 成功回调后金额只从未结算转入已结算一次；
  - 已结算订单后续部分退款保留原结算事实，并形成下一周期可追踪的负向调整；
  - CRMEB 余额、积分、旧佣金、分销和 `user_bill` 均未写入。

### 3.3 页面与构建

- Admin production build 通过，退出码 0；仅有项目既有 CSS 顺序、Browserslist 和资源提示。
- H5 production build 通过，退出码 0；使用 HBuilderX 5.14 CLI 和兼容的 Node 18.20.8，输出到仓库外验证目录。
- mp-weixin production compile 通过，退出码 0；输出到仓库外验证目录，未上传微信平台。
- B1 页面 DTO 和页面只展示未结算、已结算、批次及明细；不存在提现金额、冻结提现、提现成功或 FIFO 提现入口。
- 总部页面复用财务/结算入口管理接收方与结算批次，不存在 B1 提现申请处理入口。

### 3.4 最终静态门禁

- `git diff --check` 通过。
- 本轮新增内容敏感信息扫描通过，未发现私钥、AppSecret、微信密钥或密码。
- B1 接口和页面旧提现入口扫描通过，不存在提现申请、可提现金额、默认结算银行卡或 FIFO 提现入口。
- 本轮功能完成后仍只推送功能分支，等待独立复核。

## 4. 未执行边界

- 未调用真实微信分账接口或回调地址。
- 未执行真实支付、退款、短信或微信平台上传。
- 未连接生产 MySQL/Redis，未执行生产 migration，未部署生产。
- 未合并或推送 main。
