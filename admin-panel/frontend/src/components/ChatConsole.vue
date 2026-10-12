<template>
  <div class="chat-console">
    <!-- 左侧会话列表 -->
    <div class="conv-panel">
      <div class="conv-header">
        <el-select v-if="!fixedBot" v-model="botFilter" placeholder="全部机器人" clearable
                   size="small" style="width: 100%; margin-bottom: 8px"
                   @change="loadConversations">
          <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
        </el-select>
        <el-input v-model="keyword" placeholder="搜索用户" :prefix-icon="Search" clearable
                  size="small" @input="debounceLoad" />
      </div>
      <div class="conv-list" v-loading="loadingConv">
        <div v-for="c in conversations" :key="`${c.bot_id}-${c.user_id}`"
             class="conv-item" :class="{ active: isActive(c) }"
             @click="selectConv(c)">
          <el-avatar :size="42" :src="c.avatar_url">
            {{ (c.first_name || c.username || c.user_id).toString().slice(0, 1).toUpperCase() }}
          </el-avatar>
          <div class="conv-info">
            <div class="conv-name">
              {{ c.first_name || c.username || c.user_id }}
              <el-tag v-if="!fixedBot" size="small" effect="plain" style="margin-left: 4px">{{ c.bot_name }}</el-tag>
            </div>
            <div class="conv-meta">
              <span>{{ c.msg_count }} 条消息</span>
              <span>{{ formatTime(c.last_at) }}</span>
            </div>
          </div>
        </div>
        <el-empty v-if="conversations.length === 0 && !loadingConv"
                  description="暂无会话" :image-size="60" />
      </div>
    </div>

    <!-- 右侧聊天框 -->
    <div class="chat-panel" v-if="current">
      <div class="chat-header">
        <el-avatar :size="40" :src="current.avatar_url">
          {{ (current.first_name || current.username || current.user_id).toString().slice(0,1).toUpperCase() }}
        </el-avatar>
        <div>
          <div class="chat-title">
            {{ current.first_name || current.username || current.user_id }}
            <span class="uid">ID: {{ current.user_id }}</span>
          </div>
          <div class="chat-sub">{{ current.bot_name }} · {{ current.msg_count }} 条消息</div>
        </div>
        <el-button :icon="Refresh" circle @click="loadHistory" style="margin-left: auto" />
      </div>

      <div class="msg-list" ref="msgListEl" v-loading="loadingMsg">
        <template v-for="(m, i) in messages" :key="m.db_id || m.id">
          <div class="day-sep" v-if="showDaySep(i)">
            <span>{{ formatDay(m.created_at) }}</span>
          </div>
          <!-- 用户消息（左侧） -->
          <div v-if="m.direction === 'in'" class="msg-row left">
            <el-avatar :size="34" :src="current.avatar_url">
              {{ (current.first_name || current.username || m.user_id).toString().slice(0,1).toUpperCase() }}
            </el-avatar>
            <div class="bubble user-bubble">
              <MediaContent v-if="m.media" :media="m.media" />
              <div v-if="m.text_content" class="bubble-text"
                   :class="{ 'has-media': m.media }">{{ m.text_content }}</div>
              <div v-if="!m.text_content && !m.media" class="bubble-text">[非文本消息]</div>
              <div class="bubble-meta">
                <span v-if="m.direction === 'in'" class="fwd-tag"
                      :class="{ on: m.forwarded, busy: m._fwdBusy }">
                  <span v-if="m._fwdBusy" class="fwd-spin" />
                  <a v-else-if="!m.forwarded" class="fwd-link" @click.stop="forwardOne(m)">转发</a>
                  <span v-else>已转发</span>
                </span>
                <span class="bubble-time">{{ formatHM(m.created_at) }}</span>
              </div>
            </div>
          </div>
          <!-- 管理员/后台回复（右侧） -->
          <div v-else class="msg-row right">
            <div class="bubble admin-bubble" :class="{ 'is-pending': m._temp }">
              <div v-if="m.sender_name" class="bubble-sender">{{ m.sender_name }}</div>
              <MediaContent v-if="m.media" :media="m.media" />
              <div v-if="m.text_content" class="bubble-text"
                   :class="{ 'has-media': m.media }">{{ m.text_content }}</div>
              <div class="bubble-time">{{ formatHM(m.created_at) }}</div>
            </div>
            <el-avatar :size="34" class="admin-ava"
                       :src="(!m._temp && m.sender_id) ? `/server/api/avatar/${m.sender_id}` : undefined">
              <el-icon v-if="m._temp || !m.sender_id"><UserFilled /></el-icon>
            </el-avatar>
          </div>
        </template>
      </div>

      <div class="chat-input">
        <el-input v-model="draft" type="textarea" :rows="2" resize="none"
                  placeholder="输入回复内容，Enter 发送，Shift+Enter 换行"
                  @keydown.enter.exact.prevent="sendReply" />
        <el-button type="primary" :icon="Promotion" :loading="sending" @click="sendReply">发送</el-button>
      </div>
    </div>

    <div class="chat-panel placeholder" v-else>
      <el-icon :size="64" color="#c0c4cc"><ChatDotRound /></el-icon>
      <p>选择左侧会话查看历史消息</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { Search, Refresh, Promotion } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../api';
