// 全局 WebSocket 单例：连接 botd（经宿主 Nginx /ws 反代）。
// - 自动指数退避重连
// - on(type, fn) 订阅服务端帧（hello / bot_lifecycle / bot_status / message_new ...）
// - JWT 通过 ?token= 传递（与 Node 后端共用同一 JWT_SECRET）
import { reactive } from 'vue';

export const wsState = reactive({ connected: false });

const handlers = new Map(); // type -> Set<fn>
let ws = null;
let retry = 0;
let closedManually = false;
let reconnectTimer = null;

// 请求-应答：等待 botd 回 send_message_result（按 client_msg_id 匹配）。
const pendingReplies = new Map(); // client_msg_id -> {resolve, timer}
let replySeq = 0;

export function on(type, fn) {
  if (!handlers.has(type)) handlers.set(type, new Set());
  handlers.get(type).add(fn);
  return () => off(type, fn);
}

export function off(type, fn) {
  handlers.get(type)?.delete(fn);
}

function dispatch(frame) {
  // 先满足按 client_msg_id 等待的请求。
  if (frame.type === 'send_message_result' && frame.data && frame.data.client_msg_id) {
    const p = pendingReplies.get(frame.data.client_msg_id);
    if (p) {
      clearTimeout(p.timer);
      pendingReplies.delete(frame.data.client_msg_id);
      p.resolve(frame.data);
    }
  }
  handlers.get(frame.type)?.forEach((fn) => {
    try { fn(frame.data, frame); } catch (e) { console.error('ws handler error', e); }
  });
  handlers.get('*')?.forEach((fn) => {
    try { fn(frame.data, frame); } catch (e) { console.error('ws handler error', e); }
  });
}

export function startWs() {
  const token = localStorage.getItem('token');
  if (!token) return;
  if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;

  closedManually = false;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const url = `${proto}://${location.host}/ws?token=${encodeURIComponent(token)}`;

  try {
    ws = new WebSocket(url);
  } catch (e) {
    scheduleReconnect();
    return;
  }

  ws.onopen = () => {
    wsState.connected = true;
    retry = 0;
  };

  ws.onmessage = (ev) => {
    let frame;
    try { frame = JSON.parse(ev.data); } catch { return; }
    if (frame && frame.type) dispatch(frame);
  };

  ws.onclose = () => {
    wsState.connected = false;
    ws = null;
    if (!closedManually) scheduleReconnect();
  };

  ws.onerror = () => {
    // close 会紧随其后触发重连
    try { ws?.close(); } catch { /* noop */ }
  };
}

function scheduleReconnect() {
  if (reconnectTimer) return;
  if (!localStorage.getItem('token')) return;
  const delay = Math.min(1000 * 2 ** retry, 15000);
  retry += 1;
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    startWs();
  }, delay);
}

export function stopWs() {
  closedManually = true;
  if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
  try { ws?.close(); } catch { /* noop */ }
  ws = null;
  wsState.connected = false;
}

// 通过 WS 让 botd 本地代发，避免 Node 同步走代理调 Telegram 的高延迟。
// 返回 Promise<{ok, message_id?, error?}>。WS 不可用时 reject，由调用方回退 HTTP。
export function sendMessageViaWs({ bot_id, user_id, text }, timeoutMs = 20000) {
  return new Promise((resolve, reject) => {
    if (!ws || ws.readyState !== WebSocket.OPEN) {
      reject(new Error('WS_NOT_OPEN'));
      return;
    }
    const clientMsgId = `m${Date.now()}-${++replySeq}`;
    const timer = setTimeout(() => {
      if (pendingReplies.delete(clientMsgId)) reject(new Error('WS_TIMEOUT'));
    }, timeoutMs);
    pendingReplies.set(clientMsgId, { resolve, timer });
    try {
      ws.send(JSON.stringify({
        type: 'send_message',
        ts: Math.floor(Date.now() / 1000),
        data: { client_msg_id: clientMsgId, bot_id, user_id, text },
      }));
    } catch (e) {
      clearTimeout(timer);
      pendingReplies.delete(clientMsgId);
      reject(e);
    }
  });
}
