<?php

namespace Utils;

/**
 * 统一 Bot 日志通道
 * - 所有 Bot 的运行日志统一写入 logs/bots.log，行首标注 bot 名称
 * - 跨天时自动归档为 logs/bots/bots-YYYY-MM-DD.NN.log（多进程并发安全）
 */
class BotLog
{
    private static ?string $logFile = null;
    private static ?string $archiveDir = null;
    private static string $today = '';
    private static string $defaultBot = 'manager';

    /**
     * 初始化统一日志通道
     */
    public static function init(string $baseDir, string $defaultBot = 'manager'): void
    {
        self::$logFile = $baseDir . '/logs/bots.log';
        self::$archiveDir = $baseDir . '/logs/bots';
        self::$defaultBot = $defaultBot;
        self::$today = date('Y-m-d');

        if (!is_dir(dirname(self::$logFile))) {
            mkdir(dirname(self::$logFile), 0755, true);
        }
        if (!is_dir(self::$archiveDir)) {
            mkdir(self::$archiveDir, 0755, true);
        }
        if (!file_exists(self::$logFile)) {
            touch(self::$logFile);
        }
    }

    /**
     * 写入一行日志
     *
     * @param string      $message 日志内容
     * @param string|null $bot     bot 名称（缺省用默认值）
     * @param string      $tag     可选标签，如 MANAGER / HTTP
     */
    public static function write(string $message, ?string $bot = null, string $tag = ''): void
    {
        if (self::$logFile === null) {
            return;
        }

        self::rotate();

        $bot = $bot ?? self::$defaultBot;
        $tagStr = $tag !== '' ? "[{$tag}] " : '';
        $line = '[' . date('Y-m-d H:i:s') . "] [{$bot}] {$tagStr}" . $message;

        // 多行内容续行缩进，保持通道行格式整齐
        $line = str_replace(PHP_EOL, PHP_EOL . '    ', $line);

        file_put_contents(self::$logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * 跨天归档：把 bots.log 内容复制到 bots/bots-YYYY-MM-DD.NN.log 后清空。
     * 用 copytruncate 而非 rename，保证守护进程持有的文件句柄仍指向同一 inode。
     * 全程持排他锁，多进程并发安全。归档日期取被归档文件的 mtime。
     */
    private static function rotate(): void
    {
        $today = date('Y-m-d');
        if ($today === self::$today) {
            return;
        }

        $lock = fopen(self::$logFile, 'c+');
        if ($lock === false) {
            self::$today = $today;
            return;
        }
        flock($lock, LOCK_EX);

        try {
            clearstatcache(true, self::$logFile);

            if (fseek($lock, 0, SEEK_END) === 0 && ftell($lock) > 0) {
                // 内容属于归档前的活动日期（进程自身记录），不取 mtime
                $logDate = self::$today;

                fseek($lock, 0);
                $contents = stream_get_contents($lock);

                for ($seq = 1; $seq <= 99; $seq++) {
                    $target = sprintf('%s/bots-%s.%02d.log', self::$archiveDir, $logDate, $seq);
                    if (!file_exists($target)) {
                        file_put_contents($target, $contents);
                        break;
                    }
                }
            }

            // 清空当前文件（inode 不变，守护进程句柄继续有效）
            ftruncate($lock, 0);
            rewind($lock);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            self::$today = $today;
        }
    }
}
