<?php

namespace Utils;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Guzzle HTTP 日志中间件
 * 自动记录所有 HTTP 请求和响应
 */
class HttpLoggingMiddleware
{
    /**
     * 创建日志中间件
     *
     * @return callable Guzzle 中间件
     */
    public static function create(): callable
    {
        return function (callable $handler): callable {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler) {
                $startTime = microtime(true);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $options, $startTime) {
                        $duration = microtime(true) - $startTime;
                        self::logSuccess($request, $options, $response, $duration);
                        return $response;
                    },
                    function ($reason) use ($request, $options, $startTime) {
                        $duration = microtime(true) - $startTime;
                        self::logError($request, $options, $reason, $duration);
                        return new RejectedPromise($reason);
                    }
                );
            };
        };
    }

    /**
     * 记录成功的请求
     */
    private static function logSuccess(
        RequestInterface $request,
        array $options,
        ResponseInterface $response,
        float $duration
    ): void {
        $method = $request->getMethod();
        $url = (string) $request->getUri();
        $httpCode = $response->getStatusCode();

        // 获取响应体
        $body = (string) $response->getBody();
        // 重置 body 位置以便后续使用
        $response->getBody()->rewind();

        // 转换请求选项格式
        $logOptions = self::convertRequestOptions($request, $options);

        HttpLogger::log($method, $url, $logOptions, $body, $duration, $httpCode);
    }

    /**
     * 记录失败的请求
     */
    private static function logError(
        RequestInterface $request,
        array $options,
        $reason,
        float $duration
    ): void {
        $method = $request->getMethod();
        $url = (string) $request->getUri();
        $httpCode = null;
        $responseBody = null;

        // 转换请求选项格式
        $logOptions = self::convertRequestOptions($request, $options);

        if ($reason instanceof RequestException && $reason->hasResponse()) {
            $response = $reason->getResponse();
            $httpCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
        }

        $errorMessage = $reason instanceof \Exception ? $reason->getMessage() : (string) $reason;

        HttpLogger::log(
            $method,
            $url,
            $logOptions,
            $responseBody ?? "ERROR: {$errorMessage}",
            $duration,
            $httpCode
        );
    }

    /**
     * 转换请求选项为日志格式
     */
    private static function convertRequestOptions(RequestInterface $request, array $options): array
    {
        $logOptions = [];

        // 提取请求头
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }
        $logOptions['headers'] = $headers;

        // 优先使用 form_params (POST 请求参数)
        if (!empty($options['form_params'])) {
            $logOptions['form_params'] = $options['form_params'];
        }
        // 然后是 json 参数
        elseif (!empty($options['json'])) {
            $logOptions['json'] = $options['json'];
        }
        // 最后尝试从 body 提取
        else {
            $body = (string) $request->getBody();
            if (!empty($body)) {
                // 尝试解析为 JSON
                $json = json_decode($body, true);
                if ($json !== null) {
                    $logOptions['json'] = $json;
                } else {
                    // 尝试解析为 form data
                    parse_str($body, $formData);
                    if (!empty($formData)) {
                        $logOptions['form_params'] = $formData;
                    } else {
                        $logOptions['body'] = $body;
                    }
                }
            }
        }

        // 记录查询参数
        $query = $request->getUri()->getQuery();
        if (!empty($query)) {
            parse_str($query, $queryParams);
            if (!empty($queryParams)) {
                $logOptions['query_params'] = $queryParams;
            }
        }

        return $logOptions;
    }
}
