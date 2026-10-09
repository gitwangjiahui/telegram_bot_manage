<?php

namespace Utils;

/**
 * HTTP 请求日志
 * - 成功：单行摘要写入 http 通道（logs/http.log），只留 状态码/方法/路径/耗时/结果数
 * - 失败：详细报文（请求参数 + 响应）写入 http-error 通道（logs/http-error.log）
 * URL 中的 bot token 一律打码。
 */
class HttpLogger
{
    private static ?string $botName = null;

    /**
     * 初始化（统一通道由 BotLog::init 建立）
     */
    public static function init(string $baseDir, ?string $botName = null): void
    {
        self::$botName = $botName;
        BotLog::init($baseDir, $botName ?? 'manager');
    }

    /**
     * 设置当前 Bot 名称
     */
    public static function setBotName(string $botName): void
    {
        self::$botName = $botName;
    }

    /**
     * 记录 HTTP 请求
     *
     * @param string   $method   HTTP 方法
     * @param string   $url      请求 URL
     * @param array    $options  请求选项
     * @param mixed    $response 响应内容
     * @param float    $duration 请求耗时（秒）
     * @param int|null $httpCode HTTP 状态码
     */
    public static function log(
        string $method,
        string $url,
        array $options = [],
        mixed $response = null,
        float $duration = 0,
        ?int $httpCode = null
    ): void {
        $botName = self::$botName ?? 'unknown';
        $path = self::safePath($url);
        $ms = round($duration * 1000);

        $isError = $httpCode === null || $httpCode >= 400;

        if (!$isError) {
            // 单行紧凑日志：200 POST /bot***/getUpdates 310ms ok results=2
            $info = self::responseInfo($response);
            $extra = '';
            if (isset($info['results'])) {
                $extra = " results={$info['results']}";
            }
            BotLog::writeTo('http', "{$httpCode} {$method} {$path} {$ms}ms{$extra}", $botName, 'HTTP');
            return;
        }

        // ===== 失败：保留详细报文 =====
        $logLines = [
            sprintf('HTTP %s %s %sms %s', $httpCode ?? 'ERROR', $method, $ms, $path),
        ];

        // 请求参数（失败时保留以便排查）
        if (!empty($options['form_params'])) {
            $logLines[] = 'Request Parameters:';
            foreach ($options['form_params'] as $key => $value) {
                $logLines[] = "  {$key} = {$value}";
            }
        } elseif (!empty($options['json'])) {
            $logLines[] = 'Request Body (JSON):';
            $logLines[] = self::indent(json_encode(
                $options['json'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ));
        } elseif (!empty($options['body'])) {
            $logLines[] = 'Request Body:';
            $logLines[] = self::indent((string) $options['body']);
        }

        if ($response !== null) {
            $logLines[] = 'Response:';
            if (is_string($response)) {
                $logLines[] = self::indent($response);
            } else {
                $logLines[] = self::indent(var_export($response, true));
            }
        }

        BotLog::writeTo('http-error', implode(PHP_EOL, $logLines), $botName, 'HTTP-ERROR');
    }

    /**
     * 提取路径并遮蔽 bot token，例如 getUpdates 路径中的 token 替换为 bot 星号
     */
    private static function safePath(string $url): string
    {
        $path = preg_replace('#^https?://[^/]+#', '', $url);
        return preg_replace('#/bot[^/]+/#', '/bot***/', $path);
    }

    /**
     * 从响应中提取关键信息（结果条数）
     *
     * @return array{results?:int}
     */
    private static function responseInfo(mixed $response): array
    {
        $decoded = null;
        if (is_string($response)) {
            $decoded = json_decode($response, true);
        } elseif (is_array($response) || is_object($response)) {
            $decoded = (array) $response;
        }

        if (is_array($decoded) && isset($decoded['result']) && is_array($decoded['result'])) {
            return ['results' => count($decoded['result'])];
        }

        return [];
    }

    /**
     * 缩进多行文本
     */
    private static function indent(string $text, int $spaces = 2): string
    {
        $indent = str_repeat(' ', $spaces);
        return implode("\n", array_map(
            fn($line) => $indent . $line,
            explode("\n", $text)
        ));
    }
}
