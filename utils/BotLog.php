<?php

namespace Utils;

/**
 * 统一 Bot 日志通道（支持多通道、按保留期滚动删除）
 *
 * 通道：
 *   bots       → logs/bots.log        运行日志（所有 Bot 合并，行首标注 bot 名，保留 90 天）
 *   http       → logs/http.log        HTTP 成功日志（所有 Bot 合并，保留 7 天）
 *   http-error → logs/http-error.log  HTTP 失败日志（保留 90 天）
 *
 * 跨天时自动归档到 logs/<子目录>/<channel>-YYYY-MM-DD.NN.log，并按保留期
 * 删除过期归档。多进程并发安全（copytruncate，持锁复制后清空，inode 不变）。
 */
class BotLog
{
    private static ?string $baseDir = null;

    /**
     * 通道配置：归档子目录 + 保留天数
     */
    private const CONFIG = [
        'bots'       => ['subdir' => 'bot',        'retention' => 90],
        'http'       => ['subdir' => 'http',       'retention' => 7],
        'http-error' => ['subdir' => 'http-error', 'retention' => 90],
    ];

    /** @var array<string, array{file:string, today:string, archiveDir:string, retention:int}> */
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

        // 启动时清理一次各通道过期归档（部署/重启后立即生效）
        foreach (self::CONFIG as $channel => $cfg) {
            self::purge($channel);
        }
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
        if (self::$baseDir === null || !isset(self::CONFIG[$channel])) {
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
     * 获取（必要时创建）通道状态
     *
     * @return array{file:string, today:string, archiveDir:string, retention:int}
     */
    private static function &ensureChannel(string $channel): array
    {
        if (!isset(self::$channels[$channel])) {
            $cfg = self::CONFIG[$channel];

            $file = self::$baseDir . "/logs/{$channel}.log";
            if (!file_exists($file)) {
                touch($file);
            }

            $archiveDir = self::$baseDir . '/logs/' . $cfg['subdir'];
            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }

            self::$channels[$channel] = [
                'file' => $file,
                'today' => date('Y-m-d'),
                'archiveDir' => $archiveDir,
                'retention' => $cfg['retention'],
            ];
        }

        return self::$channels[$channel];
    }

    /**
     * 跨天归档：把当前通道文件内容复制到
     * logs/<子目录>/<channel>-YYYY-MM-DD.NN.log 后清空，并清理过期归档。
     *
     * @param array{file:string, today:string, archiveDir:string, retention:int} $state
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

        self::purge($channel);
    }

    /**
     * 按保留期删除该通道过期归档文件（按文件名中的日期判定）
     */
    private static function purge(string $channel): void
    {
        if (self::$baseDir === null) {
            return;
        }

        $state = &self::ensureChannel($channel);
        $cutoff = date('Y-m-d', strtotime("-{$state['retention']} days"));

        foreach (glob($state['archiveDir'] . "/{$channel}-*.log") ?: [] as $file) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})\.\d+\.log$/', basename($file), $m)) {
                if ($m[1] < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }
}
