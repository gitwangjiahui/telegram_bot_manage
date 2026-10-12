-- 007: 回填历史"机器人侧"出站消息（006 只补了用户入站，漏掉旧库主动入库的 bot 回复）
-- Longman message 表在私聊会话里同时存有：
--   user_id = chat_id            → 用户入站（006 已回填 direction=in）
--   user_id = <机器人tgid> ≠ chat_id → 机器人发给用户的回复/验证码/自动回复（本文件补 direction=out）
-- 只取正 chat（私聊，排除负 id 的群/频道），且行自身 bot_id 已正确、发送者确为某 bot。
-- 幂等：INSERT IGNORE 依赖唯一键 uk_bot_user_msg(bot_id,user_id,message_id,direction)。
-- 私聊内 user/bot 消息共享同一 chat 的 TG message_id 序列，out 与 in 不会撞 id。

INSERT IGNORE INTO message_log
  (bot_id, bot_name, user_id, message_id, direction, sender_id,
   msg_type, text_content, raw_content, created_at)
SELECT
  m.bot_id,
  b.bot_name,
  m.chat_id AS user_id,          -- 会话归属到该私聊用户
  m.id      AS message_id,
  'out'     AS direction,
  0         AS sender_id,        -- 机器人/后台侧，与既有 out 口径一致
  CASE
    WHEN m.photo IS NOT NULL THEN 'photo'
    WHEN m.voice IS NOT NULL THEN 'voice'
    WHEN m.video IS NOT NULL THEN 'video'
    WHEN m.video_note IS NOT NULL THEN 'video_note'
    WHEN m.document IS NOT NULL THEN 'document'
    WHEN m.sticker IS NOT NULL THEN 'sticker'
    WHEN m.animation IS NOT NULL THEN 'animation'
    WHEN m.audio IS NOT NULL THEN 'audio'
    WHEN m.contact IS NOT NULL THEN 'contact'
    WHEN m.location IS NOT NULL THEN 'location'
    WHEN m.text IS NOT NULL THEN 'text'
    ELSE 'other'
  END AS msg_type,
  COALESCE(m.text, m.caption) AS text_content,
  JSON_OBJECT(
    'message_id', m.id,
    'date', UNIX_TIMESTAMP(m.date),
    'text', m.text,
    'caption', m.caption,
    'photo',      CASE WHEN m.photo      IS NOT NULL THEN CAST(m.photo      AS JSON) END,
    'voice',      CASE WHEN m.voice      IS NOT NULL THEN CAST(m.voice      AS JSON) END,
    'video',      CASE WHEN m.video      IS NOT NULL THEN CAST(m.video      AS JSON) END,
    'video_note', CASE WHEN m.video_note IS NOT NULL THEN CAST(m.video_note AS JSON) END,
    'document',   CASE WHEN m.document   IS NOT NULL THEN CAST(m.document   AS JSON) END,
    'sticker',    CASE WHEN m.sticker    IS NOT NULL THEN CAST(m.sticker    AS JSON) END,
    'animation',  CASE WHEN m.animation  IS NOT NULL THEN CAST(m.animation  AS JSON) END,
    'audio',      CASE WHEN m.audio      IS NOT NULL THEN CAST(m.audio      AS JSON) END,
    'contact',    CASE WHEN m.contact    IS NOT NULL THEN CAST(m.contact    AS JSON) END,
    'location',   CASE WHEN m.location   IS NOT NULL THEN CAST(m.location   AS JSON) END
  ) AS raw_content,
  m.date AS created_at
FROM message m
JOIN bots b ON b.id = m.bot_id
JOIN user bu ON bu.id = m.user_id AND bu.is_bot = 1   -- 发送者确为机器人
WHERE m.bot_id IS NOT NULL
  AND m.chat_id > 0                                   -- 仅私聊，排除负 id 的群/频道
  AND m.chat_id <> m.user_id;
