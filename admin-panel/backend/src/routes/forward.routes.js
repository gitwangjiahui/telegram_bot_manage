import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';

const router = Router();

// 转发设置总览
router.get('/', requirePerm('forward:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const bots = await query(
      `SELECT b.id, b.bot_name, b.bot_username FROM bots b WHERE ${scope.where} ORDER BY b.id`,
      scope.params
    );
    if (bots.length === 0) return res.json([]);
    const lists = await query(
      `SELECT r.bot_id, r.admin_id, r.admin_type, a.username, a.first_name
         FROM bot_admin_rela r LEFT JOIN admins a ON a.id = r.admin_id
        WHERE r.bot_id IN (${bots.map(() => '?').join(',')})
        ORDER BY r.bot_id, r.admin_type DESC`,
      bots.map((b) => b.id)
    );
    const map = new Map(bots.map((b) => [b.id, { ...b, admins: [] }]));
    for (const row of lists) map.get(row.bot_id)?.admins.push(row);
    res.json([...map.values()]);
  } catch (e) { next(e); }
});

router.post('/targets', requirePerm('forward:edit'), async (req, res, next) => {
  try {
    const { bot_id, admin_id, admin_type = 'normal' } = req.body || {};
    if (!bot_id || !admin_id) return res.status(400).json({ message: '参数缺失' });
    if (admin_type === 'super') {
      await query("DELETE FROM bot_admin_rela WHERE bot_id = ? AND admin_type = 'super'", [bot_id]);
    }
    await query('INSERT INTO admins (id) VALUES (?) ON DUPLICATE KEY UPDATE id=id', [admin_id]);
    await query(
      `INSERT INTO bot_admin_rela (bot_id, admin_id, admin_type) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE admin_type = VALUES(admin_type)`,
      [bot_id, admin_id, admin_type]
    );
    res.json({ ok: true });
  } catch (e) { next(e); }
});

router.delete('/targets/:botId/:adminId', requirePerm('forward:edit'), async (req, res, next) => {
  try {
    await query('DELETE FROM bot_admin_rela WHERE bot_id = ? AND admin_id = ?',
      [req.params.botId, req.params.adminId]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 最近 20 条转发记录：含消息内容 + 用户实际信息（去重展示，一次用户消息只展示一条）
router.get('/records', requirePerm('forward:view'), async (req, res, next) => {
  try {
    const { bot_id } = req.query;
    const scope = botScopeFilter(req.ctx, 'b');
    const where = [scope.where];
    const params = [...scope.params];
    if (bot_id) { where.push('b.id = ?'); params.push(bot_id); }

    // forward_map 同一条原消息转发给多个管理员会产生多行，
    // 按 (bot_name,user_id,original_msg_id) 取最早一条转发记录代表。
    // 内容统一取 message_log（方案A，带 bot 维度，不再 JOIN Longman message）。
    const rows = await query(
      `SELECT t.bot_name, t.bot_id, t.user_id,
              t.original_msg_id, t.forwarded_msg_id, fm.created_at,
              u.username, u.first_name, u.last_name,
              ml.msg_type, ml.text_content, ml.raw_content
         FROM (
           SELECT f.bot_name, f.user_id, f.original_msg_id,
                  MIN(f.forwarded_msg_id) AS forwarded_msg_id,
                  MIN(f.id) AS min_id,
                  b.id AS bot_id
             FROM forward_map f
             JOIN bots b ON b.bot_name = f.bot_name
            WHERE ${scope.where}${bot_id ? ' AND b.id = ?' : ''}
            GROUP BY f.bot_name, b.id, f.user_id, f.original_msg_id
         ) t
         JOIN forward_map fm ON fm.id = t.min_id
         LEFT JOIN message_log ml
           ON ml.bot_id = t.bot_id AND ml.user_id = t.user_id
          AND ml.message_id = t.original_msg_id AND ml.direction = 'in'
         LEFT JOIN user u ON u.id = t.user_id
        ORDER BY fm.created_at DESC
        LIMIT 20`,
      bot_id ? [...params, bot_id] : params
    );

    // 取图片/贴纸 file_id 用于缩略图（raw_content 为标准 TG message JSON）
    for (const r of rows) {
      r.content = r.text_content || null;
      r.thumb_file_id = null;
      if (r.raw_content) {
        try {
          const g = JSON.parse(r.raw_content);
          const photo = Array.isArray(g.photo) ? g.photo : null;
          const sticker = g.sticker;
          const video = g.video || g.animation;
          if (photo && photo.length) {
            r.thumb_file_id = photo[Math.min(1, photo.length - 1)].file_id;
          } else if (sticker) {
            r.thumb_file_id = sticker.thumbnail?.file_id || sticker.file_id;
          } else if (video) {
            r.thumb_file_id = video.thumb?.file_id || null;
          }
        } catch { /* ignore */ }
      }
      r.avatar_url = `/server/api/avatar/${r.user_id}?bot_id=${r.bot_id}`;
      delete r.text_content; delete r.raw_content;
    }
    res.json(rows);
  } catch (e) { next(e); }
});

export default router;
