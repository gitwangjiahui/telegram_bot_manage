<template>
  <div class="bots-unified">
    <!-- 页头 -->
    <div class="page-top">
      <div>
        <h2 class="page-h2">机器人</h2>
        <p class="page-desc">查看每个机器人的实时运行状态，进行进程控制与转发设置。</p>
      </div>
      <div class="page-tools">
        <el-switch v-model="autoRefresh" inline-prompt active-text="自动刷新" />
        <el-button :icon="Refresh" @click="load">刷新</el-button>
        <el-button v-if="auth.has('bot:create')" type="primary" :icon="Plus" @click.stop="openEdit()">新增机器人</el-button>
      </div>
    </div>

    <div class="card-grid" v-loading="loading">
      <div v-for="b in bots" :key="b.id" class="rcard" :class="Number(b.is_running) ? 'is-up' : 'is-down'"
           @click="goDetail(b)">
        <!-- 悬浮过程层：不占布局，卡片尺寸不变 -->
        <div v-if="pendingMap[b.id]" class="pending-overlay">
          <div class="pending-box">
            <el-icon class="p-spin"><Loading /></el-icon>
            <span>{{ pendingText(b) }}</span>
          </div>
        </div>

        <!-- 头部 -->
        <div class="rc-head">
          <div class="rc-ava">{{ b.bot_name.slice(0, 1).toUpperCase() }}</div>
          <div class="rc-id">
            <div class="rc-name-row">
              <span class="rc-name">{{ b.bot_name }}</span>
              <span class="rc-state">
                <span class="state-dot" />
                <span class="state-text">{{ Number(b.is_running) ? '运行中' : '已停止' }}</span>
              </span>
            </div>
            <div class="rc-sub">{{ b.bot_username || '未设置显示名' }}</div>
          </div>
        </div>

        <!-- 右上角图标操作（阻止冒泡） -->
        <div class="rc-corner" @click.stop>
          <el-tooltip v-if="auth.has('bot:edit')" content="编辑机器人" placement="top">
            <span class="corner-btn" @click="openEdit(b)"><el-icon><Edit /></el-icon></span>
          </el-tooltip>
          <el-tooltip v-if="auth.has('bot:edit')" content="设置 Token" placement="top">
            <span class="corner-btn" @click="openToken(b)"><el-icon><Key /></el-icon></span>
          </el-tooltip>
          <el-tooltip content="详情记录" placement="top">
            <span class="corner-btn" @click="goDetail(b)"><el-icon><View /></el-icon></span>
          </el-tooltip>
          <el-tooltip v-if="auth.has('bot:delete')" content="删除机器人" placement="top">
            <span class="corner-btn danger" @click="onDelete(b)"><el-icon><Delete /></el-icon></span>
          </el-tooltip>
        </div>

        <!-- 指标 -->
        <div class="rc-metrics">
          <div class="metric">
            <span class="mv">{{ b.pid ?? '—' }}</span><span class="mk">PID</span>
          </div>
          <div class="metric">
            <span class="mv">{{ uptime(b) }}</span><span class="mk">已运行</span>
          </div>
          <div class="metric">
            <span class="mv">{{ b.captcha_available ?? 0 }}</span><span class="mk">验证码池</span>
          </div>
          <div class="metric">
            <span class="mv">{{ b.today_in ?? 0 }}/{{ b.today_out ?? 0 }}</span><span class="mk">收/发</span>
          </div>
        </div>

        <div v-if="b.last_error && !pendingMap[b.id]" class="rc-err" :title="b.last_error">
          <el-icon><WarningFilled /></el-icon><span>{{ b.last_error }}</span>
        </div>

        <!-- 操作区（阻止冒泡，不触发进详情） -->
        <div class="rc-actions" @click.stop>
          <button v-if="Number(b.is_running)" class="pill warn" :disabled="!!pendingMap[b.id]"
                  @click="control(b, 'restart')">
            <el-icon><RefreshRight /></el-icon><span>重启</span>
          </button>
          <button v-if="!Number(b.is_running)" class="pill primary" :disabled="!!pendingMap[b.id]"
                  @click="control(b, 'start')">
            <el-icon><VideoPlay /></el-icon><span>启动</span>
          </button>
          <button v-if="Number(b.is_running)" class="pill ghost" :disabled="!!pendingMap[b.id]"
                  @click="control(b, 'stop')">
            <el-icon><VideoPause /></el-icon><span>停止</span>
          </button>
          <button class="pill neutral" @click="openForward(b)">
            <el-icon><Switch /></el-icon><span>转发 · {{ b.admin_count ?? 0 }}</span>
          </button>
        </div>
      </div>

      <div v-if="!loading && bots.length === 0" class="empty-wrap">
        <el-empty description="还没有机器人，点击右上角「新增机器人」" />
      </div>
    </div>

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

    <!-- 转发管理员设置 -->
    <el-dialog v-model="fwdVisible" :title="`转发设置 · ${fwdBot?.bot_name || ''}`" width="560px">
      <div class="fwd-tags">
        <el-tag v-for="a in fwdAdmins" :key="a.admin_id" size="small"
                :type="a.admin_type === 'super' ? 'danger' : 'primary'"
                effect="light" closable class="fwd-tag" @close="removeTarget(a)">
          <span class="fwd-id">{{ a.admin_id }}</span>
          <span v-if="a.first_name"> · {{ a.first_name }}</span>
          <span class="fwd-role">（{{ a.admin_type === 'super' ? '超级' : '普通' }}）</span>
        </el-tag>
        <span v-if="!fwdAdmins.length" class="fwd-empty">暂未设置，消息不会转发</span>
      </div>
      <el-divider />
      <div class="fwd-add">
        <el-input v-model.number="fwdDraft.id" size="small" placeholder="管理员 TG ID" />
        <el-select v-model="fwdDraft.type" size="small" class="fwd-type">
          <el-option value="normal" label="普通" />
          <el-option value="super" label="超级" />
        </el-select>
        <el-button size="small" type="primary" @click="addTarget">添加</el-button>
      </div>
      <template #footer>
        <el-button type="primary" @click="fwdVisible = false">完成</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  Plus, Refresh, Edit, Key, View, Delete, Switch,
  VideoPlay, VideoPause, RefreshRight, WarningFilled, Loading,
} from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { on as wsOn } from '../ws';

