// 首页看板聚合查询：HTTP /dashboard/metrics 与 WebSocket 定时推送共用同一口径。
import { query } from '../db.js';
import { botScopeFilter } from '../auth.js';

// 心跳 90s 内视为存活（daemon 每 ≤10s 上报一次）
export const FRESH = 'hb.heartbeat_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)';

// 仅 Bot 实时运行卡片所需的轻量查询（3s 频道用），字段结构与 metrics.live 一致。
export async function livePayload(ctx) {
  const scope = botScopeFilter(ctx, 'b');
  const liveSQL = `
    SELECT b.id AS bot_id, b.bot_name, b.bot_username, b.is_active,
           CASE WHEN ${FRESH} THEN 1 ELSE 0 END AS is_running,
           hb.pid, hb.started_at, hb.heartbeat_at, hb.last_update_id,
           hb.today_in AS hb_in, hb.today_out AS hb_out, hb.captcha_available,
           hb.last_error, hb.last_error_at,
           TIMESTAMPDIFF(SECOND, hb.heartbeat_at, NOW()) AS age_seconds,
           COALESCE(lg.log_in, 0)         AS log_in,
           COALESCE(lg.log_out, 0)        AS log_out,
           COALESCE(lg.log_unverified, 0) AS log_unverified
    FROM bots b
    LEFT JOIN bot_heartbeat hb ON hb.bot_name = b.bot_name
    LEFT JOIN (
      SELECT bot_id,
             SUM(direction='in')  AS log_in,
             SUM(direction='out') AS log_out,
             SUM(is_verified=0)   AS log_unverified
      FROM message_log WHERE created_at >= CURDATE() GROUP BY bot_id
    ) lg ON lg.bot_id = b.id
    WHERE ${scope.where}
    ORDER BY b.id`;
  const liveRows = await query(liveSQL, scope.params);
  return liveRows.map((r) => ({
    bot_id: r.bot_id, bot_name: r.bot_name, bot_username: r.bot_username,
    is_active: !!r.is_active, is_running: !!Number(r.is_running),
    pid: r.pid, started_at: r.started_at, heartbeat_at: r.heartbeat_at,
    offset: r.last_update_id, age_seconds: r.age_seconds,
    today_in: Number(r.log_in), today_out: Number(r.log_out),
    unverified: Number(r.log_unverified),
    hb_in: r.hb_in, hb_out: r.hb_out,
    captcha: r.captcha_available,
    captcha_low: !(r.captcha_available > 20),
    last_error: r.last_error, last_error_at: r.last_error_at,
  }));
}

