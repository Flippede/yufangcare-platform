# 安全基线与凭据管理

- 日期：2026-06-23
- 范围：当前 Git 仓库、可达历史、配置模板、移动端可打包配置和项目文档。
- 结论：仓库治理已完成后仍不等同于外部平台凭据已轮换；外部平台凭据必须按清单人工轮换并验证。

## 1. 敏感配置分类

不得进入 Git：

- 生产 `.env`、`.env.*` 和服务器本地运行配置。
- 数据库、Redis、短信、邮件、对象存储、地图、第三方 API 的真实密钥或密码。
- 微信小程序 AppSecret、微信支付商户密钥、商户私钥、P12/PFX/PEM 证书。
- SSH/SSL 私钥、宝塔或服务器账号、支付证书密码。
- 运行时缓存、日志、Session、队列状态、动态生成证书和本地备份压缩包。

可进入 Git：

- 不含真实值的 `.env.example`。
- 公开框架源码、安装 SQL、README、公开依赖中的测试证书或 CA 根证书。
- 必须公开的客户端标识，但不得与 Secret、私钥或商户密钥一起出现。

## 2. 本地开发配置方式

1. 后端复制 `crmeb/.env.example` 为 `crmeb/.env`。
2. 按本地 MySQL、Redis、队列环境填写 `.env`。
3. 管理后台复制 `template/admin/.env.example` 为本地 `.env` 或对应模式文件。
4. uni-app 复制 `template/uni-app/.env.example` 为本地构建配置，填写公开 API 域名。
5. 所有本地 `.env` 文件均被 `.gitignore` 忽略，不得提交。

## 3. 服务器配置方式

- 服务器生产 `.env` 只保留在服务器本地，路径为 `crmeb/.env`。
- 微信支付证书建议放在服务器本地受限目录，例如 `/www/server/yufangcare/secrets/wxpay/`，由后台配置或环境变量指向。
- 服务器同步干净历史后，不得直接普通 `git pull` 旧历史；后续部署任务应重新克隆或重新绑定干净历史，并安全恢复服务器本地 `.env` 和证书目录。

## 4. `.env.example` 使用方式

- 示例文件只保留字段名和安全占位符。
- `crmeb/public/install/.env.example` 是安装/升级模板，安装流程会基于它生成运行时 `crmeb/.env`。
- 示例注释和占位值不得包含真实值、真实值片段或可恢复真实值的提示。

## 5. 微信支付证书部署位置

- 禁止提交 `crmeb/public/WXCertUtil/cert/`。
- 禁止提交 `crmeb/runtime/pem/`。
- 生产证书放置在服务器本地非 Web 可公开下载目录，并限制操作系统权限。
- 轮换后同步更新后台支付配置或服务器本地配置，不把证书复制回仓库。

## 6. 不得进入 Git 的文件

- `.env`、`.env.*`，但 `.env.example` 例外。
- `*.pem`、`*.key`、`*.p12`、`*.pfx`、`*.crt`、`*.cer`。
- `runtime/`、`crmeb/runtime/`、`crmeb/public/WXCertUtil/cert/`。
- `*.sql.gz`、`*.bak`、`*.backup`、`*.zip`、`*.tar`、`*.tar.gz`。

## 7. 凭据轮换原则

- 只要真实凭据进入过 Git 当前版本或历史，即使文件已删除，也必须在外部平台轮换。
- 不得把“仓库已清理”写成“凭据已轮换”。
- 轮换完成后，应验证旧凭据失效、新凭据可用，并记录验证日期和责任人。

## 8. Git 历史泄露处理原则

- 历史改写前必须创建并验证离线 bundle。
- 只在确认远程分支、标签和 PR 不会被覆盖时执行历史净化。
- 远程更新只能使用 `git push --force-with-lease`。
- 历史净化后执行 `git fsck --full`、路径扫描和值扫描。

## 9. 安全扫描方法

- 当前索引路径：`git ls-files`。
- 历史路径：`git log --all --name-only --pretty=format:`、`git rev-list --objects --all`。
- 敏感扩展：`.env`、PEM/KEY/P12/PFX/CRT/CER、压缩包和备份。
- 敏感字段：password、secret、token、api key、appsecret、private key、mch key、redis、database、sms、oss、cos、mail。
- 扫描报告只输出路径、类别、跟踪状态和是否疑似真实，不输出值。

## 10. 新成员开发注意事项

- 开发前先复制示例配置并在本地填写，不向 Git 添加本地配置。
- 前端仓库不得保存 AppSecret、支付私钥、商户密钥或服务端 API Secret。
- 需要第三方 Secret 的能力必须经服务端代理或服务器本地配置注入。
- 发现疑似密钥先暂停提交，按轮换清单处理。