const ACTION_TEXT = { start: '启动', stop: '停止', restart: '重启' };

const auth = useAuthStore();
const router = useRouter();
const bots = ref([]);
const loading = ref(false);
const autoRefresh = ref(true);
let timer = null;

// 每张卡片的执行态：{ action, controlId, failTimer, pollTimer, off }
const pendingMap = reactive({});

const editVisible = ref(false);
const form = reactive({ id: null, bot_name: '', api_key: '', bot_username: '', is_active: 1 });

const tokenVisible = ref(false);
const newToken = ref('');
let tokenBot = null;

// 转发弹窗
const fwdVisible = ref(false);
const fwdBot = ref(null);
const fwdAdmins = ref([]);
const fwdDraft = reactive({ id: null, type: 'normal' });

async function load() {
  loading.value = true;
  try {
    const [botRows, fwdRows] = await Promise.all([
      api.get('/bots'),
      api.get('/forward'),
    ]);
    const countMap = new Map(fwdRows.map((f) => [Number(f.id), (f.admins || []).length]));
    bots.value = botRows.map((b) => ({
      ...b,
      admin_count: countMap.get(Number(b.id)) ?? 0,
    }));
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

async function openForward(b) {
  fwdBot.value = b;
  fwdDraft.id = null; fwdDraft.type = 'normal';
  fwdVisible.value = true;
  await loadFwdAdmins();
}

async function loadFwdAdmins() {
  if (!fwdBot.value) return;
  const fwdRows = await api.get('/forward');
  const cur = fwdRows.find((f) => Number(f.id) === Number(fwdBot.value.id));
  fwdAdmins.value = cur ? cur.admins : [];
}

async function addTarget() {
  if (!fwdDraft.id) return ElMessage.warning('请输入管理员 TG ID');
  await api.post('/forward/targets', {
    bot_id: fwdBot.value.id, admin_id: fwdDraft.id, admin_type: fwdDraft.type,
  });
  ElMessage.success('已添加，机器人下次转发即生效');
  fwdDraft.id = null; fwdDraft.type = 'normal';
  await loadFwdAdmins();
  load();
}

async function removeTarget(a) {
  await api.delete(`/forward/targets/${fwdBot.value.id}/${a.admin_id}`);
  ElMessage.success('已移除');
  await loadFwdAdmins();
  load();
}

async function control(b, action) {
  if (pendingMap[b.id]) return;
  try {
    const res = await api.post(`/bots/${b.id}/control`, { action });
    const controlId = Number(res.control_id);
    beginPending(b, action, controlId);
  } catch (e) {
    ElMessage.error('命令下发失败：' + (e.message || ''));
  }
}

function beginPending(b, action, controlId) {
  const entry = { action, controlId };
  pendingMap[b.id] = entry;

  const settle = (ok, resultMsg) => finishPending(b.id, ok, resultMsg);

  entry.off = wsOn('bot_lifecycle', (data) => {
    if (Number(data.control_id) !== controlId) return;
    settle(data.state === 'done', data.result);
  });

  entry.pollTimer = setInterval(async () => {
    try {
      const last = await api.get(`/bots/${b.id}/control-last`);
      if (last && Number(last.id) === controlId && (last.status === 'done' || last.status === 'error')) {
        settle(last.status === 'done', last.result);
      }
    } catch { /* noop */ }
  }, 2000);

  entry.failTimer = setTimeout(() => settle(false, '执行超时，请刷新确认状态'), 30000);
}

function finishPending(id, ok, resultMsg) {
  const entry = pendingMap[id];
  if (!entry) return;
  clearTimeout(entry.failTimer);
  clearInterval(entry.pollTimer);
  entry.off?.();
  delete pendingMap[id];

  const verb = ACTION_TEXT[entry.action] || '操作';
  if (ok) {
    ElMessage.success(`${verb}成功`);
    // 同步本地状态：直接改这张卡，不等 /bots 心跳（旧心跳 90 秒内仍会误判运行中）
    applyLocalState(id, entry.action);
  } else {
    ElMessage.error(`${verb}失败：${resultMsg || '未知错误'}`);
    load();
  }
}

// 按动作即时更新卡片本地状态。
function applyLocalState(id, action) {
  const b = bots.value.find((x) => Number(x.id) === Number(id));
  if (!b) { load(); return; }
  if (action === 'stop') {
    b.is_running = 0;
    b.last_error = null;
  } else if (action === 'start') {
    b.is_running = 1;
    b.last_error = null;
  } else if (action === 'restart') {
    b.is_running = 1;
    b.last_error = null;
  }
  // 后台静默校正（不覆盖刚改的即时状态感：稍候等心跳跟上）
  setTimeout(load, 3000);
}

function pendingText(b) {
  const e = pendingMap[b.id];
  return e ? `${ACTION_TEXT[e.action] || '操作'}中` : '';
}

async function onDelete(b) {
  try {
    const { ElMessageBox } = await import('element-plus');
    await ElMessageBox.confirm('确认删除该机器人及其转发配置？', '确认', { type: 'warning' });
  } catch { return; }
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
onBeforeUnmount(() => {
  clearInterval(timer);
  Object.keys(pendingMap).forEach((id) => {
    const e = pendingMap[id];
    clearTimeout(e.failTimer);
    clearInterval(e.pollTimer);
    e.off?.();
  });
});
</script>

<style scoped>
.bots-unified { padding: 20px 22px; }
.page-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.page-h2 { margin: 0; font-size: 20px; font-weight: 700; color: #1f2733; }
.page-desc { margin: 5px 0 0; font-size: 13px; color: #8a929e; }
.page-tools { display: flex; gap: 10px; align-items: center; }

.card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 16px; }

.rcard {
  position: relative;
  background: #fff; border-radius: 16px; padding: 18px;
  box-shadow: 0 2px 12px rgba(31, 45, 61, 0.06);
  border: 1px solid #eef1f5;
  cursor: pointer;
  transition: transform .18s ease, box-shadow .18s ease;
  display: flex; flex-direction: column;
}
.rcard::before {
  content: ''; position: absolute; left: 0; top: 16px; bottom: 16px; width: 4px;
  border-radius: 0 4px 4px 0; background: #34c759;
}
.rcard:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(31,45,61,.12); }
.rcard.is-down::before { background: #c2c8d2; }

.rc-head { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; padding-right: 4px; }
.rc-ava {
  width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0;
  background: linear-gradient(135deg, #4facfe, #2f8ff5);
  color: #fff; font-size: 20px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.is-down .rc-ava { background: linear-gradient(135deg, #b6bdc9, #959caa); }
.rc-id { flex: 1; min-width: 0; }
.rc-name-row { display: flex; align-items: center; gap: 10px; }
.rc-name { font-size: 16px; font-weight: 700; color: #1f2733; }
.rc-sub { font-size: 12px; color: #98a0ac; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rc-state { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.state-dot { width: 8px; height: 8px; border-radius: 50%; background: #34c759; box-shadow: 0 0 0 3px rgba(52,199,89,.18); }
.is-down .state-dot { background: #c2c8d2; box-shadow: 0 0 0 3px rgba(194,200,210,.2); }
.state-text { font-size: 12px; font-weight: 600; color: #1ba94c; }
.is-down .state-text { color: #98a0ac; }

/* 右上角图标操作 */
.rc-corner {
  position: absolute; top: 12px; right: 12px; z-index: 4;
  display: none; gap: 2px;
}
.rcard:hover .rc-corner { display: inline-flex; }
.corner-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; border-radius: 8px;
  color: #9aa2ae; cursor: pointer;
  transition: color .15s ease, background .15s ease, transform .15s ease;
}
.corner-btn .el-icon { font-size: 15px; }
.corner-btn:hover {
  color: #3b6fe0; background: #eef3ff;
  transform: translateY(-1px) scale(1.08);
}
.corner-btn.danger:hover { color: #f56c6c; background: #fef0f0; }

.rc-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
.metric {
  background: #f7f9fc; border-radius: 10px; padding: 9px 4px;
  display: flex; flex-direction: column; align-items: center; gap: 3px;
}
.mv { font-size: 14px; font-weight: 700; color: #2b333f; }
.mk { font-size: 10px; color: #9aa2ae; }

.rc-err {
  display: flex; gap: 6px; align-items: center; font-size: 12px;
  color: #f56c6c; background: #fef0f0; border-radius: 8px;
  padding: 6px 9px; margin-bottom: 12px;
}
.rc-err span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.rc-actions { display: flex; align-items: center; gap: 6px; margin-top: auto; padding-top: 4px; }
.actions-spacer { flex: 1; }

.pill {
  display: inline-flex; align-items: center; gap: 5px;
  height: 30px; padding: 0 12px; border: none; border-radius: 15px;
  font-size: 12px; font-weight: 600; cursor: pointer;
  transition: filter .15s ease, opacity .15s ease;
}
.pill .el-icon { font-size: 13px; }
.pill:disabled { opacity: .5; cursor: not-allowed; }
.pill:not(:disabled):hover { filter: brightness(1.05); }
.pill.primary { background: #e6f9ee; color: #1ba94c; }
.pill.warn { background: #fff4e5; color: #e68a1a; }
.pill.ghost { background: #f2f4f7; color: #6b7280; }
.pill.neutral { background: #eef3ff; color: #3b6fe0; }

.icon-btn {
  font-size: 16px; color: #a6adb8; cursor: pointer; padding: 4px;
  border-radius: 6px; transition: color .15s, background .15s;
}
.icon-btn:hover { color: #3b6fe0; background: #f2f6ff; }
.icon-btn.danger:hover { color: #f56c6c; background: #fef0f0; }

/* 悬浮过程层 */
.pending-overlay {
  position: absolute; inset: 0; z-index: 5;
  background: rgba(255, 255, 255, 0.72);
  backdrop-filter: blur(2px);
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
}
.pending-box {
  display: inline-flex; align-items: center; gap: 8px;
  background: #fff; border-radius: 20px; padding: 9px 18px;
  box-shadow: 0 6px 20px rgba(31,45,61,.16);
  font-size: 13px; font-weight: 600; color: #2f6fed;
}
.p-spin { font-size: 16px; animation: p-rotate 1s linear infinite; }
@keyframes p-rotate { to { transform: rotate(360deg); } }

.empty-wrap { grid-column: 1 / -1; padding: 60px 0; }

.masked { font-family: monospace; color: #909399; }

.fwd-tags { display: flex; flex-wrap: wrap; gap: 8px; min-height: 24px; }
.fwd-tag { margin: 0; }
.fwd-id { font-variant-numeric: tabular-nums; }
.fwd-role { font-size: 11px; }
.fwd-empty { font-size: 13px; color: #a6adb8; }
.fwd-add { display: flex; gap: 8px; }
.fwd-type { width: 90px; flex-shrink: 0; }
</style>
