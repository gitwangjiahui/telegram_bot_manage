<template>
  <div class="dash" v-loading="loading">
    <!-- 顶部工具条 -->
    <div class="topbar">
      <div class="clock">
        <span class="pulse" :class="{ off: !allRunning }" />
        {{ allRunning ? '全部 Bot 运行中' : `${m.totals?.running ?? 0}/${m.totals?.bots ?? 0} 运行中` }}
        <i class="clock-time">{{ serverTime }}</i>
      </div>
      <el-switch v-model="autoRefresh" active-text="自动刷新 10s" inline-prompt size="small" />
      <el-button :icon="Refresh" size="small" circle @click="load" />
    </div>

    <!-- 核心 KPI -->
    <el-row :gutter="12">
      <el-col :xs="12" :sm="8" :md="6" :lg="3" v-for="c in kpiCards" :key="c.key">
        <div class="kpi" :style="{ '--accent': c.color }">
          <div class="kpi-icon"><el-icon :size="20"><component :is="c.icon" /></el-icon></div>
          <div class="kpi-body">
            <div class="kpi-num">{{ c.val }}</div>
            <div class="kpi-label">{{ c.label }}</div>
            <div v-if="c.sub" class="kpi-sub" :class="c.subCls">{{ c.sub }}</div>
          </div>
        </div>
      </el-col>
    </el-row>

    <!-- 实时 Bot 状态条 -->
    <div class="panel" style="margin-top:14px">
      <div class="panel-title">Bot 实时运行</div>
      <div class="bot-grid">
        <div v-for="b in m.live" :key="b.bot_id" class="bot-card" :class="{ down: !b.is_running }">
          <div class="bot-head">
            <span class="bot-name">{{ b.bot_name }}</span>
            <el-tag :type="b.is_running ? 'success' : 'danger'" size="small" effect="dark">
              {{ b.is_running ? '运行中' : '离线' }}
            </el-tag>
          </div>
          <div class="bot-metrics">
            <div><b>{{ b.today_in }}</b><i>今日上行</i></div>
            <div><b>{{ b.today_out }}</b><i>今日回复</i></div>
            <div><b :class="{ warn: b.unverified > 0 }">{{ b.unverified }}</b><i>未验证</i></div>
          </div>
          <div class="bot-foot">
            <span>验证码池
              <b :class="b.captcha_low ? 'cap-low' : ''">{{ b.captcha ?? '-' }}</b>
            </span>
            <span>{{ b.is_running && b.age_seconds != null ? '心跳 ' + b.age_seconds + 's' : '无心跳' }}</span>
          </div>
          <div v-if="b.last_error" class="bot-err" :title="b.last_error">
            {{ b.last_error }}
          </div>
        </div>
      </div>
    </div>

    <!-- 图表区 -->
    <el-row :gutter="12" style="margin-top:14px">
      <!-- 今日分时 -->
      <el-col :xs="24" :lg="14">
        <div class="panel">
          <div class="panel-title">今日 24 小时实时收发
            <span class="legend"><i class="sw in" />上行<i class="sw out" />回复</span>
          </div>
          <div class="hour-chart">
            <div v-for="h in m.hourly" :key="h.hr" class="h-col">
              <el-tooltip :content="`${h.hr}:00 · 上行 ${h.inbound} / 回复 ${h.outbound}`" placement="top" :show-after="100" :hide-after="0">
                <div class="h-pair">
                  <div class="h-bar in" :style="{ height: hH(h.inbound) }" :class="{ cur: h.hr === curHour }" />
                  <div class="h-bar out" :style="{ height: hH(h.outbound) }" :class="{ cur: h.hr === curHour }" />
                </div>
              </el-tooltip>
              <div class="h-label" v-if="h.hr % 3 === 0">{{ h.hr }}</div>
              <div class="h-label" v-else></div>
            </div>
          </div>
        </div>
      </el-col>

      <!-- 消息类型 + 回复覆盖 -->
      <el-col :xs="24" :lg="10">
        <div class="panel h-full">
          <div class="panel-title">入站消息类型分布</div>
          <div class="type-list">
            <div v-for="t in typeRows" :key="t.type" class="type-row">
              <span class="type-name">{{ typeLabel(t.type) }}</span>
              <div class="type-track"><div class="type-fill" :style="{ width: typePct(t.c) + '%' }" /></div>
              <span class="type-cnt">{{ t.c }}</span>
              <span class="type-pct">{{ typePct(t.c) }}%</span>
            </div>
            <div v-if="!typeRows.length" class="empty">暂无数据</div>
          </div>
          <div class="conv-box">
            <div class="conv-ring">
              <el-progress type="dashboard" :percentage="m.conversation?.rate ?? 0" :width="92"
                           :color="ringColor" :stroke-width="10">
                <template #default>{{ m.conversation?.rate ?? 0 }}%</template>
              </el-progress>
            </div>
            <div class="conv-info">
              <div class="conv-title">近30天会话回复覆盖</div>
              <div class="conv-desc">{{ m.conversation?.replied ?? 0 }} / {{ m.conversation?.total ?? 0 }} 个客户会话已获回复</div>
            </div>
          </div>
        </div>
      </el-col>
    </el-row>

    <!-- 30 天趋势 -->
    <div class="panel" style="margin-top:14px">
      <div class="panel-title">近 30 天消息趋势
        <span class="legend"><i class="sw in" />用户上行<i class="sw out" />管理员回复</span>
      </div>
      <div class="day-chart">
        <div v-for="d in m.trend" :key="d.day" class="d-col">
          <el-tooltip :content="`${d.day} · 上行 ${d.inbound} / 回复 ${d.outbound}`" placement="top" :show-after="100" :hide-after="0">
            <div class="d-pair">
              <div class="d-bar in" :style="{ height: dH(d.inbound) }" />
              <div class="d-bar out" :style="{ height: dH(d.outbound) }" />
            </div>
          </el-tooltip>
          <div class="d-label">{{ d.day.slice(5) }}</div>
        </div>
      </div>
    </div>

    <!-- 排行 -->
    <el-row :gutter="12" style="margin-top:14px">
      <el-col :xs="24" :md="12">
        <div class="panel">
          <div class="panel-title">管理员回复量 Top</div>
          <div class="rank-list">
            <div v-for="(a, i) in m.top_admins" :key="a.name" class="rank-row">
              <span class="rank-no" :class="'n' + (i < 3 ? i + 1 : '')">{{ i + 1 }}</span>
              <span class="rank-name">{{ a.name }}</span>
              <div class="rank-track"><div class="rank-fill a" :style="{ width: rankPct(a.c, maxAdmin) + '%' }" /></div>
              <span class="rank-cnt">{{ a.c }}</span>
            </div>
            <div v-if="!m.top_admins?.length" class="empty">暂无回复</div>
          </div>
        </div>
      </el-col>
      <el-col :xs="24" :md="12">
        <div class="panel">
          <div class="panel-title">活跃客户 Top（近30天入站）</div>
          <div class="rank-list">
            <div v-for="(u, i) in m.top_users" :key="u.user_id" class="rank-row">
              <span class="rank-no" :class="'n' + (i < 3 ? i + 1 : '')">{{ i + 1 }}</span>
              <span class="rank-name mono">{{ u.user_id }}</span>
              <div class="rank-track"><div class="rank-fill u" :style="{ width: rankPct(u.c, maxUser) + '%' }" /></div>
              <span class="rank-cnt">{{ u.c }}</span>
            </div>
            <div v-if="!m.top_users?.length" class="empty">暂无客户</div>
          </div>
        </div>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import {
  Refresh, Monitor, User, ChatDotRound, Promotion, CircleCheck, Warning,
} from '@element-plus/icons-vue';
import api from '../api';

