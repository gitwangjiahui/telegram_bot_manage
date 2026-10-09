import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';
import { tgCall } from '../tg.js';

const router = Router();

// 列表
router.get('/', requirePerm('bot:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const rows = await query(
      `SELECT b.id, b.bot_name, b.bot_username, b.is_active, b.created_at,
              (SELECT COUNT(DISTINCT uv.user_id) FROM user_verification uv
                WHERE uv.bot_name = b.bot_name) AS user_count
         FROM bots b WHERE ${scope.where} ORDER BY b.id`,
      scope.params
    );
    res.json(rows);
  } catch (e) { next(e); }
});

// 详情（不含 token 全文）
router.get('/:id', requirePerm('bot:view'), async (req, res, next) => {
  try {
    const bot = await one(
      `SELECT id, bot_name, bot_username, is_active, created_at,
              CONCAT(LEFT(api_key, 8), '***', RIGHT(api_key, 4)) AS api_key_masked
         FROM bots WHERE id = ?`,
      [req.params.id]
    );
    if (!bot) return res.status(404).json({ message: '机器人不存在' });
    res.json(bot);
  } catch (e) { next(e); }
});

// 新增
router.post('/', requirePerm('bot:create'), async (req, res, next) => {
  try {
    const { bot_name, api_key, bot_username, is_active = 1 } = req.body || {};
    if (!bot_name || !api_key) return res.status(400).json({ message: '名称和 Token 必填' });
    if (await one('SELECT id FROM bots WHERE bot_name = ?', [bot_name])) {
      return res.status(409).json({ message: '机器人名称已存在' });
    }
    const result = await query(
      'INSERT INTO bots (bot_name, api_key, bot_username, is_active) VALUES (?, ?, ?, ?)',
      [bot_name, api_key, bot_username || null, is_active ? 1 : 0]
    );
    res.json({ id: result.insertId });
  } catch (e) { next(e); }
});

// 更新
router.put('/:id', requirePerm('bot:edit'), async (req, res, next) => {
  try {
    const { bot_name, api_key, bot_username, is_active } = req.body || {};
    const bot = await one('SELECT * FROM bots WHERE id = ?', [req.params.id]);
    if (!bot) return res.status(404).json({ message: '机器人不存在' });

    await query('UPDATE bots SET bot_name=?, api_key=?, bot_username=?, is_active=? WHERE id=?', [
      bot_name ?? bot.bot_name,
      api_key || bot.api_key,
      bot_username ?? bot.bot_username,
      is_active === undefined ? bot.is_active : (is_active ? 1 : 0),
      bot.id,
    ]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 删除
router.delete('/:id', requirePerm('bot:delete'), async (req, res, next) => {
  try {
    await query('DELETE FROM bots WHERE id = ?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 校验 Token 有效性（调用 getMe）
router.post('/:id/check', requirePerm('bot:edit'), async (req, res, next) => {
  try {
    const bot = await one('SELECT api_key FROM bots WHERE id = ?', [req.params.id]);
    if (!bot) return res.status(404).json({ message: '机器人不存在' });
    const me = await tgCall(bot.api_key, 'getMe');
    res.json({ ok: true, username: me.username, name: me.first_name });
  } catch (e) {
    res.status(400).json({ ok: false, message: e.tg?.description || e.message });
  }
});

export default router;
