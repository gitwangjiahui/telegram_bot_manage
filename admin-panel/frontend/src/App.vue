<template>
  <router-view />
</template>

<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { startWs, stopWs } from './ws';
import { startDashWs, stopDashWs } from './ws-dashboard';
import { useAuthStore } from './stores/auth';

const auth = useAuthStore();

// 读取 JWT 过期时间（秒）；非法 token 返回 0。
function tokenExp() {
  try {
    const t = localStorage.getItem('token') || '';
    const payload = JSON.parse(atob(t.split('.')[1]));
    return Number(payload.exp) || 0;
  } catch {
    return 0;
  }
}

// 滑动续期保活：即使页面长期挂着、只走 WS 不发业务请求，
// 也在 token 剩余不足 1 天时主动发一个轻量请求换新，避免被踢去重登。
let keepAliveTimer = null;
function startKeepAlive() {
  if (keepAliveTimer) return;
  keepAliveTimer = setInterval(async () => {
    const t = localStorage.getItem('token');
    if (!t) return;
    const remain = tokenExp() - Math.floor(Date.now() / 1000);
    if (remain > 0 && remain < 24 * 60 * 60) {
      try { await auth.fetchMe(); } catch { /* 401 由拦截器跳登录 */ }
    }
  }, 60 * 60 * 1000); // 每小时检查一次
}

onMounted(() => {
  if (auth.token) {
    if (!auth.me) auth.fetchMe().catch(() => {});
    startWs();
    startDashWs();
    startKeepAlive();
  }
});
onBeforeUnmount(() => {
  stopWs();
  stopDashWs();
  if (keepAliveTimer) { clearInterval(keepAliveTimer); keepAliveTimer = null; }
});
</script>
