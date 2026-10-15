// 管理后台实时推送（Node 侧，与 Express 同端口，路径 /server/api/ws）。
// 分频道，前端按帧只更新对应区域，绝不整页刷新：
//   dash_live    每 3s  仅 Bot 实时运行卡片（顶部运行态 + 实时卡）
//   dash_metrics 每 15s 全套聚合（KPI / 24h / 30天 / 类型 / 排行 / 会话覆盖）
// 首帧 dash_metrics 与 HTTP /dashboard/metrics 同口径，保证打开即有全量数据。
import { WebSocketServer } from 'ws';
import jwt from 'jsonwebtoken';
import { config } from '../config.js';
import { one } from '../db.js';
import { loadUserContext } from '../auth.js';
import { livePayload, metricsPayload } from './metrics.js';

const LIVE_INTERVAL_MS = 3000;
const METRICS_INTERVAL_MS = 15000;
const PING_INTERVAL_MS = 30000;

export function attachDashboardWss(httpServer) {
  const wss = new WebSocketServer({ noServer: true });
  const basePath = config.basePath; // /server/api
  const wsPath = `${basePath}/ws`;

  httpServer.on('upgrade', async (req, socket, head) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      if (url.pathname !== wsPath) return; // 非本路径交给其它 upgrade 处理（直接放弃）

      const token = url.searchParams.get('token');
      let ctx0 = null;
      let user = null;
      try {
        const payload = jwt.verify(token || '', config.jwtSecret);
        user = await one('SELECT id, username, is_active FROM admin_users WHERE id = ?', [payload.sub]);
        if (!user || !user.is_active) throw new Error('inactive');
        ctx0 = await loadUserContext(user.id);
        if (!ctx0) throw new Error('no-role');
        if (!ctx0.isSuper && !ctx0.permissions.has('dashboard:view')) throw new Error('forbidden');
      } catch (e) {
        socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
        socket.destroy();
        return;
      }

      wss.handleUpgrade(req, socket, head, (ws) => {
        wss.emit('connection', ws, { user, ctx: ctx0 });
      });
    } catch (e) {
      try { socket.destroy(); } catch { /* noop */ }
    }
  });

  wss.on('connection', (ws, { ctx }) => {
    let alive = true;
    const send = (type, data) => {
      if (ws.readyState === ws.OPEN) {
        try { ws.send(JSON.stringify({ type, ts: Date.now(), data })); } catch { /* noop */ }
      }
    };

    // 首帧：全量，立即渲染
    (async () => {
      try {
        const data = await metricsPayload(ctx);
        send('dash_metrics', data);
      } catch (e) {
        send('dash_error', { message: '看板数据加载失败' });
      }
    })();

    const liveTimer = setInterval(async () => {
      try {
        const live = await livePayload(ctx);
        const running = live.filter((b) => b.is_running).length;
        send('dash_live', {
          server_time: new Date().toISOString(),
          running,
          bots: live.length,
          live,
        });
      } catch { /* 单帧失败不影响后续 */ }
    }, LIVE_INTERVAL_MS);

    const metricsTimer = setInterval(async () => {
      try {
        send('dash_metrics', await metricsPayload(ctx));
      } catch { /* noop */ }
    }, METRICS_INTERVAL_MS);

    ws.on('pong', () => { alive = true; });
    const pingTimer = setInterval(() => {
      if (!alive) { try { ws.terminate(); } catch { /* noop */ } return; }
      alive = false;
      try { ws.ping(); } catch { /* noop */ }
    }, PING_INTERVAL_MS);

    const cleanup = () => {
      clearInterval(liveTimer);
      clearInterval(metricsTimer);
      clearInterval(pingTimer);
    };
    ws.on('close', cleanup);
    ws.on('error', cleanup);
  });

  return wss;
}
