// 看板统计专用 WebSocket：连 Node 后端 /server/api/ws（与根 /ws 的 botd 控制通道分离）。
// 服务端按频道推 dash_live（3s）/ dash_metrics（15s），本端按帧回调，只做局部更新。
// 自动指数退避重连；每次（重）连都从 localStorage 取最新 token（兼容滑动续期）。
const handlers = new Map(); // type -> Set<fn>
let ws = null;
let retry = 0;
let manualClose = false;
let reconnectTimer = null;

export function onDash(type, fn) {
  if (!handlers.has(type)) handlers.set(type, new Set());
  handlers.get(type).add(fn);
  return () => handlers.get(type)?.delete(fn);
}

function dispatch(frame) {
  if (!frame || !frame.type) return;
  handlers.get(frame.type)?.forEach((fn) => {
    try { fn(frame.data, frame); } catch (e) { console.error('dash ws handler error', e); }
  });
}

export function startDashWs() {
  const token = localStorage.getItem('token');
  if (!token) return;
  if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;

  manualClose = false;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const url = `${proto}://${location.host}/server/api/ws?token=${encodeURIComponent(token)}`;

  try {
    ws = new WebSocket(url);
  } catch {
    scheduleReconnect();
    return;
  }

  ws.onopen = () => { retry = 0; dispatch({ type: 'dash_status', data: { connected: true } }); };
  ws.onmessage = (ev) => {
    try { dispatch(JSON.parse(ev.data)); } catch { /* ignore */ }
  };
  ws.onclose = () => {
    ws = null;
    dispatch({ type: 'dash_status', data: { connected: false } });
    if (!manualClose) scheduleReconnect();
  };
  ws.onerror = () => { try { ws?.close(); } catch { /* noop */ } };
}

function scheduleReconnect() {
  if (reconnectTimer || !localStorage.getItem('token')) return;
  const delay = Math.min(1000 * 2 ** retry, 15000);
  retry += 1;
  reconnectTimer = setTimeout(() => { reconnectTimer = null; startDashWs(); }, delay);
}

export function stopDashWs() {
  manualClose = true;
  if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
  try { ws?.close(); } catch { /* noop */ }
  ws = null;
}

export const dashWsConnected = () => ws?.readyState === WebSocket.OPEN;
