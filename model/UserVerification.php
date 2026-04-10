<?php

namespace Model;

use Utils\DbManager;

/**
 * 用户验证模型
 */
class UserVerification
{
    private string $botName;
    private \PDO $pdo;

    public function __construct(string $botName)
    {
        $this->botName = $botName;
        $this->pdo = DbManager::getConnection();
    }

    /**
     * 标记用户已验证
     */
    public function markVerified(int $userId): void
    {
        $sql = "INSERT INTO user_verification (bot_name, user_id) VALUES (:bot_name, :user_id)
                ON DUPLICATE KEY UPDATE verified_at = NOW()";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':bot_name' => $this->botName, ':user_id' => $userId]);
    }

    /**
     * 检查用户是否已验证
     */
    public function isVerified(int $userId): bool
    {
        $sql = "SELECT * FROM user_verification WHERE bot_name = :bot_name AND user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':bot_name' => $this->botName, ':user_id' => $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * 删除用户验证记录
     */
    public function remove(int $userId): void
    {
        $sql = "DELETE FROM user_verification WHERE bot_name = :bot_name AND user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':bot_name' => $this->botName, ':user_id' => $userId]);
    }
}
