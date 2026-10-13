<template>
  <div class="page-card">
    <el-card>
      <div class="toolbar">
        <el-button v-if="auth.has('config:edit')" type="primary" :icon="Plus" @click="openAdd">
          新增配置
        </el-button>
        <el-select v-model="scope" style="width: 180px">
          <el-option label="全局" value="global" />
          <el-option label="全部（含各机器人）" value="all" />
          <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
        </el-select>
        <span class="tip" v-if="scope !== 'global'">
          {{ scope === 'all' ? '当前显示全局 + 各机器人配置' : '当前仅显示该机器人配置' }}
        </span>
      </div>

      <el-table :data="filtered" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="范围" width="110">
          <template #default="{ row }">
            <el-tag v-if="row.bot_id === null">全局</el-tag>
            <el-tag v-else type="warning">{{ row.bot_name }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="config_key" label="配置键" width="240" show-overflow-tooltip />

        <el-table-column label="配置值" min-width="240">
          <template #default="{ row }">
            <el-input
              v-if="canEdit && editId === row.id && editField === 'config_value'"
              v-model="editVal" type="textarea" :autosize="{ minRows: 1, maxRows: 6 }"
              autofocus
              @blur="saveCell(row)" @keyup.enter="saveCell(row)" @keyup.esc="cancelEdit" />
            <div v-else class="cell-edit" :class="{ empty: !row.config_value, disabled: !canEdit }"
                 :title="canEdit ? '点击修改' : ''" @click="canEdit && startEdit(row, 'config_value')">
              {{ row.config_value || '空，点击设置' }}
            </div>
          </template>
        </el-table-column>

        <el-table-column label="说明" width="260">
          <template #default="{ row }">
            <el-input
              v-if="canEdit && editId === row.id && editField === 'description'"
              v-model="editVal" type="textarea" :autosize="{ minRows: 1, maxRows: 4 }"
              autofocus
              @blur="saveCell(row)" @keyup.enter="saveCell(row)" @keyup.esc="cancelEdit" />
            <div v-else class="cell-edit" :class="{ empty: !row.description, disabled: !canEdit }"
                 :title="canEdit ? '点击修改' : ''" @click="canEdit && startEdit(row, 'description')">
              {{ row.description || '空，点击设置' }}
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="updated_at" label="更新时间" width="180" show-overflow-tooltip />
        <el-table-column v-if="canEdit" label="操作" width="90">
          <template #default="{ row }">
            <el-popconfirm title="确认删除？删除后机器人回退继承全局配置" @confirm="onDelete(row)">
              <template #reference><el-button size="small" type="danger">删</el-button></template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" title="新增配置" width="560px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="所属范围">
          <el-select v-model="form.bot_id" placeholder="全局配置（留空）">
            <el-option :value="null" label="全局" />
            <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="配置键" required>
          <el-input v-model="form.config_key" placeholder="保存后键不可修改" />
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
const canEdit = computed(() => auth.has('config:edit'));

const bots = ref([]);
const list = ref([]);
const loading = ref(false);

// 默认只看全局；下拉不可清除（无 clearable），切到“全部”或具体机器人才显示其配置
const scope = ref('global');

const dialog = ref(false);
const form = reactive({ bot_id: null, config_key: '', config_value: '', description: '' });

// 行内即点即改状态
const editId = ref(null);
const editField = ref('');
const editVal = ref('');
let saved = false;

const filtered = computed(() => list.value.filter((r) => {
  if (scope.value === 'global') return r.bot_id === null;
  if (scope.value === 'all') return true;
  return r.bot_id === scope.value;
}));

async function load() {
  loading.value = true;
  try { list.value = await api.get('/config'); }
  finally { loading.value = false; }
}

function openAdd() {
  // 新增范围默认跟随当前筛选（选了具体 bot 则预选它）
  Object.assign(form, {
    id: null,
    bot_id: scope.value === 'global' || scope.value === 'all' ? null : scope.value,
    config_key: '', config_value: '', description: '',
  });
  dialog.value = true;
}

async function onSave() {
  if (!form.config_key) return ElMessage.warning('配置键必填');
  await api.post('/config', {
    bot_id: form.bot_id, config_key: form.config_key,
    config_value: form.config_value, description: form.description,
  });
  ElMessage.success('保存成功');
  dialog.value = false;
  load();
}

function startEdit(row, field) {
  editId.value = row.id;
  editField.value = field;
  editVal.value = row[field] ?? '';
  saved = false;
}

function cancelEdit() {
  editId.value = null;
  editField.value = '';
}

async function saveCell(row) {
  if (editId.value !== row.id || saved) return;
  saved = true;
  const patch = {
    config_value: editField.value === 'config_value' ? editVal.value : row.config_value,
    description: editField.value === 'description' ? editVal.value : row.description,
  };
  cancelEdit();
  try {
    await api.put(`/config/${row.id}`, patch);
    ElMessage.success('已保存');
    load();
  } catch { saved = false; }
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

<style scoped>
.toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.tip { font-size: 12px; color: #909399; }
.cell-edit {
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-height: 22px;
  padding: 2px 6px;
  border-radius: 4px;
  border: 1px solid transparent;
}
.cell-edit:hover { border-color: #c6d3e8; background: #f5f9ff; }
.cell-edit.empty { color: #b6bcc7; font-style: normal; }
.cell-edit.disabled { cursor: default; }
.cell-edit.disabled:hover { border-color: transparent; background: transparent; }
</style>
