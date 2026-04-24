<?php

namespace Utils;

use PDO;
use PDOException;

/**
 * 数据库连接管理器 - 简化版
 * 只保留基本连接复用功能
 */
class DbManager
{
    private static array $config = [];
    private static ?PDO $pdo = null;
    private static string $connectionName = 'default';

    /**
     * 初始化配置（只执行一次）
     */
    public static function init(array $config): void
    {
        if (!empty(self::$config)) {
            return;
        }
        self::$config = $config;
    }

    /**
     * 重置连接（用于 fork 后重新创建连接）
     */
    public static function resetConnection(): void
    {
        self::$pdo = null;
    }

    /**
     * 获取数据库连接（单例模式）
     */
    public static function getConnection(string $name = 'default'): PDO
    {
        // 如果已有连接，检查是否有效
        if (self::$pdo !== null) {
            try {
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            } catch (\PDOException $e) {
                // 连接失效，重新创建
                self::$pdo = null;
            }
        }

        // 创建新连接
        error_log("[DbManager] 创建新连接");
        return self::createConnection();
    }

    /**
     * 创建新连接
     */
    private static function createConnection(): PDO
    {
        if (empty(self::$config)) {
            throw new PDOException("数据库配置未初始化");
        }

        $config = self::$config;
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'] ?? 'localhost',
            $config['port'] ?? 3306,
            $config['database'] ?? ''
        );

        self::$pdo = new PDO(
            $dsn,
            $config['user'] ?? '',
            $config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        self::$pdo->exec("SET NAMES utf8mb4");
        self::$pdo->exec("SET SESSION wait_timeout = 28800");
        self::$pdo->exec("SET SESSION interactive_timeout = 28800");

        return self::$pdo;
    }
}
