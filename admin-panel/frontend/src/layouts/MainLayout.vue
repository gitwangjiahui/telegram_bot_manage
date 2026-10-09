<template>
  <el-container style="height: 100vh">
    <el-aside width="220px" class="aside">
      <div class="logo">
        <el-icon :size="22"><ChatDotRound /></el-icon>
        <span>机器人管理后台</span>
      </div>
      <el-menu :default-active="$route.path" router background-color="#1f2d3d"
               text-color="#bfcbd9" active-text-color="#409eff">
        <el-menu-item index="/dashboard"><el-icon><Odometer /></el-icon><span>仪表盘</span></el-menu-item>
        <el-menu-item index="/bots"><el-icon><Monitor /></el-icon><span>机器人管理</span></el-menu-item>
        <el-menu-item index="/users"><el-icon><User /></el-icon><span>用户管理</span></el-menu-item>
        <el-menu-item index="/forward"><el-icon><Switch /></el-icon><span>转发设置</span></el-menu-item>
        <el-menu-item index="/config"><el-icon><Setting /></el-icon><span>配置管理</span></el-menu-item>
        <el-menu-item index="/messages"><el-icon><ChatLineRound /></el-icon><span>历史消息</span></el-menu-item>
        <el-sub-menu v-if="auth.has('system:view')" index="system">
          <template #title><el-icon><Tools /></el-icon><span>系统管理</span></template>
          <el-menu-item index="/system/admins">后台账号</el-menu-item>
          <el-menu-item index="/system/roles">角色权限</el-menu-item>
        </el-sub-menu>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="header">
        <div class="title">{{ $route.name }}</div>
        <el-dropdown @command="onCommand">
          <span class="user-info">
            <el-icon><UserFilled /></el-icon>
            {{ auth.me?.real_name || auth.me?.username }}
            <el-icon><ArrowDown /></el-icon>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="logout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>
      <el-main class="main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

onMounted(() => {
  if (!auth.me) auth.fetchMe();
});

function onCommand(cmd) {
  if (cmd === 'logout') {
    auth.logout();
    router.push('/login');
  }
}
</script>

<style scoped>
.aside {
  background: #1f2d3d;
  overflow-y: auto;
}
.logo {
  height: 60px;
  display: flex;
  align-items: center;
  gap: 10px;
  color: #fff;
  font-size: 15px;
  font-weight: 600;
  padding: 0 18px;
  white-space: nowrap;
}
.header {
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e6e6e6;
}
.title { font-size: 16px; font-weight: 600; }
.user-info {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  color: #606266;
}
.main { background: #f0f2f5; padding: 0; overflow-y: auto; }
</style>
