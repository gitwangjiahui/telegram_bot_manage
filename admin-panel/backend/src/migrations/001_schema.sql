-- ============================================================
-- Telegram 机器人管理后台 RBAC 权限表 + 消息归档表
-- 全部使用 CREATE TABLE IF NOT EXISTS，幂等，不影响现有功能
-- ============================================================

-- 后台管理员账号
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    real_name VARCHAR(64) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 角色
CREATE TABLE IF NOT EXISTS admin_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(64) NOT NULL UNIQUE,
    role_name VARCHAR(64) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 权限点（菜单/按钮/接口）
CREATE TABLE IF NOT EXISTS admin_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    perm_code VARCHAR(128) NOT NULL UNIQUE,
    perm_name VARCHAR(128) NOT NULL,
    module VARCHAR(64) NOT NULL,
    perm_type ENUM('menu','api') NOT NULL DEFAULT 'api',
    parent_code VARCHAR(128) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 角色-权限
CREATE TABLE IF NOT EXISTS admin_role_perms (
    role_id INT NOT NULL,
    perm_id INT NOT NULL,
    PRIMARY KEY (role_id, perm_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (perm_id) REFERENCES admin_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 管理员-角色
CREATE TABLE IF NOT EXISTS admin_user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 角色可见 Bot 范围；不存在任何记录 = 全部 Bot
CREATE TABLE IF NOT EXISTS admin_role_bots (
    role_id INT NOT NULL,
    bot_id INT NOT NULL,
    PRIMARY KEY (role_id, bot_id),
    CONSTRAINT fk_rb_role FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rb_bot FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 机器人消息归档（用户上行 / 管理员下行）
-- direction: in = 用户发给机器人, out = 管理员回复用户
CREATE TABLE IF NOT EXISTS message_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bot_id INT NOT NULL,
    bot_name VARCHAR(50) NOT NULL,
    user_id BIGINT NOT NULL,
    message_id BIGINT NOT NULL,
    direction ENUM('in','out') NOT NULL,
    sender_id BIGINT NOT NULL,
    msg_type VARCHAR(32) NOT NULL DEFAULT 'text',
    text_content MEDIUMTEXT,
    raw_content MEDIUMTEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_msg (bot_name, message_id, direction),
    INDEX idx_bot_user (bot_id, user_id, created_at),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
