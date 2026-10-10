<template>
  <div class="detail-page" v-loading="loading">
    <div class="head">
      <el-button :icon="ArrowLeft" @click="$router.push('/bots')">返回</el-button>
      <span class="title">{{ bot.bot_name }}</span>
      <el-tag size="small" :type="Number(bot.is_running) ? 'success' : 'info'" effect="dark">
        {{ Number(bot.is_running) ? '运行中' : '已停止' }}
      </el-tag>
      <span class="sub">{{ bot.bot_username }}</span>
      <div class="spacer" />
      <template @click.stop>
        <el-button v-if="!Number(bot.is_running)" size="small" type="success"
                   :loading="!!busy" @click="control('start')">启动</el-button>
        <el-button v-if="Number(bot.is_running)" size="small" type="warning"
                   :loading="!!busy" @click="control('restart')">重启</el-button>
        <el-button v-if="Number(bot.is_running)" size="small" type="info"
                   :loading="!!busy" @click="control('stop')">停止</el-button>
      </template>
    </div>

    <el-card style="margin-top: 12px">
      <el-tabs v-model="tab" @tab-change="onTab">
        <!-- 概览 -->
        <el-tab-pane label="运行状态" name="overview">
          <el-descriptions :column="3" border>
            <el-descriptions-item label="PID">{{ bot.pid ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="已运行">{{ uptime }}</el-descriptions-item>
            <el-descriptions-item label="当前 Offset">{{ bot.last_update_id ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="验证码池">{{ bot.captcha_available ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="今日上行">{{ bot.today_in ?? 0 }}</el-descriptions-item>
            <el-descriptions-item label="今日回复">{{ bot.today_out ?? 0 }}</el-descriptions-item>
            <el-descriptions-item label="最后心跳">{{ bot.heartbeat_at || '-' }}</el-descriptions-item>
            <el-descriptions-item label="启动时间">{{ bot.started_at || '-' }}</el-descriptions-item>
            <el-descriptions-item label="验证用户">{{ bot.user_count ?? 0 }}</el-descriptions-item>
            <el-descriptions-item label="最后错误" :span="3">
              <span :class="{ err: bot.last_error }">{{ bot.last_error || '无' }}</span>
            </el-descriptions-item>
          </el-descriptions>
        </el-tab-pane>

        <!-- 转发管理员 -->
        <el-tab-pane label="转发管理员" name="admins">
          <div class="admins-tags">
            <el-tag v-for="a in admins" :key="a.id" closable
                    :type="a.admin_type === 'super' ? 'danger' : 'primary'"
                    style="margin: 0 8px 8px 0" @close="removeTarget(a)">
              {{ a.id }}<span v-if="a.first_name"> · {{ a.first_name }}</span>
              <span class="role">（{{ a.admin_type === 'super' ? '超级' : '普通' }}）</span>
            </el-tag>
            <span v-if="admins.length === 0" class="muted">暂未设置转发目标</span>
          </div>
          <el-divider />
          <div class="add-row">
            <el-input v-model.number="newId" placeholder="接收人 TG ID" style="width: 200px" />
            <el-select v-model="newType" style="width: 120px">
              <el-option value="normal" label="普通" />
              <el-option value="super" label="超级" />
            </el-select>
            <el-button type="primary" @click="addTarget">添加</el-button>
          </div>
        </el-tab-pane>

        <!-- 转发记录 -->
        <el-tab-pane label="转发记录" name="records">
          <el-table :data="records" stripe size="small">
            <el-table-column label="用户" min-width="180">
              <template #default="{ row }">
                <div class="user-cell">
                  <el-avatar :size="32" :src="`/server/api/avatar/${row.user_id}?bot_id=${botId}`">
                    {{ (row.first_name || row.user_id).toString().slice(0, 1) }}
                  </el-avatar>
                  <div>
                    <div>{{ nameOf(row) }}</div>
                    <div class="muted">ID: {{ row.user_id }}</div>
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="内容" min-width="220">
              <template #default="{ row }">
                <div class="user-cell">
                  <el-image v-if="row.thumb_file_id" class="thumb"
                            :src="mediaUrl(row.thumb_file_id, row.msg_type)" />
                  <span>{{ row.content || (row.msg_type === 'text' ? '' : '非文本') }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="created_at" label="时间" width="170" />
          </el-table>
        </el-tab-pane>

        <!-- 历史聊天（沿用历史消息页风格） -->
        <el-tab-pane label="历史聊天" name="chat" lazy>
          <div class="chat-console-wrap">
            <ChatConsole :fixed-bot="botId" />
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft } from '@element-plus/icons-vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api from '../api';
import { on as wsOn } from '../ws';
import ChatConsole from '../components/ChatConsole.vue';

const route = useRoute();
const router = useRouter();
const botId = Number(route.params.id);

const loading = ref(false);
const bot = ref({});
const tab = ref(route.hash.includes('admins') ? 'admins' : 'overview');

const admins = ref([]);
const newId = ref(null);
const newType = ref('normal');

const records = ref([]);

// 头部进程控制的执行态
const busy = ref(null);
let busyOff = null;

const uptime = computed(() => {
  if (!Number(bot.value.is_running) || !bot.value.started_at) return '-';
  const s = Math.max(0, (Date.now() - new Date(String(bot.value.started_at).replace(' ', 'T')).getTime()) / 1000);
  if (s < 3600) return Math.floor(s / 60) + ' 分';
  if (s < 86400) return Math.floor(s / 3600) + ' 时';
  return Math.floor(s / 86400) + ' 天';
});

async function loadBot() {
  const rows = await api.get('/bots');
  bot.value = rows.find((x) => Number(x.id) === botId) || {};
}

async function loadAdmins() {
  const fwd = await api.get('/forward');
  const cur = fwd.find((x) => Number(x.id) === botId);
  admins.value = cur ? cur.admins.map((a) => ({
    id: a.admin_id, first_name: a.first_name, admin_type: a.admin_type,
  })) : [];
}

async function addTarget() {
  if (!newId.value) return ElMessage.warning('请输入 TG ID');
  await api.post('/forward/targets', { bot_id: botId, admin_id: newId.value, admin_type: newType.value });
  ElMessage.success('已添加');
  newId.value = null;
  loadAdmins();
}

async function removeTarget(a) {
  await api.delete(`/forward/targets/${botId}/${a.id}`);
  ElMessage.success('已移除');
  loadAdmins();
}

async function loadRecords() {
  records.value = await api.get('/forward/records', { params: { bot_id: botId } });
}

function nameOf(row) {
  return [row.first_name, row.last_name].filter(Boolean).join(' ') || row.username || row.user_id;
}

function mediaUrl(fileId, type) {
  return `/server/api/media/${botId}?file_id=${fileId}` + (type ? `&type=${type}` : '');
}

async function control(action) {
  if (busy.value) return;
  try {
    await ElMessageBox.confirm(`确认对 ${bot.value.bot_name} 执行「${ {start:'启动',stop:'停止',restart:'重启'}[action] }」？`, '确认', { type: 'warning' });
  } catch { return; }
  const res = await api.post(`/bots/${botId}/control`, { action });
  const controlId = Number(res.control_id);
  busy.value = { action, controlId };
  busyOff = wsOn('bot_lifecycle', (data) => {
    if (Number(data.control_id) !== controlId) return;
    const ok = data.state === 'done';
    busy.value = null; busyOff?.(); busyOff = null;
    ElMessage[ok ? 'success' : 'error'](
      ok ? `${ {start:'启动',stop:'停止',restart:'重启'}[action] }成功`
         : `${ {start:'启动',stop:'停止',restart:'重启'}[action] }失败：${data.result || ''}`);
    if (ok && bot.value) {
      // 同步本地状态，不等 loadBot 拉旧心跳
      bot.value.is_running = action === 'stop' ? 0 : 1;
      bot.value.last_error = null;
      setTimeout(loadBot, 3000);
    } else {
      loadBot();
    }
  });
}

async function onTab(name) {
  if (name === 'admins') loadAdmins();
  if (name === 'records') loadRecords();
}

onMounted(async () => {
  loading.value = true;
  try {
    await loadBot();
    await onTab(tab.value);
  } finally { loading.value = false; }
});
onBeforeUnmount(() => { busyOff?.(); });
</script>

<style scoped>
.head { display: flex; align-items: center; gap: 10px; }
.title { font-size: 18px; font-weight: 700; }
.sub { color: #909399; font-size: 13px; }
.spacer { margin-left: auto; }
.err { color: #f56c6c; }
.muted { color: #909399; font-size: 12px; }
.add-row { display: flex; gap: 10px; }
.user-cell { display: flex; gap: 8px; align-items: center; }
.thumb { width: 40px; height: 40px; border-radius: 5px; }
.role { font-size: 11px; }

.chat-console-wrap {
  height: calc(100vh - 235px);
  min-height: 460px;
  border: 1px solid #e8e8e8;
  border-radius: 10px;
  overflow: hidden;
}
</style>
