import { Router } from 'express';
import { query } from '../db.js';
import { requirePerm, botScopeFilter } from '../auth.js';

const router = Router();

router.get('/', requirePerm('dashboard:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const [botRow, userRow, msgRow, fwdRow] = await Promise.all([
      query(`SELECT COUNT(*) AS c FROM bots b WHERE ${scope.where}`, scope.params),
      query(
        `SELECT COUNT(DISTINCT uv.user_id) AS c
           FROM user_verification uv JOIN bots b ON b.bot_name = uv.bot_name
          WHERE ${scope.where}`, scope.params),
      query(
        `SELECT COUNT(*) AS c FROM message_log ml JOIN bots b ON b.id = ml.bot_id
          WHERE ${scope.where}`, scope.params),
      query(
        `SELECT COUNT(*) AS c FROM forward_map f JOIN bots b ON b.bot_name = f.bot_name
          WHERE ${scope.where}`, scope.params),
    ]);
    res.json({
      bot_count: botRow[0].c,
      user_count: userRow[0].c,
      message_count: msgRow[0].c,
      forward_count: fwdRow[0].c,
    });
  } catch (e) { next(e); }
});

router.get('/trend', requirePerm('dashboard:view'), async (req, res, next) => {
  try {
    const scope = botScopeFilter(req.ctx, 'b');
    const data = await query(
      `SELECT DATE(ml.created_at) AS day,
              SUM(ml.direction = 'in') AS inbound,
              SUM(ml.direction = 'out') AS outbound
         FROM message_log ml JOIN bots b ON b.id = ml.bot_id
        WHERE ${scope.where} AND ml.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(ml.created_at) ORDER BY day`,
      scope.params
    );
    res.json(data);
  } catch (e) { next(e); }
});

export default router;
