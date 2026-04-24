<?php

namespace Utils;

/**
 * HTTP 请求日志记录器
 * 支持按日期切割日志文件
 */
class HttpLogger
{
    private static ?string $botName = null;
    private static string $baseLogDir;
    private static string $currentDate = '';
    private static ?string $currentLogFile = null;

    /**
     * 初始化日志目录
     */
    public static function init(string $baseDir, ?string $botName = null): void
    {
        self::$baseLogDir = $baseDir . '/logs';
        self::$botName = $botName;
        self::$currentDate = date('Y-m-d');

        // 确保日志目录存在
        if (!is_dir(self::$baseLogDir)) {
            mkdir(self::$baseLogDir, 0755, true);
        }

        // 确保 http 子目录存在
        $httpDir = self::$baseLogDir . '/http';
        if (!is_dir($httpDir)) {
            mkdir($httpDir, 0755, true);
        }

        self::updateLogFile();
    }

    /**
     * 设置当前 Bot 名称
     */
    public static function setBotName(string $botName): void
    {
        self::$botName = $botName;
        self::updateLogFile();
    }

    /**
     * 更新当前日志文件路径（按日期切割）
     */
    private static function updateLogFile(): void
    {
        $botName = self::$botName ?? 'unknown';
        self::$currentLogFile = sprintf(
            '%s/http/http_%s-%s.log',
            self::$baseLogDir,
            $botName,
            self::$currentDate
        );
    }

    /**
     * 检查是否需要切换日志文件（日期变化）
     */
    private static function checkRotation(): void
    {
        $today = date('Y-m-d');
        if ($today !== self::$currentDate) {
            self::$currentDate = $today;
            self::updateLogFile();
        }
    }

    /**
     * 记录 HTTP 请求和响应
     *
     * @param string $method HTTP 方法
     * @param string $url 请求 URL
     * @param array $options 请求选项
     * @param mixed $response 响应内容
     * @param float $duration 请求耗时（秒）
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
        if (self::$currentLogFile === null) {
            return;
        }

        self::checkRotation();

        $timestamp = date('Y-m-d H:i:s.u');
        $botName = self::$botName ?? 'unknown';

        // 构建日志内容
        $logLines = [
            str_repeat('=', 80),
            "[{$timestamp}] [{$botName}] HTTP Request",
            str_repeat('-', 40),
            "Method: {$method}",
            "URL: {$url}",
            "Duration: " . round($duration * 1000, 2) . " ms",
        ];

        // 记录请求头
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

        // 记录请求参数
        if (!empty($options['form_params'])) {
            $logLines[] = "Request Parameters:";
            foreach ($options['form_params'] as $key => $value) {
                $logLines[] = "  {$key} = {$value}";
            }
        } elseif (!empty($options['json'])) {
            $logLines[] = "Request Body (JSON):";
            $body = json_encode($options['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $logLines[] = self::indent($body);
        } elseif (!empty($options['body'])) {
            $logLines[] = "Request Body:";
            $body = (string) $options['body'];
            // 尝试解析为 form data
            parse_str($body, $formData);
            if (!empty($formData)) {
                foreach ($formData as $key => $value) {
                    $logLines[] = "  {$key} = {$value}";
                }
            } else {
                // 尝试格式化 JSON
                $decoded = json_decode($body, true);
                if ($decoded !== null) {
                    $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
                $logLines[] = self::indent($body);
            }
        }
        
        // 记录查询参数
        if (!empty($options['query_params'])) {
            $logLines[] = "Query Parameters:";
            foreach ($options['query_params'] as $key => $value) {
                $logLines[] = "  {$key} = {$value}";
            }
        }

        // 记录响应
        $logLines[] = str_repeat('-', 40);
        $logLines[] = "Response:";
        if ($httpCode !== null) {
            $statusText = self::getHttpStatusText($httpCode);
            $logLines[] = "Status: {$httpCode} {$statusText}";
        }

        if ($response !== null) {
            $decoded = null;
            if (is_string($response)) {
                $decoded = json_decode($response, true);
            } elseif (is_array($response) || is_object($response)) {
                $decoded = (array) $response;
            }
            
            if ($decoded !== null) {
                // 提取并显示关键响应参数
                if (isset($decoded['ok'])) {
                    $logLines[] = "  ok = " . ($decoded['ok'] ? 'true' : 'false');
                }
                if (isset($decoded['result']) && is_array($decoded['result'])) {
                    $count = count($decoded['result']);
                    $logLines[] = "  result_count = {$count}";
                    if ($count > 0) {
                        $updateIds = array_map(fn($item) => $item['update_id'] ?? 'N/A', $decoded['result']);
                        $logLines[] = "  update_ids = [" . implode(', ', $updateIds) . "]";
                    }
                }
                if (isset($decoded['description'])) {
                    $logLines[] = "  description = {$decoded['description']}";
                }
                if (isset($decoded['error_code'])) {
                    $logLines[] = "  error_code = {$decoded['error_code']}";
                }
                // 完整响应体
                $logLines[] = "  Full Response:";
                $logLines[] = self::indent(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 4);
            } else {
                $logLines[] = self::indent(var_export($response, true));
            }
        }

        $logLines[] = str_repeat('=', 80);
        $logLines[] = ''; // 空行分隔

        // 写入日志
        $logContent = implode(PHP_EOL, $logLines) . PHP_EOL;
        file_put_contents(self::$currentLogFile, $logContent, FILE_APPEND | LOCK_EX);

        // 同时写入主日志文件（简要信息）
        self::logToMainFile($method, $url, $options, $response, $duration, $httpCode);
    }

    /**
     * 记录简要信息到主日志文件
     */
    private static function logToMainFile(
        string $method,
        string $url,
        array $options,
        mixed $response,
        float $duration,
        ?int $httpCode
    ): void {
        $botName = self::$botName ?? 'unknown';
        $mainLogFile = self::$baseLogDir . '/http_' . $botName . '.log';

        $timestamp = date('Y-m-d H:i:s');
        $status = $httpCode !== null ? "[{$httpCode}]" : '[?]';
        $ms = round($duration * 1000, 2);

        // 提取关键请求参数
        $params = [];
        if (!empty($options['form_params'])) {
            foreach ($options['form_params'] as $key => $value) {
                $params[] = "{$key}={$value}";
            }
        } elseif (!empty($options['json'])) {
            foreach ($options['json'] as $key => $value) {
                $params[] = "{$key}={$value}";
            }
        }
        $paramsStr = !empty($params) ? ' | ' . implode(', ', $params) : '';

        // 提取关键响应信息
        $respInfo = '';
        $decoded = null;
        if (is_string($response)) {
            $decoded = json_decode($response, true);
        } elseif (is_array($response) || is_object($response)) {
            $decoded = (array) $response;
        }
        if ($decoded !== null) {
            if (isset($decoded['ok'])) {
                $respInfo .= ' ok=' . ($decoded['ok'] ? 'true' : 'false');
            }
            if (isset($decoded['result']) && is_array($decoded['result'])) {
                $count = count($decoded['result']);
                $respInfo .= ' results=' . $count;
            }
        }

        $line = "[{$timestamp}] {$status} {$method} {$url}{$paramsStr}{$respInfo} ({$ms}ms)" . PHP_EOL;
        file_put_contents($mainLogFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 缩进多行文本
     */
    private static function indent(string $text, int $spaces = 2): string
    {
        $indent = str_repeat(' ', $spaces);
        $lines = explode("\n", $text);
        $indented = array_map(fn($line) => $indent . $line, $lines);
        return implode("\n", $indented);
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
