<?php

namespace Model;

use Utils\DbManager;

/**
 * 转发映射模型
 */
class ForwardMap
{
    private string $botName;
    private \PDO $pdo;

    public function __construct(string $botName)
    {
        $this->botName = $botName;
        $this->pdo = DbManager::getConnection();
    }

    /**
     * 保存转发映射
     */
    public function save(int $forwardedMsgId, int $originalMsgId, int $userId): void
    {
        $sql = "INSERT INTO forward_map (bot_name, forwarded_msg_id, original_msg_id, user_id) 
                VALUES (:bot_name, :forwarded_msg_id, :original_msg_id, :user_id)
                ON DUPLICATE KEY UPDATE user_id = :user_id2";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':bot_name' => $this->botName,
            ':forwarded_msg_id' => $forwardedMsgId,
            ':original_msg_id' => $originalMsgId,
            ':user_id' => $userId,
            ':user_id2' => $userId,
        ]);
    }

    /**
     * 从转发消息ID获取用户ID
     */
    public function getUserId(int $forwardedMsgId): ?int
    {
        $sql = "SELECT user_id FROM forward_map 
                WHERE bot_name = :bot_name AND forwarded_msg_id = :forwarded_msg_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':bot_name' => $this->botName, ':forwarded_msg_id' => $forwardedMsgId]);
        $result = $stmt->fetch();
        return $result ? (int)$result['user_id'] : null;
    }
}
