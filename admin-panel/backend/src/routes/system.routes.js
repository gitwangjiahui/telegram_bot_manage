import { Router } from 'express';
import bcrypt from 'bcryptjs';
import { query, one } from '../db.js';
import { requirePerm } from '../auth.js';

const router = Router();

/* ---------- 权限点 ---------- */
router.get('/permissions', requirePerm('system:view'), async (req, res, next) => {
  try {
    res.json(await query('SELECT * FROM admin_permissions ORDER BY module, sort_order'));
  } catch (e) { next(e); }
});

/* ---------- 角色 ---------- */
router.get('/roles', requirePerm('system:view'), async (req, res, next) => {
  try {
    const roles = await query('SELECT * FROM admin_roles ORDER BY id');
    const perms = await query('SELECT role_id, perm_id FROM admin_role_perms');
    const bots = await query('SELECT role_id, bot_id FROM admin_role_bots');
    const permMap = new Map();
    const botMap = new Map();
    for (const p of perms) {
      if (!permMap.has(p.role_id)) permMap.set(p.role_id, []);
      permMap.get(p.role_id).push(p.perm_id);
    }
    for (const b of bots) {
      if (!botMap.has(b.role_id)) botMap.set(b.role_id, []);
      botMap.get(b.role_id).push(b.bot_id);
    }
    res.json(roles.map((r) => ({
      ...r,
      perm_ids: permMap.get(r.id) || [],
      bot_ids: botMap.get(r.id) || [], // 空 = 全部 Bot
    })));
  } catch (e) { next(e); }
});

router.post('/roles', requirePerm('system:role:manage'), async (req, res, next) => {
  try {
    const { role_code, role_name, description, perm_ids = [], bot_ids = [] } = req.body || {};
    if (!role_code || !role_name) return res.status(400).json({ message: '角色编码和名称必填' });
    const result = await query(
      'INSERT INTO admin_roles (role_code, role_name, description) VALUES (?, ?, ?)',
      [role_code, role_name, description || null]
    );
    await saveRoleRelations(result.insertId, perm_ids, bot_ids);
    res.json({ id: result.insertId });
  } catch (e) { next(e); }
});

router.put('/roles/:id', requirePerm('system:role:manage'), async (req, res, next) => {
  try {
    const id = req.params.id;
    const role = await one('SELECT * FROM admin_roles WHERE id = ?', [id]);
    if (!role) return res.status(404).json({ message: '角色不存在' });
    if (role.is_builtin) return res.status(400).json({ message: '内置角色不可修改' });

    const { role_name, description, perm_ids = [], bot_ids = [] } = req.body || {};
    await query('UPDATE admin_roles SET role_name=?, description=? WHERE id=?',
      [role_name, description || null, id]);
    await saveRoleRelations(id, perm_ids, bot_ids);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

router.delete('/roles/:id', requirePerm('system:role:manage'), async (req, res, next) => {
  try {
    const role = await one('SELECT is_builtin FROM admin_roles WHERE id = ?', [req.params.id]);
    if (role?.is_builtin) return res.status(400).json({ message: '内置角色不可删除' });
    await query('DELETE FROM admin_roles WHERE id = ?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

async function saveRoleRelations(roleId, permIds, botIds) {
  await query('DELETE FROM admin_role_perms WHERE role_id = ?', [roleId]);
  await query('DELETE FROM admin_role_bots WHERE role_id = ?', [roleId]);
  for (const pid of permIds) {
    await query('INSERT INTO admin_role_perms (role_id, perm_id) VALUES (?, ?)', [roleId, pid]);
  }
  for (const bid of botIds) {
    await query('INSERT INTO admin_role_bots (role_id, bot_id) VALUES (?, ?)', [roleId, bid]);
  }
}

/* ---------- 后台账号 ---------- */
router.get('/admins', requirePerm('system:view'), async (req, res, next) => {
  try {
    const users = await query(
      `SELECT id, username, real_name, is_active, last_login_at, created_at
         FROM admin_users ORDER BY id`
    );
    const roles = await query(
      `SELECT ur.user_id, r.id AS role_id, r.role_name
         FROM admin_user_roles ur JOIN admin_roles r ON r.id = ur.role_id`
    );
    const map = new Map(users.map((u) => [u.id, { ...u, roles: [] }]));
    for (const r of roles) map.get(r.user_id)?.roles.push({ id: r.role_id, name: r.role_name });
    res.json([...map.values()]);
  } catch (e) { next(e); }
});

router.post('/admins', requirePerm('system:admin:manage'), async (req, res, next) => {
  try {
    const { username, password, real_name, role_ids = [] } = req.body || {};
    if (!username || !password) return res.status(400).json({ message: '账号密码必填' });
    if (await one('SELECT id FROM admin_users WHERE username = ?', [username])) {
      return res.status(409).json({ message: '账号已存在' });
    }
    const hash = await bcrypt.hash(password, 10);
    const result = await query(
      'INSERT INTO admin_users (username, password_hash, real_name) VALUES (?, ?, ?)',
      [username, hash, real_name || null]
    );
    for (const rid of role_ids) {
      await query('INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?)',
        [result.insertId, rid]);
    }
    res.json({ id: result.insertId });
  } catch (e) { next(e); }
});

router.put('/admins/:id', requirePerm('system:admin:manage'), async (req, res, next) => {
  try {
    const id = req.params.id;
    const { real_name, is_active, password, role_ids } = req.body || {};
    const user = await one('SELECT * FROM admin_users WHERE id = ?', [id]);
    if (!user) return res.status(404).json({ message: '账号不存在' });

    if (real_name !== undefined) {
      await query('UPDATE admin_users SET real_name=?, is_active=? WHERE id=?',
        [real_name, is_active === undefined ? user.is_active : (is_active ? 1 : 0), id]);
    } else if (is_active !== undefined) {
      await query('UPDATE admin_users SET is_active=? WHERE id=?', [is_active ? 1 : 0, id]);
    }
    if (password) {
      const hash = await bcrypt.hash(password, 10);
      await query('UPDATE admin_users SET password_hash=? WHERE id=?', [hash, id]);
    }
    if (role_ids) {
      await query('DELETE FROM admin_user_roles WHERE user_id = ?', [id]);
      for (const rid of role_ids) {
        await query('INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?)', [id, rid]);
      }
    }
    res.json({ ok: true });
  } catch (e) { next(e); }
});

router.delete('/admins/:id', requirePerm('system:admin:manage'), async (req, res, next) => {
  try {
    if (Number(req.params.id) === req.user.id) {
      return res.status(400).json({ message: '不能删除自己' });
    }
    await query('DELETE FROM admin_users WHERE id = ?', [req.params.id]);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

export default router;
