import { Router } from 'express';
import { query, one } from '../db.js';
import { requirePerm } from '../auth.js';

const router = Router();

// 配置列表：全局 + 可见 Bot
router.get('/', requirePerm('config:view'), async (req, res, next) => {
  try {
    const rows = req.ctx.isSuper || req.ctx.botScope === null
      ? await query(
          `SELECT c.id, c.bot_id, c.config_key, c.config_value, c.description, c.updated_at,
                  b.bot_name
             FROM config c LEFT JOIN bots b ON b.id = c.bot_id
            ORDER BY c.bot_id, c.config_key`
        )
      : await query(
          `SELECT c.id, c.bot_id, c.config_key, c.config_value, c.description, c.updated_at,
                  b.bot_name
             FROM config c LEFT JOIN bots b ON b.id = c.bot_id
            WHERE c.bot_id IS NULL
               OR c.bot_id IN (${req.ctx.botScope.map(() => '?').join(',')})
            ORDER BY c.bot_id, c.config_key`,
          req.ctx.botScope
        );
    res.json(rows);
  } catch (e) { next(e); }
});

// 新增/更新配置（upsert）
router.post('/', requirePerm('config:edit'), async (req, res, next) => {
  try {
    const { bot_id = null, config_key, config_value, description } = req.body || {};
    if (!config_key) return res.status(400).json({ message: '配置键必填' });
    await query(
      `INSERT INTO config (bot_id, config_key, config_value, description)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE config_value = VALUES(config_value),
                               description = VALUES(description)`,
      [bot_id, config_key, config_value ?? null, description ?? null]
    );
    res.json({ ok: true });
  } catch (e) { next(e); }
});

router.put('/:id', requirePerm('config:edit'), async (req, res, next) => {
  try {
    const { config_value, description } = req.body || {};
    await query('UPDATE config SET config_value=?, description=? WHERE id=?',
      [config_value ?? null, description ?? null, req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

router.delete('/:id', requirePerm('config:edit'), async (req, res, next) => {
  try {
    await query('DELETE FROM config WHERE id=?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

// 单个配置解析预览（Bot 优先，回退全局）
router.get('/resolve', requirePerm('config:view'), async (req, res, next) => {
  try {
    const { bot_id, key } = req.query;
    const row = await one(
      `SELECT config_value FROM config WHERE bot_id <=> ? AND config_key = ?`,
      [bot_id || null, key]
    );
    let value = row?.config_value;
    if (value === null || value === undefined) {
      const g = await one('SELECT config_value FROM config WHERE bot_id IS NULL AND config_key = ?', [key]);
      value = g?.config_value ?? null;
    }
    res.json({ value });
  } catch (e) { next(e); }
});

export default router;
