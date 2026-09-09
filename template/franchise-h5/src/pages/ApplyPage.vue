<template>
  <section class="apply-page">
    <header class="apply-header">
      <button aria-label="返回" @click="goBack"><ArrowLeft /></button>
      <strong>加盟申请表</strong>
      <span></span>
    </header>
    <div class="step-header">
      <div :class="{active:step===1,done:step>1}"><span>{{ step>1?'✓':'1' }}</span><small>基本信息</small></div>
      <i></i>
      <div :class="{active:step===2}"><span>2</span><small>合作方案</small></div>
    </div>

    <form @submit.prevent="nextOrSubmit">
      <template v-if="step===1">
        <section class="notice-copy">
          <strong>填写申请前，请确认您认同养郎中品牌理念与统一门店标准</strong>
          <p>资料仅用于品牌合作评估。带“选填”的信息可跳过，身份证资料在进入正式审核阶段前不强制上传。</p>
        </section>
        <section class="form-card">
          <FieldTitle title="姓名" required /><input v-model.trim="form.name" maxlength="30" placeholder="请输入申请人姓名" />
          <FieldTitle title="手机号" required /><input v-model.trim="form.phone" inputmode="tel" maxlength="20" placeholder="请输入联系电话" />
          <ChoiceField title="婚姻状况" optional :options="['已婚','未婚','其他']" v-model="form.marital_status" />
          <ChoiceField title="家庭年收入" optional :options="['20万以下','20万~40万','40万~60万','60万以上']" v-model="form.household_income" />
          <ChoiceField title="最高学历" optional :options="['初中及以下','高中','大专','本科','研究生及以上']" v-model="form.education" />
          <ChoiceField title="是否了解相关健康品牌" required :options="['是','否']" v-model="form.knows_related_brands" compact />
          <ChoiceField title="是否有经营或参股门店" required :options="['是','否']" v-model="form.store_experience" compact />
          <ChoiceField title="是否有健康门店工作经历" required :options="['有','无']" v-model="form.health_store_experience" compact />
          <ChoiceField title="事业状态" required :options="['无经营经验','1年内工作/经营经验','1~3年工作/经营经验','3~5年工作/经营经验','5~10年工作/经营经验','10年以上工作/经营经验']" v-model="form.career_status" />
          <ChoiceField title="通过什么渠道了解养郎中" required :options="['短视频','加盟咨询','搜索引擎','微信','朋友介绍','其他']" v-model="form.source_channel" />
        </section>
      </template>

      <template v-else>
        <section class="form-card plan-card">
          <FieldTitle title="意向省市" required /><input v-model.trim="form.city" maxlength="40" placeholder="如：河南省郑州市" />
          <FieldTitle title="意向区县" required /><input v-model.trim="form.region" maxlength="40" placeholder="请输入意向区县" />
          <FieldTitle title="意向商圈或区域" required /><input v-model.trim="form.intention_area" maxlength="80" placeholder="请输入计划开店区域" />
          <ChoiceField title="计划店型" required :options="['社区店','标准店','旗舰店']" v-model="form.store_type" />
          <ChoiceField title="资金预算" required :options="['20万以下','20万~40万','40万~60万','60万以上']" v-model="form.budget_range" />
          <ChoiceField title="计划开店时间" required :options="['1个月内','1~3个月','3~6个月','6个月以后']" v-model="form.opening_plan" />
          <ChoiceField title="场地情况" required :options="['已有场地','正在选址','需要协助选址']" v-model="form.site_status" />
          <FieldTitle title="补充说明" optional /><textarea v-model.trim="form.remark" maxlength="500" placeholder="可填写资源、经验或合作想法"></textarea>
          <label class="agreement"><input v-model="agreed" type="checkbox" /><span>我已阅读并同意加盟申请隐私说明，确认资料真实有效。</span></label>
        </section>
      </template>
      <div v-if="error" class="error-box">{{ error }}</div>
      <footer class="form-actions">
        <button v-if="step===2" type="button" class="ghost-button" @click="step=1">上一步</button>
        <button class="primary-button" :disabled="submitting">{{ step===1?'合作方案':submitting?'提交中...':'提交申请' }}</button>
      </footer>
    </form>
  </section>
</template>

<script setup>
import { defineComponent, h, onMounted, ref, watch } from 'vue';
import { ArrowLeft } from '@lucide/vue';
import { franchiseApi, hasLoginToken, loginUrl } from '../api';

const emit=defineEmits(['navigate']);
const step=ref(1); const agreed=ref(false); const submitting=ref(false); const error=ref('');
const form=ref({name:'',phone:'',marital_status:'',household_income:'',education:'',knows_related_brands:'',store_experience:'',health_store_experience:'',career_status:'',source_channel:'',city:'',region:'',intention_area:'',store_type:'',budget_range:'',opening_plan:'',site_status:'',remark:'',partner_invite:''});

const FieldTitle=defineComponent({props:{title:String,required:Boolean,optional:Boolean},setup(props){return()=>h('div',{class:'field-title'},[props.required?h('b','*'):null,h('span',props.title),props.optional?h('small','选填'):null]);}});
const ChoiceField=defineComponent({props:{title:String,required:Boolean,optional:Boolean,options:Array,modelValue:String,compact:Boolean},emits:['update:modelValue'],setup(props,{emit}){return()=>h('section',{class:['choice-field',{compact:props.compact}]},[h(FieldTitle,{title:props.title,required:props.required,optional:props.optional}),h('div',{class:'choice-grid'},props.options.map(option=>h('button',{type:'button',class:{selected:props.modelValue===option},onClick:()=>emit('update:modelValue',option)},option)))]);}});

