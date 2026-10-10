<template>
  <router-view />
</template>

<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { startWs, stopWs } from './ws';
import { useAuthStore } from './stores/auth';

const auth = useAuthStore();

onMounted(() => {
  if (auth.token) {
    if (!auth.me) auth.fetchMe().catch(() => {});
    startWs();
  }
});
onBeforeUnmount(() => stopWs());
</script>
