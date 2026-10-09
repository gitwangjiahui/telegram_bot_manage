<template>
  <div class="page-card">
    <el-card>
      <div class="toolbar">
        <el-button v-if="auth.has('config:edit')" type="primary" :icon="Plus" @click="openAdd">
          新增配置
        </el-button>
        <el-radio-group v-model="scopeFilter" @change="load">
          <el-radio-button label="all">全部</el-radio-button>
          <el-radio-button label="global">仅全局</el-radio-button>
        </el-radio-group>
        <el-select v-model="botFilter" placeholder="按机器人筛选" clearable style="width: 160px"
                   @change="load">
          <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
        </el-select>
      </div>

      <el-table :data="filtered" stripe v-loading="loading">
        <el-table-column label="范围" width="110">
          <template #default="{ row }">
            <el-tag v-if="row.bot_id === null">全局</el-tag>
            <el-tag v-else type="warning">{{ row.bot_name }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="config_key" label="配置键" width="240" />
        <el-table-column prop="config_value" label="配置值" show-overflow-tooltip />
        <el-table-column prop="description" label="说明" width="260" show-overflow-tooltip />
        <el-table-column prop="updated_at" label="更新时间" width="180" />
        <el-table-column v-if="auth.has('config:edit')" label="操作" width="150">
          <template #default="{ row }">
            <el-button size="small" type="primary" @click="openEdit(row)">编辑</el-button>
            <el-popconfirm title="确认删除？" @confirm="onDelete(row)">
              <template #reference><el-button size="small" type="danger">删</el-button></template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑配置' : '新增配置'" width="560px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="所属范围">
          <el-select v-model="form.bot_id" clearable placeholder="全局配置（留空）">
            <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="配置键" required>
          <el-input v-model="form.config_key" :disabled="!!form.id" />
        </el-form-item>
        <el-form-item label="配置值">
          <el-input v-model="form.config_value" type="textarea" :rows="4" />
        </el-form-item>
        <el-form-item label="说明">
          <el-input v-model="form.description" />
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
import { computed, onMounted, reactive, ref } from 'vue';
import { Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const bots = ref([]);
const list = ref([]);
const loading = ref(false);
const scopeFilter = ref('all');
const botFilter = ref(null);
const dialog = ref(false);
const form = reactive({ id: null, bot_id: null, config_key: '', config_value: '', description: '' });

const filtered = computed(() => list.value.filter((r) => {
  if (scopeFilter.value === 'global' && r.bot_id !== null) return false;
  if (botFilter.value && r.bot_id !== botFilter.value) return false;
  return true;
}));

async function load() {
  loading.value = true;
  try { list.value = await api.get('/config'); }
  finally { loading.value = false; }
}

function openAdd() {
  Object.assign(form, { id: null, bot_id: null, config_key: '', config_value: '', description: '' });
  dialog.value = true;
}
function openEdit(row) {
  Object.assign(form, { ...row });
  dialog.value = true;
}
async function onSave() {
  if (!form.config_key) return ElMessage.warning('配置键必填');
  if (form.id) {
    await api.put(`/config/${form.id}`, { config_value: form.config_value, description: form.description });
  } else {
    await api.post('/config', {
      bot_id: form.bot_id, config_key: form.config_key,
      config_value: form.config_value, description: form.description,
    });
  }
  ElMessage.success('保存成功');
  dialog.value = false;
  load();
}
async function onDelete(row) {
  await api.delete(`/config/${row.id}`);
  ElMessage.success('已删除');
  load();
}

onMounted(async () => {
  bots.value = await api.get('/bots');
  await load();
});
</script>
