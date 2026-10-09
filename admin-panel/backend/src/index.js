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

const app = express();

app.use(cors());
app.use(express.json({ limit: '2mb' }));

app.get('/api/health', (req, res) => res.json({ ok: true }));
app.use('/api/auth', authRoutes);

app.use('/api/dashboard', authRequired, dashboardRoutes);
app.use('/api/bots', authRequired, botsRoutes);
app.use('/api/users', authRequired, usersRoutes);
app.use('/api/forward', authRequired, forwardRoutes);
app.use('/api/config', authRequired, configRoutes);
app.use('/api/messages', authRequired, messagesRoutes);
app.use('/api/system', authRequired, systemRoutes);

// 当前登录者信息（菜单权限）
app.get('/api/me', authRequired, (req, res) => {
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
  console.log(`Admin API listening on :${config.port}`);
});
