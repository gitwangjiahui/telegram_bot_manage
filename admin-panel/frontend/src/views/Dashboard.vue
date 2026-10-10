<template>
  <div class="page-card">
    <!-- 概览卡片 -->
    <el-row :gutter="16">
      <el-col :span="4" v-for="card in cards" :key="card.key">
        <el-card shadow="hover" :body-style="{ padding: '16px' }">
          <div class="num" :style="{ color: card.color }">{{ stats[card.key] ?? '-' }}</div>
          <div class="label">{{ card.label }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 各 Bot 详细运行状态 -->
    <el-card style="margin-top: 16px">
      <template #header>
        <div class="card-head">
          <span>机器人运行状态</span>
          <div>
            <el-switch v-model="autoRefresh" active-text="自动刷新" inline-prompt />
            <el-button :icon="Refresh" size="small" @click="loadStatus" style="margin-left: 12px" />
          </div>
        </div>
      </template>

      <el-table :data="statusList" stripe v-loading="loading" size="small">
        <el-table-column prop="bot_name" label="机器人" width="90" />
        <el-table-column label="运行状态" width="110">
          <template #default="{ row }">
            <el-tag :type="row.is_running ? 'success' : 'danger'" effect="dark">
              <el-icon v-if="row.is_running" class="dot"><VideoPlay /></el-icon>
              {{ row.is_running ? '运行中' : '离线' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="pid" label="PID" width="80" />
        <el-table-column label="已运行" width="130">
          <template #default="{ row }">{{ uptime(row.started_at, row.is_running) }}</template>
        </el-table-column>
        <el-table-column prop="last_update_id" label="当前 Offset" width="130" />
        <el-table-column label="验证码池" width="90">
          <template #default="{ row }">
            <el-badge :value="row.captcha_available" :type="row.captcha_available > 20 ? 'success' : 'warning'" />
          </template>
        </el-table-column>
        <el-table-column prop="today_in" label="今日上行" width="85" />
        <el-table-column prop="today_out" label="今日回复" width="85" />
        <el-table-column label="最后心跳" width="100">
          <template #default="{ row }">{{ row.age_seconds != null ? row.age_seconds + 's 前' : '-' }}</template>
        </el-table-column>
        <el-table-column label="最后错误" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.last_error" class="err">{{ row.last_error_at }} {{ row.last_error }}</span>
            <span v-else class="ok-text">无</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 近 30 天消息趋势 -->
    <el-card style="margin-top: 16px">
      <template #header>近 30 天消息趋势</template>
      <div class="bars">
        <div v-for="d in trend" :key="d.day" class="bar-col">
          <div class="bar-pair">
            <el-tooltip :content="`${d.day} 上行 ${d.inbound}`" placement="top" :show-after="120" :hide-after="0">
              <div class="bar in" :style="{ height: barH(d.inbound) }" />
            </el-tooltip>
            <el-tooltip :content="`${d.day} 回复 ${d.outbound}`" placement="top" :show-after="120" :hide-after="0">
              <div class="bar out" :style="{ height: barH(d.outbound) }" />
            </el-tooltip>
          </div>
          <div class="bar-label">{{ d.day.slice(8) }}</div>
        </div>
      </div>
      <div class="legend">
        <span><i class="sw in" /> 用户上行</span>
        <span><i class="sw out" /> 管理员回复</span>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import { Refresh, VideoPlay } from '@element-plus/icons-vue';
import api from '../api';

const stats = reactive({});
const statusList = ref([]);
const trend = ref([]);
const loading = ref(false);
const autoRefresh = ref(true);
let timer = null;

const cards = [
  { key: 'bot_count', label: '机器人总数', color: '#303133' },
  { key: 'running_count', label: '运行中', color: '#67C23A' },
  { key: 'stopped_count', label: '离线', color: '#F56C6C' },
  { key: 'user_count', label: '验证用户数', color: '#2AABEE' },
  { key: 'message_count', label: '归档消息数', color: '#E6A23C' },
  { key: 'forward_count', label: '转发记录数', color: '#9B59B6' },
];

async function loadOverview() {
  Object.assign(stats, await api.get('/dashboard'));
}

async function loadStatus() {
  loading.value = true;
  try {
    statusList.value = await api.get('/dashboard/status');
  } finally {
    loading.value = false;
  }
}

async function loadTrend() {
  const rows = await api.get('/dashboard/trend');
  const byDay = new Map(rows.map((r) => [r.day, r]));
  const out = [];
  const now = new Date();
  for (let i = 29; i >= 0; i--) {
    const d = new Date(now.getTime() - i * 86400000);
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    out.push(byDay.get(key) || { day: key, inbound: 0, outbound: 0 });
  }
  trend.value = out;
}

function loadAll() {
  loadOverview();
  loadStatus();
  loadTrend();
}

// 运行时长
function uptime(started, running) {
  if (!started || !running) return '-';
  const s = Math.max(0, Math.floor((Date.now() - new Date(started.replace(' ', 'T') + '+08:00')) / 1000));
  if (s < 60) return `${s} 秒`;
  if (s < 3600) return `${Math.floor(s / 60)} 分 ${s % 60} 秒`;
  if (s < 86400) return `${Math.floor(s / 3600)} 时 ${Math.floor((s % 3600) / 60)} 分`;
  return `${Math.floor(s / 86400)} 天 ${Math.floor((s % 86400) / 3600)} 时`;
}

// 柱状图
function barH(v) {
  const max = Math.max(1, ...trend.value.flatMap((d) => [d.inbound, d.outbound]));
  return Math.max(2, Math.round((v / max) * 120)) + 'px';
}

onMounted(() => {
  loadAll();
  timer = setInterval(() => {
    if (autoRefresh.value) loadAll();
  }, 10000);
});

onUnmounted(() => timer && clearInterval(timer));
</script>

<style scoped>
.card-head { display: flex; align-items: center; justify-content: space-between; }
.num { font-size: 26px; font-weight: 700; }
.label { color: #909399; font-size: 13px; margin-top: 4px; }
.dot { vertical-align: -1px; }
.err { color: #F56C6C; font-size: 12px; }
.ok-text { color: #c0c4cc; font-size: 12px; }

.bars { display: flex; align-items: flex-end; gap: 6px; height: 160px; padding: 8px 4px; overflow-x: auto; }
.bar-col { display: flex; flex-direction: column; align-items: center; flex: 0 0 auto; min-width: 26px; }
.bar-pair { display: flex; align-items: flex-end; gap: 2px; height: 130px; }
.bar { width: 10px; border-radius: 2px 2px 0 0; cursor: default; }
.bar.in { background: linear-gradient(#42b883, #2f9e6c); }
.bar.out { background: linear-gradient(#2aabee, #1f86c4); }
.bar-label { margin-top: 6px; font-size: 11px; color: #909399; }
.legend { display: flex; gap: 20px; font-size: 12px; color: #606266; margin-top: 4px; }
.sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; }
.sw.in { background: #2f9e6c; }
.sw.out { background: #1f86c4; }
</style>
