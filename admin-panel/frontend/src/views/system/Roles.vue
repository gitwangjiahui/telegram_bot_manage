<template>
  <div class="page-card">
    <el-card>
      <div class="toolbar">
        <el-button type="primary" :icon="Plus" @click="openAdd">新增角色</el-button>
      </div>
      <el-table :data="list" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="role_code" label="角色编码" width="180" />
        <el-table-column prop="role_name" label="角色名称" width="160" />
        <el-table-column prop="description" label="描述" />
        <el-table-column label="数据范围" width="200">
          <template #default="{ row }">
            <el-tag v-if="row.bot_ids.length === 0" type="success">全部机器人</el-tag>
            <el-tag v-else type="warning">{{ row.bot_ids.length }} 个机器人</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180">
          <template #default="{ row }">
            <el-button size="small" type="primary" :disabled="row.is_builtin"
                       @click="openEdit(row)">编辑</el-button>
            <el-popconfirm title="确认删除？" @confirm="onDelete(row)">
              <template #reference>
                <el-button size="small" type="danger" :disabled="row.is_builtin">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑角色' : '新增角色'" width="720px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="角色编码" required>
          <el-input v-model="form.role_code" :disabled="!!form.id" placeholder="如 customer_service" />
        </el-form-item>
        <el-form-item label="角色名称" required>
          <el-input v-model="form.role_name" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="form.description" />
        </el-form-item>
        <el-form-item label="功能权限">
          <el-checkbox v-model="checkAll" :indeterminate="indeterminate"
                       @change="onCheckAll">全选</el-checkbox>
          <el-divider style="margin: 8px 0" />
          <template v-for="(group, module) in groupedPerms" :key="module">
            <div class="perm-group">
              <div class="perm-module">{{ moduleNames[module] || module }}</div>
              <el-checkbox-group v-model="form.perm_ids">
                <el-checkbox v-for="p in group" :key="p.id" :value="p.id"
                             :label="p.perm_name" />
              </el-checkbox-group>
            </div>
          </template>
        </el-form-item>
        <el-form-item label="数据范围">
          <el-checkbox v-model="allBots">全部机器人</el-checkbox>
          <el-checkbox-group v-if="!allBots" v-model="form.bot_ids" style="margin-top: 8px">
            <el-checkbox v-for="b in bots" :key="b.id" :value="b.id"
                         :label="b.bot_name" />
          </el-checkbox-group>
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
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../../api';

const list = ref([]);
const perms = ref([]);
const bots = ref([]);
const loading = ref(false);
const dialog = ref(false);
const form = reactive({
  id: null, role_code: '', role_name: '', description: '',
  perm_ids: [], bot_ids: [],
});

const moduleNames = {
  dashboard: '仪表盘', bot: '机器人管理', user: '用户管理',
  forward: '转发设置', config: '配置管理', message: '历史消息', system: '系统管理',
};

const groupedPerms = computed(() => {
  const map = {};
  for (const p of perms.value) {
    (map[p.module] ||= []).push(p);
  }
  return map;
});

const allPermIds = computed(() => perms.value.map((p) => p.id));
const checkAll = ref(false);
const indeterminate = ref(false);
watch(() => form.perm_ids, (v) => {
  checkAll.value = v.length === allPermIds.value.length && v.length > 0;
  indeterminate.value = v.length > 0 && v.length < allPermIds.value.length;
}, { deep: true });
function onCheckAll(val) {
  form.perm_ids = val ? [...allPermIds.value] : [];
  indeterminate.value = false;
}

const allBots = ref(true);
watch(allBots, (v) => { if (v) form.bot_ids = []; });

async function load() {
  loading.value = true;
  try {
    list.value = await api.get('/system/roles');
    perms.value = await api.get('/system/permissions');
    bots.value = await api.get('/bots');
  } finally { loading.value = false; }
}

function openAdd() {
  Object.assign(form, { id: null, role_code: '', role_name: '', description: '', perm_ids: [], bot_ids: [] });
  allBots.value = true;
  dialog.value = true;
}
function openEdit(row) {
  Object.assign(form, {
    id: row.id, role_code: row.role_code, role_name: row.role_name,
    description: row.description, perm_ids: [...row.perm_ids], bot_ids: [...row.bot_ids],
  });
  allBots.value = row.bot_ids.length === 0;
  dialog.value = true;
}
async function onSave() {
  if (!form.role_code || !form.role_name) return ElMessage.warning('编码和名称必填');
  const payload = {
    role_name: form.role_name, description: form.description,
    perm_ids: form.perm_ids, bot_ids: allBots.value ? [] : form.bot_ids,
  };
  if (form.id) await api.put(`/system/roles/${form.id}`, payload);
  else await api.post('/system/roles', { role_code: form.role_code, ...payload });
  ElMessage.success('保存成功');
  dialog.value = false;
  load();
}
async function onDelete(row) {
  await api.delete(`/system/roles/${row.id}`);
  ElMessage.success('已删除');
  load();
}

onMounted(load);
</script>

<style scoped>
.perm-group { margin-bottom: 10px; }
.perm-module {
  font-size: 13px; font-weight: 600; color: #606266; margin-bottom: 4px;
}
</style>
