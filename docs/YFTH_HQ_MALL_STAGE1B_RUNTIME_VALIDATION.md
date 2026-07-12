# 御方通和总部统一商城 Stage 1B 运行验证

## 1. 环境

- 功能分支：`codex/yfth-hq-mall-stage1b-readonly-surface`
- 起始提交：`328f5b658d1e260d9bd84bbe851f4c0b24980346`
- PHP：portable PHP 7.4.33
- Database：MySQL Community Server 8.0.46，隔离临时实例，端口 `33310`
- Admin build：Node.js 18.20.8 / npm 10.8.2
- Uni-app build：HBuilderX 5.14 `uniapp-cli` + Node.js 18.20.8
- 系统 Node 24.13.0 / npm 11.6.2 仅用于版本调查，没有用于旧 Vue2/uni-app 正式构建。
- 未连接生产 MySQL、Redis 或生产服务。

## 2. PHP 与静态契约

| 验证 | 结果 |
| --- | --- |
| 本轮 18 个 PHP 路由、Controller、Service、migration、test 文件 `php -l` | 通过，exit 0 |
| `yfth_hq_authority_readonly_contract_check.php` | 通过，exit 0 |
| `yfth_hq_authority_readonly_source_guard.php` | 通过，exit 0 |
| Stage 1A contract | 通过，exit 0 |
| Stage 1A source guard | 通过，exit 0 |

Stage 1A contract 对固定回滚顺序的断言补充 CRLF/LF 归一化；Stage 1A source guard 仅将本轮明确的 Stage 1B GET-only 文件加入差异白名单，仍对全局 Stage 1A 写服务引用执行扫描。两处均为 Windows 可重复验证适配，没有放宽生产写边界。

## 3. MySQL migration 生命周期

`yfth_hq_authority_readonly_migration_check.php` 在 MySQL 8.0.46 严格模式通过，exit 0：

- full migration run；
- direct duplicate up；
- direct down 后七个 Stage 1B 权限全部移除；
- Stage 1A 四张表签名保持不变；
- 其他 YFTH 菜单保持不变；
- no record / no permissions 恢复；
- no record / partial permissions 恢复；
- rollback `-t 0`；
- rerun；
- duplicate run；
- 七个 `unique_auth` 唯一，API methods 均为 GET；
- 最长菜单名称长度为 6，符合严格模式字段限制。

Migration 没有写业务表、业务 fixture 或测试数据。

## 4. 真实 HTTP 权限与只读验证

`yfth_hq_authority_readonly_real_flow_check.php` 通过本地真实 PHP HTTP server 调用完整路由，在 MySQL 8.0.46 隔离库通过，exit 0。

覆盖结果：

- 用户未登录拒绝。
- 用户无 current 行返回未归属，且没有创建 placeholder。
- active、paused、pristine unassigned、historical unassigned、closed 返回对应安全 DTO。
- 客户端传入 UID 不改变实际查询用户。
- active referral 只返回布尔值，不返回推荐人身份。
- `franchisee`、`store_manager` 可读取本店列表/详情。
- `store_staff`、`service_mentor`、普通顾客被拒绝。
- 客户端 store_id 不能切换可信门店。
- 跨店 attribution ID 被拒绝。
- historical unassigned、closed 不作为本店当前客户返回。
- 手机号已脱敏。
- Admin 未登录、无权限、门店范围后台账号被拒绝。
- 有普通权限的总部账号可读列表/详情，但不能读事件。
- 有独立 audit 权限的总部账号可读结构化事件。
- DTO 禁止字段未出现。

共执行 27 个真实 HTTP 请求。每个请求前后均对以下五张表做数量和内容哈希快照，全部保持一致：

- `yfth_hq_customer_attribution_current`
- `yfth_hq_customer_attribution_event`
- `yfth_hq_active_referral_current`
- `yfth_hq_active_referral_event`
- `yfth_idempotency_record`

验证中发现并修复一个真实跨店风险：DAO `search([])` 返回的 builder 在条件查询时必须接收 `where()` 的返回值。所有条件查询现均显式重新赋值，并为 count/list 分别创建 builder；修复后门店范围、状态和详情隔离均由真实 HTTP 测试覆盖。

