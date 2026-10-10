<template>
  <div class="page-card">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>TG 管理员</span>
          <div>
            <el-button @click="loadProfiles"><el-icon><Refresh /></el-icon>刷新</el-button>
            <el-button type="primary" @click="openAdd"><el-icon><Plus /></el-icon>添加管理员</el-button>
          </div>
        </div>
      </template>

      <el-alert type="info" :closable="false" style="margin-bottom: 14px"
        title="超级管理员：每个机器人仅可有一个，接收全部用户转发并可使用机器人命令；普通管理员只接收转发并回复。此处修改对机器人进程实时生效（进程启动时从 bot_admin_rela 读取）。" />

      <el-table :data="profiles" v-loading="loading" stripe>
        <el-table-column label="TG ID" prop="id" width="120" />
        <el-table-column label="头像" width="70">
          <template #default="{ row }">
            <el-avatar :size="38" :src="`/server/api/avatar/${row.id}`">
              {{ (row.first_name || row.id).toString().slice(0, 1) }}
            </el-avatar>
          </template>
        </el-table-column>
        <el-table-column label="昵称" min-width="140">
          <template #default="{ row }">
            {{ [row.first_name, row.last_name].filter(Boolean).join(' ') || '—' }}
          </template>
        </el-table-column>
        <el-table-column label="用户名" width="140">
          <template #default="{ row }">
            <el-link v-if="row.username" type="primary"
                     :href="`https://t.me/${row.username}`" target="_blank">
              @{{ row.username }}
            </el-link>
            <span v-else>—</span>
          </template>
        </el-table-column>
        <el-table-column label="绑定机器人 / 角色" min-width="280">
          <template #default="{ row }">
            <div v-if="row.bindings.length" class="bind-list">
              <el-tag v-for="b in row.bindings" :key="b.bot_id"
                      :type="b.admin_type === 'super' ? 'danger' : 'primary'"
                      class="bind-tag" closable @close="unbind(row, b)">
                {{ b.bot_name }} · {{ b.admin_type === 'super' ? '超级' : '普通' }}
              </el-tag>
            </div>
            <span v-else class="muted">未绑定</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button size="small" @click="openBind(row)">绑定 Bot</el-button>
            <el-button size="small" @click="openEdit(row)">编辑</el-button>
            <el-popconfirm title="删除管理员将同时解除全部绑定，确定？"
                           @confirm="remove(row)">
              <template #reference>
                <el-button size="small" type="danger">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 添加 -->
    <el-dialog v-model="addVisible" title="添加 TG 管理员" width="480px">
      <el-form :model="form" label-width="90px">
        <el-form-item label="TG ID" required>
          <el-input v-model="form.admin_id" placeholder="数字 ID，如 9549987304" />
        </el-form-item>
        <el-form-item>
          <el-button @click="fetchInfo">
            <el-icon><Search /></el-icon>通过机器人获取资料
          </el-button>
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="form.first_name" />
        </el-form-item>
        <el-form-item label="姓">
          <el-input v-model="form.last_name" />
        </el-form-item>
        <el-form-item label="用户名">
          <el-input v-model="form.username" placeholder="不带 @" />
        </el-form-item>
        <el-form-item label="绑定机器人">
          <el-select v-model="form.bot_id" clearable placeholder="可暂不绑定" style="width: 100%">
            <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="角色">
          <el-radio-group v-model="form.admin_type" :disabled="!form.bot_id">
            <el-radio value="normal">普通管理员</el-radio>
            <el-radio value="super">超级管理员（唯一）</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="addVisible = false">取消</el-button>
        <el-button type="primary" @click="submitAdd">确定</el-button>
      </template>
    </el-dialog>

    <!-- 绑定 -->
    <el-dialog v-model="bindVisible" title="绑定到机器人" width="420px">
      <el-form :model="bindForm" label-width="90px">
        <el-form-item label="机器人">
          <el-select v-model="bindForm.bot_id" placeholder="选择机器人" style="width: 100%">
            <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="角色">
          <el-radio-group v-model="bindForm.admin_type">
            <el-radio value="normal">普通管理员</el-radio>
            <el-radio value="super">超级管理员（唯一）</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bindVisible = false">取消</el-button>
        <el-button type="primary" @click="submitBind">确定</el-button>
      </template>
    </el-dialog>

    <!-- 编辑 -->
    <el-dialog v-model="editVisible" title="编辑资料" width="420px">
      <el-form :model="editForm" label-width="90px">
        <el-form-item label="昵称"><el-input v-model="editForm.first_name" /></el-form-item>
        <el-form-item label="姓"><el-input v-model="editForm.last_name" /></el-form-item>
        <el-form-item label="用户名"><el-input v-model="editForm.username" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" @click="submitEdit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Plus, Refresh, Search } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../api';

