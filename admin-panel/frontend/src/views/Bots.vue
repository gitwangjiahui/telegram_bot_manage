<template>
  <div class="bots-unified">
    <!-- 页头 -->
    <div class="page-top">
      <div>
        <h2 class="page-h2">机器人与转发</h2>
        <p class="page-desc">统一管理每个机器人的运行状态与转发目标；消息会实时转发给卡片内的管理员，管理员可直接回复用户。</p>
      </div>
      <div class="page-tools">
        <el-switch v-model="autoRefresh" inline-prompt active-text="自动刷新" />
        <el-button :icon="Refresh" @click="load">刷新</el-button>
        <el-button v-if="auth.has('bot:create')" type="primary" :icon="Plus" @click="openEdit()">新增机器人</el-button>
      </div>
    </div>

    <el-row v-loading="loading" :gutter="18">
      <el-col v-for="b in bots" :key="b.id" :xs="24" :sm="12" :lg="8" style="margin-bottom: 18px">
        <div class="rcard" :class="Number(b.is_running) ? 'is-up' : 'is-down'">
          <!-- 卡片头部 -->
          <div class="rc-head">
            <div class="rc-ava">{{ b.bot_name.slice(0, 1).toUpperCase() }}</div>
            <div class="rc-id">
              <div class="rc-name-row">
                <span class="rc-name">{{ b.bot_name }}</span>
                <span class="rc-badge">{{ Number(b.is_running) ? '运行中' : '已停止' }}</span>
              </div>
              <div class="rc-sub">{{ b.bot_username || '未设置显示名' }}</div>
            </div>
          </div>

          <!-- 状态指标 -->
          <div class="rc-metrics">
            <div class="m">
              <span class="mv">{{ b.pid ?? '—' }}</span><span class="mk">PID</span>
            </div>
            <div class="m">
              <span class="mv">{{ uptime(b) }}</span><span class="mk">已运行</span>
            </div>
            <div class="m">
              <span class="mv">{{ b.captcha_available ?? 0 }}</span><span class="mk">验证码池</span>
            </div>
            <div class="m">
              <span class="mv">{{ b.today_in ?? 0 }}/{{ b.today_out ?? 0 }}</span><span class="mk">今日 收/发</span>
            </div>
          </div>

          <div v-if="b.last_error" class="rc-err" :title="b.last_error">
            <el-icon><WarningFilled /></el-icon><span>{{ b.last_error }}</span>
          </div>

          <!-- 转发管理员（内联编辑） -->
          <div class="rc-fwd">
            <div class="fwd-label">
              <el-icon><Switch /></el-icon>
              <span>转发管理员 · {{ (b.admins || []).length }}</span>
            </div>
            <div class="fwd-tags">
              <el-tag v-for="a in (b.admins || [])" :key="a.admin_id" size="small"
                      :type="a.admin_type === 'super' ? 'danger' : 'primary'"
                      effect="light" closable class="fwd-tag"
                      @close="removeTarget(b, a)">
                <span class="fwd-id">{{ a.admin_id }}</span>
                <span v-if="a.first_name"> · {{ a.first_name }}</span>
              </el-tag>
              <span v-if="!(b.admins || []).length" class="fwd-empty">暂未设置，消息不会转发</span>
            </div>
            <div class="fwd-add">
              <el-input v-model.number="draftMap[b.id].id" size="small" placeholder="管理员 TG ID" />
              <el-select v-model="draftMap[b.id].type" size="small" class="fwd-type">
                <el-option value="normal" label="普通" />
                <el-option value="super" label="超级" />
              </el-select>
              <el-button size="small" type="primary" plain @click="addTarget(b)">添加</el-button>
            </div>
          </div>

          <!-- 操作区 -->
          <div class="rc-actions">
            <el-button size="small" :icon="View" @click="goDetail(b)">详情记录</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" :icon="Edit" @click="openEdit(b)">编辑</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" :icon="Key" @click="openToken(b)">Token</el-button>
          </div>
          <div class="rc-actions">
            <el-button v-if="auth.has('bot:edit')" size="small" type="success" plain :icon="VideoPlay"
                       @click="control(b, 'start')">启动</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" type="warning" plain :icon="RefreshRight"
                       @click="control(b, 'restart')">重启</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" :icon="VideoPause"
                       @click="control(b, 'stop')">停止</el-button>
            <el-popconfirm v-if="auth.has('bot:delete')" title="确认删除该机器人及其转发配置？"
                           @confirm="onDelete(b)">
              <template #reference>
                <el-button size="small" type="danger" plain :icon="Delete">删除</el-button>
              </template>
            </el-popconfirm>
          </div>
        </div>
      </el-col>

      <el-col :span="24" v-if="!loading && bots.length === 0">
        <el-empty description="还没有机器人，点击右上角「新增机器人」" />
      </el-col>
    </el-row>

    <!-- 编辑 / 新增 -->
    <el-dialog v-model="editVisible" :title="form.id ? '编辑机器人' : '新增机器人'" width="520px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="机器人名称" required>
          <el-input v-model="form.bot_name" placeholder="如 bot1，对应守护进程配置名" />
        </el-form-item>
        <el-form-item v-if="!form.id" label="Token" required>
          <el-input v-model="form.api_key" type="password" show-password placeholder="BotFather 获取的 Token" />
        </el-form-item>
        <el-form-item label="显示名">
          <el-input v-model="form.bot_username" placeholder="如 王大王（自己）" />
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
          <span class="masked">已设置（出于安全不回显全文）</span>
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
  Plus, Refresh, Edit, Key, View, Delete, Switch,
  VideoPlay, VideoPause, RefreshRight, WarningFilled,
} from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const bots = ref([]);
const loading = ref(false);
const autoRefresh = ref(true);
let timer = null;

