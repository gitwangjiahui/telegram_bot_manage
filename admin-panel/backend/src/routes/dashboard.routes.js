import { Router } from 'express';
import { query } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';

const router = Router();

// 心跳 90s 内视为存活（daemon 每 ≤10s 上报一次）
const FRESH = 'hb.heartbeat_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)';

router.get('/', requirePerm('dashboard:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const [botRow, userRow, msgRow, fwdRow, runRow] = await Promise.all([
      query(`SELECT COUNT(*) AS c FROM bots b WHERE ${scope.where}`, scope.params),
      query(
        `SELECT COUNT(DISTINCT uv.user_id) AS c
           FROM user_verification uv JOIN bots b ON b.bot_name = uv.bot_name
          WHERE ${scope.where}`, scope.params),
      query(
        `SELECT COUNT(DISTINCT m.id) AS c
           FROM message m
           JOIN (SELECT DISTINCT bot_name,user_id,original_msg_id FROM forward_map) f
             ON f.user_id = m.chat_id AND f.original_msg_id = m.id
           JOIN bots b ON b.bot_name = f.bot_name
          WHERE ${scope.where}`, scope.params),
      query(
        `SELECT COUNT(*) AS c FROM forward_map f JOIN bots b ON b.bot_name = f.bot_name
          WHERE ${scope.where}`, scope.params),
      query(
        `SELECT COUNT(*) AS c
           FROM bots b LEFT JOIN bot_heartbeat hb ON hb.bot_name = b.bot_name
          WHERE ${scope.where} AND ${FRESH}`, scope.params),
    ]);
    res.json({
      bot_count: botRow[0].c,
      running_count: runRow[0].c,
      stopped_count: botRow[0].c - runRow[0].c,
      user_count: userRow[0].c,
      message_count: msgRow[0].c,
      forward_count: fwdRow[0].c,
    });
  } catch (e) { next(e); }
});

// 每个 Bot 的详细运行状态
router.get('/status', requirePerm('dashboard:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const rows = await query(
      `SELECT b.id, b.bot_name, b.bot_username, b.is_active,
              hb.pid, hb.started_at, hb.heartbeat_at, hb.last_update_id,
              hb.today_in, hb.today_out, hb.captcha_available,
              hb.last_error, hb.last_error_at,
              CASE WHEN ${FRESH} THEN 1 ELSE 0 END AS is_running,
              TIMESTAMPDIFF(SECOND, hb.heartbeat_at, NOW()) AS age_seconds
         FROM bots b LEFT JOIN bot_heartbeat hb ON hb.bot_name = b.bot_name
        WHERE ${scope.where}
        ORDER BY b.id`,
      scope.params
    );
    res.json(rows);
  } catch (e) { next(e); }
});

router.get('/trend', requirePerm('dashboard:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    // 与历史消息同一口径：用户上行=原消息关联 forward_map；
    // 管理员回复=回复消息经 reply_to_message 关联 forward_map 的 forwarded_msg_id
    const data = await query(
      `SELECT DATE(t.created_at) AS day,
              SUM(t.direction = 'in')  AS inbound,
              SUM(t.direction = 'out') AS outbound
         FROM (
           SELECT m.date AS created_at, 'in' AS direction
             FROM message m
             JOIN (SELECT DISTINCT bot_name,user_id,original_msg_id FROM forward_map) f
               ON f.user_id = m.chat_id AND f.original_msg_id = m.id
             JOIN bots b ON b.bot_name = f.bot_name
            WHERE ${scope.where}
           UNION ALL
           SELECT m.date, 'out'
             FROM message m
             JOIN (SELECT DISTINCT bot_name,forwarded_msg_id FROM forward_map) f
               ON f.forwarded_msg_id = m.reply_to_message
             JOIN bots b ON b.bot_name = f.bot_name
            WHERE ${scope.where}
         ) t
        WHERE t.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(t.created_at) ORDER BY day`,
      [...scope.params, ...scope.params]
    );

    // 补齐近 7 天无数据的日期，图表才连续
    const map = new Map(data.map((d) => [String(d.day).slice(0, 10), d]));
    const out = [];
    for (let i = 6; i >= 0; i--) {
      const day = new Date(Date.now() - i * 86400000).toISOString().slice(0, 10);
      const hit = map.get(day);
      out.push({
        day,
        inbound: hit ? Number(hit.inbound) : 0,
        outbound: hit ? Number(hit.outbound) : 0,
      });
    }
    res.json(out);
  } catch (e) { next(e); }
});

export default router;
