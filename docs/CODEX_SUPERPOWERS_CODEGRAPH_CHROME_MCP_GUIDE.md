# Codex + Superpowers + CodeGraph + Chrome DevTools MCP 指南

## 目标与当前安装状态

本项目将三类能力组合使用：

- **Superpowers**：提供可复用的开发工作流，例如先规划、系统化排障、TDD、代码审查和完成前验证。
- **CodeGraph**：为本地代码建立知识图谱，供 Codex 通过 MCP 进行语义探索、调用链和影响范围分析，避免先用 `grep` 拼凑上下文。
- **Chrome DevTools MCP**：让 Codex 通过本地 Chrome 调试协议检查真实页面的 DOM/无障碍快照、截图、控制台、网络、移动端视口和性能。

2026-07-16 验证时的版本：

| 组件 | 版本 / 标识 | 安装位置 |
| --- | --- | --- |
| Codex CLI | `0.142.0` | 用户级 Codex 安装 |
| Superpowers | `superpowers@openai-curated`，缓存修订 `bd2122cb` | `%USERPROFILE%\\.codex\\plugins\\cache\\openai-curated\\superpowers\\bd2122cb` |
| CodeGraph | `1.4.1` | `%LOCALAPPDATA%\\codegraph\\current` |
| Chrome DevTools MCP | `chrome-devtools-mcp@1.6.0`（由 `@latest` 解析） | npm/npx 缓存 |

全局 Codex 配置为 `%USERPROFILE%\\.codex\\config.toml`。本次修改前的备份位于同目录，文件名为 `config.toml.backup-YYYYMMDD-HHMMSS`。项目索引位于仓库根目录的 `.codegraph\\codegraph.db`；它是本地生成物，已由 `.gitignore` 忽略，绝不能提交。

## 如何确认 MCP 已加载

在仓库目录执行：

```powershell
codex mcp list
codex mcp get codegraph
codex mcp get chrome-devtools
```

当前配置的核心等价于：

```toml
[mcp_servers.codegraph]
command = "codegraph"
args = ["serve", "--mcp"]

[mcp_servers.chrome-devtools]
command = "cmd"
args = [
  "/c", "npx", "-y", "chrome-devtools-mcp@latest",
  "--no-usage-statistics", "--no-performance-crux",
]
env = { SystemRoot="C:\\Windows", PROGRAMFILES="C:\\Program Files" }
startup_timeout_sec = 20
```

在新建或重启后的 Codex 任务中，CodeGraph 应提供 `codegraph_explore`；Chrome DevTools MCP 应提供 `list_pages`、`new_page`、`navigate_page`、`take_snapshot`、`take_screenshot`、`list_console_messages`、`list_network_requests`、`evaluate_script`、`click`、`fill`、`fill_form`、`wait_for`、`emulate`、`resize_page`、`lighthouse_audit`、`performance_start_trace` 和 `performance_stop_trace` 等工具。Windows 安装器更新了用户 PATH，因此首次安装后应重启 Codex/终端再使用 CodeGraph。

## CodeGraph 日常使用

本项目已索引 3,178 个文件、37,102 个节点和 194,880 条关系；主要语言为 PHP（1,437 文件）、JavaScript（914）和 Vue（820）。日常改动后，CodeGraph 会自动观察文件；必要时可执行：

```powershell
codegraph status
codegraph sync
codegraph files
```

只有索引损坏、状态明确提示不完整或需要升级解析引擎时才执行完整重建：

```powershell
codegraph index
```

在 Codex 中直接这样说：

1. “使用 CodeGraph 分析这个功能从 Controller 到数据库的完整调用链，不要先使用 grep。”
2. “使用 CodeGraph 查找修改这个 Service 会影响哪些接口、页面和测试。”
3. “使用 CodeGraph 分析会员套餐购买成功后，永久会员身份是如何写入和读取的。”
4. “使用 CodeGraph 找出所有调用某个方法的代码，并判断是否可能发生跨店越权。”
5. “使用 CodeGraph 分析当前分支修改影响到哪些测试。”

命令行也可作只读验证：

```powershell
codegraph explore "Trace the current project user login flow from frontend to backend API and authentication service."
codegraph callers createOrderFromIntent
codegraph callees createOrderFromIntent
codegraph impact createOrderFromIntent
```

## Chrome DevTools MCP 日常使用

优先把线上浏览器检查限定为只读、低风险操作。不要登录、提交订单、支付、退款、发送验证码、删除数据、修改会员身份或填写真实个人信息。

