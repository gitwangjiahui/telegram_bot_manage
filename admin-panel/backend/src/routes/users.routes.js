import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';
import { tgCall } from '../tg.js';

const router = Router();

// 设为超级管理员前，清除该 Bot 原有超级，保证唯一
async function replaceSuper(botId) {
  await query("DELETE FROM bot_admin_rela WHERE bot_id = ? AND admin_type = 'super'", [botId]);
}

/* ---------------- 用户列表 ---------------- */
router.get('/', requirePerm('user:view'), async (req, res, next) => {
  try {
    const { bot_id, keyword = '', page = 1, page_size = 20 } = req.query;
    const scope = botScopeFilter(req.ctx, 'b');
    const where = [scope.where];
    const params = [...scope.params];

    if (bot_id) { where.push('b.id = ?'); params.push(bot_id); }
    if (keyword) {
      where.push('(u.username LIKE ? OR CAST(u.id AS CHAR) LIKE ? OR u.first_name LIKE ?)');
      params.push(`%${keyword}%`, `%${keyword}%`, `%${keyword}%`);
    }

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

// 取消验证
router.delete('/:botName/verify/:userId', requirePerm('user:verify:manage'), async (req, res, next) => {
  try {
    await query('DELETE FROM user_verification WHERE bot_name = ? AND user_id = ?',
      [req.params.botName, req.params.userId]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

/* ---------------- TG 管理员（admins 表） ---------------- */

// 全部 TG 管理员 + 绑定的 Bot/角色
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

// admins 表完整列表（含资料与绑定情况）
router.get('/admins/profiles', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const admins = await query('SELECT * FROM admins ORDER BY id');
    const bindings = await query(
      `SELECT r.admin_id, r.bot_id, r.admin_type, b.bot_name
         FROM bot_admin_rela r JOIN bots b ON b.id = r.bot_id
        WHERE ${scope.where}`,
      scope.params
    );
    const map = new Map(admins.map((a) => [a.id, { ...a, bindings: [] }]));
    for (const b of bindings) map.get(b.admin_id)?.bindings.push({
      bot_id: b.bot_id, bot_name: b.bot_name, admin_type: b.admin_type,
    });
    res.json([...map.values()]);
  } catch (e) { next(e); }
});

// 通过 Bot 调 TG getChat 拉取用户真实资料（对方需与该 Bot 有过交互）
router.get('/admins/fetch-info', requirePerm('user:admin:manage'), async (req, res) => {
  const adminId = Number(req.query.admin_id);
  const botId = Number(req.query.bot_id);
  if (!adminId || !botId) return res.status(400).json({ message: '参数缺失' });
  try {
    const bot = await one('SELECT api_key FROM bots WHERE id = ?', [botId]);
    if (!bot) return res.status(404).json({ message: '机器人不存在' });
    const chat = await tgCall(bot.api_key, 'getChat', { chat_id: adminId });
    res.json({
      username: chat.username || null,
      first_name: chat.first_name || null,
      last_name: chat.last_name || null,
    });
  } catch (e) {
    res.status(400).json({ message: e.tg?.description || e.message });
  }
});

// 添加 TG 管理员（upsert admins + 绑定 Bot + 指定角色）
router.post('/admins', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    const {
      admin_id, username = null, first_name = null, last_name = null,
      bot_id, admin_type = 'normal',
    } = req.body || {};
    if (!admin_id) return res.status(400).json({ message: '管理员 ID 必填' });

    await query(
      `INSERT INTO admins (id, username, first_name, last_name)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         username = COALESCE(VALUES(username), username),
         first_name = COALESCE(VALUES(first_name), first_name),
         last_name = COALESCE(VALUES(last_name), last_name)`,
      [admin_id, username, first_name, last_name]
    );

    if (bot_id) {
      if (admin_type === 'super') await replaceSuper(bot_id);
      await query(
        `INSERT INTO bot_admin_rela (bot_id, admin_id, admin_type) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE admin_type = VALUES(admin_type)`,
        [bot_id, admin_id, admin_type]
      );
    }
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 更新管理员资料
router.put('/admins/:id', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    const { username, first_name, last_name } = req.body || {};
    await query(
      `INSERT INTO admins (id, username, first_name, last_name)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         username = VALUES(username),
         first_name = VALUES(first_name),
         last_name = VALUES(last_name)`,
      [req.params.id, username ?? null, first_name ?? null, last_name ?? null]
    );
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 删除管理员（级联解绑全部 Bot）
router.delete('/admins/:id', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    await query('DELETE FROM admins WHERE id = ?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 移除某 Bot 的管理员关系
router.delete('/admins/rela/:relaId', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    await query('DELETE FROM bot_admin_rela WHERE id = ?', [req.params.relaId]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 为已有管理员追加绑定 Bot
router.post('/admins/bind', requirePerm('user:admin:manage'), async (req, res, next) => {
  try {
    const { admin_id, bot_id, admin_type = 'normal' } = req.body || {};
    if (!admin_id || !bot_id) return res.status(400).json({ message: '参数缺失' });
    if (admin_type === 'super') await replaceSuper(bot_id);
    await query(
      `INSERT INTO bot_admin_rela (bot_id, admin_id, admin_type) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE admin_type = VALUES(admin_type)`,
      [bot_id, admin_id, admin_type]
    );
    res.json({ ok: true });
  } catch (e) { next(e); }
});

export default router;