const draftMap = reactive({});

const editVisible = ref(false);
const form = reactive({ id: null, bot_name: '', api_key: '', bot_username: '', is_active: 1 });

const tokenVisible = ref(false);
const newToken = ref('');
let tokenBot = null;

async function load() {
  loading.value = true;
  try {
    const [botRows, fwdRows] = await Promise.all([
      api.get('/bots'),
      api.get('/forward'),
    ]);
    const fwdMap = new Map(fwdRows.map((f) => [Number(f.id), f.admins || []]));
    bots.value = botRows.map((b) => {
      const id = Number(b.id);
      if (!draftMap[id]) draftMap[id] = { id: null, type: 'normal' };
      return { ...b, admins: fwdMap.get(id) || [] };
    });
  } finally { loading.value = false; }
}

function goDetail(b) {
  router.push(`/bots/${b.id}`);
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

async function addTarget(b) {
  const d = draftMap[b.id];
  if (!d.id) return ElMessage.warning('请输入管理员 TG ID');
  await api.post('/forward/targets', {
    bot_id: b.id, admin_id: d.id, admin_type: d.type,
  });
  ElMessage.success('已添加，机器人下次转发即生效');
  d.id = null; d.type = 'normal';
  load();
}

async function removeTarget(b, a) {
  await api.delete(`/forward/targets/${b.id}/${a.admin_id}`);
  ElMessage.success('已移除');
  load();
}

async function control(b, action) {
  await api.post(`/bots/${b.id}/control`, { action });
  ElMessage.success(`${{ start: '启动', stop: '停止', restart: '重启' }[action]}命令已下发`);
  setTimeout(load, 1800);
}

async function onDelete(b) {
  await api.delete(`/bots/${b.id}`);
  ElMessage.success('已删除');
  load();
}

function uptime(b) {
  if (!Number(b.is_running) || !b.started_at) return '—';
  const s = Math.max(0, (Date.now() - new Date(String(b.started_at).replace(' ', 'T')).getTime()) / 1000);
  if (s < 3600) return Math.floor(s / 60) + '分';
  if (s < 86400) return Math.floor(s / 3600) + '时';
  return Math.floor(s / 86400) + '天';
}

onMounted(() => {
  load();
  timer = setInterval(() => autoRefresh.value && load(), 10000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.bots-unified { padding: 20px 22px; }
.page-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
.page-h2 { margin: 0; font-size: 20px; font-weight: 700; color: #1f2733; }
.page-desc { margin: 5px 0 0; font-size: 13px; color: #8a929e; }
.page-tools { display: flex; gap: 10px; align-items: center; }

.rcard {
  background: #fff; border-radius: 14px; padding: 16px;
  box-shadow: 0 2px 14px rgba(31, 45, 61, 0.07);
  border: 1px solid #eef1f5; border-top: 3px solid #34c759;
  transition: transform .15s ease, box-shadow .15s ease;
}
.rcard:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(31,45,61,.12); }
.rcard.is-down { border-top-color: #c2c8d2; }

.rc-head { display: flex; gap: 12px; align-items: center; margin-bottom: 14px; }
.rc-ava {
  width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
  background: linear-gradient(135deg, #4facfe, #2f8ff5);
  color: #fff; font-size: 19px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.is-down .rc-ava { background: linear-gradient(135deg, #b6bdc9, #959caa); }
.rc-name-row { display: flex; align-items: center; gap: 8px; }
.rc-name { font-size: 16px; font-weight: 700; color: #1f2733; }
.rc-badge {
  font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px;
  background: #e6f9ee; color: #1ba94c;
}
.is-down .rc-badge { background: #eef1f5; color: #8a929e; }
.rc-sub { font-size: 12px; color: #98a0ac; margin-top: 3px; }

.rc-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 12px; }
.m {
  background: #f7f9fc; border-radius: 10px; padding: 8px 6px;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
}
.mv { font-size: 14px; font-weight: 700; color: #2b333f; }
.mk { font-size: 11px; color: #9aa2ae; }

.rc-err {
  display: flex; gap: 6px; align-items: center; font-size: 12px;
  color: #f56c6c; background: #fef0f0; border-radius: 8px;
  padding: 6px 9px; margin-bottom: 12px;
}
.rc-err span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.rc-fwd {
  background: #fafbfd; border: 1px solid #eef1f5; border-radius: 10px;
  padding: 10px 12px; margin-bottom: 12px;
}
.fwd-label { display: flex; gap: 6px; align-items: center; font-size: 12px; font-weight: 600; color: #58606e; margin-bottom: 8px; }
.fwd-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 9px; min-height: 20px; }
.fwd-tag { margin: 0; }
.fwd-id { font-variant-numeric: tabular-nums; }
.fwd-empty { font-size: 12px; color: #a6adb8; }
.fwd-add { display: flex; gap: 6px; }
.fwd-type { width: 78px; flex-shrink: 0; }

.rc-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.masked { font-family: monospace; color: #909399; }
</style>
