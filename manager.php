#!/usr/bin/env php
<?php
/**
 * Telegram Bot 统一管理器
 * 替代 start.sh / stop.sh / status.sh / restart.sh
 *
 * 用法: php manager.php <command> [bot_name]
 *
 * Commands:
 *   start     启动 Bot(守护进程模式)
 *   stop      停止 Bot
 *   restart   重启 Bot
 *   status    查看 Bot 状态
 *   list      列出所有 Bot
 *   logs      查看 Bot 日志
 */

use TelegramBot\TelegramBotManager\BotManager;

// 检查 PCNTL 扩展
if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "错误: 需要安装 PCNTL 扩展\n");
    exit(1);
}

// 设置时区
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/vendor/autoload.php';

// 自动加载 utils、model 和 commands
spl_autoload_register(function ($class) {
    // Utils
    $prefix = 'Utils\\';
    $base_dir = __DIR__ . '/utils/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $file = $base_dir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) require $file;
        return;
    }

    // Model
    $prefix = 'Model\\';
    $base_dir = __DIR__ . '/model/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $file = $base_dir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) require $file;
        return;
    }
    
    // Commands
    $prefix = 'Commands\\UserCommands\\';
    $base_dir = __DIR__ . '/commands/UserCommands/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $file = $base_dir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) require $file;
        return;
    }
});

// 预加载 HTTP 日志相关类
require_once __DIR__ . '/utils/HttpLogger.php';
require_once __DIR__ . '/utils/HttpLoggingMiddleware.php';

class BotManagerDaemon
{
    private $baseDir;
    private $runDir;
    private $logsDir;
    private $dataDir;
    private $dbConfig;

    private $currentBotName = null;