function serializableDraft(){ return {...form.value}; }
function saveLocal(){ window.localStorage.setItem('YLZ_FRANCHISE_DRAFT_V1',JSON.stringify(serializableDraft())); }
watch(form,saveLocal,{deep:true});
onMounted(async()=>{
  try { const local=JSON.parse(window.localStorage.getItem('YLZ_FRANCHISE_DRAFT_V1')||'{}'); Object.assign(form.value,local); } catch(_) {}
  const query = `${window.location.search}&${window.location.hash.split('?')[1] || ''}`;
  const invite = new URLSearchParams(query.replace(/^&/, '')).get('partner_invite');
  if (invite) form.value.partner_invite = invite;
  if(hasLoginToken()) try { const data=await franchiseApi.draft(); if(data?.profile) Object.assign(form.value,data.profile); } catch(_) {}
});
function goBack(){ if(step.value===2) step.value=1; else emit('navigate','workbench'); }
function validateStepOne(){ const required=['name','phone','knows_related_brands','store_experience','health_store_experience','career_status','source_channel']; return required.every(key=>form.value[key]); }
function validateStepTwo(){ const required=['city','region','intention_area','store_type','budget_range','opening_plan','site_status']; return required.every(key=>form.value[key]); }
async function nextOrSubmit(){
  error.value='';
  if(step.value===1){ if(!validateStepOne()){error.value='请完成基本信息中的必填项目';return;} step.value=2; if(hasLoginToken()) franchiseApi.saveDraft(serializableDraft()).catch(()=>{}); return; }
  if(!validateStepTwo()){error.value='请完成合作方案中的必填项目';return;}
  if(!agreed.value){error.value='请先阅读并同意加盟申请隐私说明';return;}
  if(!hasLoginToken()){ saveLocal(); window.location.href=loginUrl(); return; }
  submitting.value=true;
  try { await franchiseApi.submitDraft(serializableDraft()); window.localStorage.removeItem('YLZ_FRANCHISE_DRAFT_V1'); emit('navigate','workbench'); }
  catch(err){ error.value=err.message||'申请提交失败，请稍后重试'; }
  finally{ submitting.value=false; }
}
</script>

<style>
.apply-page { min-height:100vh; background:#f3f5f3; padding-bottom:92px; color:#292f2b; }
.apply-header { height:74px; background:white; display:grid; grid-template-columns:56px 1fr 56px; align-items:center; text-align:center; position:sticky; top:0; z-index:20; border-bottom:1px solid #eff0ec; }
.apply-header button { background:transparent; color:#1d2924; display:grid; place-items:center; }
.apply-header strong { font-size:18px; }
.step-header { height:120px; background:white; display:flex; align-items:center; justify-content:center; padding:0 70px; }
.step-header div { width:80px; display:flex; flex-direction:column; align-items:center; gap:8px; color:#aaa; }
.step-header span { width:34px; height:34px; border-radius:50%; background:#eee; display:grid; place-items:center; }
.step-header .active,.step-header .done { color:var(--jade); }
.step-header .active span,.step-header .done span { background:var(--gold-bright); color:var(--jade); }
.step-header i { height:1px; background:#ddd; flex:1; margin-top:-25px; }
.notice-copy { padding:18px 24px; background:#fff9dc; color:#6b5b2b; line-height:1.55; }
.notice-copy strong { color:#9a6e0d; }
.notice-copy p { margin:8px 0 0; font-size:13px; }
.form-card { margin:18px; padding:10px 26px 26px; background:white; border-radius:8px; }
.field-title { display:flex; align-items:center; gap:6px; font-size:16px; color:#505751; padding-top:24px; margin-bottom:12px; }
.field-title b { color:#c43e31; }
.field-title small { color:#9b9f9a; font-size:12px; }
.form-card>input,.form-card>textarea { width:100%; border:1px solid #e8e9e5; border-radius:8px; background:#f7f8f5; padding:0 14px; color:#242b27; outline:none; }
.form-card>input { height:50px; }
.form-card>textarea { min-height:118px; padding-top:14px; resize:none; }
.choice-field { padding:0 0 24px; border-bottom:1px solid #efefe9; }
.choice-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.choice-grid button { min-height:50px; border-radius:8px; background:#f4f5f2; color:#343a36; padding:8px; }
.choice-grid button.selected { background:#f5df98; color:var(--jade); box-shadow:inset 0 0 0 1px var(--gold); font-weight:700; }
.choice-field.compact .choice-grid { grid-template-columns:repeat(2,92px); justify-content:end; }
.choice-field.compact .field-title { margin-bottom:8px; }
.agreement { margin-top:24px; display:flex; align-items:flex-start; gap:10px; font-size:13px; color:#636963; line-height:1.5; }
.agreement input { width:19px; height:19px; accent-color:var(--jade); flex:0 0 auto; }
.error-box { margin-left:18px; margin-right:18px; }
.form-actions { position:fixed; bottom:0; left:50%; transform:translateX(-50%); width:min(100%,620px); min-height:78px; padding:12px 18px calc(12px + env(safe-area-inset-bottom)); background:white; display:flex; gap:12px; z-index:30; }
.form-actions button { flex:1; }
@media(max-width:390px){ .form-card{margin-left:12px;margin-right:12px;padding-left:18px;padding-right:18px}.choice-grid{gap:8px}.choice-grid button{font-size:13px}.step-header{padding:0 42px} }
</style>
