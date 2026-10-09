<template>
  <div class="page-card">
    <el-card>
      <div class="toolbar">
        <el-button type="primary" :icon="Plus" @click="openAdd">新增账号</el-button>
      </div>
      <el-table :data="list" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="username" label="账号" width="160" />
        <el-table-column prop="real_name" label="姓名" width="160" />
        <el-table-column label="角色">
          <template #default="{ row }">
            <el-tag v-for="r in row.roles" :key="r.id" style="margin-right: 6px">
              {{ r.name }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'">
              {{ row.is_active ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="last_login_at" label="最后登录" width="180" />
        <el-table-column label="操作" width="180">
          <template #default="{ row }">
            <el-button size="small" type="primary" @click="openEdit(row)">编辑</el-button>
            <el-popconfirm title="确认删除？" @confirm="onDelete(row)">
              <template #reference><el-button size="small" type="danger">删除</el-button></template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑账号' : '新增账号'" width="480px">
      <el-form :model="form" label-width="90px">
        <el-form-item label="账号" required>
          <el-input v-model="form.username" :disabled="!!form.id" />
        </el-form-item>
        <el-form-item label="姓名">
          <el-input v-model="form.real_name" />
        </el-form-item>
        <el-form-item :label="form.id ? '新密码' : '密码'" :required="!form.id">
          <el-input v-model="form.password" type="password" show-password
                    :placeholder="form.id ? '留空则不修改' : ''" />
        </el-form-item>
        <el-form-item label="角色">
          <el-select v-model="form.role_ids" multiple style="width: 100%">
            <el-option v-for="r in roles" :key="r.id" :label="r.role_name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="启用" v-if="form.id">
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
import { Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import api from '../../api';

const list = ref([]);
const roles = ref([]);
const loading = ref(false);
const dialog = ref(false);
const form = reactive({
  id: null, username: '', real_name: '', password: '',
  role_ids: [], is_active: 1,
});

async function load() {
  loading.value = true;
  try {
    list.value = await api.get('/system/admins');
    roles.value = await api.get('/system/roles');
  } finally { loading.value = false; }
}

function openAdd() {
  Object.assign(form, { id: null, username: '', real_name: '', password: '', role_ids: [], is_active: 1 });
  dialog.value = true;
}
function openEdit(row) {
  Object.assign(form, {
    id: row.id, username: row.username, real_name: row.real_name,
    password: '', role_ids: row.roles.map((r) => r.id), is_active: row.is_active,
  });
  dialog.value = true;
}
async function onSave() {
  if (!form.username || (!form.id && !form.password)) {
    return ElMessage.warning('账号和密码必填');
  }
  if (form.id) {
    await api.put(`/system/admins/${form.id}`, {
      real_name: form.real_name,
      password: form.password || undefined,
      role_ids: form.role_ids,
      is_active: form.is_active,
    });
  } else {
    await api.post('/system/admins', {
      username: form.username, password: form.password,
      real_name: form.real_name, role_ids: form.role_ids,
    });
  }
  ElMessage.success('保存成功');
  dialog.value = false;
  load();
}
async function onDelete(row) {
  await api.delete(`/system/admins/${row.id}`);
  ElMessage.success('已删除');
  load();
}

onMounted(load);
</script>
