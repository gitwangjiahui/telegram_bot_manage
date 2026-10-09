<?php

namespace Model;

use PDO;
use Utils\DbManager;

/**
 * 消息归档（供后台管理系统浏览）
 *
 * 安全约定：所有写入入口只暴露 safeRecord()，内部 catch \Throwable 全吞掉，
 * 归档失败绝不允许影响机器人正常收发逻辑。
 */
class MessageLog
{
    private static array $botIdCache = [];

    /**
     * 安全记录一条消息。任何异常都静默。
     *
     * @param string  $botName   机器人配置名
     * @param object  $message   Longman Message 实体
     * @param string  $direction in = 用户上行, out = 管理员回复
     * @param int     $senderId  实际发送者 TG ID
     * @param int     $userId    会话用户 TG ID（in 时等于 senderId）
     */
    public static function safeRecord(string $botName, $message, string $direction, int $senderId, int $userId): void
    {
        try {
            $pdo = DbManager::getConnection();

            if (!isset(self::$botIdCache[$botName])) {
                $stmt = $pdo->prepare("SELECT id FROM bots WHERE bot_name = ?");
                $stmt->execute([$botName]);
                $botId = (int) $stmt->fetchColumn();
                if ($botId <= 0) {
                    return;
                }
                self::$botIdCache[$botName] = $botId;
            }
            $botId = self::$botIdCache[$botName];

            $type = self::detectType($message);
            $text = $message->getText();
            if ($text === null && method_exists($message, 'getCaption')) {
                $text = $message->getCaption();
            }
            $text = $text === null ? null : (string) $text;
            if ($text !== null && strlen($text) > 5000) {
                $text = mb_substr($text, 0, 5000);
            }

            // message_log 可能尚未建表：用 INSERT IGNORE 语义无法忽略“表不存在”，
            // 因此整段仍在外层 try/catch 保护下，表不存在时静默返回。
            $stmt = $pdo->prepare(
                "INSERT INTO message_log
                    (bot_id, bot_name, user_id, message_id, direction, sender_id, msg_type, text_content)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE text_content = VALUES(text_content)"
            );
            $stmt->execute([
                $botId,
                $botName,
                $userId,
                $message->getMessageId(),
                $direction,
                $senderId,
                $type,
                $text,
            ]);
        } catch (\Throwable $e) {
            // 静默：归档不得影响主流程
        }
    }

    private static function detectType($message): string
    {
        $map = [
            'getPhoto' => 'photo',
            'getVoice' => 'voice',
            'getVideo' => 'video',
            'getVideoNote' => 'video_note',
            'getDocument' => 'document',
            'getSticker' => 'sticker',
            'getAnimation' => 'animation',
            'getAudio' => 'audio',
            'getContact' => 'contact',
            'getLocation' => 'location',
        ];
        foreach ($map as $getter => $type) {
            try {
                if (method_exists($message, $getter) && $message->$getter()) {
                    return $type;
                }
            } catch (\Throwable $e) {
                // 某些 getter 在字段不存在时可能抛错，继续探测
            }
        }
        return $message->getText() !== null ? 'text' : 'other';
    }
}
