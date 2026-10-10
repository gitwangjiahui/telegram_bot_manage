<?php

namespace Utils;

/**
 * Telegram API 并发调用（curl_multi）
 *
 * 把同一请求扇出给多个 chat：N 个目标并发发出，总耗时 ≈ 单次 RTT 而非 N×RTT。
 */
class TgMulti
{
    /**
     * 并发转发一条消息给多个 chat
     *
     * @param string   $token     bot token
     * @param int[]    $chatIds   目标 chat
     * @param int      $fromChat  源 chat
     * @param int      $messageId 源消息 id
     * @return array<int, int>  chatId => 转发后的 message_id（仅成功项）
     */
    public static function forwardToChats(string $token, array $chatIds, int $fromChat, int $messageId): array
    {
        $handles = [];
        foreach ($chatIds as $chatId) {
            $handles[$chatId] = [
                'chat_id'      => $chatId,
                'from_chat_id' => $fromChat,
                'message_id'   => $messageId,
            ];
        }

        $results = self::execMulti($token, 'forwardMessage', $handles);

        $map = [];
        foreach ($results as $chatId => $resp) {
            if (!empty($resp['result']['message_id'])) {
                $map[(int) $chatId] = (int) $resp['result']['message_id'];
            }
        }
        return $map;
    }

    /**
     * 并发发送同一条文本给多个 chat
     *
     * @param string   $token
     * @param int[]    $chatIds
     * @param string   $text
     * @param string   $parseMode
     * @return int 成功数
     */
    public static function sendToChats(string $token, array $chatIds, string $text, string $parseMode = ''): int
    {
        $handles = [];
        foreach ($chatIds as $chatId) {
            $handles[$chatId] = array_filter([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => $parseMode,
            ], fn($v) => $v !== '' && $v !== null);
        }

        $results = self::execMulti($token, 'sendMessage', $handles);

        $ok = 0;
        foreach ($results as $resp) {
            if (!empty($resp['ok'])) {
                $ok++;
            }
        }
        return $ok;
    }

    /**
     * 执行多个并发请求
     *
     * @param string                $token
     * @param string                $method Telegram API 方法名
     * @param array<int|string,array> $requests key 为调用方关联键（如 chatId）
     * @return array<int|string, array> 关联键 => 解析后的响应
     */
    private static function execMulti(string $token, string $method, array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        $proxy = Config::getProxy();
        $mh = curl_multi_init();
        $handles = [];

        foreach ($requests as $key => $fields) {
            $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($fields),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            if ($proxy) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        // 并发执行
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        // 收集
        $out = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $decoded = json_decode($body, true);
            $out[$key] = is_array($decoded) ? $decoded : [];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $out;
    }
}