    public function __construct()
    {
        $this->baseDir = __DIR__;
        $this->runDir = $this->baseDir . '/run';
        $this->logsDir = $this->baseDir . '/logs';
        $this->dataDir = $this->baseDir . '/data';

        // 确保目录存在
        foreach ([$this->runDir, $this->logsDir, $this->dataDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // 加载数据库配置
        $this->dbConfig = require $this->baseDir . '/config/database.php';
    }

    /**
     * 设置当前 Bot 名称（用于日志）
     */
    private function setCurrentBot(string $botName): void
    {
        $this->currentBotName = $botName;
    }

    /**
     * 写入日志到对应 Bot 的日志文件
     */
    private function log(string $message, ?string $botName = null): void
    {
        $targetBot = $botName ?? $this->currentBotName ?? 'manager';
        $logFile = $this->logsDir . '/' . $targetBot . '.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [MANAGER] ' . $message . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 输出消息到终端和日志
     */
    private function output(string $message, ?string $botName = null): void
    {
        echo $message . PHP_EOL;
        $this->log($message, $botName);
    }

    /**
     * 获取 PID 文件路径
     */
    private function getPidFile(string $botName): string
    {
        return $this->runDir . '/' . $botName . '.pid';
    }

    /**
     * 获取日志文件路径
     */
    private function getLogFile(string $botName): string
    {
        return $this->logsDir . '/' . $botName . '.log';
    }

    /**
     * 获取 bot 的 ID
     */
    private function getBotId(string $botName): ?int
    {
        try {
            $pdo = Utils\DbManager::getConnection();
            $stmt = $pdo->prepare("SELECT id FROM bots WHERE bot_name = ? AND is_active = 1");
            $stmt->execute([$botName]);
            $value = $stmt->fetchColumn();
            return $value ? (int) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取 bot 的 last_update_id
     */
    private function getBotLastUpdateId(int $botId): ?int
    {
        try {
            $pdo = Utils\DbManager::getConnection();
            $stmt = $pdo->prepare("SELECT config_value FROM config WHERE bot_id = ? AND config_key = 'last_update_id' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$botId]);
            $value = $stmt->fetchColumn();
            return $value ? (int) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 设置 bot 的 last_update_id
     */
    private function setBotLastUpdateId(int $botId, int $updateId): void
    {
        try {
            $pdo = Utils\DbManager::getConnection();
            
            // 先检查是否存在
            $stmt = $pdo->prepare("SELECT id FROM config WHERE bot_id = ? AND config_key = 'last_update_id'");
            $stmt->execute([$botId]);
            $existingId = $stmt->fetchColumn();
            
            if ($existingId) {
                // 更新
                $stmt = $pdo->prepare("UPDATE config SET config_value = ? WHERE id = ?");
                $stmt->execute([$updateId, $existingId]);
            } else {
                // 插入
                $stmt = $pdo->prepare("INSERT INTO config (bot_id, config_key, config_value) VALUES (?, 'last_update_id', ?)");
                $stmt->execute([$botId, $updateId]);
            }
        } catch (\Exception $e) {
            echo "[MANAGER] Error saving last_update_id: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 读取 PID
     */
    private function readPid(string $botName): ?int
    {
        $pidFile = $this->getPidFile($botName);
        if (!file_exists($pidFile)) {
            return null;
        }
        $pid = file_get_contents($pidFile);
        return is_numeric($pid) ? (int)$pid : null;
    }

    /**
     * 写入 PID
     */
    private function writePid(string $botName, int $pid): void
    {
        $pidFile = $this->getPidFile($botName);
        file_put_contents($pidFile, $pid, LOCK_EX);
    }

    /**
     * 删除 PID 文件
     */
    private function removePid(string $botName): void
    {
        $pidFile = $this->getPidFile($botName);
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }

    /**
     * 检查进程是否运行
     */
    private function isRunning(?int $pid): bool
    {
        if ($pid === null || $pid <= 0) {
            return false;
        }
        return posix_kill($pid, 0);
    }

    /**
     * 获取 PDO 连接（复用 DbManager）
     */
    private function getPdo(): PDO
    {
        // 确保 DbManager 已初始化
        if (empty($this->dbConfig)) {
            $this->dbConfig = require $this->baseDir . '/config/database.php';
        }
        Utils\DbManager::init($this->dbConfig['mysql']);
        return Utils\DbManager::getConnection();
    }

    /**
     * 从数据库获取 Bot 配置
     */
    private function getBotConfig(string $botName): ?array
    {
        try {
            $pdo = $this->getPdo();

            $stmt = $pdo->prepare("SELECT b.*, GROUP_CONCAT(CONCAT(r.admin_id, ':', r.admin_type)) as admins 
                FROM bots b 
                LEFT JOIN bot_admin_rela r ON b.id = r.bot_id 
                WHERE b.bot_name = ? AND b.is_active = 1 
                GROUP BY b.id");
            $stmt->execute([$botName]);
            $bot = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bot) {
                return null;
            }

            // 解析管理员
            $superAdminId = null;
            $adminIds = [];
            if ($bot['admins']) {
                foreach (explode(',', $bot['admins']) as $admin) {
                    list($adminId, $adminType) = explode(':', $admin);
                    if ($adminType === 'super') {
                        $superAdminId = (int)$adminId;
                    } else {
                        $adminIds[] = (int)$adminId;
                    }
                }
            }

            return [
                'api_key' => $bot['api_key'],
                'bot_username' => $bot['bot_username'],
                'super_admin_id' => $superAdminId,
                'admin_ids' => $adminIds,
                'bot_name' => $botName,
                'bot_dir' => $this->dataDir . '/' . $botName,
                'commands' => ['paths' => [$this->baseDir . '/commands/UserCommands']],
                'command_config' => [
                    'genericmessage' => [
                        'active' => true,
                    ],
                ],
                'mysql' => $this->dbConfig['mysql'],
            ];
        } catch (PDOException $e) {
            fwrite(STDERR, "数据库错误: " . $e->getMessage() . "\n");
            return null;
        }
    }

    /**
     * 获取所有 Bot 列表
     */
    public function getAllBots(): array
    {
        try {
            $pdo = $this->getPdo();

            $stmt = $pdo->query("SELECT bot_name, is_active FROM bots ORDER BY bot_name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * 获取运行中的 Bot 列表
     */
    public function getRunningBots(): array
    {
        $bots = $this->getAllBots();
        $running = [];

        foreach ($bots as $bot) {
            $pid = $this->readPid($bot['bot_name']);
            if ($this->isRunning($pid)) {
                $running[] = $bot;
            }
        }

        return $running;
    }

    /**
     * 启动 Bot
     */
    public function start(?string $botName = null): int
    {
        if ($botName === null) {
            $bots = $this->getAllBots();
            $exitCode = 0;
            $startedCount = 0;

            foreach ($bots as $bot) {
                if ($bot['is_active']) {
                    $code = $this->startSingle($bot['bot_name']);
                    if ($code === 0) {
                        $startedCount++;
                    } else {
                        $exitCode = $code;
                    }
                }
            }

            echo "共启动 {$startedCount} 个 Bot\n";
            return $exitCode;
        }

        return $this->startSingle($botName);
    }

    /**
     * 启动单个 Bot
     */
    private function startSingle(string $botName): int
    {
        $this->setCurrentBot($botName);

        // 检查是否已运行
        $pid = $this->readPid($botName);
        if ($this->isRunning($pid)) {
            $this->output("Bot '{$botName}' 已在运行 (PID: {$pid})", $botName);
            return 0;
        }

        // 获取配置
        $config = $this->getBotConfig($botName);
        if (!$config) {
            $this->output("错误: Bot '{$botName}' 不存在或未激活", $botName);
            return 2;
        }

        // 确保数据目录
        if (!is_dir($config['bot_dir'])) {
            mkdir($config['bot_dir'], 0755, true);
        }

        $this->output("启动 Bot '{$botName}'...", $botName);

        // 创建守护进程
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->output("错误: 无法创建子进程", $botName);
            return 1;
        } elseif ($pid > 0) {
            // 父进程：等待子进程完成初始化
            sleep(2);

            // 读取守护进程 PID
            $daemonPid = $this->readPid($botName);

            if ($daemonPid && $this->isRunning($daemonPid)) {
                $this->output("Bot '{$botName}' 已启动 (PID: {$daemonPid})", $botName);
                return 0;
            }

            $this->output("Bot '{$botName}' 启动中，用 'php manager.php status {$botName}' 检查状态", $botName);
            return 0;
        }

        // 子进程：成为守护进程并运行 Bot
        $this->daemonizeAndRun($config, $botName);
        exit(0);
    }

    /**
     * 守护进程化并运行 Bot
     */
    private function daemonizeAndRun(array $config, string $botName): void
    {
        // 创建新会话
        if (posix_setsid() === -1) {
            fwrite(STDERR, "错误: 无法创建会话\n");
            exit(1);
        }

        // 第二次 fork
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "错误: 第二次 fork 失败\n");
            exit(1);
        } elseif ($pid > 0) {
            exit(0);
        }

        // ====== 守护进程开始 ======

        // 获取守护进程 PID 并写入文件
        $daemonPid = posix_getpid();
        $this->writePid($botName, $daemonPid);

        $logFile = $this->getLogFile($botName);

        // 重定向标准输出/错误到日志文件
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $stdIn = fopen('/dev/null', 'r');
        $stdOut = fopen($logFile, 'a');
        $stdErr = fopen($logFile, 'a');

        // 设置当前 bot 名称（用于日志）
        $this->setCurrentBot($botName);
        
        $this->log("=== Daemon started ===", $botName);

        // 设置全局配置
        $GLOBALS['bot_config'] = $config;

        // 重置数据库连接（fork 后必须重新创建）
        Utils\DbManager::resetConnection();

        // 初始化 DbManager
        Utils\DbManager::init($config['mysql']);

        // 获取代理（复用 DbManager 连接）
        $pdo = Utils\DbManager::getConnection();
        try {
            Utils\Config::init($pdo);
            $proxy = Utils\Config::getProxy();
        } catch (\Exception $e) {
            $proxy = null;
        }

        // 初始化 HTTP 日志
        Utils\HttpLogger::init($this->baseDir, $botName);

        // 设置 Guzzle 代理和日志中间件
        $handlerStack = \GuzzleHttp\HandlerStack::create();
        $handlerStack->push(Utils\HttpLoggingMiddleware::create());

        $clientConfig = [
            'base_uri' => 'https://api.telegram.org',
            'timeout' => 60,
            'connect_timeout' => 10,
            'handler' => $handlerStack,
        ];

        if ($proxy) {
            $clientConfig['proxy'] = $proxy;
        }

        $guzzleClient = new \GuzzleHttp\Client($clientConfig);
        \Longman\TelegramBot\Request::setClient($guzzleClient);

        // 主循环
        $errors = 0;
        $running = true;

        pcntl_signal(SIGTERM, function () use (&$running) {
            $running = false;
        });
        pcntl_signal(SIGINT, function () use (&$running) {
            $running = false;
        });

        $botManager = null;
        
        $this->log("Entering main loop...", $botName);

        while ($running) {
            pcntl_signal_dispatch();

            try {
                // 只在第一次或异常后创建 BotManager
                if ($botManager === null) {
                    $this->log("Creating BotManager...", $botName);
                    try {
                        // 从配置中移除 mysql，防止 BotManager 自动创建连接
                        // 我们使用外部 PDO 连接
                        $botConfig = $config;
                        unset($botConfig['mysql']);
                        $this->log("botConfig keys: " . implode(', ', array_keys($botConfig)), $botName);
                        $this->log("api_key exists: " . (isset($botConfig['api_key']) ? 'yes' : 'no'), $botName);
                        $this->log("bot_username: " . ($botConfig['bot_username'] ?? 'null'), $botName);
                        $this->log("About to create BotManager...", $botName);
                        $botManager = new BotManager($botConfig);
                        $this->log("BotManager instance created", $botName);
                        
                        // 检查数据库连接
                        $pdo = Utils\DbManager::getConnection();
                        try {
                            $pdo->query('SELECT 1');
                        } catch (\PDOException $e) {
                            // 重新创建连接
                            Utils\DbManager::resetConnection();
                            $pdo = Utils\DbManager::getConnection();
                        }
                        
                        $botManager->getTelegram()->enableExternalMySql($pdo);
                        
                        // 手动添加 commands 路径
                        $commandsPath = $this->baseDir . '/commands/UserCommands';
                        if (is_dir($commandsPath)) {
                            $botManager->getTelegram()->addCommandsPaths([$commandsPath]);
                            $this->log("Added commands path: {$commandsPath}", $botName);
                        }
                        
                        $this->log("BotManager created successfully", $botName);
                    } catch (\Exception $e) {
                        $this->log("Error creating BotManager: " . $e->getMessage(), $botName);
                        throw $e;
                    }
                }

                
                // 获取该 bot 的 ID 和 last_update_id
                $botId = $this->getBotId($botName);
                $this->log("botId = " . ($botId ?? 'null'), $botName);
                $botLastUpdateId = $botId ? $this->getBotLastUpdateId($botId) : null;
                $this->log("botLastUpdateId = " . ($botLastUpdateId ?? 'null'), $botName);
                
                $telegram = $botManager->getTelegram();
                if ($botLastUpdateId && $botId) {
                    $this->log("Using custom offset: " . ($botLastUpdateId + 1), $botName);
                    // 使用自定义 offset，禁用数据库查询
                    $telegram->useGetUpdatesWithoutDatabase(true);
                    // 直接调用 Telegram API，不使用 handleGetUpdates
                    $this->log("Calling Request::getUpdates with offset=" . ($botLastUpdateId + 1), $botName);
                    $response = \Longman\TelegramBot\Request::getUpdates([
                        'offset' => $botLastUpdateId + 1,
                        'timeout' => 30,
                    ]);
                    if ($response->isOk()) {
                        $updates = $response->getResult();
                        $this->log("Got " . count($updates) . " updates", $botName);
                        if (count($updates) > 0) {
                            $maxUpdateId = 0;
                            foreach ($updates as $update) {
                                $updateId = $update->getUpdateId();
                                if ($updateId > $maxUpdateId) {
                                    $maxUpdateId = $updateId;
                                }
                                $this->log("Processing update_id={$updateId}", $botName);
                                try {
                                    $telegram->processUpdate($update);
                                    $this->log("Processed update_id={$updateId} successfully", $botName);
                                } catch (\Exception $e) {
                                    $this->log("Error processing update_id={$updateId}: " . $e->getMessage(), $botName);
                                }
                            }
                            // 更新该 bot 的 last_update_id
                            $newLastId = $maxUpdateId > 0 ? $maxUpdateId : $telegram->getLastUpdateId();
                            $this->log("newLastId = " . ($newLastId ?? 'null'), $botName);
                            if ($newLastId) {
                                $this->setBotLastUpdateId($botId, $newLastId);
                                $this->log("Saved last_update_id = {$newLastId}", $botName);
                            }
                        }
                    } else {
                        $this->log("getUpdates failed: " . $response->getDescription(), $botName);
                    }
                } else {
                    $this->log("Using default run()", $botName);
                    // 第一次运行，使用默认方式
                    $botManager->run();
                    // 保存 last_update_id
                    $newLastId = $telegram->getLastUpdateId();
                    if ($newLastId && $botId) {
                        $this->setBotLastUpdateId($botId, $newLastId);
                    }
                }
                
                $errors = 0;
                
                // 如果没有新消息，稍微等待一下避免频繁请求
                sleep(1);
            } catch (Exception $e) {
                $errors++;
                $errorMsg = "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage();
                $this->log($errorMsg, $botName);
                $botManager = null; // 异常后重置，下次循环重新创建
                if ($errors >= 10) {
                    $exitMsg = "[" . date('Y-m-d H:i:s') . "] Max errors reached, exiting";
                    $this->log($exitMsg, $botName);
                    break;
                }
                sleep(min($errors * 2, 30));
            }

            pcntl_signal_dispatch();
        }

        // 清理
        $this->removePid($botName);
        exit(0);
    }

    /**
     * 停止 Bot
     */
    public function stop(?string $botName = null): int
    {
        if ($botName === null) {
            $bots = $this->getRunningBots();
            $exitCode = 0;
            $stoppedCount = 0;

            if (empty($bots)) {
                $this->output("没有运行中的 Bot");
                return 0;
            }

            foreach ($bots as $bot) {
                $code = $this->stopSingle($bot['bot_name']);
                if ($code === 0) {
                    $stoppedCount++;
                } else {
                    $exitCode = $code;
                }
            }

            $this->output("共停止 {$stoppedCount} 个 Bot");
            return $exitCode;
        }

        return $this->stopSingle($botName);
    }

    /**
     * 停止单个 Bot
     */
    private function stopSingle(string $botName): int
    {
        $this->setCurrentBot($botName);
        $pid = $this->readPid($botName);

        if (!$this->isRunning($pid)) {
            $this->output("Bot '{$botName}' 未运行", $botName);
            $this->removePid($botName);
            return 0;
        }

        $this->output("停止 Bot '{$botName}' (PID: {$pid})...", $botName);

        posix_kill($pid, SIGTERM);

        $waited = 0;
        while ($this->isRunning($pid) && $waited < 10) {
            sleep(1);
            $waited++;
        }

        if ($this->isRunning($pid)) {
            $this->output("强制停止...", $botName);
            posix_kill($pid, SIGKILL);
            sleep(1);
        }

        $this->removePid($botName);
        $this->output("Bot '{$botName}' 已停止", $botName);
        return 0;
    }

    /**
     * 重启 Bot
     */
    public function restart(?string $botName = null): int
    {
        if ($botName === null) {
            $bots = $this->getRunningBots();
            $exitCode = 0;
            $restartedCount = 0;

            if (empty($bots)) {
                $this->output("没有运行中的 Bot");
                return 0;
            }

            foreach ($bots as $bot) {
                $name = $bot['bot_name'];
                $this->output("重启 Bot '{$name}'...", $name);
                $this->stopSingle($name);
                sleep(1);
                $code = $this->startSingle($name);
                if ($code === 0) {
                    $restartedCount++;
                } else {
                    $exitCode = $code;
                }
            }

            $this->output("共重启 {$restartedCount} 个 Bot");
            return $exitCode;
        }

        $this->output("重启 Bot '{$botName}'...", $botName);
        $this->stopSingle($botName);
        sleep(1);
        return $this->startSingle($botName);
    }

    /**
     * 查看状态
     */
    public function status(?string $botName = null): int
    {
        if ($botName === null) {
            return $this->list();
        }

        $this->setCurrentBot($botName);
        $pid = $this->readPid($botName);

        if ($this->isRunning($pid)) {
            $this->output("✅ {$botName}: 运行中 (PID: {$pid})", $botName);
            return 0;
        } else {
            $this->output("❌ {$botName}: 未运行", $botName);
            if ($pid) {
                $this->removePid($botName);
            }
            return 1;
        }
    }

    /**
     * 列出所有 Bot
     */
    public function list(): int
    {
        $bots = $this->getAllBots();

        if (empty($bots)) {
            $this->output("没有配置任何 Bot");
            return 0;
        }

        $output = "Bot 列表:\n";
        $output .= str_repeat('-', 40) . "\n";

        foreach ($bots as $bot) {
            $botName = $bot['bot_name'];
            $isActive = $bot['is_active'];
            $pid = $this->readPid($bot['bot_name']);
            $isRunning = $this->isRunning($pid);

            $status = $isRunning ? '✅ 运行中' : '⏹️  停止';
            $active = $isActive ? '' : ' (已禁用)';

            $output .= sprintf("%-20s %s%s\n", $botName, $status, $active);
        }

        echo $output;
        // list 命令不写入特定 bot 日志，可以写入 manager.log
        $this->log("\n" . trim($output), 'manager');
        return 0;
    }

    /**
     * 查看日志
     */
    public function logs(string $botName, int $lines = 50): int
    {
        $logFile = $this->getLogFile($botName);

        if (!file_exists($logFile)) {
            echo "Bot '{$botName}' 没有日志文件\n";
            return 1;
        }

        echo "Bot '{$botName}' 最近 {$lines} 行日志:\n";
        echo str_repeat('=', 50) . "\n";

        system("tail -n {$lines} " . escapeshellarg($logFile));

        return 0;
    }
}

// ==================== 主程序入口 ====================

$command = $argv[1] ?? 'help';
$botName = $argv[2] ?? null;

$manager = new BotManagerDaemon();

switch ($command) {
    case 'start':
        exit($manager->start($botName));

    case 'stop':
        exit($manager->stop($botName));

    case 'restart':
        exit($manager->restart($botName));

    case 'status':
        exit($manager->status($botName));

    case 'list':
        exit($manager->list());

    case 'logs':
        $lines = isset($argv[3]) ? (int)$argv[3] : 50;
        if (!$botName) {
            fwrite(STDERR, "用法: php manager.php logs <bot_name> [行数]\n");
            exit(1);
        }
        exit($manager->logs($botName, $lines));

    case 'help':
    default:
        echo "Telegram Bot 管理器\n\n";
        echo "用法: php manager.php <command> [bot_name]\n\n";
        echo "Commands:\n";
        echo "  start     [bot_name]  启动 Bot（默认全部）\n";
        echo "  stop      [bot_name]  停止 Bot（默认全部运行中的）\n";
        echo "  restart   [bot_name]  重启 Bot（默认全部运行中的）\n";
        echo "  status    [bot_name]  查看状态（默认全部）\n";
        echo "  list                  列出所有 Bot\n";
        echo "  logs      <bot_name>  [行数] 查看日志（默认 50 行）\n";
        exit(0);
}
