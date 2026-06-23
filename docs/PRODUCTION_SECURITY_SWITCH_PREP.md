# 生产服务器安全切换准备记录

- 执行日期：2026-06-23
- 目标服务器别名：`yfth`
- 旧生产目录：`/www/wwwroot/CRMEB-master`
- 干净仓库目标 commit：`9e194629da7a2bd1b4d00d4d489d9b139d43675d`
- 本记录只保存路径、字段名、类别和布尔状态，不保存任何密钥、证书内容或生产配置值。

## 1. 当前生产结构

- 旧生产目录保持不变，真实路径为 `/www/wwwroot/CRMEB-master`。
- 旧生产 Git 分支为 `main`，commit 为 `273b5faa25502dd59fab5ccd70253b2ec4f70cf2`，工作区干净。
- Nginx 由宝塔目录管理，`yfth.top` 和 `39.107.70.253` 当前 Web 根目录均指向 `/www/wwwroot/CRMEB-master/crmeb/public`。
- PHP 版本为 `7.4.33`，Composer 版本为 `2.0.14`。
- MySQL 运行中并监听 `3306`，Redis 运行中并监听 `127.0.0.1:6379`。
- 队列、Timer、Workerman 当前未发现运行进程。
- Docker 存在，但当前容器数为 `0`，线上不是通过当前 Docker Compose 容器承载。
- 未发现 `/www/wwwroot` 下针对当前项目的软链接发布结构。

## 2. 生产凭据类别核验

| 类别 | 配置来源 | 生产是否配置 | 代码是否引用 | 疑似有效 | 是否必须轮换 | 轮换后更新位置 |
| --- | --- | --- | --- | --- | --- | --- |
| 数据库 | `crmeb/.env` | 是 | 是 | 是 | P0 | `/www/wwwroot/CRMEB-master/crmeb/.env`，正式切换时注入新目录外部配置 |
| Redis | `crmeb/.env` | 主机和端口已配置，密码为空 | 是 | Redis 本地可用，未使用密码 | 否，建议后续加固 | `crmeb/.env` |
| 微信支付 | `eb_system_config`、`public/WXCertUtil/cert`、`runtime/pem` | 是 | 是 | 是 | P0 | 后台支付配置、微信支付证书目录、`runtime/pem` |
| 微信小程序 | `eb_system_config` | 字段存在但无有效物料 | 是 | 否 | 否，需平台核验 | 后台小程序配置 |
| 微信公众号 | `eb_system_config` | 是 | 是 | 是 | P1，若确认与历史泄露值一致则升为 P0 | 后台公众号配置 |
| 地图服务 | `eb_system_config` | 是 | 是 | 是 | P1 | 后台地图配置 |
| 短信 | `eb_system_config` | 是 | 是 | 是 | P1 | 后台短信配置及对应云平台 AccessKey |
| 对象存储 | `eb_system_config` | 是 | 是 | 是 | P1 | 后台上传配置及对应云存储 AccessKey |
| 邮件 | `eb_system_config` | 否 | 未发现有效引用 | 否 | 否 | 后台邮件配置，仅启用后需要 |
| 其他第三方 API | `eb_system_config` | 字段存在但无有效物料 | 是 | 否 | 否，需平台核验 | 对应后台扩展配置 |
| CRMEB 授权身份 | `crmeb/.version` | 是 | 是 | 是 | P0，若仍绑定官方服务 | `crmeb/.version` 或官方授权后台 |

## 3. 安全备份

- 备份目录：`/root/yufangcare-security-backup/20260623-171035`
- 目录权限：`drwx------ root:root`
- 已备份类别：
  - `crmeb-env/.env`
  - `crmeb-version/.version`
  - `wxpay-cert/cert`
  - `runtime-pem/pem`
  - `nginx/yfth.top.conf`
  - `nginx/crmeb_2748.conf`
  - `nginx-rewrite/yfth.top.conf`
  - `nginx-cert/yfth.top`
  - `docker-compose/docker-compose.yml`
  - `crontab/root-crontab.txt`
- 已验证备份目录存在。备份位于 Web 根目录之外，未上传 GitHub。
- 风险记录：生产 `.env`、`.version`、微信支付证书目录和 `runtime/pem` 的运行文件权限偏宽，本轮按要求只记录风险，未修改正在运行文件权限。

## 4. 干净仓库验证

- SSH 克隆到 `/www/wwwroot/yufangcare-platform-clean` 失败，原因是服务器当前 GitHub SSH 身份未获仓库授权。
- 只读 HTTPS 兜底克隆成功，目录为 `/www/wwwroot/yufangcare-platform-clean-https`。
- 新目录分支为 `main`，commit 为 `9e194629da7a2bd1b4d00d4d489d9b139d43675d`，工作区干净。
- 新目录未发现真实生产 `.env`、非 vendor 证书、运行时 PEM、前端真实 `.env` 或敏感压缩包。
- 唯一非 vendor 私钥文本匹配来自 `crmeb/crmeb/services/pay/extend/allinpay/Client.php` 的 PEM 格式拼接代码，不是密钥材料。
- vendor 内仍存在第三方 SDK 测试证书和 CA bundle，未判定为生产敏感材料。
- `.gitignore` 已验证会忽略 `crmeb/.env`、`crmeb/runtime/`、`crmeb/public/WXCertUtil/cert/`、前端 `.env.*` 和压缩包。

## 5. 配置字段兼容性

旧生产 `crmeb/.env` 与新仓库 `crmeb/.env.example` 均包含 20 个字段，字段名完全覆盖，无旧生产独有字段，无新示例独有字段。

已覆盖字段：

