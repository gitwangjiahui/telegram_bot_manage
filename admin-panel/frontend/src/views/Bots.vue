<template>
  <div class="page-card">
    <el-card>
      <div class="toolbar">
        <el-button v-if="auth.has('bot:create')" type="primary" :icon="Plus" @click="openEdit()">新增机器人</el-button>
        <span class="spacer" />
        <el-button :icon="Refresh" @click="load">刷新</el-button>
      </div>
      <el-table :data="list" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="bot_name" label="机器人名称" />
        <el-table-column prop="bot_username" label="显示名/用户名" />
        <el-table-column prop="user_count" label="用户数" width="90" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'">
              {{ row.is_active ? '运行中' : '已停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" />
        <el-table-column label="操作" width="230">
          <template #default="{ row }">
            <el-button size="small" @click="onCheck(row)">校验Token</el-button>
            <el-button v-if="auth.has('bot:edit')" size="small" type="primary"
                       @click="openEdit(row)">编辑</el-button>
            <el-popconfirm v-if="auth.has('bot:delete')" title="确认删除？" @confirm="onDelete(row)">
              <template #reference>
                <el-button size="small" type="danger">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑机器人' : '新增机器人'" width="520px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="机器人名称" required>
          <el-input v-model="form.bot_name" placeholder="如 bot1，对应守护进程配置名" />
        </el-form-item>
        <el-form-item label="Token" :required="!form.id">
          <el-input v-model="form.api_key" type="password" show-password
                    :placeholder="form.id ? '留空则不修改' : 'BotFather 获取的 Token'" />
        </el-form-item>
        <el-form-item label="显示名">
          <el-input v-model="form.bot_username" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="onSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Plus, Refresh } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const list = ref([]);
const loading = ref(false);
const dialog = ref(false);
const form = reactive({ id: null, bot_name: '', api_key: '', bot_username: '', is_active: 1 });

async function load() {
  loading.value = true;
  try { list.value = await api.get('/bots'); }
  finally { loading.value = false; }
}

function openEdit(row) {
  Object.assign(form, {
    id: row?.id || null,
    bot_name: row?.bot_name || '',
    api_key: '',
    bot_username: row?.bot_username || '',
    is_active: row?.is_active ?? 1,
  });
  dialog.value = true;
}

async function onSave() {
  if (!form.bot_name || (!form.id && !form.api_key)) return ElMessage.warning('名称和 Token 必填');
  if (form.id) {
    await api.put(`/bots/${form.id}`, {
      bot_name: form.bot_name,
      api_key: form.api_key || undefined,
      bot_username: form.bot_username,
      is_active: form.is_active,
    });
  } else {
    await api.post('/bots', { ...form });
  }
  ElMessage.success('保存成功');
  dialog.value = false;
  load();
}

async function onDelete(row) {
  await api.delete(`/bots/${row.id}`);
  ElMessage.success('已删除');
  load();
}

async function onCheck(row) {
  try {
    const r = await api.post(`/bots/${row.id}/check`);
    ElMessage.success(`Token 有效：@${r.username}`);
  } catch (e) {
    ElMessage.error(e.message);
  }
}

onMounted(load);
</script>
