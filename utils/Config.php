<?php

namespace Utils;

/**
 * 配置管理器 - 简化版
 */
class Config
{
    private static ?\PDO $pdo = null;
    private static array $cache = [];
    private static int $cacheTtl = 60;
    private static array $cacheTime = [];

    /**
     * 初始化（只执行一次）
     */
    public static function init(\PDO $pdo): void
    {
        if (self::$pdo !== null) {
            return;
        }
        self::$pdo = $pdo;
    }

    /**
     * 获取 PDO 连接（检查有效性）
     */
    private static function getPdo(): ?\PDO
    {
        if (self::$pdo === null) {
            return null;
        }

        try {
            self::$pdo->query('SELECT 1');
            return self::$pdo;
        } catch (\PDOException $e) {
            // 连接失效，尝试重新获取
            try {
                self::$pdo = DbManager::getConnection();
                return self::$pdo;
            } catch (\Exception $e) {
                return null;
            }
        }
    }

    /**
     * 获取配置值
     */
    public static function get(string $key, ?int $botId = null, mixed $default = null): mixed
    {
        $cacheKey = $botId === null ? "global:{$key}" : "bot{$botId}:{$key}";

        // 检查缓存
        if (isset(self::$cache[$cacheKey])) {
            if (time() - (self::$cacheTime[$cacheKey] ?? 0) < self::$cacheTtl) {
                return self::$cache[$cacheKey];
            }
            unset(self::$cache[$cacheKey]);
            unset(self::$cacheTime[$cacheKey]);
        }

        $pdo = self::getPdo();
        if ($pdo === null) {
            return $default;
        }

        try {
            if ($botId === null) {
                $sql = "SELECT config_value FROM config WHERE bot_id IS NULL AND config_key = ?";
                $params = [$key];
            } else {
                $sql = "SELECT config_value FROM config WHERE bot_id = ? AND config_key = ?";
                $params = [$botId, $key];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchColumn();

            if ($result !== false) {
                $value = self::parseValue($result);
                self::$cache[$cacheKey] = $value;
                self::$cacheTime[$cacheKey] = time();
                return $value;
            }
        } catch (\PDOException $e) {
            // 连接断开，尝试重试一次
            if (strpos($e->getMessage(), 'gone away') !== false || strpos($e->getMessage(), '2006') !== false) {
                self::$pdo = null;
                $pdo = self::getPdo();
                if ($pdo !== null) {
                    try {
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $result = $stmt->fetchColumn();
                        if ($result !== false) {
                            $value = self::parseValue($result);
                            self::$cache[$cacheKey] = $value;
                            self::$cacheTime[$cacheKey] = time();
                            return $value;
                        }
                    } catch (\PDOException $e2) {
                        // 重试失败
                    }
                }
            }
        }

        return $default;
    }

    /**
     * 获取自动回复消息
     */
    public static function getAutoReply(int $botId): ?string
    {
        $botReply = self::get('auto_reply_message', $botId, null);
        if ($botReply !== null && $botReply !== '') {
            return $botReply;
        }

        $globalReply = self::get('auto_reply_message', null, null);
        if ($globalReply !== null && $globalReply !== '') {
            return $globalReply;
        }

        return null;
    }

    /**
     * 获取代理配置
     */
    public static function getProxy(): ?string
    {
        return self::get('http_proxy', null, null);
    }

    /**
     * 解析配置值
     */
    private static function parseValue(string $value): mixed
    {
        $decoded = json_decode($value, true);
        return $decoded !== null || $value === 'null' ? $decoded : $value;
    }
}
