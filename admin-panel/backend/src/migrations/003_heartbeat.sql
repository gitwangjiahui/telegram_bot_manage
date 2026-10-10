-- Bot 运行心跳（由 PHP 守护进程上报）
CREATE TABLE IF NOT EXISTS bot_heartbeat (
    bot_name VARCHAR(50) PRIMARY KEY,
    pid INT NOT NULL,
    started_at TIMESTAMP NULL,
    heartbeat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_update_id BIGINT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'polling',
    today_in INT NOT NULL DEFAULT 0,
    today_out INT NOT NULL DEFAULT 0,
    captcha_available INT NOT NULL DEFAULT 0,
    last_error TEXT,
    last_error_at TIMESTAMP NULL,
    INDEX idx_heartbeat (heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
