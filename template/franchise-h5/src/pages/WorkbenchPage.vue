<template>
  <section class="page workbench-page">
    <header class="top-wash workbench-hero">
      <div class="brand-chip"><img :src="asset('brand-mark.png')" alt="养郎中商标" /><span>加盟服务中心</span></div>
      <h1 class="display-title">申请加盟</h1>
      <p class="display-subtitle">下一个合作伙伴就是你</p>
    </header>
    <div class="page-inner content-shift">
      <section class="progress card">
        <h2>加盟进度</h2>
        <div class="steps">
          <div v-for="(step,index) in steps" :key="step" :class="{done:index<=activeStep}"><span>{{ index < activeStep ? '✓' : index+1 }}</span><small>{{ step }}</small></div>
        </div>
      </section>

      <div v-if="error" class="error-box">{{ error }}</div>
      <section v-if="loading" class="card empty-state">正在读取申请进度...</section>
      <template v-else>
        <section class="application-card card">
          <h2>{{ currentApplication ? '当前加盟申请' : '加盟申请' }}</h2>
          <p>{{ currentApplication ? currentApplication.next_step : '收集您的合作意向，帮助我们更好地与您联系。' }}</p>
          <dl v-if="currentApplication">
            <div><dt>申请编号</dt><dd>{{ currentApplication.application_no }}</dd></div>
            <div><dt>意向区域</dt><dd>{{ currentApplication.city }} {{ currentApplication.intention_area }}</dd></div>
            <div><dt>当前状态</dt><dd>{{ currentApplication.status_text }}</dd></div>
          </dl>
          <button class="primary-button" @click="$emit('navigate','apply')">{{ currentApplication ? '查看或补充资料' : '提交加盟申请表' }}</button>
        </section>
        <section class="intent-card card">
          <h2>门店意向申请</h2>
          <p v-if="!currentApplication">请先完成加盟申请</p>
          <p v-else>{{ currentApplication.status_text }} · 后续步骤由总部按正式流程推进</p>
        </section>
      </template>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { franchiseApi, hasLoginToken } from '../api';
defineEmits(['navigate']);
const asset = (name) => `${import.meta.env.BASE_URL}assets/${name}`;
const steps=['填写申请','资格沟通','意向确认','选址','加盟签订'];
const loading=ref(true); const error=ref(''); const applications=ref([]);
const currentApplication=computed(()=>applications.value[0]||null);
const statusStep={draft:0,submitted:0,contacting:1,communicating:2,inspecting:3,pending_contract:4,signed:4,preparing:4,opened:4};
const activeStep=computed(()=>statusStep[currentApplication.value?.status] ?? 0);
onMounted(async()=>{
  if(!hasLoginToken()){ loading.value=false; return; }
  try { const data=await franchiseApi.myApplications(); applications.value=data?.list||[]; }
  catch(err){ error.value=err.message||'申请进度暂时无法读取'; }
  finally{ loading.value=false; }
});
</script>

<style scoped>
.workbench-hero { min-height:310px; background:linear-gradient(180deg,#f1d989,#fbf5df); }
.content-shift { margin-top:-86px; position:relative; }
.progress { padding:26px 18px; margin-bottom:18px; }
.progress h2,.application-card h2,.intent-card h2 { margin:0 0 20px; font-size:21px; }
.steps { display:grid; grid-template-columns:repeat(5,1fr); position:relative; }
.steps::before { content:""; position:absolute; left:9%; right:9%; top:15px; height:2px; background:#e1d3a7; }
.steps div { position:relative; z-index:1; display:flex; flex-direction:column; align-items:center; gap:10px; text-align:center; }
.steps span { width:31px; height:31px; border-radius:50%; display:grid; place-items:center; background:#e8e9e5; color:#777; font-size:13px; }
.steps .done span { background:var(--gold-bright); color:var(--jade); font-weight:800; }
.steps small { font-size:11px; line-height:1.3; color:#5c625e; }
.application-card,.intent-card { padding:24px; margin-bottom:18px; }
.application-card p,.intent-card p { color:var(--muted); line-height:1.6; }
.application-card .primary-button { display:block; margin-left:auto; margin-top:20px; }
dl { margin:18px 0; background:#f7f5ee; padding:12px 16px; border-radius:8px; }
dl div { display:flex; justify-content:space-between; gap:16px; padding:7px 0; font-size:13px; }
dt { color:var(--muted); } dd { margin:0; text-align:right; color:var(--jade); font-weight:700; }
</style>
