<template>
  <section class="portal-login">
    <header class="login-header">
      <button aria-label="返回加盟首页" @click="goHome"><House :size="25" /></button>
      <strong>登录</strong>
      <span></span>
    </header>

    <div class="login-brand">
      <img :src="asset('brand-mark.png')" alt="养郎中商标" />
      <h1>养郎中</h1>
      <p>品牌加盟服务平台</p>
    </div>

    <div class="login-panel">
      <label class="login-agreement">
        <input v-model="agreed" type="checkbox" />
        <span>已阅读并同意</span>
        <button type="button" @click.prevent="notice='terms'">《用户服务协议》</button>
        <span>及</span>
        <button type="button" @click.prevent="notice='privacy'">《隐私政策》</button>
      </label>

      <template v-if="mode === 'wechat'">
        <button class="login-primary" :disabled="loading" @click="wechatLogin">
          <MessageCircle :size="22" />
          {{ loading ? '正在登录...' : '微信快捷登录' }}
        </button>
        <button class="login-secondary" @click="mode='account'">切换手机号/账号登录</button>
        <p v-if="!isWechat" class="login-hint">微信快捷登录需在微信内打开本页面</p>
      </template>

      <form v-else class="account-form" @submit.prevent="accountLogin">
        <label><span>手机号或账号</span><input v-model.trim="account" maxlength="32" autocomplete="username" placeholder="请输入手机号或账号" /></label>
        <label><span>登录密码</span><input v-model="password" type="password" maxlength="32" autocomplete="current-password" placeholder="请输入登录密码" /></label>
        <button class="login-primary" :disabled="loading">{{ loading ? '正在登录...' : '登录加盟平台' }}</button>
        <button v-if="isWechat" type="button" class="login-secondary" @click="mode='wechat'">切换微信登录</button>
      </form>

      <button class="skip-login" @click="goHome">暂不登录</button>
      <div v-if="error" class="login-error">{{ error }}</div>
    </div>

    <aside v-if="notice" class="agreement-notice">
      <div>
        <button aria-label="关闭" @click="notice=''">×</button>
        <h2>{{ notice === 'terms' ? '用户服务协议' : '隐私政策' }}</h2>
        <p v-if="notice === 'terms'">本账号与御方通和商城共用统一用户身份，仅用于登录养郎中加盟服务平台。加盟合作须经过正式审核并以签署合同为准。</p>
        <p v-else>北京御生堂众康源健康管理服务有限公司仅在登录、加盟申请和审核所必需的范围内处理您的资料，不会因本页面创建第二套用户账号。</p>
      </div>
    </aside>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { House, MessageCircle } from '@lucide/vue';
import { hasLoginToken, loginApi, saveLoginToken } from '../api';

const asset = (name) => `${import.meta.env.BASE_URL}assets/${name}`;
const isWechat = /micromessenger/i.test(navigator.userAgent);
const mode = ref(isWechat ? 'wechat' : 'account');
const agreed = ref(false);
const loading = ref(false);
const account = ref('');
const password = ref('');
const error = ref('');
const notice = ref('');

function goHome() {
  window.location.replace(`${window.location.origin}/join/#/home`);
}

function finishLogin(data) {
  saveLoginToken(data);
  window.history.replaceState({}, '', `${window.location.origin}/join/#/login`);
  goHome();
}

async function accountLogin() {
  error.value = '';
  if (!agreed.value) { error.value = '请先阅读并同意用户服务协议和隐私政策'; return; }
  if (!account.value || !password.value) { error.value = '请输入手机号或账号及登录密码'; return; }
  loading.value = true;
  try {
    finishLogin(await loginApi.account(account.value, password.value));
  } catch (err) {
    error.value = err.message || '登录失败，请检查账号和密码';
  } finally {
    loading.value = false;
  }
}

async function wechatLogin() {
  error.value = '';
  if (!agreed.value) { error.value = '请先阅读并同意用户服务协议和隐私政策'; return; }
  if (!isWechat) { error.value = '请在微信内打开本页面后使用微信快捷登录'; return; }
  loading.value = true;
  try {
    const callback = `${window.location.origin}/join/?ylz_oauth=1#/login`;
    const config = await loginApi.wechatConfig(callback);
    if (!config?.appId) throw new Error('微信登录配置暂不可用');
    const oauth = `https://open.weixin.qq.com/connect/oauth2/authorize?appid=${encodeURIComponent(config.appId)}&redirect_uri=${encodeURIComponent(callback)}&response_type=code&scope=snsapi_userinfo&state=ylz_franchise_login&connect_redirect=1#wechat_redirect`;
    window.location.assign(oauth);
  } catch (err) {
    error.value = err.message || '微信登录暂不可用';
    loading.value = false;
  }
}

