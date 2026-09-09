<template>
  <section class="page profile-page">
    <header class="profile-hero">
      <img src="/assets/brand-mark.png" alt="养郎中商标" />
      <div><small>养郎中品牌加盟</small><h1>{{ loggedIn ? '尊敬的合作伙伴' : '登录后查看申请' }}</h1></div>
    </header>
    <div class="page-inner">
      <section class="menu-card card">
        <div><Phone :size="21" /><span>联系电话</span><strong>以官方公示为准</strong></div>
        <div><Headphones :size="21" /><span>专属客服</span><strong>暂无客服</strong></div>
        <button @click="$emit('navigate','workbench')"><ClipboardList :size="21" /><span>我的加盟申请</span><ChevronRight /></button>
        <button @click="infoPanel='about'"><Landmark :size="21" /><span>关于养郎中</span><ChevronRight /></button>
        <button @click="infoPanel='privacy'"><ShieldCheck :size="21" /><span>隐私政策</span><ChevronRight /></button>
      </section>
      <section v-if="infoPanel" class="info-card card">
        <template v-if="infoPanel==='about'">
          <h2>关于养郎中</h2>
          <p>“养郎中”为健康服务品牌商标。本页面用于收集品牌加盟合作意向，后续合作以正式审核及合同为准。</p>
        </template>
        <template v-else>
          <h2>加盟申请隐私说明</h2>
          <p>申请资料仅用于加盟资格评估、联系沟通和后续签约准备。法定运营主体为北京御生堂众康源健康管理服务有限公司。敏感证件资料仅在正式审核阶段按受控权限收集。</p>
        </template>
      </section>
      <section class="declaration card">
        <div><ShieldAlert :size="22" /><strong>严正声明</strong></div>
        <p>“养郎中”为品牌商标。请通过官方页面提交合作申请，谨防假冒招商、私下收费和虚假承诺。合同、隐私政策及责任主体以页面公示的法定运营主体为准。</p>
      </section>
      <a v-if="!loggedIn" class="login primary-button" :href="loginUrl()">登录或切换账号</a>
      <button v-else class="logout ghost-button" @click="logout">退出登录</button>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import { ChevronRight, ClipboardList, Headphones, Landmark, Phone, ShieldAlert, ShieldCheck } from '@lucide/vue';
import { hasLoginToken, loginUrl } from '../api';
defineEmits(['navigate']);
const loggedIn=hasLoginToken();
const infoPanel=ref('');
function logout() {
  window.localStorage.removeItem('LOGIN_STATUS_TOKEN');
  window.location.reload();
}
</script>

<style scoped>
.profile-page { background:linear-gradient(180deg,#f1dda0 0,#f8f4e8 260px,#f4f5f2 500px); padding-top:1px; }
.profile-hero { min-height:280px; padding:48px 28px 30px; display:flex; align-items:center; gap:20px; }
.profile-hero img { width:118px; height:118px; border-radius:50%; object-fit:cover; border:5px solid rgba(255,255,255,.72); }
.profile-hero small { color:#6f664e; }
.profile-hero h1 { color:var(--jade); font-size:28px; margin:8px 0 0; }
.menu-card { overflow:hidden; margin-bottom:18px; }
.menu-card a,.menu-card button,.menu-card>div { min-height:72px; width:100%; padding:0 20px; background:white; color:#26312c; text-decoration:none; display:grid; grid-template-columns:28px 1fr auto 20px; align-items:center; gap:10px; border-bottom:1px solid #efefe9; text-align:left; }
.menu-card strong { color:var(--gold); font-weight:600; font-size:14px; }
.menu-card svg:last-child { color:#b8bcb8; }
.declaration { padding:22px; background:#fff7d5; border-color:#ead47d; }
.declaration div { display:flex; align-items:center; gap:9px; color:#6f541a; }
.declaration p { color:#665d46; line-height:1.65; font-size:13px; }
.info-card { margin-bottom:18px; padding:22px; }
.info-card h2 { margin:0 0 10px; color:var(--jade); font-size:19px; }
.info-card p { margin:0; color:#5f6661; font-size:13px; line-height:1.7; }
.login,.logout { width:100%; display:flex; justify-content:center; align-items:center; text-decoration:none; margin-top:20px; }
</style>
