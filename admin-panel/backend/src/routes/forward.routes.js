import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';

const router = Router();

// 转发设置总览：每个 Bot 的管理员接收名单
router.get('/', requirePerm('forward:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const bots = await query(
      `SELECT b.id, b.bot_name, b.bot_username FROM bots b WHERE ${scope.where} ORDER BY b.id`,
      scope.params
    );
    const lists = await query(
      `SELECT r.bot_id, r.admin_id, r.admin_type, a.username, a.first_name
         FROM bot_admin_rela r LEFT JOIN admins a ON a.id = r.admin_id
        WHERE r.bot_id IN (${bots.map(() => '?').join(',') || 'SELECT 0'})
        ORDER BY r.bot_id, r.admin_type DESC`,
      bots.map((b) => b.id)
    );
    const map = new Map(bots.map((b) => [b.id, { ...b, admins: [] }]));
    for (const row of lists) map.get(row.bot_id)?.admins.push(row);
    res.json([...map.values()]);
  } catch (e) { next(e); }
});

// 开启/关闭转发 = 增删 bot_admin_rela 关系（转发目标即管理员名单）
router.post('/targets', requirePerm('forward:edit'), async (req, res, next) => {
  try {
    const { bot_id, admin_id, admin_type = 'normal' } = req.body || {};
    if (!bot_id || !admin_id) return res.status(400).json({ message: '参数缺失' });
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

// 转发记录浏览（forward_map）
router.get('/records', requirePerm('forward:view'), async (req, res, next) => {
  try {
    const { bot_id, page = 1, page_size = 30 } = req.query;
    const scope = botScopeFilter(req.ctx, 'b');
    const where = [scope.where];
    const params = [...scope.params];
    if (bot_id) { where.push('b.id = ?'); params.push(bot_id); }

    const countSql = `SELECT COUNT(*) AS c FROM forward_map f
                      JOIN bots b ON b.bot_name = f.bot_name WHERE ${where.join(' AND ')}`;
    const totalRow = await one(countSql, params);
    const rows = await query(
      `SELECT f.*, b.id AS bot_id FROM forward_map f
         JOIN bots b ON b.bot_name = f.bot_name
        WHERE ${where.join(' AND ')}
        ORDER BY f.id DESC LIMIT ? OFFSET ?`,
      [...params, Number(page_size), (Number(page) - 1) * Number(page_size)]
    );
    res.json({ total: totalRow.c, list: rows });
  } catch (e) { next(e); }
});

export default router;
