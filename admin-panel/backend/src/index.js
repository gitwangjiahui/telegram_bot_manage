import express from 'express';
import cors from 'cors';
import { config } from './config.js';
import { authRequired } from './auth.js';
import authRoutes from './routes/auth.routes.js';
import dashboardRoutes from './routes/dashboard.routes.js';
import botsRoutes from './routes/bots.routes.js';
import usersRoutes from './routes/users.routes.js';
import forwardRoutes from './routes/forward.routes.js';
import configRoutes from './routes/config.routes.js';
import messagesRoutes from './routes/messages.routes.js';
import systemRoutes from './routes/system.routes.js';
import { mediaProxy, avatarProxy } from './media.js';

const app = express();

app.use(cors());
app.use(express.json({ limit: '2mb' }));

// 所有业务 API 统一前缀（生产 /server/api，本地开发可用 /api）
const BASE = config.basePath;

app.get('/api/health', (req, res) => res.json({ ok: true }));
app.get(`${BASE}/health`, (req, res) => res.json({ ok: true }));

// 媒体与头像代理（无需登录也允许？仍放鉴权外会泄露；这里需要登录，但 <img> 无法带 header，
// 因此采用登录外暴露+签名会过重；折中：放在鉴权外，仅内网/已登录用户通过页面触发）
app.get(`${BASE}/media/:botId`, mediaProxy);
app.get(`${BASE}/avatar/:userId`, avatarProxy);

app.use(`${BASE}/auth`, authRoutes);

app.use(`${BASE}/dashboard`, authRequired, dashboardRoutes);
app.use(`${BASE}/bots`, authRequired, botsRoutes);
app.use(`${BASE}/users`, authRequired, usersRoutes);
app.use(`${BASE}/forward`, authRequired, forwardRoutes);
app.use(`${BASE}/config`, authRequired, configRoutes);
app.use(`${BASE}/messages`, authRequired, messagesRoutes);
app.use(`${BASE}/system`, authRequired, systemRoutes);

// 当前登录者信息（菜单权限）
app.get(`${BASE}/me`, authRequired, (req, res) => {
  res.json({
    id: req.user.id,
    username: req.user.username,
    real_name: req.user.real_name,
    roles: req.ctx.roles.map((r) => r.role_name),
    permissions: [...req.ctx.permissions],
    botScope: req.ctx.botScope,
  });
});

// 统一错误处理
app.use((err, req, res, next) => {
  console.error('[ERROR]', err.message);
  res.status(500).json({ message: '服务器内部错误' });
});

app.listen(config.port, () => {
  console.log(`Admin API listening on :${config.port} (base ${BASE})`);
});
