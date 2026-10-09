<?php

namespace Utils;

/**
 * 统一 Bot 日志通道（支持多通道）
 *
 * 通道：
 *   bots → logs/bots.log  运行日志（所有 Bot 合并，行首标注 bot 名）
 *   http → logs/http.log  HTTP 详细日志（所有 Bot 合并）
 *
 * 跨天时自动归档到 logs/bot/<channel>-YYYY-MM-DD.NN.log
 * 多进程并发安全（copytruncate，持锁复制后清空，inode 不变）
 */
class BotLog
{
    private static ?string $baseDir = null;

    /** @var array<string, array{file:string, today:string, archiveDir:string}> */
    private static array $channels = [];

    /**
     * 初始化日志目录与默认通道
     */
    public static function init(string $baseDir, string $defaultBot = 'manager'): void
    {
        self::$baseDir = $baseDir;

        if (!is_dir($baseDir . '/logs')) {
            mkdir($baseDir . '/logs', 0755, true);
        }

        self::ensureChannel('bots');
    }

    /**
     * 写入 bots 通道
     */
    public static function write(string $message, ?string $bot = null, string $tag = ''): void
    {
        self::writeTo('bots', $message, $bot, $tag);
    }

    /**
     * 写入指定通道
     */
    public static function writeTo(string $channel, string $message, ?string $bot = null, string $tag = ''): void
    {
        if (self::$baseDir === null) {
            return;
        }

        $state = &self::ensureChannel($channel);
        self::rotate($state, $channel);

        $bot = $bot ?? 'manager';
        $tagStr = $tag !== '' ? "[{$tag}] " : '';
        $line = '[' . date('Y-m-d H:i:s') . "] [{$bot}] {$tagStr}" . $message;

        // 多行内容续行缩进，保持通道行格式整齐
        $line = str_replace(PHP_EOL, PHP_EOL . '    ', $line);

        file_put_contents($state['file'], $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * 各通道对应的归档子目录
     */
    private const ARCHIVE_SUBDIR = [
        'bots' => 'bot',
        'http' => 'http',
    ];

    /**
     * 获取（必要时创建）通道状态
     *
     * @return array{file:string, today:string, archiveDir:string}
     */
    private static function &ensureChannel(string $channel): array
    {
        if (!isset(self::$channels[$channel])) {
            $file = self::$baseDir . "/logs/{$channel}.log";
            if (!file_exists($file)) {
                touch($file);
            }

            $subdir = self::ARCHIVE_SUBDIR[$channel] ?? $channel;
            $archiveDir = self::$baseDir . "/logs/{$subdir}";
            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }

            self::$channels[$channel] = [
                'file' => $file,
                'today' => date('Y-m-d'),
                'archiveDir' => $archiveDir,
            ];
        }

        return self::$channels[$channel];
    }

    /**
     * 跨天归档：把当前通道文件内容复制到
     * logs/bot/<channel>-YYYY-MM-DD.NN.log 后清空。
     *
     * @param array{file:string, today:string} $state
     */
    private static function rotate(array &$state, string $channel): void
    {
        $today = date('Y-m-d');
        if ($today === $state['today']) {
            return;
        }

        $lock = fopen($state['file'], 'c+');
        if ($lock === false) {
            $state['today'] = $today;
            return;
        }
        flock($lock, LOCK_EX);

        try {
            clearstatcache(true, $state['file']);

            if (fseek($lock, 0, SEEK_END) === 0 && ftell($lock) > 0) {
                // 内容属于归档前的活动日期（进程自身记录）
                $logDate = $state['today'];

                fseek($lock, 0);
                $contents = stream_get_contents($lock);

                for ($seq = 1; $seq <= 99; $seq++) {
                    $target = sprintf('%s/%s-%s.%02d.log', $state['archiveDir'], $channel, $logDate, $seq);
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
            $state['today'] = $today;
        }
    }
}
