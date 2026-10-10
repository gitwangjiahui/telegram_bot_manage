<template>
  <div class="bots-page">
    <div class="toolbar">
      <el-button v-if="auth.has('bot:create')" type="primary" :icon="Plus" @click="openEdit()">新增机器人</el-button>
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-switch v-model="autoRefresh" active-text="自动刷新" inline-prompt style="margin-left: auto" />
    </div>

    <el-row :gutter="16" v-loading="loading">
      <el-col v-for="b in list" :key="b.id" :xs="24" :sm="12" :lg="8" style="margin-bottom: 16px">
        <el-card class="bot-card" shadow="hover" :class="{ stopped: !Number(b.is_running) }">
          <!-- 头部 -->
          <div class="bc-head">
            <div class="bc-title">
              <span class="bc-name">{{ b.bot_name }}</span>
              <el-tag size="small" :type="Number(b.is_running) ? 'success' : 'info'" effect="dark">
                {{ Number(b.is_running) ? '运行中' : '已停止' }}
              </el-tag>
            </div>
            <div class="bc-uname">{{ b.bot_username || '—' }}</div>
          </div>

          <!-- 关键状态 -->
          <div class="bc-stats">
            <div class="stat"><span class="k">PID</span><span class="v">{{ b.pid ?? '-' }}</span></div>
            <div class="stat"><span class="k">已运行</span><span class="v">{{ uptime(b) }}</span></div>
            <div class="stat"><span class="k">验证码池</span><span class="v">{{ b.captcha_available ?? '-' }}</span></div>
            <div class="stat"><span class="k">今日上行</span><span class="v">{{ b.today_in ?? 0 }}</span></div>
            <div class="stat"><span class="k">今日回复</span><span class="v">{{ b.today_out ?? 0 }}</span></div>
            <div class="stat"><span class="k">用户/管理员</span><span class="v">{{ b.user_count ?? 0 }} / {{ b.admin_count ?? 0 }}</span></div>
          </div>
          <div v-if="b.last_error" class="bc-err" :title="b.last_error">
            <el-icon><WarningFilled /></el-icon><span>{{ b.last_error }}</span>
          </div>
          <div class="bc-hb">最后心跳 {{ b.heartbeat_at ? fmtHb(b.heartbeat_at) : '—' }}</div>

          <!-- 操作 -->
          <div class="bc-actions">
            <el-button size="small" :icon="View" @click="goDetail(b)">详情</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" :icon="Edit" @click="openEdit(b)">编辑</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" :icon="Key" @click="openToken(b)">Token</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" :icon="Share" @click="goDetail(b, 'admins')">转发管理员</el-button>
          </div>
          <div class="bc-actions">
            <el-button v-if="auth.has('bot:edit')" size="small" type="success" :icon="VideoPlay"
                       @click="control(b, 'start')">启动</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" type="warning" :icon="RefreshRight"
                       @click="control(b, 'restart')">重启</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" type="info" :icon="VideoPause"
                       @click="control(b, 'stop')">停止</el-button>
            <el-popconfirm v-if="auth.has('bot:delete')" title="确认删除该机器人？" @confirm="onDelete(b)">
              <template #reference>
                <el-button size="small" type="danger" :icon="Delete">删除</el-button>
              </template>
            </el-popconfirm>
          </div>
        </el-card>
      </el-col>
      <el-col :span="24" v-if="!loading && list.length === 0">
        <el-empty description="还没有机器人，点击右上角新增" />
      </el-col>
    </el-row>

    <!-- 编辑/新增 -->
    <el-dialog v-model="editVisible" :title="form.id ? '编辑机器人' : '新增机器人'" width="520px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="机器人名称" required>
          <el-input v-model="form.bot_name" placeholder="如 bot1，对应守护进程配置名" />
        </el-form-item>
        <el-form-item v-if="!form.id" label="Token" required>
          <el-input v-model="form.api_key" type="password" show-password
                    placeholder="BotFather 获取的 Token" />
        </el-form-item>
        <el-form-item label="显示名">
          <el-input v-model="form.bot_username" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" @click="onSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 设置 Token -->
    <el-dialog v-model="tokenVisible" title="设置 Token" width="520px">
      <el-form label-width="100px">
        <el-form-item label="当前">
          <span class="masked">{{ tokenMasked }}</span>
        </el-form-item>
        <el-form-item label="新 Token" required>
          <el-input v-model="newToken" type="password" show-password placeholder="BotFather 获取的 Token" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="tokenVisible = false">取消</el-button>
        <el-button type="primary" @click="saveToken">保存并校验</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  Plus, Refresh, Edit, Key, Share, View, Delete,
  VideoPlay, VideoPause, RefreshRight, WarningFilled,
} from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const list = ref([]);
const loading = ref(false);
const autoRefresh = ref(true);
let timer = null;

