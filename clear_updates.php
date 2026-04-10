#!/usr/bin/env php
<?php
/**
 * 清空 Telegram Bot 的积压消息
 * 用法: php clear_updates.php <bot_name>
 */

require_once __DIR__ . '/vendor/autoload.php';

// 加载配置
$dbConfig = require __DIR__ . '/config/database.php';

// 获取 bot 配置
$botName = $argv[1] ?? null;
if (!$botName) {
    fwrite(STDERR, "用法: php clear_updates.php <bot_name>\n");
    exit(1);
}

// 连接数据库
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['mysql']['host'] ?? 'localhost',
        $dbConfig['mysql']['port'] ?? 3306,
        $dbConfig['mysql']['database'] ?? ''
    ),
    $dbConfig['mysql']['user'] ?? '',
    $dbConfig['mysql']['password'] ?? ''
);

// 获取 bot 的 API key
$stmt = $pdo->prepare("SELECT api_key FROM bots WHERE bot_name = ? AND is_active = 1");
$stmt->execute([$botName]);
$bot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bot) {
    fwrite(STDERR, "错误: Bot '{$botName}' 不存在或未激活\n");
    exit(1);
}

$apiKey = $bot['api_key'];

// 获取代理配置
$proxy = null;
try {
    $stmt = $pdo->prepare("SELECT config_value FROM config WHERE bot_id IS NULL AND config_key = 'http_proxy'");
    $stmt->execute();
    $proxy = $stmt->fetchColumn();
    if ($proxy) {
        $proxy = json_decode($proxy, true) ?: $proxy;
    }
} catch (Exception $e) {
    // 忽略错误
}

// 创建 Guzzle 客户端
$clientConfig = [
    'base_uri' => 'https://api.telegram.org',
    'timeout' => 30,
];

if ($proxy) {
    $clientConfig['proxy'] = $proxy;
    echo "使用代理: {$proxy}\n";
}

$client = new \GuzzleHttp\Client($clientConfig);

echo "正在清空 Bot '{$botName}' 的积压消息...\n";

try {
    // 获取所有积压的消息
    $response = $client->post("/bot{$apiKey}/getUpdates", [
        'form_params' => [
            'limit' => 100,
        ]
    ]);
    
    $data = json_decode($response->getBody(), true);
    
    if ($data['ok'] && !empty($data['result'])) {
        $updates = $data['result'];
        $firstUpdateId = $updates[0]['update_id'];
        $lastUpdateId = end($updates)['update_id'];
        
        echo "发现 " . count($updates) . " 条积压消息\n";
        echo "第一条 update_id: {$firstUpdateId}\n";
        echo "最后一条 update_id: {$lastUpdateId}\n";
        
        // 设置 offset = lastUpdateId + 1 来确认所有消息
        $offset = $lastUpdateId + 1;
        
        echo "正在确认所有消息 (offset={$offset})...\n";
        
        // 多次调用确保消息被清空
        for ($i = 0; $i < 3; $i++) {
            $response = $client->post("/bot{$apiKey}/getUpdates", [
                'form_params' => [
                    'offset' => $offset,
                    'limit' => 1,
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if ($data['ok'] && empty($data['result'])) {
                echo "第 " . ($i + 1) . " 次确认: 消息已清空\n";
                break;
            } else {
                echo "第 " . ($i + 1) . " 次确认: 还有 " . count($data['result']) . " 条消息\n";
                // 更新 offset
                if (!empty($data['result'])) {
                    $lastUpdateId = end($data['result'])['update_id'];
                    $offset = $lastUpdateId + 1;
                }
            }
            
            sleep(1);
        }
        
        // 获取 bot_id
        $stmt = $pdo->prepare("SELECT id FROM bots WHERE bot_name = ? AND is_active = 1");
        $stmt->execute([$botName]);
        $botId = $stmt->fetchColumn();
        
        if ($botId) {
            // 更新 config 表中该 bot 的 last_update_id
            $stmt = $pdo->prepare("SELECT id FROM config WHERE bot_id = ? AND config_key = 'last_update_id'");
            $stmt->execute([$botId]);
            $existingId = $stmt->fetchColumn();
            
            if ($existingId) {
                $pdo->prepare("UPDATE config SET config_value = ? WHERE id = ?")->execute([$offset, $existingId]);
            } else {
                $pdo->prepare("INSERT INTO config (bot_id, config_key, config_value) VALUES (?, 'last_update_id', ?)")->execute([$botId, $offset]);
            }
            echo "Config 表已更新，bot_id={$botId}, last_update_id = {$offset}\n";
        }
    } else {
        echo "没有积压消息需要清空\n";
    }
    
    echo "完成!\n";
    
} catch (Exception $e) {
    fwrite(STDERR, "错误: " . $e->getMessage() . "\n");
    exit(1);
}
