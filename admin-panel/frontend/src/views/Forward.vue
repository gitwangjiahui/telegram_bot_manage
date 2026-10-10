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

    <el-card style="margin-top: 16px">
      <template #header>
        <div class="rec-header">
          <span>最近转发记录（20 条）</span>
          <el-radio-group v-model="botFilter" size="small" @change="loadRecords">
            <el-radio-button :value="null">全部</el-radio-button>
            <el-radio-button v-for="b in list" :key="b.id" :value="b.id">{{ b.bot_name }}</el-radio-button>
          </el-radio-group>
        </div>
      </template>

      <el-table :data="records" stripe>
        <el-table-column label="用户" min-width="220">
          <template #default="{ row }">
            <div class="user-cell">
              <el-avatar :size="38" :src="row.avatar_url">
                {{ displayName(row).slice(0, 1) }}
              </el-avatar>
              <div class="user-meta">
                <div class="user-name">{{ displayName(row) }}</div>
                <div class="user-sub">
                  <span>ID: {{ row.user_id }}</span>
                  <span v-if="row.username">@{{ row.username }}</span>
                </div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="bot_name" label="机器人" width="90" />
        <el-table-column label="消息内容" min-width="260">
          <template #default="{ row }">
            <div class="content-cell">
              <el-image v-if="row.thumb_file_id" class="thumb"
                :src="`/server/api/media/${row.bot_id}?file_id=${row.thumb_file_id}`"
                :preview-src-list="[`/server/api/media/${row.bot_id}?file_id=${row.thumb_file_id}`]"
                preview-teleported fit="cover" />
              <el-tag v-if="row.msg_type && row.msg_type !== 'text'" size="small" type="info"
                      style="margin-right: 6px">{{ typeNames[row.msg_type] || row.msg_type }}</el-tag>
              <span class="content-text">{{ row.content || (row.msg_type === 'text' ? '' : '非文本消息') }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="转发时间" width="170" />
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
const botFilter = ref(null);

const typeNames = {
  photo: '图片', video: '视频', voice: '语音', document: '文件',
  sticker: '贴纸', audio: '音乐', animation: '动图', video_note: '视频消息',
};

function displayName(row) {
  return [row.first_name, row.last_name].filter(Boolean).join(' ')
    || row.username || String(row.user_id);
}

async function load() {
  list.value = await api.get('/forward');
  await loadRecords();
}

async function loadRecords() {
  records.value = await api.get('/forward/records', {
    params: botFilter.value ? { bot_id: botFilter.value } : {},
  });
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
.rec-header { display: flex; justify-content: space-between; align-items: center; }
.user-cell { display: flex; align-items: center; gap: 10px; }
.user-name { font-weight: 600; font-size: 14px; color: #303133; }
.user-sub { font-size: 12px; color: #909399; display: flex; gap: 10px; margin-top: 2px; }
.content-cell { display: flex; align-items: center; gap: 8px; }
.thumb { width: 46px; height: 46px; border-radius: 6px; flex-shrink: 0; }
.content-text {
  color: #303133; font-size: 13px;
  overflow: hidden; text-overflow: ellipsis;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
</style>
