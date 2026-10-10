import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';
import { tgCall } from '../tg.js';
import { parseMedia } from '../media.js';

const router = Router();

// Longman message 表中可展示的媒体原始 JSON 列（in/out 分支取实体，message_log 分支补 NULL）
const MEDIA_COLS = [
  'photo', 'video', 'document', 'sticker', 'voice',
  'animation', 'audio', 'video_note', 'contact', 'location',
];

const TYPE_CASE = `CASE
    WHEN m.photo IS NOT NULL THEN 'photo'
    WHEN m.voice IS NOT NULL THEN 'voice'
    WHEN m.video IS NOT NULL THEN 'video'
    WHEN m.video_note IS NOT NULL THEN 'video_note'
    WHEN m.document IS NOT NULL THEN 'document'
    WHEN m.sticker IS NOT NULL THEN 'sticker'
    WHEN m.animation IS NOT NULL THEN 'animation'
    WHEN m.audio IS NOT NULL THEN 'audio'
    WHEN m.contact IS NOT NULL THEN 'contact'
    WHEN m.location IS NOT NULL THEN 'location'
    WHEN m.text IS NOT NULL THEN 'text'
    ELSE 'other' END`;

const NULL_MEDIA = MEDIA_COLS.map(() => 'NULL').join(',');

// 会话内全部消息：用户上行 + 管理员回复（message 表），后台回复（message_log）
const CONVERSATION_SQL = `
    SELECT m.id AS db_id, m.chat_id, m.id AS tg_msg_id, 'in' AS direction,
           m.user_id AS sender_id, ${TYPE_CASE} AS msg_type,
           COALESCE(m.text, m.caption) AS text_content, m.date AS created_at,
           ${MEDIA_COLS.map((c) => `m.${c}`).join(',')}
      FROM message m
      JOIN (SELECT DISTINCT bot_name, user_id, original_msg_id FROM forward_map) f
        ON f.user_id = m.chat_id AND f.original_msg_id = m.id
     WHERE f.bot_name = ? AND f.user_id = ?
    UNION
    SELECT m.id, m.chat_id, m.id, 'out',
           m.user_id, ${TYPE_CASE},
           COALESCE(m.text, m.caption), m.date,
           ${MEDIA_COLS.map((c) => `m.${c}`).join(',')}
      FROM message m
      JOIN (SELECT DISTINCT bot_name, user_id, forwarded_msg_id FROM forward_map) f
        ON f.forwarded_msg_id = m.reply_to_message
     WHERE f.bot_name = ? AND f.user_id = ?
    UNION
    SELECT ml.id, 0, ml.message_id,
           ml.direction COLLATE utf8mb4_unicode_520_ci,
           ml.sender_id,
           ml.msg_type COLLATE utf8mb4_unicode_520_ci,
           ml.text_content COLLATE utf8mb4_unicode_520_ci,
           ml.created_at,
           ${NULL_MEDIA}
      FROM message_log ml
     WHERE ml.bot_name = ? AND ml.user_id = ? AND ml.sender_id = 0
 `;

function decorate(row, botId) {
  const mediaRow = {};
  for (const c of MEDIA_COLS) mediaRow[c] = row[c];
  const media = parseMedia(mediaRow);
  const out = {
    db_id: row.db_id,
    tg_msg_id: row.tg_msg_id,
    direction: row.direction,
    sender_id: row.sender_id,
    msg_type: row.msg_type,
    text_content: row.text_content,
    created_at: row.created_at,
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
      where.push('(u.username LIKE ? OR CAST(f.user_id AS CHAR) LIKE ? OR u.first_name LIKE ?)');
      params.push(`%${keyword}%`, `%${keyword}%`, `%${keyword}%`);
    }

    const rows = await query(
      `SELECT b.id AS bot_id, f.bot_name, f.user_id,
              u.username, u.first_name, u.last_name
         FROM (SELECT DISTINCT bot_name, user_id FROM forward_map) f
         JOIN bots b ON b.bot_name = f.bot_name
         LEFT JOIN user u ON u.id = f.user_id
        WHERE ${where.join(' AND ')}
        ORDER BY f.bot_name, f.user_id`,
      params
    );

    for (const c of rows) {
      const stat = await one(
        `SELECT COUNT(*) AS cnt, MAX(t.created_at) AS last_at FROM (${CONVERSATION_SQL}) t`,
        [c.bot_name, c.user_id, c.bot_name, c.user_id, c.bot_name, c.user_id]
      );
      c.msg_count = stat.cnt;
      c.last_at = stat.last_at;
      c.avatar_url = `/server/api/avatar/${c.user_id}?bot_id=${c.bot_id}`;
    }
    rows.sort((a, b) => (b.last_at || '').localeCompare(a.last_at || ''));
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
      [bot.bot_name, user_id, bot.bot_name, user_id, bot.bot_name, user_id]
    );

    const seen = new Set();
    const deduped = rows.filter((r) => {
      const key = `${r.direction}-${r.sender_id}-${r.tg_msg_id}-${r.created_at}`;
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
