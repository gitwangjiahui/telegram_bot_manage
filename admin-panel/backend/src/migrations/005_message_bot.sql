-- message 表增加 bot 归属：入站时由 botd 直接写入，不再靠转发关系反推。
-- 幂等：MySQL 8.0 的 ADD COLUMN 不支持 IF NOT EXISTS，用 information_schema 守卫。

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message' AND COLUMN_NAME = 'bot_id'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE message ADD COLUMN bot_id INT NULL AFTER id, ADD INDEX idx_bot_chat (bot_id, chat_id, date)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---- 历史回填（仅填仍为 NULL 的行，按可靠性从高到低）----

-- A) bot 自身发出的消息：发件人是 bot，其 TG user_id 即 token 冒号前的数字
UPDATE message m
JOIN `user` u ON u.id = m.user_id AND u.is_bot = 1
JOIN bots b ON CAST(SUBSTRING_INDEX(b.api_key, ':', 1) AS UNSIGNED) = m.user_id
SET m.bot_id = b.id
WHERE m.bot_id IS NULL;

-- B1) 用户入站消息：forward_map 精确记录了 original_msg_id
UPDATE message m
JOIN (SELECT DISTINCT bot_name, user_id, original_msg_id FROM forward_map) f
  ON f.user_id = m.chat_id AND f.original_msg_id = m.id
JOIN bots b ON b.bot_name = f.bot_name
SET m.bot_id = b.id
WHERE m.bot_id IS NULL;

-- B2) 转发到管理员侧的副本：reply_to_message 命中 forwarded_msg_id
UPDATE message m
JOIN forward_map f ON f.forwarded_msg_id = m.reply_to_message AND f.user_id <> m.chat_id
JOIN bots b ON b.bot_name = f.bot_name
SET m.bot_id = b.id
WHERE m.bot_id IS NULL;

-- C) 只验证过单个 bot 的用户：message 表不记 bot，多 bot 用户无法区分，不做猜测
UPDATE message m
JOIN user_verification uv ON uv.user_id = m.chat_id
JOIN (
    SELECT user_id FROM user_verification
    GROUP BY user_id HAVING COUNT(DISTINCT bot_name) = 1
) single ON single.user_id = uv.user_id
JOIN bots b ON b.bot_name = uv.bot_name
SET m.bot_id = b.id
WHERE m.bot_id IS NULL;