const m = reactive({});
const loading = ref(false);
const autoRefresh = ref(true);
const serverTime = ref('');
let timer = null;

const allRunning = computed(() => (m.totals?.bots ?? 0) > 0 && (m.totals?.running ?? 0) === m.totals?.bots);
const curHour = new Date().getHours();

const kpiCards = computed(() => {
  const t = m.totals || {};
  const td = m.today || {};
  const deltaIn = td.yday_in ? pctDelta(td.inbound, td.yday_in) : null;
  return [
    { key: 'bots', icon: Monitor, color: '#409eff', label: 'Bot 运行', val: `${t.running ?? 0}/${t.bots ?? 0}` },
    { key: 'users', icon: User, color: '#67c23a', label: '已验证客户', val: t.verified_users ?? 0,
      sub: td.new_users ? `今日新增 ${td.new_users}` : '', },
    { key: 'in', icon: ChatDotRound, color: '#2f9e6c', label: '今日上行消息', val: td.inbound ?? 0,
      sub: deltaIn != null ? `${deltaIn >= 0 ? '▲' : '▼'} ${Math.abs(deltaIn)}% 较昨日` : '昨日 0',
      subCls: deltaIn >= 0 ? 'up' : 'down' },
    { key: 'out', icon: Promotion, color: '#1f86c4', label: '今日管理员回复', val: td.outbound ?? 0,
      sub: `回复率 ${td.reply_rate ?? 0}%` },
    { key: 'fwd', icon: Promotion, color: '#9b59b6', label: '转发消息', val: t.forwards ?? 0,
      sub: td.forwards ? `今日 ${td.forwards}` : '今日 0' },
    { key: 'unv', icon: Warning, color: '#e6a23c', label: '未验证留痕', val: t.unverified ?? 0,
      sub: t.unverified ? '含历史广告消息' : '', subCls: 'warn' },
    { key: 'msg', icon: CircleCheck, color: '#909399', label: '归档消息总量', val: t.messages ?? 0 },
    { key: 'conv', icon: ChatDotRound, color: '#13c2c2', label: '会话回复率', val: (m.conversation?.rate ?? 0) + '%',
      sub: `${m.conversation?.replied ?? 0}/${m.conversation?.total ?? 0} 会话` },
  ];
});

