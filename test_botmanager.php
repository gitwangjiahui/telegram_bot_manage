<?php
require_once __DIR__ . '/vendor/autoload.php';

use TelegramBot\TelegramBotManager\BotManager;

$dbConfig = require __DIR__ . '/config/database.php';

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

// 获取 bot2 的配置
$stmt = $pdo->prepare("SELECT * FROM bots WHERE bot_name = ? AND is_active = 1");
$stmt->execute(['bot2']);
$bot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bot) {
    echo "Bot not found\n";
    exit(1);
}

// 解析管理员
$superAdminId = null;
$adminIds = [];
$stmt = $pdo->prepare("SELECT admin_id, admin_type FROM bot_admin_rela WHERE bot_id = ?");
$stmt->execute([$bot['id']]);
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($admins as $admin) {
    if ($admin['admin_type'] === 'super') {
        $superAdminId = (int)$admin['admin_id'];
    } else {
        $adminIds[] = (int)$admin['admin_id'];
    }
}

$config = [
    'api_key' => $bot['api_key'],
    'bot_username' => $bot['bot_username'],
    'super_admin_id' => $superAdminId,
    'admin_ids' => $adminIds,
    'bot_name' => 'bot2',
    'bot_dir' => __DIR__ . '/data/bot2',
    'commands' => ['paths' => [__DIR__ . '/commands']],
];

echo "Config keys: " . implode(', ', array_keys($config)) . "\n";
echo "api_key exists: " . (isset($config['api_key']) ? 'yes' : 'no') . "\n";
echo "bot_username: " . ($config['bot_username'] ?? 'null') . "\n";

try {
    echo "Creating BotManager...\n";
    $botManager = new BotManager($config);
    echo "BotManager created successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
