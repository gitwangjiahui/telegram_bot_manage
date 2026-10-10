<template>
  <div class="chat-page">
    <!-- 左侧会话列表 -->
    <div class="conv-panel">
      <div class="conv-header">
        <el-select v-model="botFilter" placeholder="全部机器人" clearable size="small"
                   style="width: 100%; margin-bottom: 8px" @change="loadConversations">
          <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
        </el-select>
        <el-input v-model="keyword" placeholder="搜索用户" :prefix-icon="Search" clearable
                  size="small" @input="debounceLoad" />
      </div>
      <div class="conv-list" v-loading="loadingConv">
        <div v-for="c in conversations" :key="`${c.bot_id}-${c.user_id}`"
             class="conv-item" :class="{ active: isActive(c) }"
             @click="selectConv(c)">
          <el-avatar :size="42" :style="{ background: avatarColor(c.user_id) }">
            {{ (c.first_name || c.username || c.user_id).toString().slice(0, 1).toUpperCase() }}
          </el-avatar>
          <div class="conv-info">
            <div class="conv-name">
              {{ c.first_name || c.username || c.user_id }}
              <el-tag size="small" effect="plain" style="margin-left: 4px">{{ c.bot_name }}</el-tag>
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
        <el-avatar :size="40" :style="{ background: avatarColor(current.user_id) }">
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
            <el-avatar :size="34" :style="{ background: avatarColor(m.user_id) }">
              {{ (current.first_name || current.username || m.user_id).toString().slice(0,1).toUpperCase() }}
            </el-avatar>
            <div class="bubble user-bubble">
              <div class="bubble-text">{{ m.text_content || '[非文本消息]' }}</div>
              <div class="bubble-time">{{ formatHM(m.created_at) }}</div>
            </div>
          </div>
          <!-- 管理员/后台回复（右侧） -->
          <div v-else class="msg-row right">
            <div class="bubble admin-bubble">
              <div class="bubble-text">{{ m.text_content }}</div>
              <div class="bubble-time">{{ formatHM(m.created_at) }}</div>
            </div>
            <el-avatar :size="34" style="background: #2AABEE">
              <el-icon><UserFilled /></el-icon>
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

const bots = ref([]);
const botFilter = ref(null);
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

const AVATAR_COLORS = ['#2AABEE', '#67C23A', '#E6A23C', '#F56C6C', '#9B59B6', '#1ABC9C', '#34495E'];
function avatarColor(id) {
  return AVATAR_COLORS[Number(String(id).slice(-2)) % AVATAR_COLORS.length];
}

function debounceLoad() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadConversations, 300);
}

async function loadConversations() {
  loadingConv.value = true;
  try {
    conversations.value = await api.get('/messages/conversations', {
      params: { bot_id: botFilter.value || undefined, keyword: keyword.value },
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
  if (!text) return;
  sending.value = true;
  try {
    await api.post('/messages/reply', {
      bot_id: current.value.bot_id,
      user_id: current.value.user_id,
      text,
    });
    draft.value = '';
    await loadHistory();
  } catch (e) {
    ElMessage.error(e.message || '发送失败');
  } finally { sending.value = false; }
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
  bots.value = await api.get('/bots');
  await loadConversations();
});
</script>

<style scoped>
.chat-page {
  display: flex;
  height: calc(100vh - 60px);
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
.load-more {
  text-align: center; color: #409eff; cursor: pointer;
  font-size: 13px; margin-bottom: 12px;
}
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
.bubble-time {
  font-size: 11px; color: #909399;
  text-align: right; margin-top: 2px;
}
.admin-bubble .bubble-time { color: rgba(255,255,255,0.75); }

.chat-input {
  display: flex; gap: 10px; align-items: flex-end;
  padding: 12px 16px;
  background: #fff;
  border-top: 1px solid #e8e8e8;
}
.chat-input .el-button { height: 40px; }
</style>
