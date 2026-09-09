<template>
  <section class="page home-page">
    <div class="hero">
      <div class="brand-chip"><img :src="asset('brand-mark.png')" alt="养郎中商标" /><span>养郎中品牌加盟</span></div>
      <h1>尊敬的<br />品牌合作伙伴</h1>
      <p>携手打造社区智慧健康驿站</p>
      <div class="hero-photo"><img :src="asset('storefront.png')" alt="养郎中健康驿站门店" /></div>
    </div>

    <div class="action-grid">
      <button class="apply-card" @click="$emit('navigate', 'apply')">
        <span class="eyebrow">JOIN US</span>
        <strong>加盟申请</strong>
        <span>填写合作意向 <ArrowRight :size="18" /></span>
        <img :src="asset('brand-mark.png')" alt="" />
      </button>
      <div class="side-actions">
        <button @click="$emit('navigate', 'news')"><MapPinned /><span><strong>开放地区</strong><small>查看合作区域</small></span></button>
        <button @click="scrollToRequirement"><ClipboardCheck /><span><strong>加盟要求</strong><small>条件与费用</small></span></button>
      </div>
    </div>

    <button class="notice"><Megaphone :size="20" /><span>《品牌公告》养郎中加盟合作说明</span><ArrowRight :size="18" /></button>

    <section class="feature-card">
      <img :src="asset('interior-reception.png')" alt="养郎中接待区" />
      <div><span>项目说明会</span><strong>走进养郎中智慧健康驿站</strong><button @click="$emit('navigate', 'news')">了解更多</button></div>
    </section>

    <section ref="requirement" class="requirements card">
      <div class="section-kicker">PARTNERSHIP</div>
      <h2 class="section-title">合作基本要求</h2>
      <div class="requirement-grid">
        <div><Store /><strong>认同品牌</strong><span>认可统一标准与服务理念</span></div>
        <div><MapPinned /><strong>稳定场地</strong><span>具备适合经营的意向区域</span></div>
        <div><Users /><strong>全心投入</strong><span>重视长期经营与用户服务</span></div>
        <div><BadgeCheck /><strong>总部审核</strong><span>按正式加盟流程逐步推进</span></div>
      </div>
    </section>

    <aside class="floating-contact">
      <button @click="$emit('navigate', 'profile')"><Phone :size="20" /><span>电话</span></button>
      <button @click="$emit('navigate', 'profile')"><Headphones :size="20" /><span>客服</span></button>
    </aside>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import { ArrowRight, BadgeCheck, ClipboardCheck, Headphones, MapPinned, Megaphone, Phone, Store, Users } from '@lucide/vue';
defineEmits(['navigate']);
const asset = (name) => `${import.meta.env.BASE_URL}assets/${name}`;
const requirement = ref(null);
function scrollToRequirement() { requirement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
</script>

<style scoped>
.home-page { background: #f4f5f2; padding-bottom: 96px; }
.hero { min-height: 590px; padding: 28px 26px 0; color: white; position: relative; overflow: hidden; background: linear-gradient(155deg, #173f35 0%, #0d2d25 62%, #071b16 100%); }
.hero::after { content: ""; position: absolute; width: 330px; height: 330px; border: 1px solid rgba(231,197,83,.26); border-radius: 50%; top: -82px; right: -126px; }
.hero .brand-chip { color: #f5e4b1; position: relative; z-index: 2; }
.hero .brand-chip img { border: 1px solid rgba(255,255,255,.3); }
.hero h1 { position: relative; z-index: 2; font-size: 42px; line-height: 1.15; margin: 56px 0 12px; letter-spacing: 0; }
.hero p { position: relative; z-index: 2; color: #e8d9b7; margin: 0; font-size: 16px; }
.hero-photo { position: absolute; left: 26px; right: 26px; bottom: 24px; height: 290px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(231,197,83,.5); }
.hero-photo img { width: 100%; height: 100%; object-fit: cover; }
.action-grid { margin: 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; min-height: 276px; }
.action-grid button { text-align: left; }
.apply-card { position: relative; overflow: hidden; padding: 22px; background: linear-gradient(145deg, #f4e3ad, #d9b55c); color: #173f35; border-radius: 8px; display: flex; flex-direction: column; align-items: flex-start; }
.apply-card .eyebrow { font-size: 11px; font-weight: 800; opacity: .7; }
.apply-card strong { font-size: 27px; margin-top: 8px; }
.apply-card span:nth-of-type(2) { margin-top: 8px; display: flex; align-items: center; gap: 5px; }
.apply-card img { position: absolute; width: 142px; right: -30px; bottom: -26px; border-radius: 50%; }
.side-actions { display: grid; gap: 14px; }
.side-actions button { border-radius: 8px; background: white; color: var(--jade); padding: 18px; display: flex; align-items: center; gap: 12px; }
.side-actions span { display: flex; flex-direction: column; gap: 4px; }
.side-actions small { color: var(--muted); }
.notice { margin: 0 24px 24px; width: calc(100% - 48px); min-height: 64px; padding: 0 18px; border-radius: 8px; background: #7d1820; color: white; display: flex; align-items: center; gap: 10px; }
.notice span { flex: 1; text-align: left; }
.feature-card { margin: 0 24px 24px; height: 250px; border-radius: 8px; overflow: hidden; position: relative; color: white; }
.feature-card::after { content: ""; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(8,27,22,.78), rgba(8,27,22,.08)); }
.feature-card img { width: 100%; height: 100%; object-fit: cover; }
.feature-card div { position: absolute; z-index: 2; left: 22px; top: 50%; transform: translateY(-50%); display: flex; flex-direction: column; align-items: flex-start; max-width: 58%; }
.feature-card span { color: #e8c35b; font-size: 13px; font-weight: 800; }
.feature-card strong { font-size: 25px; line-height: 1.25; margin: 8px 0 16px; }
.feature-card button { color: var(--jade); background: #f5e4b1; border-radius: 18px; padding: 8px 16px; }
.requirements { margin: 0 24px 12px; padding: 24px; }
.requirement-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px 14px; }
.requirement-grid div { display: grid; grid-template-columns: 34px 1fr; column-gap: 8px; align-items: center; }
.requirement-grid svg { color: var(--gold); grid-row: span 2; }
.requirement-grid strong { color: var(--jade); }
.requirement-grid span { font-size: 12px; line-height: 1.4; color: var(--muted); }
.floating-contact { position: fixed; right: max(8px, calc(50% - 300px)); top: 56%; z-index: 20; background: white; border: 1px solid var(--line); border-radius: 8px 0 0 8px; box-shadow: 0 8px 24px rgba(10,36,29,.12); overflow: hidden; }
.floating-contact a, .floating-contact button { width: 64px; height: 66px; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 4px; background: white; color: var(--jade); text-decoration: none; font-size: 11px; }
.floating-contact a { border-bottom: 1px solid var(--line); }
@media (max-width: 390px) { .hero { min-height: 540px; } .hero-photo { height: 260px; } .action-grid { margin-left: 18px; margin-right: 18px; } .notice,.feature-card,.requirements { margin-left:18px; margin-right:18px; width:auto; } }
</style>
