-- 权限点、内置角色初始化（幂等）
-- 模块：dashboard / bot / user / forward / config / message / system

INSERT INTO admin_permissions (perm_code, perm_name, module, perm_type, parent_code, sort_order) VALUES
-- 仪表盘
('dashboard:view',        '仪表盘查看',   'dashboard', 'menu', NULL, 10),
-- 机器人管理
('bot:view',              '机器人查看',   'bot', 'menu', NULL, 20),
('bot:edit',              '机器人编辑',   'bot', 'api',  NULL, 21),
('bot:create',            '机器人新增',   'bot', 'api',  NULL, 22),
('bot:delete',            '机器人删除',   'bot', 'api',  NULL, 23),
-- 用户管理
('user:view',             '用户查看',     'user', 'menu', NULL, 30),
('user:verify:manage',    '验证状态管理', 'user', 'api',  NULL, 31),
('user:admin:manage',     '机器人管理员管理', 'user', 'api', NULL, 32),
-- 转发设置
('forward:view',          '转发设置查看', 'forward', 'menu', NULL, 40),
('forward:edit',          '转发设置编辑', 'forward', 'api',  NULL, 41),
-- 配置管理
('config:view',           '配置查看',     'config', 'menu', NULL, 50),
('config:edit',           '配置编辑',     'config', 'api',  NULL, 51),
-- 历史消息
('message:view',          '历史消息查看', 'message', 'menu', NULL, 60),
-- 系统管理（后台账号/角色）
('system:view',           '系统管理查看', 'system', 'menu', NULL, 90),
('system:admin:manage',   '后台账号管理', 'system', 'api',  NULL, 91),
('system:role:manage',    '角色权限管理', 'system', 'api',  NULL, 92)
ON DUPLICATE KEY UPDATE perm_name = VALUES(perm_name);

-- 内置超级角色
INSERT INTO admin_roles (role_code, role_name, description, is_builtin)
VALUES ('super_admin', '超级管理员', '拥有全部权限与全部机器人可见范围', 1)
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- 超级角色绑定全部权限
INSERT INTO admin_role_perms (role_id, perm_id)
SELECT r.id, p.id FROM admin_roles r CROSS JOIN admin_permissions p
WHERE r.role_code = 'super_admin'
ON DUPLICATE KEY UPDATE role_id = role_id;
