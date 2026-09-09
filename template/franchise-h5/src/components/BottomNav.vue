<template>
  <nav class="bottom-nav" aria-label="养郎中加盟导航">
    <button v-for="item in items" :key="item.key" :class="{ active: active === item.key }" @click="$emit('navigate', item.key)">
      <component :is="item.icon" :size="25" :stroke-width="active === item.key ? 2.5 : 2" />
      <span>{{ item.label }}</span>
    </button>
  </nav>
</template>

<script setup>
import { House, Newspaper, FolderKanban, CircleUserRound } from '@lucide/vue';

defineProps({ active: { type: String, required: true } });
defineEmits(['navigate']);
const items = [
  { key: 'home', label: '首页', icon: House },
  { key: 'news', label: '养郎中动态', icon: Newspaper },
  { key: 'workbench', label: '加盟进度', icon: FolderKanban },
  { key: 'profile', label: '我的', icon: CircleUserRound }
];
</script>