import { sendMessageViaWs, forwardMessageViaWs } from '../ws';
import MediaContent from '../components/MediaContent.vue';

// fixedBot：指定后锁定单个机器人（用于 BotDetail 标签内），隐藏机器人筛选
const props = defineProps({ fixedBot: { type: Number, default: null } });

const bots = ref([]);
const botFilter = ref(props.fixedBot || null);
const conversations = ref([]);
const current = ref(null);
const messages = ref([]);
const keyword = ref('');
const loadingConv = ref(false);
const loadingMsg = ref(false);
const sending = ref(false);
const draft = ref('');
const msgListEl = ref(null);
let searchTimer = null;

function debounceLoad() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadConversations, 300);
}

async function loadConversations() {
  loadingConv.value = true;
  try {
    conversations.value = await api.get('/messages/conversations', {
      params: {
        bot_id: props.fixedBot || botFilter.value || undefined,
        keyword: keyword.value,
      },
    });
  } finally { loadingConv.value = false; }
}

function isActive(c) {
  return current.value && current.value.bot_id === c.bot_id && current.value.user_id === c.user_id;
}

async function selectConv(c) {
  current.value = c;
  messages.value = [];
  await loadHistory();
}

async function loadHistory() {
  if (!current.value) return;
  loadingMsg.value = true;
  try {
    messages.value = await api.get('/messages/history', {
      params: { bot_id: current.value.bot_id, user_id: current.value.user_id },
    });
    await scrollToBottom();
  } finally { loadingMsg.value = false; }
}

async function sendReply() {
  const text = draft.value.trim();
  if (!text || sending.value) return;
  sending.value = true;

  // 即时乐观气泡：点击即显示，不必等待 TG 往返 + 历史重新拉取
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ` +
                `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  const temp = {
    id: `temp-${now.getTime()}`, direction: 'out',
    text_content: text, created_at: stamp, _temp: true,
    sender_id: 0, sender_name: '管理员',
  };
  messages.value.push(temp);
  draft.value = '';
  await scrollToBottom();

  try {
    let ok = false;
    try {
      const r = await sendMessageViaWs({
        bot_id: current.value.bot_id,
        user_id: current.value.user_id,
        text,
      });
      ok = !!(r && r.ok);
      if (!ok) throw new Error(r?.error || '发送失败');
    } catch (wsErr) {
      // WS 未连接/超时：回退原 HTTP 接口
      if (wsErr.message === 'WS_NOT_OPEN' || wsErr.message === 'WS_TIMEOUT') {
        await api.post('/messages/reply', {
          bot_id: current.value.bot_id,
          user_id: current.value.user_id,
          text,
        });
        ok = true;
      } else {
        throw wsErr;
      }
    }
    if (ok) {
      // 原地把临时气泡转为正式气泡：不整表 reload，避免节点重挂造成的跳动
      temp._temp = false;
      temp.sender_id = 0;
    }
  } catch (e) {
    // 失败：移除临时气泡，恢复草稿
    messages.value = messages.value.filter((m) => m.id !== temp.id);
    draft.value = text;
    ElMessage.error(e.message || '发送失败');
  } finally { sending.value = false; }
}

// 手动补转发一条未转发消息（WS 同步响应）。
async function forwardOne(m) {
  if (m._fwdBusy || m.forwarded) return;
  m._fwdBusy = true;
  try {
    const r = await forwardMessageViaWs({
      bot_id: current.value.bot_id,
      user_id: current.value.user_id,
      tg_msg_id: m.tg_msg_id,
    });
    if (r && r.ok) {
      m.forwarded = true;
      ElMessage.success(`已转发给 ${r.forwarded}/${r.targets} 位管理员`);
    } else {
      ElMessage.error((r && r.error) || '转发失败');
    }
  } catch (e) {
    ElMessage.error(e.message === 'WS_NOT_OPEN' ? 'WS 未连接，稍后重试' : '转发失败：' + (e.message || ''));
  } finally {
    m._fwdBusy = false;
  }
}

