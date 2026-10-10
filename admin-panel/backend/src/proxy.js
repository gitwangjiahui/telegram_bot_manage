import { ProxyAgent } from 'undici';
import { one } from './db.js';

// 全局 TG 代理：数据库 config 表 bot_id IS NULL AND config_key='tg_proxy'
let cachedAt = 0;
let cachedValue = '';
const TTL = 15_000;
const agentCache = new Map();

export async function getTgProxyUrl() {
  const now = Date.now();
  if (now - cachedAt < TTL) return cachedValue;
  let value = process.env.TG_PROXY || '';
  try {
    const row = await one(
      "SELECT config_value FROM config WHERE bot_id IS NULL AND config_key = 'tg_proxy' LIMIT 1");
    if (row?.config_value) value = String(row.config_value).trim();
  } catch {
    /* DB 不可用时沿用 env */
  }
  cachedAt = now;
  cachedValue = value || '';
  return cachedValue;
}

export async function getDispatcher() {
  const url = await getTgProxyUrl();
  if (!url) return undefined;
  if (!agentCache.has(url)) agentCache.set(url, new ProxyAgent(url));
  return agentCache.get(url);
}

// 配置变更后调用，立即重新读取数据库
export function clearProxyCache() {
  cachedAt = 0;
}