// 全套看板聚合（15s 频道用）。与旧 GET /dashboard/metrics 输出保持一致。
export async function metricsPayload(ctx) {
  const scope = botScopeFilter(ctx, 'b');
  const P = scope.params;

  const kpiSQL = `
    SELECT
      COUNT(*)                                                       AS messages_total,
      SUM(g.direction='in')                                          AS inbound_total,
      SUM(g.direction='out')                                         AS outbound_total,
      SUM(g.is_verified=0)                                           AS unverified_total,
      SUM(g.created_at >= CURDATE() AND g.direction='in')            AS today_in,
      SUM(g.created_at >= CURDATE() AND g.direction='out')           AS today_out,
      SUM(g.created_at >= CURDATE())                                 AS today_total,
      SUM(g.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          AND g.created_at < CURDATE() AND g.direction='in')         AS yday_in,
      SUM(g.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          AND g.created_at < CURDATE() AND g.direction='out')        AS yday_out
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    WHERE ${scope.where}`;

  const fwdSQL = `
    SELECT
      COUNT(DISTINCT f.bot_name, f.user_id, f.original_msg_id)        AS forwards_total,
      COUNT(DISTINCT CASE WHEN f.created_at >= CURDATE()
           THEN CONCAT(f.bot_name,'#',f.user_id,'#',f.original_msg_id) END) AS forwards_today
    FROM forward_map f JOIN bots b ON b.bot_name = f.bot_name
    WHERE ${scope.where}`;

  const userSQL = `
    SELECT
      COUNT(DISTINCT uv.user_id)                                     AS verified_users,
      COUNT(DISTINCT CASE WHEN uv.verified_at >= CURDATE() THEN uv.user_id END) AS new_users_today
    FROM user_verification uv JOIN bots b ON b.bot_name = uv.bot_name
    WHERE ${scope.where}`;

  const funnelSQL = `
    SELECT
      SUM(g.direction='in')                                          AS in_total,
      SUM(g.direction='in' AND g.is_verified=1)                      AS in_verified,
      SUM(g.direction='in' AND g.is_verified=0)                      AS in_unverified,
      SUM(g.direction='in' AND EXISTS (
            SELECT 1 FROM forward_map fm
             WHERE fm.bot_name = g.bot_name COLLATE utf8mb4_unicode_ci
               AND fm.user_id  = g.user_id
               AND fm.original_msg_id = g.message_id))               AS in_forwarded
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    WHERE ${scope.where} AND g.created_at >= CURDATE()`;

  const botSQL = `
    SELECT COUNT(*) AS bots,
           SUM(CASE WHEN ${FRESH} THEN 1 ELSE 0 END) AS running
    FROM bots b LEFT JOIN bot_heartbeat hb ON hb.bot_name = b.bot_name
    WHERE ${scope.where}`;

  const hourlySQL = `
    SELECT HOUR(g.created_at) AS hr,
           SUM(g.direction='in')  AS inbound,
           SUM(g.direction='out') AS outbound
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    WHERE ${scope.where} AND g.created_at >= CURDATE()
    GROUP BY HOUR(g.created_at)`;

  const trendSQL = `
    SELECT DATE(g.created_at) AS day,
           SUM(g.direction='in')  AS inbound,
           SUM(g.direction='out') AS outbound
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    WHERE ${scope.where} AND g.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(g.created_at) ORDER BY day`;

  const typeSQL = `
    SELECT g.msg_type AS type, COUNT(*) AS c
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    WHERE ${scope.where} AND g.direction='in'
    GROUP BY g.msg_type ORDER BY c DESC`;

  const byBotSQL = `
    SELECT b.bot_name,
           COALESCE(SUM(g.direction='in'),0)  AS inbound,
           COALESCE(SUM(g.direction='out'),0) AS outbound,
           COALESCE(SUM(g.is_verified=0),0)   AS unverified,
           COUNT(g.id)                         AS total
    FROM bots b LEFT JOIN message_log g ON g.bot_id = b.id
    WHERE ${scope.where}
    GROUP BY b.id, b.bot_name ORDER BY b.id`;

  const adminSQL = `
    SELECT COALESCE(NULLIF(a.first_name,''), a.username,
                    CASE WHEN g.sender_id=0 THEN '管理员(网页)'
                         ELSE CONCAT('ID ', g.sender_id) END) AS name,
           COUNT(*) AS c
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    LEFT JOIN admins a ON a.id = g.sender_id
    WHERE ${scope.where} AND g.direction='out'
    GROUP BY name ORDER BY c DESC LIMIT 8`;

  const userTopSQL = `
    SELECT g.user_id, COUNT(*) AS c, MAX(g.created_at) AS last_at
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    WHERE ${scope.where} AND g.direction='in'
          AND g.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY g.user_id ORDER BY c DESC LIMIT 8`;

  const convTotalSQL = `
    SELECT COUNT(DISTINCT CONCAT(g.bot_id,'#',g.user_id)) AS c
    FROM message_log g JOIN bots b ON b.id = g.bot_id
    WHERE ${scope.where} AND g.direction='in'
          AND g.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)`;
  const convReplySQL = `
    SELECT COUNT(DISTINCT CONCAT(i.bot_id,'#',i.user_id)) AS c
    FROM message_log i JOIN bots b ON b.id = i.bot_id
    WHERE ${scope.where} AND i.direction='in'
          AND i.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
          AND EXISTS (SELECT 1 FROM message_log o
                      WHERE o.bot_id=i.bot_id AND o.user_id=i.user_id
                        AND o.direction='out')`;

  const [
    kpi, fwd, usr, bt, liveRows, hourRows, trendRows,
    typeRows, byBotRows, adminRows, userTopRows, convTotal, convReply, funnel,
  ] = await Promise.all([
    query(kpiSQL, P), query(fwdSQL, P), query(userSQL, P), query(botSQL, P),
    query(liveSQL, P), query(hourlySQL, P), query(trendSQL, P),
    query(typeSQL, P), query(byBotSQL, P), query(adminSQL, P),
    query(userTopSQL, P), query(convTotalSQL, P), query(convReplySQL, P),
    query(funnelSQL, P),
  ]);

  const hourMap = new Map(hourRows.map((r) => [Number(r.hr), r]));
  const hourly = [];
  for (let h = 0; h < 24; h++) {
    const r = hourMap.get(h);
    hourly.push({ hr: h, inbound: r ? Number(r.inbound) : 0, outbound: r ? Number(r.outbound) : 0 });
  }

  const tMap = new Map(trendRows.map((r) => [String(r.day).slice(0, 10), r]));
  const trend = [];
  for (let i = 29; i >= 0; i--) {
    const day = new Date(Date.now() - i * 86400000).toISOString().slice(0, 10);
    const hit = tMap.get(day);
    trend.push({ day, inbound: hit ? Number(hit.inbound) : 0, outbound: hit ? Number(hit.outbound) : 0 });
  }

  const k = kpi[0];
  const todayIn = Number(k.today_in) || 0;
  const todayOut = Number(k.today_out) || 0;
  const convTot = Number(convTotal[0].c) || 0;
  const convRep = Number(convReply[0].c) || 0;
  const fn = funnel[0] || {};
  const fnIn = Number(fn.in_total) || 0;
  const fnFwd = Number(fn.in_forwarded) || 0;
  const fnVer = Number(fn.in_verified) || 0;

  return {
    server_time: new Date().toISOString(),
    totals: {
      bots: Number(bt[0].bots),
      running: Number(bt[0].running),
      stopped: Number(bt[0].bots) - Number(bt[0].running),
      verified_users: Number(usr[0].verified_users),
      new_users_today: Number(usr[0].new_users_today),
      messages: Number(k.messages_total),
      inbound: Number(k.inbound_total),
      outbound: Number(k.outbound_total),
      unverified: Number(k.unverified_total) || 0,
      forwards: Number(fwd[0].forwards_total),
    },
    today: {
      inbound: todayIn,
      outbound: todayOut,
      forwards: Number(fwd[0].forwards_today) || 0,
      new_users: Number(usr[0].new_users_today),
      reply_rate: todayIn ? +(todayOut / todayIn * 100).toFixed(1) : 0,
      yday_in: Number(k.yday_in) || 0,
      yday_out: Number(k.yday_out) || 0,
      in_total: fnIn,
      forwarded: fnFwd,
      unforwarded: fnIn - fnFwd,
      verified: fnVer,
      unverified: fnIn - fnVer,
    },
    conversation: { total: convTot, replied: convRep, rate: convTot ? +(convRep / convTot * 100).toFixed(1) : 0 },
    live: liveRows.map((r) => ({
      bot_id: r.bot_id, bot_name: r.bot_name, bot_username: r.bot_username,
      is_active: !!r.is_active, is_running: !!Number(r.is_running),
      pid: r.pid, started_at: r.started_at, heartbeat_at: r.heartbeat_at,
      offset: r.last_update_id, age_seconds: r.age_seconds,
      today_in: Number(r.log_in), today_out: Number(r.log_out),
      unverified: Number(r.log_unverified),
      hb_in: r.hb_in, hb_out: r.hb_out,
      captcha: r.captcha_available,
      captcha_low: !(r.captcha_available > 20),
      last_error: r.last_error, last_error_at: r.last_error_at,
    })),
    hourly,
    trend,
    by_type: typeRows.map((r) => ({ type: r.type, c: Number(r.c) })),
    by_bot: byBotRows.map((r) => ({
      bot_name: r.bot_name,
      inbound: Number(r.inbound), outbound: Number(r.outbound),
      unverified: Number(r.unverified), total: Number(r.total),
    })),
    top_admins: adminRows.map((r) => ({ name: r.name, c: Number(r.c) })),
    top_users: userTopRows.map((r) => ({ user_id: String(r.user_id), c: Number(r.c), last_at: r.last_at })),
  };
}
