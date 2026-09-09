# 养郎中加盟门户

独立于商城前端的品牌加盟 H5，生产路径预留为 `/join/`。页面共用现有 CRMEB 登录态与加盟申请后端，不建立第二套加盟状态机。

## 页面

- 首页
- 养郎中动态
- 加盟进度
- 我的
- 两步加盟申请表
- 独立养郎中品牌登录页（复用现有用户账号与微信认证，成功后返回加盟首页）

## 数据边界

- 草稿和提交记录写入现有 `yfth_franchise_application`。
- 扩展问卷写入一对一的 `yfth_franchise_application_profile`。
- 招商来源继续写入既有 `yfth_franchise_recruit_source`。
- 浏览器本地草稿不保存身份证号、证件图片或其他敏感附件。
- 证件附件表只预留私有对象存储元数据；未配置受控私有上传前，前端不采集证件。
- 加盟门户不跳转商城登录页，登录成功固定返回 `/join/#/home`。

## 本地运行

```bash
npm install
npm run dev
```

## 构建

```bash
npm run build
```
