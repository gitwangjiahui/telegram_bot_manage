<template>
  <div class="page-card">
    <el-row :gutter="16">
      <el-col :span="6" v-for="card in cards" :key="card.label">
        <el-card shadow="hover">
          <div class="stat">
            <el-icon :size="40" :color="card.color"><component :is="card.icon" /></el-icon>
            <div>
              <div class="num">{{ stats[card.key] ?? '-' }}</div>
              <div class="label">{{ card.label }}</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-card style="margin-top: 16px" header="近 7 天消息趋势">
      <el-table :data="trend" stripe>
        <el-table-column prop="day" label="日期" />
        <el-table-column prop="inbound" label="用户上行" />
        <el-table-column prop="outbound" label="管理员回复" />
      </el-table>
      <el-empty v-if="trend.length === 0" description="暂无数据（接入消息归档后显示）" />
    </el-card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api from '../api';

const stats = reactive({});
const trend = ref([]);

const cards = [
  { key: 'bot_count', label: '机器人数量', icon: 'Monitor', color: '#2AABEE' },
  { key: 'user_count', label: '验证用户数', icon: 'User', color: '#67C23A' },
  { key: 'message_count', label: '归档消息数', icon: 'ChatLineRound', color: '#E6A23C' },
  { key: 'forward_count', label: '转发记录数', icon: 'Switch', color: '#F56C6C' },
];

onMounted(async () => {
  Object.assign(stats, await api.get('/dashboard'));
  trend.value = await api.get('/dashboard/trend');
});
</script>

<style scoped>
.stat { display: flex; align-items: center; gap: 16px; }
.num { font-size: 26px; font-weight: 700; color: #303133; }
.label { color: #909399; font-size: 13px; margin-top: 4px; }
</style>
