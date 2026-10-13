-- 011 补回填割接前 bot_id IS NULL 的历史私聊消息（绝大多数是未验证广告号）。
-- 当年三 bot 共库撞键、归属列没写上，006 因 bot_id IS NULL 跳过，后台看不到。
-- 归属依据：广告号都会先 /start，verification_codes 发码时即写 bot_name。
-- 只采纳“该用户在验证码表里只对应单一 bot”的用户（零歧义，实测 170/181 用户、849 行），
-- 多 bot / 无验证码记录的不强行归属。
-- is_verified：该 (user,bot) 在 user_verification 有记录=1，否则未验证=0。
-- INSERT IGNORE 靠 uk_bot_user_msg 去重，不覆盖已归档行。

INSERT IGNORE INTO message_log
  (bot_id, bot_name, user_id, message_id, direction, sender_id,
   msg_type, text_content, raw_content, is_verified, created_at)
SELECT
  b.id,
  b.bot_name,
  t.chat_id AS user_id,
  t.id AS message_id,
  'in' AS direction,
  t.chat_id AS sender_id,
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
  CASE WHEN uv.user_id IS NOT NULL THEN 1 ELSE 0 END AS is_verified,
  t.date AS created_at
FROM (
  SELECT m.chat_id, m.user_id, m.id, m.date, m.text, m.caption,
         m.photo, m.voice, m.video, m.video_note, m.document,
         m.sticker, m.animation, m.audio, m.contact, m.location,
         vc.bn AS attr_bot,
         ROW_NUMBER() OVER (PARTITION BY vc.bn, m.chat_id, m.id
                            ORDER BY m.date DESC) AS rn
  FROM message m
  JOIN (
    SELECT user_id, MIN(bot_name) AS bn
    FROM verification_codes
    GROUP BY user_id
    HAVING COUNT(DISTINCT bot_name) = 1
  ) vc ON vc.user_id = m.chat_id
  WHERE m.bot_id IS NULL
    AND m.chat_id > 0
    AND m.chat_id = m.user_id
    AND COALESCE((SELECT u.is_bot FROM user u WHERE u.id = m.user_id), 0) = 0
) t
JOIN bots b ON b.bot_name = t.attr_bot COLLATE utf8mb4_unicode_ci
LEFT JOIN user_verification uv
  ON uv.user_id = t.chat_id
 AND uv.bot_name = t.attr_bot COLLATE utf8mb4_unicode_ci
WHERE t.rn = 1;
