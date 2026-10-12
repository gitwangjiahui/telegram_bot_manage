-- 008 纠正 007 的错误前提。
-- 007 把 Longman `message` 表里“bot 自己发出、目标 chat 是管理员”的 128 行
-- 误当成“客服回复客户”。实际上那些是 bot 转发给管理员的【客户消息副本】和
-- 【系统通知】（📩新消息 / ✅验证通过 / 操作指引）。老 PHP 系统把 bot→真实客户
-- 的回复发出后从不落库，所以真实客户会话只剩入站，看起来“没有来回”。
--
-- 真实客服回复其实可还原：客服在 Telegram 里对“bot 转发的客户消息副本”点回复，
-- 其输入文本以入站形式存在管理员私聊桶（chat_id=user_id=管理员，
-- reply_to_message=副本id）。经 副本id -> forward_map -> 唯一客户 即可归位。
--
-- 仅采纳 forward_map 中 (bot,user) 唯一映射的副本（uniq=1），多管理员扇出导致
-- 同一副本id映射多客户的歧义行（17条）与回给系统通知的行（29条）一律不重建。

-- 1) 删除 007 误填：direction=out 且 user_id 是管理员（副本/通知，非客户回复）
DELETE ml
FROM message_log ml
JOIN admins a ON a.id = ml.user_id
WHERE ml.direction = 'out';

-- 2) 重建真实客服回复（bot 归属以权威 forward_map 为准，不信混桶的 message.bot_id）
INSERT IGNORE INTO message_log
  (bot_id, bot_name, user_id, message_id, direction, sender_id, msg_type,
   text_content, raw_content, created_at)
SELECT
  b.id,
  fm.bn,
  fm.cust,
  r.id,
  'out',
  r.user_id,
  CASE
    WHEN COALESCE(r.photo, '') <> '' THEN 'photo'
    WHEN COALESCE(r.document, '') <> '' THEN 'document'
    WHEN COALESCE(r.voice, '') <> '' THEN 'voice'
    WHEN COALESCE(r.video, '') <> '' THEN 'video'
    ELSE 'text'
  END,
  NULLIF(COALESCE(r.text, r.caption), ''),
  NULL,
  r.date
FROM message r
JOIN message c
  ON c.chat_id = r.chat_id
 AND c.id = r.reply_to_message
 AND c.user_id IN (8748778438, 8745413860, 8222826461)   -- 三个 bot 自身 tgid
JOIN (
  SELECT forwarded_msg_id,
         MIN(bot_name) AS bn,
         MIN(user_id)  AS cust,
         COUNT(DISTINCT bot_name, user_id) AS uniq
  FROM forward_map
  GROUP BY forwarded_msg_id
) fm ON fm.forwarded_msg_id = c.id AND fm.uniq = 1
JOIN bots b ON b.bot_name = fm.bn
WHERE r.chat_id = r.user_id          -- 管理员在自己私聊桶里亲手发的
  AND r.reply_to_message IS NOT NULL
  AND r.user_id NOT IN (8748778438, 8745413860, 8222826461);
