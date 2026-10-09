<template>
  <div class="page-card">
    <el-card>
      <div class="toolbar">
        <el-select v-model="query.bot_id" placeholder="全部机器人" clearable style="width: 180px"
                   @change="reload">
          <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
        </el-select>
        <el-input v-model="query.keyword" placeholder="搜索 ID/用户名/姓名" clearable
                  style="width: 240px" @keyup.enter="reload" @clear="reload" />
        <el-button type="primary" @click="reload">查询</el-button>
        <el-button v-if="auth.has('user:admin:manage')" type="warning" @click="adminDialog = true">
          机器人管理员
        </el-button>
      </div>

      <el-table :data="list" stripe v-loading="loading">
        <el-table-column prop="user_id" label="TG 用户 ID" width="140" />
        <el-table-column label="用户名" width="140">
          <template #default="{ row }">{{ row.username ? '@' + row.username : '无' }}</template>
        </el-table-column>
        <el-table-column label="姓名">
          <template #default="{ row }">
            {{ [row.first_name, row.last_name].filter(Boolean).join(' ') || '无' }}
          </template>
        </el-table-column>
        <el-table-column prop="bot_name" label="所属机器人" width="120" />
        <el-table-column prop="verified_at" label="验证时间" width="180" />
        <el-table-column label="操作" width="130">
          <template #default="{ row }">
            <el-popconfirm title="取消验证？用户需重新验证" @confirm="resetVerify(row)">
              <template #reference>
                <el-button size="small" type="danger" v-if="auth.has('user:verify:manage')">
                  取消验证
                </el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination style="margin-top: 16px; justify-content: flex-end" background layout="total, prev, pager, next"
                     :total="total" :page-size="query.page_size" :current-page="query.page"
                     @current-change="onPage" />
    </el-card>

    <!-- 机器人管理员管理 -->
    <el-dialog v-model="adminDialog" title="机器人管理员" width="720px">
      <el-button type="primary" size="small" @click="addAdminForm = {}; addAdminDialog = true">
        添加管理员
      </el-button>
      <el-table :data="adminList" stripe style="margin-top: 12px">
        <el-table-column prop="bot_name" label="机器人" width="110" />
        <el-table-column prop="admin_id" label="TG ID" width="140" />
        <el-table-column label="用户名/姓名">
          <template #default="{ row }">
            {{ row.username ? '@' + row.username : [row.first_name, row.last_name].filter(Boolean).join(' ') }}
          </template>
        </el-table-column>
        <el-table-column label="类型" width="100">
          <template #default="{ row }">
            <el-tag :type="row.admin_type === 'super' ? 'danger' : ''">
              {{ row.admin_type === 'super' ? '超级' : '普通' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <el-popconfirm title="确认移除？" @confirm="removeAdmin(row)">
              <template #reference><el-button size="small" type="danger">移除</el-button></template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <el-dialog v-model="addAdminDialog" title="添加机器人管理员" width="420px">
      <el-form :model="addAdminForm" label-width="90px">
        <el-form-item label="机器人" required>
          <el-select v-model="addAdminForm.bot_id" style="width: 100%">
            <el-option v-for="b in bots" :key="b.id" :label="b.bot_name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="TG 用户ID" required>
          <el-input v-model.number="addAdminForm.admin_id" />
        </el-form-item>
        <el-form-item label="类型">
          <el-radio-group v-model="addAdminForm.admin_type">
            <el-radio value="normal">普通管理员</el-radio>
            <el-radio value="super">超级管理员</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="addAdminDialog = false">取消</el-button>
        <el-button type="primary" @click="submitAddAdmin">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const bots = ref([]);
const list = ref([]);
const total = ref(0);
const loading = ref(false);
const query = reactive({ bot_id: undefined, keyword: '', page: 1, page_size: 20 });

const adminDialog = ref(false);
const adminList = ref([]);
const addAdminDialog = ref(false);
const addAdminForm = reactive({ bot_id: null, admin_id: null, admin_type: 'normal' });

async function load() {
  loading.value = true;
  try {
    const r = await api.get('/users', { params: query });
    list.value = r.list;
    total.value = r.total;
  } finally { loading.value = false; }
}
function reload() { query.page = 1; load(); }
function onPage(p) { query.page = p; load(); }

async function resetVerify(row) {
  await api.delete(`/users/${row.bot_name}/verify/${row.user_id}`);
  ElMessage.success('已取消验证');
  load();
}

async function loadAdmins() {
  adminList.value = await api.get('/users/admins/all');
}
async function submitAddAdmin() {
  if (!addAdminForm.bot_id || !addAdminForm.admin_id) return ElMessage.warning('请填写完整');
  await api.post('/users/admins', { ...addAdminForm });
  ElMessage.success('已添加');
  addAdminDialog.value = false;
  loadAdmins();
}
async function removeAdmin(row) {
  await api.delete(`/users/admins/${row.rela_id}`);
  ElMessage.success('已移除');
  loadAdmins();
}

onMounted(async () => {
  bots.value = await api.get('/bots');
  await load();
});
</script>