## 5. Stage 1A 与旧模块回归

Stage 1A migration check 和 Stage 1A real-flow 均在同一 MySQL 8.0.46 隔离实例复跑通过，exit 0。real-flow 覆盖并发归属、推荐、循环、锁等待和死锁重试；第一次 worker 运行因未继承 portable PHP PDO ini 失败，补入 `YFTH_PHP_INI` 后原脚本通过。该失败属于测试进程配置，未改业务代码。

以下静态契约均通过，exit 0：foundation、package benefit、referral reward、franchise customer、service appointment、store workbench adapter、monthly fulfillment、supply chain、product quota、franchise opening、franchise application、HQ workbench、Stage 1A 和 Stage 1B。

以下 real-flow 脚本本轮执行的是其默认 source-guard 模式，并非完整隔离业务流程：store workbench、monthly fulfillment、supply chain、product quota、franchise opening。全部通过，exit 0。

## 6. 前端构建

| 构建 | 命令/工具 | 结果 |
| --- | --- | --- |
| Admin production | `npm run build`，Node 18.20.8 | 通过，exit 0；606 文件，39,680,547 bytes |
| H5 production | HBuilderX `uniapp-cli`，`UNI_PLATFORM=h5` | 通过，exit 0；694 文件，25,043,086 bytes |
| mp-weixin production | 同一 CLI + Node 18 `--no-opt` | 通过，exit 0；1,233 文件，7,772,433 bytes |
| uni-app multi-role shell contract | Node check | 通过，exit 0 |
| request fallback check | Node check | 通过，exit 0 |

Admin 仅有工程既有 CSS 顺序、资源体积和 Browserslist 数据提示。H5 仅有既有资源体积提示。mp-weixin 仅有既有 skeleton `:key` 和组件分包建议。没有升级 Vue、ElementUI、Webpack、Babel、uni-app 或 Sass 技术栈，也没有上传微信平台。

产物检索确认：

- Admin 生产 chunk 包含 `hqAuthority` / `hq_authority` 相关页面与 API。
- H5 生成 `pages-yfth-authority-index` 页面 chunk。
- mp-weixin 生成用户归属页和门店 `customer_attribution` 页面 JS/JSON/WXML。

## 7. 浏览器验证

使用本地静态服务与 Codex in-app Browser 验证：

- Admin 登录页成功渲染品牌标题、用户名、密码和登录按钮。
- 未连接 CRMEB 后端时，Admin 动态配置/API 请求由本地 history fallback 返回 HTML，控制台出现 JSON/脚本解析错误；这不是已连接后端的成功证据。
- H5 直达“我的归属”在无 Token 下按现有认证逻辑回到顾客首页；纯静态服务不提供 `/api/*` 和动态配置，控制台出现本地 fallback 错误。
- 因此，总部普通/审计权限差异、用户 active/unassigned、门店本店列表、跨店拒绝和失败重试均以第 4 节真实 HTTP 测试为运行证据；没有虚构静态浏览器环境具备登录后端联调能力。
- 页面源码和生产依赖图均无写操作按钮；权限与跨店安全边界由后端再次断言。

## 8. 差异与敏感信息

- `git diff --check` 通过，exit 0。
- 生产 source allowlist 未修改，production qualification 继续 fail closed。
- `crmeb/.env` 未修改；验证前后 SHA-256 保持 `1FEAB6EE35F27EFB592701D08B54C0ABF826EBA3D5BA60351F1B425E45CA0452`。
- 没有提交密码、Token、AppSecret、私钥、证书、微信上传密钥、临时数据库、日志、node_modules 或 unpackage 构建产物。

## 9. 临时环境清理

隔离数据库、MySQL data 目录和日志、本地 PHP/静态 HTTP server 在验证后停止并删除。没有连接生产数据库或 Redis，没有执行生产 migration，没有部署服务器。

## 10. 当前结论

Stage 1B 开发和可重复验证已完成，但尚未经过独立 Architecture Auditor 审核，尚未合并 `main`，也未部署。下一门禁只能是独立只读架构审核。
