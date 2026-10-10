-- Bot 进程控制队列（与 PHP config/schema.sql 保持一致）
CREATE TABLE IF NOT EXISTS bot_control (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bot_id INT NOT NULL,
    action ENUM('start','stop','restart') NOT NULL,
    status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
    result VARCHAR(255) DEFAULT NULL,
    requested_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    executed_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
