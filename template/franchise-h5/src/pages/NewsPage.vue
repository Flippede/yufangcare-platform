<template>
  <section class="page news-page">
    <header class="top-wash">
      <div class="brand-chip"><img src="/assets/brand-mark.png" alt="养郎中商标" /><span>养郎中品牌加盟</span></div>
      <h1 class="display-title">养郎中动态</h1>
      <div class="guide-card">
        <div><span>加盟必读指南</span><button @click="$emit('navigate','apply')">查看详情 <ArrowRight :size="16" /></button></div>
        <img src="/assets/brand-mark.png" alt="" />
      </div>
    </header>
    <div class="page-inner">
      <nav class="category-tabs">
        <button v-for="item in tabs" :key="item" :class="{active: tab === item}" @click="tab=item">{{ item }}</button>
      </nav>
      <article v-for="card in filteredCards" :key="card.title" class="news-card card">
        <div class="media"><img :src="card.image" :alt="card.title" /><span v-if="card.video"><Play fill="currentColor" :size="28" /></span></div>
        <div class="copy"><strong>{{ card.title }}</strong><p>{{ card.desc }}</p><small>{{ card.meta }}</small></div>
      </article>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { ArrowRight, Play } from '@lucide/vue';
defineEmits(['navigate']);
const tabs = ['品牌资讯','走进养郎中','加盟故事','行业动态'];
const tab = ref(tabs[0]);
const asset = name => `${import.meta.env.BASE_URL}assets/${name}`;
const cards = [
  { category:'品牌资讯', title:'养郎中智慧健康驿站品牌说明', desc:'了解门店定位、空间分区与统一品牌标准。', meta:'品牌手册', image:asset('storefront.png'), video:true },
  { category:'品牌资讯', title:'中医养护，贵在日常', desc:'从产品陈列到健康服务，构建可信赖的社区健康场景。', meta:'品牌理念', image:asset('interior-reception.png') },
  { category:'走进养郎中', title:'产品陈列接待区', desc:'玉墨、暖木与古金共同形成沉静专业的接待体验。', meta:'门店空间', image:asset('interior-reception.png') },
  { category:'走进养郎中', title:'体验沙龙区', desc:'面向用户沟通、体验与日常健康知识服务。', meta:'门店空间', image:asset('interior-experience.png') },
  { category:'加盟故事', title:'从申请到开店的每一步', desc:'总部按合同、付款、筹备和验收流程陪伴合作伙伴落地。', meta:'合作纪实', image:asset('storefront.png') },
  { category:'行业动态', title:'社区健康服务的新场景', desc:'围绕中医养护与社区服务建立长期经营能力。', meta:'行业观察', image:asset('interior-care.png') }
];
const filteredCards = computed(() => cards.filter(item => item.category === tab.value));
</script>

<style scoped>
.guide-card { height: 174px; margin-top: 38px; border-radius: 8px; border: 1px solid rgba(201,155,62,.55); background: rgba(255,253,248,.7); display: flex; align-items: center; justify-content: space-between; overflow: hidden; padding-left: 28px; }
.guide-card div { display:flex; flex-direction:column; align-items:flex-start; gap:16px; z-index:1; }
.guide-card span { font-size:25px; font-weight:850; color:var(--jade); }
.guide-card button { background:transparent; padding:0; display:flex; align-items:center; color:#454e49; }
.guide-card img { width:178px; height:178px; object-fit:cover; border-radius:50%; transform:translate(32px,18px); }
.category-tabs { display:grid; grid-template-columns:repeat(4,1fr); margin:10px 0 22px; gap:4px; }
.category-tabs button { min-height:52px; padding:0 2px; background:transparent; font-weight:700; color:#9b9e99; font-size:14px; }
.category-tabs button.active { color:var(--jade); font-size:16px; }
.news-card { overflow:hidden; margin-bottom:18px; }
.media { height:245px; position:relative; }
.media img { width:100%; height:100%; object-fit:cover; }
.media span { position:absolute; inset:0; margin:auto; width:58px; height:58px; display:grid; place-items:center; border-radius:50%; background:rgba(255,255,255,.82); color:var(--jade); }
.copy { padding:18px 20px 20px; }
.copy strong { font-size:18px; color:#222a26; }
.copy p { color:#676e69; font-size:14px; line-height:1.6; margin:8px 0; }
.copy small { color:var(--gold); }
</style>