可直接对 Codex 说：

1. “使用 Chrome DevTools MCP 打开 https://yfth.top，检查控制台错误和失败的网络请求。”
2. “使用 Chrome DevTools MCP 以 390x844 移动端视口检查首页底部栏。”
3. “点击登录页协议勾选框，观察 DOM、样式和控制台变化，但不要发送验证码。”
4. “点击验证码入口，检查弹窗、事件绑定和相关接口，但不要真正发送短信。”
5. “对当前页面运行 Lighthouse accessibility、SEO 和 best practices 审计。”
6. “录制一次页面加载性能 trace，分析 LCP、INP 和 CLS。”

## Superpowers 的适用范围

适合启用完整流程的场景：跨层功能开发、权限/支付/订单/数据库迁移、难以复现的缺陷、需要明确验证证据的修复，以及代码审查整改。

可以直接要求：

1. “使用 systematic-debugging 定位这个 Bug 的根因，不要直接猜测修改。”
2. “使用 verification-before-completion 验证这个修复是否在真实页面生效。”
3. “使用 writing-plans 为这个功能生成实施计划，但不得改变我指定的任务范围。”
4. “使用 requesting-code-review 对当前分支做开发自检，但不能替代 Architecture Auditor。”
5. “使用 receiving-code-review 逐项处理审核报告中的 P1 问题，只修复本轮范围。”

纯文案、简单样式或布局调整不强制完整 TDD；登录、鉴权、门店权限、订单、支付、推荐、奖励和数据库迁移必须采用适当测试。Superpowers 的 review 永远不能替代独立 Architecture Auditor。

## 组合分析示例

当“代码看起来已修复但线上仍异常”时，请 Codex：

1. 使用 CodeGraph 找到首页底部导航、登录协议勾选和验证码弹窗的源代码及调用关系。
2. 使用 Chrome DevTools MCP 获取线上 DOM/无障碍快照、截图、控制台和网络请求。
3. 对照源码预期与线上页面状态，并区分构建产物未发布、CDN/浏览器缓存、错误环境变量、接口返回差异、异步请求未完成、灰度路由和运行时兼容性问题。
4. 仅输出分析和证据；除非另有明确授权，不修改业务代码、不部署、不连接生产数据库。

## 常见故障

| 现象 | 处理 |
| --- | --- |
| `codegraph` 找不到 | 关闭并重新打开 Codex/终端，让 Windows PATH 更新；确认 `%LOCALAPPDATA%\\codegraph\\current\\bin` 存在。 |
| CodeGraph 状态提示索引不完整 | 先运行 `codegraph sync`；只有仍提示损坏/不完整时执行 `codegraph index`。 |
| Codex 看不到 MCP 工具 | 运行 `codex mcp list`、检查 `%USERPROFILE%\\.codex\\config.toml`，再重启或新建 Codex 任务。 |
| Chrome DevTools MCP 启动失败 | 运行 `npx -y chrome-devtools-mcp@latest --help`；确认 Node/npm/npx 可用、Chrome 位于 `C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe`。 |
| Chrome 页面有 uni-app 系统 API 报错 | 先区分 H5 运行时兼容提示与业务 JavaScript 异常；同时检查页面快照和 API 状态码，不能仅凭一条控制台信息下结论。 |

## 升级、禁用与卸载

升级：

```powershell
codegraph upgrade --check
codegraph upgrade
codegraph install --target codex --location global --yes --refresh
npx -y chrome-devtools-mcp@latest --version
codex plugin marketplace upgrade
```

禁用或卸载前请先备份 `%USERPROFILE%\\.codex\\config.toml`。可使用：

```powershell
codex mcp remove chrome-devtools
codegraph uninstall --target codex --location global --yes
codex plugin remove superpowers@openai-curated
```

如需移除本仓库本地索引，可在确认无其他 CodeGraph 任务运行后执行 `codegraph uninit`；这会删除 `.codegraph/`，但不会修改业务代码。

## 隐私与生产环境

CodeGraph 的图谱和数据库只保留在本机；不要提交 `.codegraph/`。Chrome DevTools MCP 可以看到浏览器内页面、DOM、网络和控制台数据，调试生产站点时只使用隔离、未登录上下文，避免暴露令牌、个人资料和支付信息。当前 Chrome 配置已关闭该 MCP 自身使用统计和 CrUX 请求；这不替代项目自身的隐私与发布审核。所有正式部署、生产数据库操作、订单支付、退款和短信发送仍需用户的明确单独授权。
