import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';
import { tgCall } from '../tg.js';

const router = Router();

// 会话列表（Bot × 用户）
router.get('/conversations', requirePerm('message:view'), async (req, res, next) => {
  try {
    const { keyword = '' } = req.query;
    const scope = botScopeFilter(req.ctx, 'b');
    const where = [scope.where];
    const params = [...scope.params];
    if (keyword) {
      where.push('(u.username LIKE ? OR CAST(ml.user_id AS CHAR) LIKE ? OR u.first_name LIKE ?)');
      params.push(`%${keyword}%`, `%${keyword}%`, `%${keyword}%`);
    }
    const rows = await query(
      `SELECT ml.bot_id, ml.bot_name, ml.user_id,
              MAX(ml.created_at) AS last_at,
              COUNT(*) AS msg_count,
              u.username, u.first_name, u.last_name
         FROM message_log ml
         JOIN bots b ON b.id = ml.bot_id
         LEFT JOIN user u ON u.id = ml.user_id
        WHERE ${where.join(' AND ')}
        GROUP BY ml.bot_id, ml.user_id
        ORDER BY last_at DESC
        LIMIT 200`,
      params
    );
    res.json(rows);
  } catch (e) { next(e); }
});

// 单个会话的聊天记录
router.get('/history', requirePerm('message:view'), async (req, res, next) => {
  try {
    const { bot_id, user_id, limit = 200, before_id } = req.query;
    if (!bot_id || !user_id) return res.status(400).json({ message: '参数缺失' });

    let sql = `SELECT * FROM message_log WHERE bot_id = ? AND user_id = ?`;
    const params = [bot_id, user_id];
    if (before_id) {
      sql += ' AND id < ?';
      params.push(before_id);
    }
    sql += ' ORDER BY id DESC LIMIT ?';
    params.push(Number(limit));

    const rows = await query(sql, params);
    res.json(rows.reverse()); // 时间正序返回
  } catch (e) { next(e); }
});

// 从后台回复用户
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
       VALUES (?, ?, ?, ?, 'out', ?, 'text', ?)
       ON DUPLICATE KEY UPDATE text_content = VALUES(text_content)`,
      [bot_id, bot.bot_name, user_id, sent.message_id, 0, text]
    );
    res.json({ ok: true, message_id: sent.message_id });
  } catch (e) {
    res.status(400).json({ message: e.tg?.description || e.message });
  }
});

export default router;