- 普通运行参数：`APP_DEBUG`、`APP.DEFAULT_TIMEZONE`、`CACHE.CACHE_PREFIX`、`CACHE.CACHE_TAG_PREFIX`、`CACHE.DRIVER`、`LANG.default_lang`、`QUEUE.QUEUE_NAME`
- 敏感或环境绑定字段：`DATABASE.TYPE`、`DATABASE.HOSTNAME`、`DATABASE.HOSTPORT`、`DATABASE.DATABASE`、`DATABASE.USERNAME`、`DATABASE.PASSWORD`、`DATABASE.PREFIX`、`DATABASE.CHARSET`、`DATABASE.DEBUG`、`REDIS.REDIS_HOSTNAME`、`REDIS.PORT`、`REDIS.REDIS_PASSWORD`、`REDIS.SELECT`

其他示例文件状态：

- `template/admin/.env.example` 存在，字段为 `NODE_ENV`、`VUE_APP_API_URL`、`VUE_APP_ENV`、`VUE_APP_TITLE`、`VUE_APP_WS_ADMIN_URL`、`VUE_APP_WS_KEFU_URL`。
- `template/uni-app/.env.example` 存在，字段为 `VUE_APP_API_URL`。
- `crmeb/public/install/.env.example` 存在，字段与 `crmeb/.env.example` 一致。

## 6. 新仓库只读可用性验证

- `php -v` 通过，版本为 `7.4.33`。
- `composer --version` 通过，版本为 `2.0.14`。
- `composer validate` 通过，但提示 `composer.json` 存在 PSR-0 空命名空间和精确版本约束警告。
- `php -l crmeb/public/install/index.php` 通过。
- `php -l crmeb/app/adminapi/controller/UpgradeController.php` 通过。
- `.env.example` 文件均可被 PHP INI 解析。
- 严格 JSON 检查共扫描 237 个 tracked JSON 文件，3 个 uni-app 配置文件不符合纯 JSON 解析：`template/uni-app/androidPrivacy.json`、`template/uni-app/manifest.json`、`template/uni-app/pages.json`。这些文件需按 uni-app/JSONC 语法另行确认。
- `crmeb/think` 存在，代码中仍可检索到 queue、Timer、Workerman 相关命令入口。

## 7. 外部平台轮换清单

P0 必须立即安排：

- 数据库密码：登录数据库管理入口创建或更新生产数据库账号密码，更新服务器外部安全配置，验证后台/API 读写，再禁用旧密码。会造成短暂维护窗口。
- 微信支付证书和私钥：登录微信支付商户平台重新签发证书/API 凭据，更新后台支付配置和服务器证书目录，验证支付、退款、回调，再撤销旧证书。通常需要维护窗口。
- CRMEB 授权身份：登录 CRMEB 或对应授权平台重新生成授权身份，更新 `.version` 类本地运行配置，验证后台升级/授权校验，再失效旧授权。

P1 核验后轮换：

- 微信公众号：登录微信公众平台核验 AppSecret、Token、EncodingAESKey 是否仍有效；若与历史泄露值一致或无法排除泄露，更新后台公众号配置并验证授权、消息和登录流程。
- 地图服务：登录腾讯地图平台核验 Key，建议更换并绑定域名/IP/应用限制，更新后台地图配置，验证定位和地址选择。
- 短信：登录短信服务平台和云厂商 IAM，轮换短信 Token 或 AccessKey，更新后台短信配置，验证验证码发送，再禁用旧凭据。
- 对象存储：登录当前上传类型对应的云存储平台，轮换 AccessKey/SecretKey 并收敛权限，更新后台上传配置，验证图片上传、读取和删除，再禁用旧凭据。
- 其他第三方 API：当前无有效物料，仅在平台侧确认仍启用时轮换。

## 8. 正式切换预案

1. 维护窗口前确认旧目录、Nginx 指向、PHP/Composer、MySQL、Redis、队列、Timer、Workerman 状态。
2. 完整备份数据库，并验证备份可恢复。
3. 重新备份生产 `.env`、`.version`、支付证书、Nginx、守护进程和启动配置。
4. 在外部平台生成新数据库密码、微信支付证书、公众号/短信/对象存储等新凭据。
5. 在新代码目录外部注入生产配置和证书，不提交 Git，不放入仓库跟踪路径。
6. 只读验证数据库、Redis、支付配置、上传、短信、登录授权和基础 API。
7. 在维护窗口内启动或接管队列、Timer、Workerman。
8. 修改 Nginx 或发布指针到新目录的 `crmeb/public`。
9. 执行健康检查和错误日志观察。
10. 验证支付、退款、登录、下单、核销、上传、短信和后台登录。
11. 失败时回滚 Nginx 或发布指针到旧目录 `/www/wwwroot/CRMEB-master/crmeb/public`。
12. 切换稳定后确认旧凭据全部失效。

## 9. 未执行项目

- 未修改旧生产目录 Git 历史。
- 未在旧目录执行普通 `git pull`。
- 未使用 `git reset --hard`。
- 未修改数据库，未执行迁移。
- 未修改生产 `.env`、`.version` 或支付证书。
- 未修改 Nginx，未重启、停止或 reload 服务。
- 未轮换任何外部平台凭据。
- 未切换线上流量。
- 未开始业务功能开发。

## 10. 下一步唯一建议

先为生产服务器配置 GitHub Deploy Key 或受控 SSH 凭据，使 `git@github.com:Flippede/yufangcare-platform.git` 能按预案克隆到 `/www/wwwroot/yufangcare-platform-clean`，然后在维护窗口前重新执行一次干净 SSH 克隆验证。
