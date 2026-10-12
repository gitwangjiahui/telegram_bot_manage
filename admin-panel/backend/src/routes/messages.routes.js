import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';
import { tgCall } from '../tg.js';
import { parseMedia } from '../media.js';

const router = Router();

// 方案A：消息展示/统计统一走 message_log（独立自增主键、天然带 bot_id/direction），
// 不再 UNION Longman message 表——后者主键 (chat_id,id) 不含 bot_id，多 bot 共库会撞键。
// raw_content 存标准 Telegram message JSON，媒体直接交给 parseMedia 解析。
function parseRaw(s) {
  if (!s) return null;
  try { return JSON.parse(s); } catch { return null; }
}

// 单个会话的全部消息（in=用户上行，out=管理员/后台回复）。
// 入站按 forward_map 现打「已转发」标记（只作展示，不参与归属）。
const CONVERSATION_SQL = `
    SELECT ml.id AS db_id,
           ml.message_id AS tg_msg_id,
           ml.direction,
           ml.sender_id,
           ml.msg_type,
           ml.text_content,
           ml.created_at,
           ml.raw_content,
           CASE WHEN ml.direction = 'in' AND EXISTS (
                  SELECT 1 FROM forward_map fm
                   WHERE fm.bot_name = ml.bot_name
                     AND fm.user_id  = ml.user_id
                     AND fm.original_msg_id = ml.message_id)
                THEN 1 ELSE 0 END AS forwarded
      FROM message_log ml
     WHERE ml.bot_id = ? AND ml.user_id = ?
`;

function decorate(row, botId) {
  const media = parseMedia(parseRaw(row.raw_content));
  const out = {
    db_id: row.db_id,
    tg_msg_id: row.tg_msg_id,
    direction: row.direction,
    sender_id: row.sender_id,
    msg_type: row.msg_type,
    text_content: row.text_content,
    created_at: row.created_at,
    forwarded: Number(row.forwarded) === 1,
    media: media ? {
      ...media,
      url: `/server/api/media/${botId}?file_id=${media.file_id}&type=${media.type}`,
    } : null,
  };
  if (media?.thumb_id && media.thumb_id !== media.file_id) {
    out.media.thumb_url =
      `/server/api/media/${botId}?file_id=${media.thumb_id}&type=${media.type}`;
  }
  return out;
}

// 会话列表
router.get('/conversations', requirePerm('message:view'), async (req, res, next) => {
  try {
    const { bot_id, keyword = '' } = req.query;
    const scope = botScopeFilter(req.ctx, 'b');
    const where = [scope.where];
    const params = [...scope.params];
    if (bot_id) { where.push('b.id = ?'); params.push(bot_id); }
    if (keyword) {
      where.push('(u.username LIKE ? OR CAST(g.user_id AS CHAR) LIKE ? OR u.first_name LIKE ?)');
      params.push(`%${keyword}%`, `%${keyword}%`, `%${keyword}%`);
    }

    // 聚合直接来自 message_log，一次查询拿到条数与最近时间（消除旧的 N+1）。
    const rows = await query(
      `SELECT b.id AS bot_id, b.bot_name, g.user_id, g.msg_count, g.last_at,
              u.username, u.first_name, u.last_name
         FROM (
           SELECT bot_id, user_id, COUNT(*) AS msg_count, MAX(created_at) AS last_at
             FROM message_log
            GROUP BY bot_id, user_id
         ) g
         JOIN bots b ON b.id = g.bot_id
         LEFT JOIN user u ON u.id = g.user_id
        WHERE ${where.join(' AND ')}
        ORDER BY g.last_at DESC`,
      params
    );

    for (const c of rows) {
      c.avatar_url = `/server/api/avatar/${c.user_id}?bot_id=${c.bot_id}`;
    }
    res.json(rows);
  } catch (e) { next(e); }
});

// 单个会话
router.get('/history', requirePerm('message:view'), async (req, res, next) => {
  try {
    const { bot_id, user_id } = req.query;
    if (!bot_id || !user_id) return res.status(400).json({ message: '参数缺失' });

    const bot = await one('SELECT bot_name FROM bots WHERE id = ?', [bot_id]);
    if (!bot) return res.status(404).json({ message: '机器人不存在' });

    const rows = await query(
      `SELECT t.* FROM (${CONVERSATION_SQL}) t ORDER BY t.created_at, t.db_id`,
      [bot_id, user_id]
    );

    // 唯一键已保证不重复；保留一层防御性去重（按方向+TG消息id）。
    const seen = new Set();
    const deduped = rows.filter((r) => {
      const key = `${r.direction}-${r.tg_msg_id}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).map((r) => decorate(r, bot_id));

    res.json(deduped);
  } catch (e) { next(e); }
});

// 后台回复
router.post('/reply', requirePerm('message:view'), async (req, res, next) => {
  try {
    const { bot_id, user_id, text } = req.body || {};
    if (!bot_id || !user_id || !text) return res.status(400).json({ message: '参数缺失' });

    const bot = await one('SELECT bot_name, api_key FROM bots WHERE id = ?', [bot_id]);
    if (!bot) return res.status(404).json({ message: '机器人不存在' });

    const sent = await tgCall(bot.api_key, 'sendMessage', {
      chat_id: Number(user_id),
      text,
    });

    await query(
      `INSERT INTO message_log
        (bot_id, bot_name, user_id, message_id, direction, sender_id, msg_type, text_content)
       VALUES (?, ?, ?, ?, 'out', 0, 'text', ?)
       ON DUPLICATE KEY UPDATE text_content = VALUES(text_content)`,
      [bot_id, bot.bot_name, user_id, sent.message_id, text]
    );
    res.json({ ok: true, message_id: sent.message_id });
  } catch (e) {
    res.status(400).json({ message: e.tg?.description || e.message });
  }
});

export default router;