const editVisible = ref(false);
const form = reactive({ id: null, bot_name: '', api_key: '', bot_username: '', is_active: 1 });

const tokenVisible = ref(false);
const tokenMasked = ref('');
const newToken = ref('');
let tokenBot = null;

async function load() {
  loading.value = true;
  try { list.value = await api.get('/bots'); }
  finally { loading.value = false; }
}

function goDetail(b, tab) {
  router.push({ path: `/bots/${b.id}`, hash: tab ? `#tab=${tab}` : '' });
}

function openEdit(row) {
  Object.assign(form, {
    id: row?.id || null,
    bot_name: row?.bot_name || '',
    api_key: '',
    bot_username: row?.bot_username || '',
    is_active: row?.is_active ?? 1,
  });
  editVisible.value = true;
}

async function onSave() {
  if (!form.bot_name) return ElMessage.warning('机器人名称必填');
  if (form.id) {
    await api.put(`/bots/${form.id}`, {
      bot_name: form.bot_name,
      bot_username: form.bot_username,
      is_active: form.is_active,
    });
  } else {
    if (!form.api_key) return ElMessage.warning('Token 必填');
    await api.post('/bots', {
      bot_name: form.bot_name, api_key: form.api_key,
      bot_username: form.bot_username, is_active: form.is_active,
    });
  }
  ElMessage.success('保存成功');
  editVisible.value = false;
  load();
}

function openToken(b) {
  tokenBot = b;
  tokenMasked.value = '已设置（留安全考虑不回显全文）';
  newToken.value = '';
  tokenVisible.value = true;
}

async function saveToken() {
  if (!newToken.value) return ElMessage.warning('请输入新 Token');
  try {
    await api.put(`/bots/${tokenBot.id}`, { api_key: newToken.value });
    await api.post(`/bots/${tokenBot.id}/check`);
    ElMessage.success('Token 已保存且校验通过');
    tokenVisible.value = false;
    load();
  } catch (e) {
    ElMessage.error('已保存，但校验失败：' + (e.message || ''));
  }
}

async function control(b, action) {
  await api.post(`/bots/${b.id}/control`, { action });
  ElMessage.success(`${label[action]}命令已下发`);
  setTimeout(load, 1800);
}

async function onDelete(b) {
  await api.delete(`/bots/${b.id}`);
  ElMessage.success('已删除');
  load();
}

const label = { start: '启动', stop: '停止', restart: '重启' };

function uptime(b) {
  if (!Number(b.is_running) || !b.started_at) return '-';
  const s = Math.max(0, (Date.now() - new Date(String(b.started_at).replace(' ', 'T')).getTime()) / 1000);
  if (s < 3600) return Math.floor(s / 60) + ' 分';
  if (s < 86400) return Math.floor(s / 3600) + ' 时';
  return Math.floor(s / 86400) + ' 天';
}
function fmtHb(t) {
  const s = Math.max(0, (Date.now() - new Date(String(t).replace(' ', 'T')).getTime()) / 1000);
  return s < 60 ? Math.floor(s) + ' 秒前' : Math.floor(s / 60) + ' 分前';
}

onMounted(() => {
  load();
  timer = setInterval(() => autoRefresh.value && load(), 10000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; }
.bot-card { border-top: 3px solid #67c23a; }
.bot-card.stopped { border-top-color: #c0c4cc; }
.bc-head { margin-bottom: 12px; }
.bc-title { display: flex; align-items: center; gap: 8px; }
.bc-name { font-size: 17px; font-weight: 700; color: #303133; }
.bc-uname { font-size: 13px; color: #909399; margin-top: 3px; }
.bc-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 7px 12px; }
.stat { display: flex; justify-content: space-between; font-size: 13px;
        background: #f7f9fc; border-radius: 6px; padding: 5px 9px; }
.stat .k { color: #909399; }
.stat .v { color: #303133; font-weight: 600; }
.bc-err { display: flex; gap: 5px; align-items: center; margin-top: 10px;
          font-size: 12px; color: #f56c6c; overflow: hidden; }
.bc-err span { white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
.bc-hb { font-size: 12px; color: #b0b4bb; margin-top: 8px; }
.bc-actions { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
.masked { font-family: monospace; color: #909399; }
</style>