const typeRows = computed(() => m.by_type || []);
const typeTotal = computed(() => typeRows.value.reduce((s, x) => s + x.c, 0));
const maxAdmin = computed(() => Math.max(1, ...(m.top_admins || []).map((x) => x.c)));
const maxUser = computed(() => Math.max(1, ...(m.top_users || []).map((x) => x.c)));

const TYPE_LABELS = {
  text: '文本', photo: '图片', voice: '语音', video: '视频', video_note: '视频留言',
  document: '文件', sticker: '贴纸', animation: '动图', audio: '音频',
  contact: '名片', location: '位置', other: '其他',
};
const typeLabel = (t) => TYPE_LABELS[t] || t;
const typePct = (c) => (typeTotal.value ? Math.round((c / typeTotal.value) * 100) : 0);
const rankPct = (c, max) => Math.max(2, Math.round((c / max) * 100));
const pctDelta = (cur, prev) => (prev ? Math.round(((cur - prev) / prev) * 100) : 0);
const ringColor = [
  { color: '#f56c6c', percentage: 40 },
  { color: '#e6a23c', percentage: 70 },
  { color: '#67c23a', percentage: 100 },
];

// 柱状高度（按时段内最大值归一）
function hH(v) {
  const max = Math.max(1, ...(m.hourly || []).flatMap((d) => [d.inbound, d.outbound]));
  return Math.max(2, Math.round((v / max) * 150)) + 'px';
}
function dH(v) {
  const max = Math.max(1, ...(m.trend || []).flatMap((d) => [d.inbound, d.outbound]));
  return Math.max(2, Math.round((v / max) * 130)) + 'px';
}

async function load() {
  loading.value = true;
  try {
    const data = await api.get('/dashboard/metrics');
    Object.keys(m).forEach((k) => delete m[k]);
    Object.assign(m, data);
    serverTime.value = new Date(data.server_time).toLocaleTimeString('zh-CN', { hour12: false });
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  load();
  timer = setInterval(() => autoRefresh.value && load(), 10000);
});
onUnmounted(() => timer && clearInterval(timer));
</script>

<style scoped>
.dash { padding: 4px 2px 24px; }

