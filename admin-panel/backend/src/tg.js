import { ProxyAgent, fetch } from 'undici';
import { config } from './config.js';

const dispatcher = config.tgProxy ? new ProxyAgent(config.tgProxy) : undefined;
const API = 'https://api.telegram.org';

export async function tgCall(token, method, params = {}) {
  const res = await fetch(`${API}/bot${token}/${method}`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(params),
    dispatcher,
  });
  const data = await res.json();
  if (!data.ok) {
    const err = new Error(data.description || 'Telegram API 错误');
    err.tg = data;
    throw err;
  }
  return data.result;
}

export function maskToken(token) {
  if (!token || token.length < 12) return '***';
  return token.slice(0, 5) + '***' + token.slice(-4);
}