onMounted(async () => {
  if (hasLoginToken()) { goHome(); return; }
  const query = new URLSearchParams(window.location.search);
  const code = query.get('code');
  if (!code) return;
  loading.value = true;
  try {
    const data = await loginApi.wechatCallback(code);
    if (data?.bindPhone) {
      mode.value = 'account';
      error.value = '该微信尚未绑定手机号，请使用已有手机号或账号登录';
      window.history.replaceState({}, '', `${window.location.origin}/join/#/login`);
      return;
    }
    finishLogin(data);
  } catch (err) {
    error.value = err.message || '微信登录失败，请重试';
    window.history.replaceState({}, '', `${window.location.origin}/join/#/login`);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.portal-login { min-height:100vh; background:linear-gradient(180deg,#fff 0,#fff 70%,#fbf6e9); padding-bottom:40px; }
.login-header { height:76px; display:grid; grid-template-columns:56px 1fr 56px; align-items:center; text-align:center; padding:0 14px; }
.login-header button { width:46px; height:46px; display:grid; place-items:center; background:transparent; color:var(--jade); }
.login-header strong { font-size:19px; font-weight:700; }
.login-brand { padding:62px 24px 42px; text-align:center; }
.login-brand img { width:154px; height:154px; border-radius:50%; object-fit:cover; margin:0 auto; box-shadow:0 12px 34px rgba(18,63,51,.12); }
.login-brand h1 { margin:24px 0 4px; color:var(--jade); font-size:44px; font-weight:900; }
.login-brand p { margin:0; color:#868a84; font-size:14px; }
.login-panel { width:min(100% - 48px,460px); margin:0 auto; }
.login-agreement { min-height:46px; display:flex; justify-content:center; align-items:center; flex-wrap:wrap; gap:4px; font-size:13px; color:#626862; }
.login-agreement input { width:20px; height:20px; margin-right:4px; accent-color:var(--gold); }
.login-agreement button { padding:0; background:transparent; color:#b77b15; }
.login-primary,.login-secondary,.skip-login { width:100%; min-height:58px; border-radius:29px; margin-top:14px; display:flex; align-items:center; justify-content:center; gap:9px; }
.login-primary { color:#17352d; background:linear-gradient(135deg,#e8c35b,#d4a844); font-size:18px; font-weight:800; }
.login-primary:disabled { opacity:.58; }
.login-secondary { background:white; color:var(--jade); border:1px solid #daddd8; }
.skip-login { min-height:44px; margin-top:12px; background:transparent; color:#777d77; }
.login-hint { margin:10px 0 0; text-align:center; color:#a06f20; font-size:12px; }
.account-form { margin-top:10px; }
.account-form label { display:block; margin-bottom:14px; }
.account-form label span { display:block; margin:0 0 8px 4px; color:#4f5752; font-size:13px; }
.account-form input { width:100%; height:54px; border:1px solid #e1e3df; border-radius:8px; padding:0 16px; background:#f8f9f6; color:#26302b; outline:none; }
.account-form input:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(201,155,62,.12); }
.login-error { margin-top:12px; padding:12px 14px; border-radius:8px; background:#fff1ec; color:#943d31; text-align:center; font-size:13px; }
.agreement-notice { position:fixed; inset:0; z-index:80; display:grid; place-items:center; padding:24px; background:rgba(13,28,23,.46); }
.agreement-notice>div { width:min(100%,460px); background:white; border-radius:8px; padding:26px; position:relative; }
.agreement-notice button { position:absolute; right:15px; top:12px; background:transparent; font-size:28px; color:#777; }
.agreement-notice h2 { margin:0 0 14px; color:var(--jade); font-size:20px; }
.agreement-notice p { margin:0; color:#59615c; line-height:1.8; font-size:14px; }
@media(max-height:700px){ .login-brand{padding-top:28px;padding-bottom:22px}.login-brand img{width:112px;height:112px}.login-brand h1{font-size:36px;margin-top:14px} }
</style>
