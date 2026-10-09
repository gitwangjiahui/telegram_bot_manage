<?php

namespace Utils;

/**
 * HTTP 请求日志
 * 所有 Bot 的 HTTP 请求/响应详细内容统一写入 http 通道（logs/http.log），
 * 跨天与 bots 通道一样归档到 logs/bot/http-YYYY-MM-DD.NN.log。
 * 失败请求额外在 bots 通道输出一行摘要。
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
     * 记录 HTTP 请求和响应到 http 通道；失败时同时向 bots 通道输出摘要
     *
     * @param string      $method   HTTP 方法
     * @param string      $url      请求 URL
     * @param array       $options  请求选项
     * @param mixed       $response 响应内容
     * @param float       $duration 请求耗时（秒）
     * @param int|null    $httpCode HTTP 状态码
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

        // ===== 组装详细日志块 =====
        $logLines = [
            "Method: {$method}",
            "URL: {$url}",
            "Duration: " . round($duration * 1000, 2) . " ms",
        ];

        // 请求头
        if (!empty($options['headers'])) {
            $logLines[] = "Request Headers:";
            foreach ($options['headers'] as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                // 隐藏敏感信息
                if (stripos($key, 'authorization') !== false || stripos($key, 'token') !== false) {
                    $value = '***REDACTED***';
                }
                $logLines[] = "  {$key}: {$value}";
            }
        }

        // 请求参数
        if (!empty($options['form_params'])) {
            $logLines[] = "Request Parameters:";
            foreach ($options['form_params'] as $key => $value) {
                $logLines[] = "  {$key} = {$value}";
            }
        } elseif (!empty($options['json'])) {
            $logLines[] = "Request Body (JSON):";
            $logLines[] = self::indent(json_encode($options['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } elseif (!empty($options['body'])) {
            $logLines[] = "Request Body:";
            $body = (string) $options['body'];
            parse_str($body, $formData);
            if (!empty($formData)) {
                foreach ($formData as $key => $value) {
                    $logLines[] = "  {$key} = {$value}";
                }
            } else {
                $decoded = json_decode($body, true);
                if ($decoded !== null) {
                    $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
                $logLines[] = self::indent($body);
            }
        }

        // 查询参数
        if (!empty($options['query_params'])) {
            $logLines[] = "Query Parameters:";
            foreach ($options['query_params'] as $key => $value) {
                $logLines[] = "  {$key} = {$value}";
            }
        }

        // 响应
        $logLines[] = "Response:";
        if ($httpCode !== null) {
            $logLines[] = "Status: {$httpCode} " . self::getHttpStatusText($httpCode);
        }

        if ($response !== null) {
            $decoded = null;
            if (is_string($response)) {
                $decoded = json_decode($response, true);
            } elseif (is_array($response) || is_object($response)) {
                $decoded = (array) $response;
            }

            if ($decoded !== null) {
                $logLines[] = self::indent(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $logLines[] = self::indent(var_export($response, true));
            }
        }

        // 成功/失败分流：成功详细日志 → http 通道(保留7天)，失败详细日志 → http-error 通道(保留90天)
        $isError = $httpCode === null || $httpCode >= 400;

        if ($isError) {
            $path = preg_replace('#^https?://[^/]+#', '', $url);
            array_unshift($logLines, sprintf(
                'HTTP %s %s %sms %s',
                $httpCode !== null ? $httpCode : 'ERROR',
                $method,
                round($duration * 1000),
                $path
            ));
            BotLog::writeTo('http-error', implode(PHP_EOL, $logLines), $botName, 'HTTP-ERROR');
        } else {
            BotLog::writeTo('http', implode(PHP_EOL, $logLines), $botName, 'HTTP');
        }
    }

    /**
     * 缩进多行文本
     */
    private static function indent(string $text, int $spaces = 2): string
    {
        $indent = str_repeat(' ', $spaces);
        $lines = explode("\n", $text);
        return implode("\n", array_map(fn($line) => $indent . $line, $lines));
    }

    /**
     * 获取 HTTP 状态码文本
     */
    private static function getHttpStatusText(int $code): string
    {
        $statuses = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];
        return $statuses[$code] ?? 'Unknown';
    }
}