async function scrollToBottom() {
  requestAnimationFrame(() => {
    const el = msgListEl.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}

function showDaySep(i) {
  if (i === 0) return true;
  return messages.value[i - 1].created_at.slice(0, 10) !== messages.value[i].created_at.slice(0, 10);
}

function formatTime(t) {
  if (!t) return '';
  const d = new Date(t.replace(' ', 'T'));
  const now = new Date();
  const sameDay = d.toDateString() === now.toDateString();
  return sameDay ? formatHM(t) : t.slice(5, 10);
}
function formatHM(t) { return t.slice(11, 16); }
function formatDay(t) {
  const d = new Date(t.replace(' ', 'T'));
  const now = new Date();
  const diff = (now - d) / 86400000;
  if (diff < 1) return '今天';
  if (diff < 2) return '昨天';
  return t.slice(0, 10);
}

onMounted(async () => {
  if (!props.fixedBot) bots.value = await api.get('/bots');
  await loadConversations();
});
</script>

<style scoped>
.chat-console {
  display: flex;
  width: 100%;
  height: 100%;
  background: #fff;
}
.conv-panel {
  width: 300px;
  border-right: 1px solid #e8e8e8;
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
}
.conv-header { padding: 12px; border-bottom: 1px solid #f0f0f0; }
.conv-list { flex: 1; overflow-y: auto; }
.conv-item {
  display: flex;
  gap: 10px;
  padding: 12px;
  cursor: pointer;
  border-bottom: 1px solid #f7f7f7;
}
.conv-item:hover { background: #f5f7fa; }
.conv-item.active { background: #ecf5ff; }
.conv-info { flex: 1; min-width: 0; }
.conv-name {
  font-size: 14px; font-weight: 600; color: #303133;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.conv-meta {
  display: flex; justify-content: space-between;
  font-size: 12px; color: #909399; margin-top: 4px;
}

.chat-panel {
  flex: 1; display: flex; flex-direction: column;
  background: linear-gradient(180deg, #e9edf2 0%, #dde3ea 100%);
  min-width: 0;
}
.chat-panel.placeholder {
  align-items: center; justify-content: center; color: #909399;
}
.chat-header {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 16px;
  background: #fff;
  border-bottom: 1px solid #e8e8e8;
}
.chat-title { font-size: 15px; font-weight: 600; color: #303133; }
.uid { font-size: 12px; color: #909399; font-weight: 400; margin-left: 8px; }
.chat-sub { font-size: 12px; color: #909399; }

.msg-list { flex: 1; overflow-y: auto; padding: 16px 24px; }
.day-sep { text-align: center; margin: 16px 0; }
.day-sep span {
  background: rgba(0,0,0,0.25); color: #fff;
  padding: 3px 12px; border-radius: 12px; font-size: 12px;
}
.msg-row { display: flex; gap: 8px; margin-bottom: 14px; align-items: flex-end; }
.msg-row.right { justify-content: flex-end; }
.bubble { max-width: 62%; position: relative; }
.bubble-text {
  padding: 9px 13px;
  border-radius: 12px;
  font-size: 14px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
  box-shadow: 0 1px 1px rgba(0,0,0,0.08);
}
.user-bubble .bubble-text { background: #fff; color: #303133; border-top-left-radius: 4px; }
.admin-bubble .bubble-text { background: #2AABEE; color: #fff; border-top-right-radius: 4px; }
.bubble-text.has-media { margin-top: 8px; }
.bubble-sender {
  font-size: 11px; line-height: 1.2;
  color: #1d8ec4;
  margin: 0 2px 3px 2px; font-weight: 600;
  text-align: right;
}
.bubble-time {
  font-size: 11px; color: #909399;
  text-align: right; margin-top: 2px;
}
.bubble-meta {
  display: flex; align-items: center; justify-content: flex-end; gap: 6px;
  margin-top: 2px;
}
.fwd-tag {
  font-size: 10px; line-height: 1;
  padding: 2px 6px; border-radius: 8px;
  background: rgba(0,0,0,0.06); color: #909399;
}
.fwd-tag.on { background: rgba(42,171,238,0.14); color: #1d8ec4; }
.fwd-tag.busy { background: rgba(0,0,0,0.06); color: #909399; display: inline-flex; align-items: center; gap: 4px; }
.fwd-link { cursor: pointer; color: #409eff; }
.fwd-link:hover { text-decoration: underline; }
.fwd-spin {
  width: 8px; height: 8px; border-radius: 50%;
  border: 1.5px solid #c0c4cc; border-top-color: transparent;
  display: inline-block; animation: fwdrot .7s linear infinite;
}
@keyframes fwdrot { to { transform: rotate(360deg); } }
/* 管理员头像始终占位，临时气泡不冒头像导致整行跳动 */
.admin-ava { flex-shrink: 0; }
.admin-bubble .bubble-time { color: rgba(255,255,255,0.75); }

.chat-input {
  display: flex; gap: 10px; align-items: flex-end;
  padding: 12px 16px;
  background: #fff;
  border-top: 1px solid #e8e8e8;
}
.chat-input .el-button { height: 40px; }
</style>
