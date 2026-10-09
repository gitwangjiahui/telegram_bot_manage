import jwt from 'jsonwebtoken';
import { config } from './config.js';
import { query, one } from './db.js';

// 加载用户角色、权限、Bot 范围
async function loadUserContext(userId) {
  const roles = await query(
    `SELECT r.id, r.role_code, r.role_name
       FROM admin_user_roles ur JOIN admin_roles r ON r.id = ur.role_id
      WHERE ur.user_id = ?`,
    [userId]
  );
  if (roles.length === 0) return null;

  const roleIds = roles.map((r) => r.id);
  const placeholders = roleIds.map(() => '?').join(',');
  const perms = await query(
    `SELECT DISTINCT p.perm_code
       FROM admin_role_perms rp JOIN admin_permissions p ON p.id = rp.perm_id
      WHERE rp.role_id IN (${placeholders})`,
    roleIds
  );
  const botRows = await query(
    `SELECT DISTINCT bot_id FROM admin_role_bots WHERE role_id IN (${placeholders})`,
    roleIds
  );

  return {
    roles,
    isSuper: roles.some((r) => r.role_code === 'super_admin'),
    permissions: new Set(perms.map((p) => p.perm_code)),
    // null = 全部 Bot；否则为可见 id 列表
    botScope: botRows.length === 0 ? null : botRows.map((b) => b.bot_id),
  };
}

export async function authRequired(req, res, next) {
  try {
    const header = req.headers.authorization || '';
    const token = header.startsWith('Bearer ') ? header.slice(7) : null;
    if (!token) return res.status(401).json({ message: '未登录' });

    const payload = jwt.verify(token, config.jwtSecret);
    const user = await one(
      'SELECT id, username, real_name, is_active FROM admin_users WHERE id = ?',
      [payload.sub]
    );
    if (!user || !user.is_active) return res.status(401).json({ message: '账号不可用' });

    const ctx = await loadUserContext(user.id);
    if (!ctx) return res.status(403).json({ message: '未分配角色' });

    req.user = user;
    req.ctx = ctx;
    next();
  } catch (e) {
    return res.status(401).json({ message: '登录已过期' });
  }
}

export function requirePerm(code) {
  return (req, res, next) => {
    if (req.ctx.isSuper || req.ctx.permissions.has(code)) return next();
    return res.status(403).json({ message: '没有操作权限' });
  };
}

// 过滤 SQL 中 Bot 范围，返回 {where, params}
export function botScopeFilter(ctx, alias = 'b') {
  if (ctx.isSuper || ctx.botScope === null) return { where: '1=1', params: [] };
  const placeholders = ctx.botScope.map(() => '?').join(',');
  return { where: `${alias}.id IN (${placeholders})`, params: [...ctx.botScope] };
}
