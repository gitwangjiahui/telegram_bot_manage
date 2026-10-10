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
              hb.pid, hb.started_at, hb.heartbeat_at, hb.captcha_available,
              hb.today_in, hb.today_out, hb.last_error,
              CASE WHEN hb.heartbeat_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
                   THEN 1 ELSE 0 END AS is_running,
              (SELECT COUNT(DISTINCT uv.user_id) FROM user_verification uv
                WHERE uv.bot_name = b.bot_name) AS user_count,
              (SELECT COUNT(*) FROM bot_admin_rela r WHERE r.bot_id = b.id) AS admin_count
         FROM bots b
         LEFT JOIN bot_heartbeat hb ON hb.bot_name = b.bot_name
        WHERE ${scope.where} ORDER BY b.id`,
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

// 进程控制（start/stop/restart）：写入 bot_control 队列，由宿主 control_worker.php 执行
router.post('/:id/control', requirePerm('bot:edit'), async (req, res, next) => {
  try {
    const action = String(req.body?.action || '');
    if (!['start', 'stop', 'restart'].includes(action)) {
      return res.status(400).json({ message: 'action 必须是 start/stop/restart' });
    }
    const bot = await one('SELECT id FROM bots WHERE id = ?', [req.params.id]);
    if (!bot) return res.status(404).json({ message: '机器人不存在' });

    const result = await query(
      `INSERT INTO bot_control (bot_id, action, requested_by) VALUES (?, ?, ?)`,
      [bot.id, action, req.user?.id ?? null]
    );
    // 返回 control_id，前端据此匹配 WS bot_lifecycle 帧，精确结束过程动画
    res.json({ ok: true, control_id: result.insertId, message: '命令已下发，约 1~2 秒生效' });
  } catch (e) { next(e); }
});

// 最近一次控制结果（前端轮询确认执行结果）
router.get('/:id/control-last', requirePerm('bot:view'), async (req, res, next) => {
  try {
    const row = await one(
      `SELECT id, action, status, result, created_at, executed_at
         FROM bot_control WHERE bot_id = ? ORDER BY id DESC LIMIT 1`,
      [req.params.id]
    );
    res.json(row || null);
  } catch (e) { next(e); }
});

export default router;