const loading = ref(false);
const profiles = ref([]);
const bots = ref([]);

const addVisible = ref(false);
const bindVisible = ref(false);
const editVisible = ref(false);

const emptyForm = () => ({
  admin_id: '', first_name: '', last_name: '', username: '',
  bot_id: null, admin_type: 'normal',
});
const form = reactive(emptyForm());
const bindForm = reactive({ admin_id: null, bot_id: null, admin_type: 'normal' });
const editForm = reactive({ id: null, first_name: '', last_name: '', username: '' });

async function loadProfiles() {
  loading.value = true;
  try {
    profiles.value = await api.get('/users/admins/profiles');
  } finally { loading.value = false; }
}

function openAdd() {
  Object.assign(form, emptyForm());
  addVisible.value = true;
}

async function fetchInfo() {
  if (!form.admin_id || !form.bot_id) {
    ElMessage.warning('请先填写 TG ID 并选择一个机器人（用于调用 TG 接口）');
    return;
  }
  try {
    const info = await api.get('/users/admins/fetch-info',
      { admin_id: form.admin_id, bot_id: form.bot_id });
    form.first_name = info.first_name || '';
    form.last_name = info.last_name || '';
    form.username = info.username || '';
    ElMessage.success('已获取资料');
  } catch (e) { ElMessage.error(e.message || '获取失败'); }
}

async function submitAdd() {
  if (!form.admin_id) return ElMessage.warning('TG ID 必填');
  await api.post('/users/admins', { ...form, admin_id: Number(form.admin_id) });
  ElMessage.success('已添加');
  addVisible.value = false;
  loadProfiles();
}

function openBind(row) {
  Object.assign(bindForm, { admin_id: row.id, bot_id: null, admin_type: 'normal' });
  bindVisible.value = true;
}

async function submitBind() {
  if (!bindForm.bot_id) return ElMessage.warning('请选择机器人');
  await api.post('/users/admins/bind', { ...bindForm });
  ElMessage.success('已绑定');
  bindVisible.value = false;
  loadProfiles();
}

async function unbind(row, b) {
  // 需要 rela_id；profiles 绑定项没带，调 all 接口拿
  const all = await api.get('/users/admins/all');
  const rela = all.find((x) => x.admin_id === row.id && x.bot_id === b.bot_id);
  if (rela) {
    await api.del(`/users/admins/rela/${rela.rela_id}`);
    ElMessage.success('已解绑');
    loadProfiles();
  }
}

function openEdit(row) {
  Object.assign(editForm, {
    id: row.id, first_name: row.first_name || '',
    last_name: row.last_name || '', username: row.username || '',
  });
  editVisible.value = true;
}

async function submitEdit() {
  await api.put(`/users/admins/${editForm.id}`, {
    first_name: editForm.first_name || null,
    last_name: editForm.last_name || null,
    username: editForm.username || null,
  });
  ElMessage.success('已保存');
  editVisible.value = false;
  loadProfiles();
}

async function remove(row) {
  await api.del(`/users/admins/${row.id}`);
  ElMessage.success('已删除');
  loadProfiles();
}

onMounted(async () => {
  bots.value = await api.get('/bots', { page_size: 100 });
  bots.value = bots.value.list || bots.value;
  loadProfiles();
});
</script>

<style scoped>
.card-header { display: flex; justify-content: space-between; align-items: center; }
.bind-list { display: flex; flex-wrap: wrap; gap: 6px; }
.bind-tag { margin: 0; }
.muted { color: #909399; font-size: 13px; }
</style>
