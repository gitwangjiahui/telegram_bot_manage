import { createRouter, createWebHashHistory } from 'vue-router';

const routes = [
  { path: '/login', component: () => import('../views/Login.vue'), meta: { public: true } },
  {
    path: '/',
    component: () => import('../layouts/MainLayout.vue'),
    redirect: '/dashboard',
    children: [
      { path: 'dashboard', name: '仪表盘', component: () => import('../views/Dashboard.vue'), meta: { perm: 'dashboard:view' } },
      { path: 'bots', name: '机器人管理', component: () => import('../views/Bots.vue'), meta: { perm: 'bot:view' } },
      { path: 'bots/:id', name: '机器人详情', component: () => import('../views/BotDetail.vue'), meta: { perm: 'bot:view' } },
      { path: 'users', name: '用户管理', component: () => import('../views/Users.vue'), meta: { perm: 'user:view' } },
      { path: 'admins', name: 'TG管理员', component: () => import('../views/Admins.vue'), meta: { perm: 'user:admin:manage' } },
      { path: 'config', name: '配置管理', component: () => import('../views/ConfigManage.vue'), meta: { perm: 'config:view' } },
      { path: 'messages', name: '历史消息', component: () => import('../views/Messages.vue'), meta: { perm: 'message:view' } },
      { path: 'system/admins', name: '后台账号', component: () => import('../views/system/Admins.vue'), meta: { perm: 'system:view' } },
      { path: 'system/roles', name: '角色权限', component: () => import('../views/system/Roles.vue'), meta: { perm: 'system:view' } },
    ],
  },
];

const router = createRouter({
  history: createWebHashHistory(),
  routes,
});

router.beforeEach(async (to) => {
  const token = localStorage.getItem('token');
  if (to.meta.public) return true;
  if (!token) return '/login';
  return true;
});

export default router;
