-- 机器人管理表
CREATE TABLE IF NOT EXISTS bots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_name VARCHAR(50) NOT NULL UNIQUE,
    api_key VARCHAR(255) NOT NULL,
    bot_username VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
);

-- 管理员表
CREATE TABLE IF NOT EXISTS admins (
    id BIGINT PRIMARY KEY,
    username VARCHAR(100),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 管理员与 Bot 关系表（多对多）
CREATE TABLE IF NOT EXISTS bot_admin_rela (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_id INT NOT NULL,
    admin_id BIGINT NOT NULL,
    admin_type ENUM('super', 'normal') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_bot_admin (bot_id, admin_id),
    INDEX idx_bot_id (bot_id),
    INDEX idx_admin_id (admin_id),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
);

-- 转发映射表
CREATE TABLE IF NOT EXISTS forward_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_name VARCHAR(50) NOT NULL,
    forwarded_msg_id BIGINT NOT NULL,
    original_msg_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_unique_forward (bot_name, forwarded_msg_id),
    INDEX idx_user (user_id)
);

-- 用户验证表
CREATE TABLE IF NOT EXISTS user_verification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_name VARCHAR(50) NOT NULL,
    user_id BIGINT NOT NULL,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_unique_user (bot_name, user_id)
);

-- 验证码表（临时）
CREATE TABLE IF NOT EXISTS verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_name VARCHAR(50) NOT NULL,
    user_id BIGINT NOT NULL,
    code VARCHAR(50) NOT NULL,
    answer VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    INDEX idx_user_code (bot_name, user_id)
);

-- 配置表（全局配置 + Bot 配置）
-- bot_id IS NULL: 全局配置
-- bot_id IS NOT NULL: Bot 专属配置
CREATE TABLE IF NOT EXISTS config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_id INT NULL,
    config_key VARCHAR(50) NOT NULL,
    config_value TEXT,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_config (bot_id, config_key),
    INDEX idx_bot_id (bot_id),
    INDEX idx_config_key (config_key),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
);
