import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';

const router = Router();

// 用户列表（聚合 Longman user 表 + 各 bot 验证状态）
router.get('/', requirePerm('user:view'), async (req, res, next) => {
  try {
    const { bot_id, keyword = '', page = 1, page_size = 20 } = req.query;
    const scope = botScopeFilter(req.ctx, 'b');
    const joins = [];
    const where = [scope.where];
    const params = [...scope.params];

    if (bot_id) {
      where.push('b.id = ?');
      params.push(bot_id);
    }
    if (keyword) {
      where.push('(u.username LIKE ? OR CAST(u.id AS CHAR) LIKE ? OR u.first_name LIKE ?)');
      params.push(`%${keyword}%`, `%${keyword}%`, `%${keyword}%`);
    }

    // 以 user_verification 为锚点（只有交互过的用户），关联 user 拿资料
    const sqlBase = `
      FROM user_verification uv
      JOIN bots b ON b.bot_name = uv.bot_name
      LEFT JOIN user u ON u.id = uv.user_id
      WHERE ${where.join(' AND ')}`;

    const totalRow = await one(`SELECT COUNT(*) AS c ${sqlBase}`, params);
    const offset = (Number(page) - 1) * Number(page_size);
    const rows = await query(
      `SELECT uv.user_id, uv.bot_name, b.id AS bot_id, uv.verified_at,
              u.username, u.first_name, u.last_name, u.language_code
       ${sqlBase}
       ORDER BY uv.verified_at DESC
       LIMIT ? OFFSET ?`,
      [...params, Number(page_size), offset]
    );
    res.json({ total: totalRow.c, list: rows });
  } catch (e) { next(e); }
});

// 取消验证（用户需重新验证）
router.delete('/:botName/verify/:userId', requirePerm('user:verify:manage'), async (req, res, next) => {
  try {
    await query('DELETE FROM user_verification WHERE bot_name = ? AND user_id = ?',
      [req.params.botName, req.params.userId]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 机器人管理员列表（bot_admin_rela + admins）
router.get('/admins/all', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const rows = await query(
      `SELECT r.id AS rela_id, r.bot_id, b.bot_name, r.admin_id, r.admin_type,
              a.username, a.first_name, a.last_name
         FROM bot_admin_rela r
         JOIN bots b ON b.id = r.bot_id
         LEFT JOIN admins a ON a.id = r.admin_id
        WHERE ${scope.where}
        ORDER BY b.id, r.admin_type DESC, r.admin_id`,
      scope.params
    );
    res.json(rows);
  } catch (e) { next(e); }
});

// 添加机器人管理员
router.post('/admins', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    const { bot_id, admin_id, admin_type = 'normal' } = req.body || {};
    if (!bot_id || !admin_id) return res.status(400).json({ message: '参数缺失' });
    await query(
      `INSERT INTO admins (id) VALUES (?) ON DUPLICATE KEY UPDATE id = id`,
      [admin_id]
    );
    await query(
      `INSERT INTO bot_admin_rela (bot_id, admin_id, admin_type) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE admin_type = VALUES(admin_type)`,
      [bot_id, admin_id, admin_type]
    );
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 移除机器人管理员
router.delete('/admins/:relaId', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    await query('DELETE FROM bot_admin_rela WHERE id = ?', [req.params.relaId]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

export default router;
