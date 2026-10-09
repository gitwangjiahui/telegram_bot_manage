<template>
  <div class="login-wrap">
    <el-card class="login-card">
      <div class="brand">
        <el-icon :size="32" color="#2AABEE"><ChatDotRound /></el-icon>
        <h2>Telegram 机器人管理后台</h2>
      </div>
      <el-form @submit.prevent="onSubmit">
        <el-form-item>
          <el-input v-model="form.username" placeholder="账号" size="large" :prefix-icon="User" />
        </el-form-item>
        <el-form-item>
          <el-input v-model="form.password" type="password" placeholder="密码" size="large"
                    :prefix-icon="Lock" show-password @keyup.enter="onSubmit" />
        </el-form-item>
        <el-button type="primary" size="large" style="width: 100%" :loading="loading"
                   @click="onSubmit">登 录</el-button>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { User, Lock } from '@element-plus/icons-vue';
import { useAuthStore } from '../stores/auth';

const form = reactive({ username: '', password: '' });
const loading = ref(false);
const router = useRouter();
const auth = useAuthStore();

async function onSubmit() {
  if (!form.username || !form.password) return ElMessage.warning('请输入账号和密码');
  loading.value = true;
  try {
    await auth.login(form.username, form.password);
    router.push('/dashboard');
  } catch (e) {
    ElMessage.error(e.message || '登录失败');
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.login-wrap {
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #2AABEE 0%, #229ED9 100%);
}
.login-card {
  width: 380px;
  padding: 12px 8px;
}
.brand {
  text-align: center;
  margin-bottom: 24px;
}
.brand h2 {
  margin: 12px 0 0;
  font-size: 18px;
  color: #303133;
}
</style>
