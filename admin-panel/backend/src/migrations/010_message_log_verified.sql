-- 010 未验证消息留痕：门控挡下的消息只归档不转发，用 is_verified=0 标记。
-- 历史已入站消息与全部出站消息均视为正常（=1），列默认 1 保证 Go/Node 旧 INSERT 不受影响。
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'message_log' AND COLUMN_NAME = 'is_verified');
SET @ddl := IF(@col = 0,
  'ALTER TABLE message_log ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER raw_content',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 存量数据显式置 1（默认值已覆盖，这里幂等保险）。
UPDATE message_log SET is_verified = 1 WHERE is_verified IS NULL OR is_verified <> 0;

-- 便于按会话筛未验证
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log'
                AND INDEX_NAME = 'idx_verified');
SET @idxl := IF(@idx = 0,
  'CREATE INDEX idx_verified ON message_log (bot_id, user_id, is_verified, created_at)',
  'SELECT 1');
PREPARE i FROM @idxl; EXECUTE i; DEALLOCATE PREPARE i;
