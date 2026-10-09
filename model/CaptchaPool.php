<?php

namespace Model;

use Utils\DbManager;

/**
 * 验证码预生成池模型
 *
 * 池维护子进程提前生成并上传图片；/start 通过 acquire() 原子取出一条 file_id 使用。
 */
class CaptchaPool
{
    private string $botName;
    private \PDO $pdo;

    public function __construct(string $botName)
    {
        $this->botName = $botName;
        $this->pdo = DbManager::getConnection();
    }

    /**
     * 可用验证码数量
     */
    public function countAvailable(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM captcha_pool WHERE bot_name = ? AND status = 'available'"
        );
        $stmt->execute([$this->botName]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * 原子取出一条可用验证码（取出即标记为 used）
     *
     * @return array{file_id:string, code:string, answer:string}|null
     */
    public function acquire(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, file_id, code, answer FROM captcha_pool
                 WHERE bot_name = ? AND status = 'available'
                 ORDER BY id LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$this->botName]);
            $row = $stmt->fetch();

            if (!$row) {
                $this->pdo->commit();
                return null;
            }

            $upd = $this->pdo->prepare(
                "UPDATE captcha_pool SET status = 'used', used_at = NOW() WHERE id = ?"
            );
            $upd->execute([$row['id']]);
            $this->pdo->commit();

            return [
                'file_id' => $row['file_id'],
                'code'    => $row['code'],
                'answer'  => $row['answer'],
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 批量写入预生成验证码
     *
     * @param array<int, array{file_id:string, code:string, answer:string}> $items
     */
    public function insertMany(array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        $sql = "INSERT INTO captcha_pool (bot_name, file_id, code, answer)
                VALUES (:bot_name, :file_id, :code, :answer)";
        $stmt = $this->pdo->prepare($sql);

        $count = 0;
        foreach ($items as $it) {
            $stmt->execute([
                ':bot_name' => $this->botName,
                ':file_id'  => $it['file_id'],
                ':code'     => $it['code'],
                ':answer'   => $it['answer'],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * 清理已使用超过保留天数的记录，避免表无限增长
     */
    public function pruneUsed(int $keepDays = 3): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM captcha_pool
             WHERE bot_name = ? AND status = 'used'
             AND used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$this->botName, $keepDays]);
        return $stmt->rowCount();
    }
}
