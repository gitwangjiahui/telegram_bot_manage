<template>
  <div class="page-card">
    <el-card>
      <el-alert type="info" :closable="false" style="margin-bottom: 16px"
        title="转发设置决定每个机器人把用户消息转发给哪些 TG 账号；列表中的管理员都会收到转发消息并可直接回复用户。" />
      <el-row :gutter="16">
        <el-col :span="8" v-for="bot in list" :key="bot.id">
          <el-card :header="`${bot.bot_name}（${bot.bot_username || '-'}）`" shadow="hover">
            <div v-if="bot.admins.length === 0" class="empty">暂未设置转发目标</div>
            <el-tag v-for="a in bot.admins" :key="a.admin_id" closable
                    :type="a.admin_type === 'super' ? 'danger' : 'primary'"
                    style="margin: 0 8px 8px 0"
                    @close="removeTarget(bot.id, a.admin_id)">
              {{ a.admin_id }}<span v-if="a.first_name"> · {{ a.first_name }}</span>
            </el-tag>
            <el-divider style="margin: 8px 0" />
            <div style="display: flex; gap: 8px">
              <el-input v-model.number="newIdMap[bot.id]" placeholder="接收人 TG ID" size="small" />
              <el-button size="small" type="primary" @click="addTarget(bot.id)">添加</el-button>
            </div>
          </el-card>
        </el-col>
      </el-row>
    </el-card>

    <el-card style="margin-top: 16px" header="最近转发记录">
      <el-table :data="records" stripe>
        <el-table-column prop="bot_name" label="机器人" width="110" />
        <el-table-column prop="user_id" label="用户 ID" width="140" />
        <el-table-column prop="original_msg_id" label="原消息 ID" width="130" />
        <el-table-column prop="forwarded_msg_id" label="转发消息 ID" width="130" />
        <el-table-column prop="created_at" label="时间" />
      </el-table>
    </el-card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import api from '../api';

const list = ref([]);
const records = ref([]);
const newIdMap = reactive({});

async function load() {
  list.value = await api.get('/forward');
  const r = await api.get('/forward/records', { params: { page: 1, page_size: 20 } });
  records.value = r.list;
}

async function addTarget(botId) {
  const adminId = newIdMap[botId];
  if (!adminId) return ElMessage.warning('请输入 TG ID');
  await api.post('/forward/targets', { bot_id: botId, admin_id: adminId });
  ElMessage.success('已添加，机器人下次转发即生效');
  newIdMap[botId] = null;
  load();
}

async function removeTarget(botId, adminId) {
  await api.delete(`/forward/targets/${botId}/${adminId}`);
  ElMessage.success('已移除');
  load();
}

onMounted(load);
</script>

<style scoped>
.empty { color: #909399; font-size: 13px; margin-bottom: 8px; }
</style>
