<?php

namespace Model;

use Utils\DbManager;

/**
 * 验证码模型
 */
class VerificationCode
{
    private string $botName;
    private \PDO $pdo;

    public function __construct(string $botName)
    {
        $this->botName = $botName;
        $this->pdo = DbManager::getConnection();
    }

    /**
     * 保存验证码
     */
    public function save(int $userId, string $code, string $answer): void
    {
        $this->clear($userId);
        
        $sql = "INSERT INTO verification_codes (bot_name, user_id, code, answer, expires_at) 
                VALUES (:bot_name, :user_id, :code, :answer, DATE_ADD(NOW(), INTERVAL 5 MINUTE))";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':bot_name' => $this->botName,
            ':user_id' => $userId,
            ':code' => $code,
            ':answer' => $answer,
        ]);
    }

    /**
     * 验证验证码
     */
    public function verify(int $userId, string $answer): bool
    {
        $sql = "SELECT * FROM verification_codes 
                WHERE bot_name = :bot_name AND user_id = :user_id 
                AND answer = :answer AND expires_at > NOW()";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':bot_name' => $this->botName,
            ':user_id' => $userId,
            ':answer' => $answer,
        ]);
        $result = $stmt->fetch();
        
        if ($result) {
            $this->clear($userId);
            return true;
        }
        
        return false;
    }

    /**
     * 清除验证码
     */
    public function clear(int $userId): void
    {
        $sql = "DELETE FROM verification_codes WHERE bot_name = :bot_name AND user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':bot_name' => $this->botName, ':user_id' => $userId]);
    }
}