/* 顶栏 */
.topbar { display: flex; align-items: center; justify-content: flex-end; gap: 14px; margin-bottom: 12px; }
.clock { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #606266; margin-right: auto; }
.clock-time { color: #909399; font-style: normal; }
.pulse { width: 9px; height: 9px; border-radius: 50%; background: #67c23a; box-shadow: 0 0 0 0 rgba(103,194,58,.6); animation: pulse 1.8s infinite; }
.pulse.off { background: #f56c6c; box-shadow: none; animation: none; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(103,194,58,.5);} 70% { box-shadow: 0 0 0 7px rgba(103,194,58,0);} 100% { box-shadow: 0 0 0 0 rgba(103,194,58,0);} }

/* KPI 卡片 */
.kpi { display: flex; gap: 12px; align-items: center; background: #fff; border-radius: 10px; padding: 14px 14px;
       margin-bottom: 12px; border-left: 4px solid var(--accent); box-shadow: 0 1px 4px rgba(0,0,0,.06); min-height: 86px; }
.kpi-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            background: color-mix(in srgb, var(--accent) 12%, #fff); color: var(--accent); flex: none; }
.kpi-num { font-size: 24px; font-weight: 700; color: #303133; line-height: 1.1; }
.kpi-label { font-size: 12.5px; color: #909399; margin-top: 3px; }
.kpi-sub { font-size: 11px; margin-top: 2px; color: #909399; }
.kpi-sub.up { color: #67c23a; } .kpi-sub.down { color: #f56c6c; } .kpi-sub.warn { color: #e6a23c; }

/* 面板 */
.panel { background: #fff; border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.panel.h-full { height: 100%; }
.panel-title { font-size: 15px; font-weight: 600; color: #303133; margin-bottom: 14px; display: flex; align-items: center; }
.legend { font-size: 12px; font-weight: 400; color: #909399; margin-left: auto; display: flex; align-items: center; gap: 6px; }
.sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin: 0 2px 0 8px; }
.sw:first-child { margin-left: 0; }
.sw.in { background: #2f9e6c; } .sw.out { background: #1f86c4; }

/* Bot 卡 */
.bot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
.bot-card { border: 1px solid #ebeef5; border-radius: 10px; padding: 12px 14px; background: #fafbfc; }
.bot-card.down { background: #fef6f6; border-color: #fbc4c4; }
.bot-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.bot-name { font-weight: 700; color: #303133; }
.bot-metrics { display: flex; gap: 6px; }
.bot-metrics > div { flex: 1; text-align: center; background: #fff; border-radius: 8px; padding: 8px 4px; }
.bot-metrics b { display: block; font-size: 19px; color: #303133; }
.bot-metrics b.warn { color: #e6a23c; }
.bot-metrics i { font-style: normal; font-size: 11px; color: #909399; }
.bot-foot { display: flex; justify-content: space-between; font-size: 11.5px; color: #909399; margin-top: 10px; }
.bot-foot b { color: #303133; }
.bot-foot b.cap-low { color: #e6a23c; }
.bot-err { margin-top: 8px; font-size: 11px; color: #f56c6c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* 分时图 */
.hour-chart { display: flex; align-items: flex-end; gap: 1px; height: 188px; }
.h-col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
.h-pair { display: flex; align-items: flex-end; gap: 2px; height: 156px; width: 100%; justify-content: center; }
.h-bar { width: 50%; max-width: 9px; border-radius: 2px 2px 0 0; }
.h-bar.in { background: linear-gradient(#42c48a,#2f9e6c); }
.h-bar.out { background: linear-gradient(#45b6f2,#1f86c4); }
.h-bar.cur { outline: 2px solid rgba(230,162,60,.45); }
.h-label { font-size: 9.5px; color: #909399; margin-top: 5px; height: 12px; }

/* 类型分布 */
.type-list { display: flex; flex-direction: column; gap: 9px; }
.type-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; }
.type-name { width: 56px; color: #606266; flex: none; }
.type-track { flex: 1; height: 14px; background: #f2f4f7; border-radius: 7px; overflow: hidden; }
.type-fill { height: 100%; background: linear-gradient(90deg,#5bb0f0,#409eff); border-radius: 7px; }
.type-cnt { width: 38px; text-align: right; color: #303133; font-weight: 600; }
.type-pct { width: 40px; text-align: right; color: #909399; font-size: 11px; }

.conv-box { display: flex; align-items: center; gap: 16px; margin-top: 18px; padding-top: 16px; border-top: 1px dashed #ebeef5; }
.conv-title { font-size: 13.5px; font-weight: 600; color: #303133; }
.conv-desc { font-size: 12px; color: #909399; margin-top: 5px; }

/* 30 天 */
.day-chart { display: flex; align-items: flex-end; gap: 2px; height: 168px; }
.d-col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; min-width: 0; }
.d-pair { display: flex; align-items: flex-end; gap: 2px; height: 136px; width: 100%; justify-content: center; }
.d-bar { width: 50%; max-width: 11px; border-radius: 2px 2px 0 0; }
.d-bar.in { background: linear-gradient(#42c48a,#2f9e6c); }
.d-bar.out { background: linear-gradient(#45b6f2,#1f86c4); }
.d-label { font-size: 9px; color: #909399; margin-top: 5px; transform: scale(.9); white-space: nowrap; }

/* 排行 */
.rank-list { display: flex; flex-direction: column; gap: 10px; }
.rank-row { display: flex; align-items: center; gap: 9px; font-size: 12.5px; }
.rank-no { width: 20px; height: 20px; border-radius: 50%; background: #f0f2f5; color: #909399; font-size: 11px;
           display: flex; align-items: center; justify-content: center; flex: none; font-weight: 600; }
.rank-no.n1 { background: #f5c04a; color: #fff; }
.rank-no.n2 { background: #c0c7d1; color: #fff; }
.rank-no.n3 { background: #d99a6c; color: #fff; }
.rank-name { width: 110px; color: #303133; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: none; }
.rank-name.mono { font-family: ui-monospace, monospace; font-size: 12px; }
.rank-track { flex: 1; height: 12px; background: #f2f4f7; border-radius: 6px; overflow: hidden; }
.rank-fill { height: 100%; border-radius: 6px; }
.rank-fill.a { background: linear-gradient(90deg,#9b8cf0,#7c6ce0); }
.rank-fill.u { background: linear-gradient(90deg,#4fd1c5,#13c2c2); }
.rank-cnt { width: 34px; text-align: right; color: #303133; font-weight: 600; }

.empty { color: #c0c4cc; font-size: 12.5px; text-align: center; padding: 20px 0; }
</style>
