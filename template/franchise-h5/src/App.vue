<template>
  <main class="app-shell">
    <component :is="currentPage" @navigate="navigate" />
    <BottomNav v-if="showTabs" :active="route" @navigate="navigate" />
  </main>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BottomNav from './components/BottomNav.vue';
import HomePage from './pages/HomePage.vue';
import NewsPage from './pages/NewsPage.vue';
import WorkbenchPage from './pages/WorkbenchPage.vue';
import ProfilePage from './pages/ProfilePage.vue';
import ApplyPage from './pages/ApplyPage.vue';
import LoginPage from './pages/LoginPage.vue';

const routes = {
  home: HomePage,
  news: NewsPage,
  workbench: WorkbenchPage,
  profile: ProfilePage,
  apply: ApplyPage,
  login: LoginPage
};
const route = ref('home');

function readRoute() {
  const name = window.location.hash.replace(/^#\/?/, '').split('?')[0] || 'home';
  route.value = routes[name] ? name : 'home';
  window.requestAnimationFrame(() => window.scrollTo(0, 0));
}

function navigate(name) {
  window.location.hash = `#/${name}`;
  window.scrollTo(0, 0);
}

const currentPage = computed(() => routes[route.value]);
const showTabs = computed(() => !['apply', 'login'].includes(route.value));

onMounted(() => {
  readRoute();
  window.addEventListener('hashchange', readRoute);
});
onBeforeUnmount(() => window.removeEventListener('hashchange', readRoute));
</script>
