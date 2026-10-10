<?php

namespace Model;

use Utils\DbManager;

/**
 * Bot 运行心跳
 *
 * 守护进程每轮上报真实状态；管理后端按 heartbeat_at 新鲜度判定存活。
 * 写入失败静默，绝不影响主循环。
 */
class Heartbeat
{
    /**
     * 上报心跳（upsert）
     *
     * @param array{
     *   pid:int, started_at:?string, last_update_id:int, status:string,
     *   today_in:int, today_out:int, captcha_available:int,
     *   last_error:?string, last_error_at:?string
     * } $d
     */
    public static function touch(string $botName, array $d): void
    {
        try {
            $pdo = DbManager::getConnection();
            $sql = "INSERT INTO bot_heartbeat
                        (bot_name, pid, started_at, heartbeat_at, last_update_id, status,
                         today_in, today_out, captcha_available, last_error, last_error_at)
                    VALUES
                        (:bot_name, :pid, :started_at, NOW(), :last_update_id, :status,
                         :today_in, :today_out, :captcha_available, :last_error, :last_error_at)
                    ON DUPLICATE KEY UPDATE
                        pid = VALUES(pid),
                        started_at = VALUES(started_at),
                        heartbeat_at = NOW(),
                        last_update_id = VALUES(last_update_id),
                        status = VALUES(status),
                        today_in = VALUES(today_in),
                        today_out = VALUES(today_out),
                        captcha_available = VALUES(captcha_available),
                        last_error = VALUES(last_error),
                        last_error_at = VALUES(last_error_at)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':bot_name'          => $botName,
                ':pid'               => $d['pid'],
                ':started_at'        => $d['started_at'],
                ':last_update_id'    => $d['last_update_id'],
                ':status'            => $d['status'],
                ':today_in'          => $d['today_in'],
                ':today_out'         => $d['today_out'],
                ':captcha_available' => $d['captcha_available'],
                ':last_error'        => $d['last_error'],
                ':last_error_at'     => $d['last_error_at'],
            ]);
        } catch (\Throwable $e) {
            // 静默
        }
    }

    /**
     * 标记停止（进程正常退出时）
     */
    public static function markStopped(string $botName): void
    {
        try {
            $pdo = DbManager::getConnection();
            $stmt = $pdo->prepare(
                "UPDATE bot_heartbeat SET status = 'stopped', heartbeat_at = NOW() WHERE bot_name = ?"
            );
            $stmt->execute([$botName]);
        } catch (\Throwable $e) {
            // 静默
        }
    }
}
