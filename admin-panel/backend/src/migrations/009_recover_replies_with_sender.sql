-- 009 在 008 基础上扩大真实客服回复的归位覆盖，并记录回复人。
-- 008 只走 forward_map 唯一映射，多管理员扇出的歧义副本（17条）被放弃；
-- 009 合并两个客户线索：
--   ① 副本行自带 forward_from（真·TG 转发时记录的原始客户 id）——最权威，优先；
--   ② forward_map 对 (bot,user) 唯一映射的副本（uniq=1）兜底。
-- bot 归属以“副本发送者 tgid”JOIN bots 为准（不看混桶的 message.bot_id）。
-- 回复人 = 管理员在自己私聊桶里亲手发的那条（r.user_id，即客服 TG id）。
--
-- 只重建割接前（< 2026-10-10）的历史 out；割接后 Go 实时归档（sender_id=0）保留。

-- 1) 清除 008 重建的历史 out（全部 < 割接），稍后用更全的集合重插
DELETE FROM message_log
 WHERE direction = 'out' AND created_at < '2026-10-10';

-- 2) 合并链重建（INSERT IGNORE 靠 uk_bot_user_msg 兜底）
INSERT IGNORE INTO message_log
  (bot_id, bot_name, user_id, message_id, direction, sender_id, msg_type,
   text_content, raw_content, created_at)
SELECT
  bb.id,
  bb.bot_name,
  z.cust,
  z.rid,
  'out',
  z.adminid,
  CASE
    WHEN COALESCE(z.photo, '') <> '' THEN 'photo'
    WHEN COALESCE(z.document, '') <> '' THEN 'document'
    WHEN COALESCE(z.voice, '') <> '' THEN 'voice'
    WHEN COALESCE(z.video, '') <> '' THEN 'video'
    ELSE 'text'
  END,
  NULLIF(COALESCE(z.txt, z.cap), ''),
  NULL,
  z.date
FROM (
  SELECT r.id AS rid,
         r.user_id AS adminid,
         c.user_id AS bottgid,
         r.date,
         r.text AS txt, r.caption AS cap,
         r.photo, r.document, r.voice, r.video,
         COALESCE(
           CASE WHEN c.forward_from IS NOT NULL
                 AND c.forward_from NOT IN (8748778438, 8745413860, 8222826461)
                 AND fa.id IS NULL
                THEN c.forward_from END,
           CASE WHEN fm.uniq = 1 THEN fm.cust END
         ) AS cust
  FROM message r
  JOIN message c
    ON c.chat_id = r.chat_id AND c.id = r.reply_to_message
   AND c.user_id IN (8748778438, 8745413860, 8222826461)
  LEFT JOIN admins fa ON fa.id = c.forward_from
  LEFT JOIN (
    SELECT forwarded_msg_id,
           MIN(user_id) AS cust,
           COUNT(DISTINCT bot_name, user_id) AS uniq
      FROM forward_map GROUP BY forwarded_msg_id
  ) fm ON fm.forwarded_msg_id = c.id
  WHERE r.chat_id = r.user_id
    AND r.reply_to_message IS NOT NULL
    AND r.user_id NOT IN (8748778438, 8745413860, 8222826461)
) z
JOIN bots bb
  ON CAST(SUBSTRING_INDEX(bb.api_key, ':', 1) AS UNSIGNED) = z.bottgid
WHERE z.cust IS NOT NULL;
