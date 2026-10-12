-- 006: 消息归档统一到 message_log（方案A）
-- 1) 唯一键补 user_id：TG message_id 按聊天各自从1开始，旧键 (bot_name,message_id,direction)
--    会让同一 bot 下不同用户的相同 id 互相覆盖。
-- 2) 从 Longman message 表回填历史私聊入站消息（多 bot 撞键的行每 (bot,user,id) 只取一条）。
--    raw_content 包装成标准 TG message 的媒体子结构，供 Node parseMedia 直接消费。
-- 全程幂等：守卫 + INSERT IGNORE（依赖新唯一键）。

-- 1) 更换唯一键 -------------------------------------------------------------
SET @have_old := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log'
    AND INDEX_NAME = 'uk_msg'
);
SET @ddl := IF(@have_old > 0,
  'ALTER TABLE message_log DROP INDEX uk_msg',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @have_new := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log'
    AND INDEX_NAME = 'uk_bot_user_msg'
);
SET @ddl := IF(@have_new = 0,
  'ALTER TABLE message_log ADD UNIQUE KEY uk_bot_user_msg (bot_id, user_id, message_id, direction)',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 覆盖“割接脏行”等场景下可能缺失的用户+时间索引
SET @have_idx := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log'
    AND INDEX_NAME = 'idx_bot_user_time'
);
SET @ddl := IF(@have_idx = 0,
  'ALTER TABLE message_log ADD INDEX idx_bot_user_time (bot_id, user_id, created_at)',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) 回填历史入站私聊 -------------------------------------------------------
--    每个 (bot_id,user_id,message_id) 取一行（多 bot 共库撞键时去重），
--    只补 message_log 中尚不存在的键。
INSERT IGNORE INTO message_log
  (bot_id, bot_name, user_id, message_id, direction, sender_id,
   msg_type, text_content, raw_content, created_at)
SELECT
  t.bot_id,
  b.bot_name,
  t.user_id,
  t.id AS message_id,
  'in' AS direction,
  t.user_id AS sender_id,
  CASE
    WHEN t.photo IS NOT NULL THEN 'photo'
    WHEN t.voice IS NOT NULL THEN 'voice'
    WHEN t.video IS NOT NULL THEN 'video'
    WHEN t.video_note IS NOT NULL THEN 'video_note'
    WHEN t.document IS NOT NULL THEN 'document'
    WHEN t.sticker IS NOT NULL THEN 'sticker'
    WHEN t.animation IS NOT NULL THEN 'animation'
    WHEN t.audio IS NOT NULL THEN 'audio'
    WHEN t.contact IS NOT NULL THEN 'contact'
    WHEN t.location IS NOT NULL THEN 'location'
    WHEN t.text IS NOT NULL THEN 'text'
    ELSE 'other'
  END AS msg_type,
  COALESCE(t.text, t.caption) AS text_content,
  JSON_OBJECT(
    'message_id', t.id,
    'date', UNIX_TIMESTAMP(t.date),
    'text', t.text,
    'caption', t.caption,
    'photo',      CASE WHEN t.photo      IS NOT NULL THEN CAST(t.photo      AS JSON) END,
    'voice',      CASE WHEN t.voice      IS NOT NULL THEN CAST(t.voice      AS JSON) END,
    'video',      CASE WHEN t.video      IS NOT NULL THEN CAST(t.video      AS JSON) END,
    'video_note', CASE WHEN t.video_note IS NOT NULL THEN CAST(t.video_note AS JSON) END,
    'document',   CASE WHEN t.document   IS NOT NULL THEN CAST(t.document   AS JSON) END,
    'sticker',    CASE WHEN t.sticker    IS NOT NULL THEN CAST(t.sticker    AS JSON) END,
    'animation',  CASE WHEN t.animation  IS NOT NULL THEN CAST(t.animation  AS JSON) END,
    'audio',      CASE WHEN t.audio      IS NOT NULL THEN CAST(t.audio      AS JSON) END,
    'contact',    CASE WHEN t.contact    IS NOT NULL THEN CAST(t.contact    AS JSON) END,
    'location',   CASE WHEN t.location   IS NOT NULL THEN CAST(t.location   AS JSON) END
  ) AS raw_content,
  t.date AS created_at
FROM (
  SELECT m.bot_id, m.user_id, m.id, m.date, m.text, m.caption,
         m.photo, m.voice, m.video, m.video_note, m.document,
         m.sticker, m.animation, m.audio, m.contact, m.location,
         ROW_NUMBER() OVER (PARTITION BY m.bot_id, m.user_id, m.id
                            ORDER BY m.date DESC) AS rn
    FROM message m
   WHERE m.bot_id IS NOT NULL
     AND m.chat_id = m.user_id
     AND COALESCE((SELECT u.is_bot FROM user u WHERE u.id = m.user_id), 0) = 0
) t
JOIN bots b ON b.id = t.bot_id
WHERE t.rn = 1;
