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

        // 初始化统一日志通道（logs/bots.log、logs/http.log，跨天归档到 logs/bot/）
        Utils\BotLog::init($this->baseDir);

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
     * 写入日志到统一通道（行内标注 bot 名）
     */
    private function log(string $message, ?string $botName = null): void
    {
        $targetBot = $botName ?? $this->currentBotName ?? 'manager';
        Utils\BotLog::write($message, $targetBot, 'MANAGER');
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
     * 获取 bot 的 ID
     */
    private function getBotId(string $botName): ?int
    {
        $pdo = Utils\DbManager::getConnection();
        $stmt = $pdo->prepare("SELECT id FROM bots WHERE bot_name = ? AND is_active = 1");
        $stmt->execute([$botName]);
        $value = $stmt->fetchColumn();
        return $value ? (int) $value : null;
    }

    /**
     * 获取 bot 的 last_update_id
     */
    private function getBotLastUpdateId(int $botId): ?int
    {
        $pdo = Utils\DbManager::getConnection();
        $stmt = $pdo->prepare("SELECT config_value FROM config WHERE bot_id = ? AND config_key = 'last_update_id' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$botId]);
        $value = $stmt->fetchColumn();
        return $value ? (int) $value : null;
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
     * 确保数据库连接可用：做 SELECT 1 健康检查，失败则重置并重连一次。
     * 返回可用 PDO；完全不可用时返回 null（不抛出）。
     */
    private function ensureDbConnection(string $botName, int $dbFails): ?\PDO
    {
        // 先探活现有连接
        try {
            $pdo = Utils\DbManager::getConnection();
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (\Throwable $e) {
            // 落到下方重连
        }

        Utils\DbManager::resetConnection();

        try {
            return Utils\DbManager::getConnection();
        } catch (\Throwable $e) {
            // 降频记录日志，避免抖动期刷屏
            if ($dbFails === 0 || $dbFails % 10 === 0) {
                $this->log('数据库不可用，持续重试中: ' . $e->getMessage(), $botName);
            }
            return null;
        }
    }

    /**
     * 判断异常是否为 Telegram 网络层故障（请求未到达 Telegram，无响应）
     * 如 cURL error 35/28/7/56、连接重置、超时等。
     */
    private function isNetworkError(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'cURL error')) {
            // 提取 cURL 错误码
            if (preg_match('/cURL error (\d+)/', $msg, $m)) {
                $code = (int) $m[1];
                // 6=无法解析主机, 7=无法连接, 28=超时, 35=SSL握手失败, 52/56=连接重置, 18=部分传输
                return in_array($code, [6, 7, 18, 28, 35, 52, 56], true);
            }
            return true;
        }

        return str_contains($msg, 'Connection reset')
            || str_contains($msg, 'Connection timed out')
            || str_contains($msg, 'Encountered end of file')
            || str_contains($msg, 'Failed to connect');
    }

    /**
     * 判断异常是否为数据库连接/查询类
     */
    private function isDbError(\Throwable $e): bool
    {
        if ($e instanceof \PDOException) {
            return true;
        }
        $msg = $e->getMessage();
        return str_contains($msg, 'SQLSTATE')
            || str_contains($msg, 'gone away')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, "can't connect")
            || str_contains($msg, 'Lost connection')
            || str_contains($msg, '数据库配置未初始化');
    }

    /**
     * fork 验证码池常驻维护进程
     *
     * @return int 子进程 PID（0 表示 fork 失败）
     */
    private function spawnPoolWorker(array $config, string $botName): int
    {
        $token = (string) $config['api_key'];
        $superAdmin = (int) ($config['super_admin_id'] ?? 0);

        // 存储聊天：优先 config.captcha_chat_id（推荐静音私有频道）；否则用超管个人聊天并在发送后删除
        $chatId = (int) Utils\Config::get('captcha_chat_id', null, 0);
        if ($chatId <= 0) {
            $chatId = $superAdmin;
            $deleteAfter = true;
        } else {
            $deleteAfter = false;
        }

        if ($token === '' || $chatId <= 0) {
            $this->log('验证码池未启动：缺少 token 或存储聊天', $botName);
            return 0;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->log('验证码池维护进程 fork 失败', $botName);
            return 0;
        }

        if ($pid === 0) {
            // 子进程
            $worker = new Utils\CaptchaPoolWorker(
                $botName,
                $config['mysql'],
                $token,
                $chatId,
                $deleteAfter
            );
            $worker->run();
            exit(0);
        }

        $this->log("验证码池维护进程已 fork (PID: {$pid})", $botName);
        return $pid;
    }

    /**
     * 聚合并上报心跳（任何聚合失败用默认值，不抛出）
     */
    private function sendHeartbeat(
        string $botName, ?int $botId, string $startedAt, int $offset, string $status,
        ?string $lastError, ?string $lastErrorAt
    ): void {
        $captchaAvailable = 0;
        $todayIn = 0;
        $todayOut = 0;

        try {
            $captchaAvailable = (new \Model\CaptchaPool($botName))->countAvailable();
        } catch (\Throwable $e) {
        }

        if ($botId) {
            try {
                $pdo = Utils\DbManager::getConnection();
                $stmt = $pdo->prepare(
                    "SELECT SUM(direction = 'in'), SUM(direction = 'out')
                       FROM message_log
                      WHERE bot_id = ? AND created_at >= CURDATE()"
                );
                $stmt->execute([$botId]);
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $todayIn = (int) ($row[0] ?? 0);
                $todayOut = (int) ($row[1] ?? 0);
            } catch (\Throwable $e) {
            }
        }

        \Model\Heartbeat::touch($botName, [
            'pid'               => (int) getmypid(),
            'started_at'        => $startedAt,
            'last_update_id'    => $offset,
            'status'            => $status,
            'today_in'          => $todayIn,
            'today_out'         => $todayOut,
            'captcha_available' => $captchaAvailable,
            'last_error'        => $lastError !== null ? mb_substr($lastError, 0, 500) : null,
            'last_error_at'     => $lastErrorAt,
        ]);
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

        // 守护进程的原始输出也并入统一通道
        $logFile = $this->logsDir . '/bots.log';
        Utils\BotLog::init($this->baseDir, $botName);

        // 重定向标准输出/错误到日志文件
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $stdIn = fopen('/dev/null', 'r');
        $stdOut = fopen($logFile, 'a');
        $stdErr = fopen($logFile, 'a');


        // 运行 Bot 工作主体（与 supervisor 管理的子进程共用同一实现）
        $this->runBotWorker($config, $botName);

    }

    /**
     * Bot 工作主体：初始化连接/HTTP 客户端/验证码池并进入拉取循环。
     * daemonize 模式与 Swoole supervisor 的子进程（worker 命令）共用此实现。
     */
    private function runBotWorker(array $config, string $botName): void
    {
        // 设置当前 bot 名称（用于日志）
        $this->setCurrentBot($botName);
        
        $this->log("=== Daemon started ===", $botName);

        // 设置全局配置
        $GLOBALS['bot_config'] = $config;

        // 重置数据库连接（fork 后必须重新创建）
        Utils\DbManager::resetConnection();

        // 初始化 DbManager
        Utils\DbManager::init($config['mysql']);

        // 启动阶段数据库不可用不能让守护进程退出，主循环会持续重试
        $pdo = null;
        $proxy = null;
        try {
            $pdo = Utils\DbManager::getConnection();
            Utils\Config::init($pdo);
            $proxy = Utils\Config::getProxy();
        } catch (\Throwable $e) {
            $this->log('启动时数据库不可用，进入主循环持续重试: ' . $e->getMessage(), $botName);
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

        // fork 常驻验证码池维护进程（启动即预生成，后台动态补货）
        $poolWorkerPid = $this->spawnPoolWorker($config, $botName);

        // 主循环
        $errors = 0;      // 非数据库类连续业务异常（收到 Telegram 响应的真错误，如 401/409）
        $dbFails = 0;     // 数据库类连续异常（无限重试，不退出）
        $netFails = 0;    // Telegram 网络类连续异常（连不上/超时，无限重试，不退出）
        $running = true;

        pcntl_signal(SIGTERM, function () use (&$running) {
            $running = false;
        });
        pcntl_signal(SIGINT, function () use (&$running) {
            $running = false;
        });

        $botManager = null;

        // 心跳追踪
        $startedAt = date('Y-m-d H:i:s');
        $lastError = null;
        $lastErrorAt = null;
        $currentOffset = 0;

        while ($running) {
            pcntl_signal_dispatch();

            // ===== 数据库健康检查：不可用时无限重试，绝不让守护进程退出 =====
            $pdo = $this->ensureDbConnection($botName, $dbFails);
            if ($pdo === null) {
                // 等待恢复（等待期间响应停止信号）
                $wait = (int) min($dbFails * 2, 30);
                for ($i = 0; $i < $wait && $running; $i++) {
                    sleep(1);
                    pcntl_signal_dispatch();
                }
                continue;
            }
            if ($dbFails > 0) {
                $this->log("数据库连接已恢复", $botName);
                $dbFails = 0;
                $errors = 0;
                $botManager = null; // 用新连接重建
            }

            try {
                // 只在第一次、DB 恢复或异常后创建 BotManager
                if ($botManager === null) {
                    $botConfig = $config;
                    unset($botConfig['mysql']);
                    $botManager = new BotManager($botConfig);

                    $botManager->getTelegram()->enableExternalMySql($pdo);

                    // 手动添加 commands 路径
                    $commandsPath = $this->baseDir . '/commands/UserCommands';
                    if (is_dir($commandsPath)) {
                        $botManager->getTelegram()->addCommandsPaths([$commandsPath]);
                    }

                    $this->log("BotManager created successfully", $botName);
                }

                
                // 获取该 bot 的 ID 和 last_update_id
                $botId = $this->getBotId($botName);
                $botLastUpdateId = $botId ? $this->getBotLastUpdateId($botId) : null;
                if ($botLastUpdateId) {
                    $currentOffset = (int) $botLastUpdateId;
                }
                
                $telegram = $botManager->getTelegram();
                $hadUpdates = false;
                if ($botLastUpdateId && $botId) {
                    $telegram->useGetUpdatesWithoutDatabase(true);
                    $response = \Longman\TelegramBot\Request::getUpdates([
                        'offset' => $botLastUpdateId + 1,
                        'timeout' => 8,
                    ]);
                    if ($response->isOk()) {
                        $updates = $response->getResult();
                        if (count($updates) > 0) {
                            $hadUpdates = true;
                            $maxUpdateId = 0;
                            foreach ($updates as $update) {
                                $updateId = $update->getUpdateId();
                                if ($updateId > $maxUpdateId) {
                                    $maxUpdateId = $updateId;
                                }
                                try {
                                    $telegram->processUpdate($update);
                                } catch (\Throwable $e) {
                                    $this->log("Error processing update_id={$updateId}: " . $e->getMessage(), $botName);
                                }
                            }
                            // 更新该 bot 的 last_update_id
                            $newLastId = $maxUpdateId > 0 ? $maxUpdateId : $telegram->getLastUpdateId();
                            if ($newLastId) {
                                $this->setBotLastUpdateId($botId, $newLastId);
                            }
                        }
                    }
                } elseif ($botId) {
                    // 已登记但还没有 last_update_id（首次运行），使用默认方式
                    $botManager->run();
                    // 保存 last_update_id
                    $newLastId = $telegram->getLastUpdateId();
                    if ($newLastId) {
                        $this->setBotLastUpdateId($botId, $newLastId);
                    }
                } else {
                    // 配置存在但 bots 表查不到（运行中被停用/删除），不按首次运行处理
                    $this->log("Bot 在数据库中不存在或已停用，5 秒后重试", $botName);
                    sleep(5);
                }

                $errors = 0;
                $netFails = 0;

                // 仅空轮询时稍作等待；处理过消息立即进入下一次拉取
                // 间隔 0.3s：消息落在等待窗口最多滞后 0.3s，同时避免空转打满请求
                if (!$hadUpdates) {
                    usleep(300000);
                }

                // 上报真实运行状态
                $this->sendHeartbeat($botName, $botId, $startedAt, $currentOffset, 'polling',
                    $lastError, $lastErrorAt);
            } catch (\Throwable $e) {
                // 数据库类异常：无限等待恢复，绝不退出
                if ($this->isDbError($e)) {
                    $dbFails++;
                    $this->log("数据库异常，等待恢复 ({$dbFails}): " . $e->getMessage(), $botName);
                    Utils\DbManager::resetConnection();
                    $botManager = null;
                    continue;
                }

                // Telegram 网络类异常（请求未到达 Telegram）：无限重试，绝不退出
                if ($this->isNetworkError($e)) {
                    $netFails++;
                    if ($netFails === 1 || $netFails % 10 === 0) {
                        $this->log("Telegram 网络异常，持续重试中 ({$netFails}): " . $e->getMessage(), $botName);
                    }
                    $botManager = null; // 网络恢复后重建
                    sleep((int) min($netFails * 2, 30));
                    continue;
                }

                // 其余为收到响应的真正业务错误（401 token 无效 / 409 冲突等），计入熔断
                $errors++;
                $netFails = 0;
                $this->log("Error: " . $e->getMessage(), $botName);
                $botManager = null; // 异常后重置，下次循环重新创建
                if ($errors >= 10) {
                    $this->log("Max errors reached, exiting", $botName);
                    break;
                }
                sleep(min($errors * 2, 30));
            }

            pcntl_signal_dispatch();

            // 非阻塞回收池维护进程，防止意外退出产生僵尸
            if ($poolWorkerPid > 0) {
                $done = pcntl_waitpid($poolWorkerPid, $wstatus, WNOHANG);
                if ($done === $poolWorkerPid) {
                    $poolWorkerPid = 0;
                }
            }
        }

        // 清理
        if ($poolWorkerPid > 0) {
            posix_kill($poolWorkerPid, SIGTERM);
            pcntl_waitpid($poolWorkerPid, $wstatus);
        }
        $this->removePid($botName);
        exit(0);
    }

    /**
     * 常驻监控者：每 30s 对账 bots 表与实际进程
     *   is_active=1 且无存活 PID → start
     *   is_active=0 但进程存活    → stop
     * 纯 PHP CLI 无原生协程，用单进程 + 可中断 sleep tick 实现（等价轻量协程调度，不额外占资源）
     */
    public function watch(?int $interval = 30): int
    {
        $interval = $interval && $interval >= 5 ? $interval : 30;

        // 单实例：避免多个 watcher 重复拉起
        $wPid = $this->readPid('__watcher');
        if ($this->isRunning($wPid)) {
            $this->output("监控者已在运行 (PID: {$wPid})", 'manager');
            return 0;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "错误: 无法 fork 监控者\n");
            return 1;
        } elseif ($pid > 0) {
            sleep(1);
            return 0;
        }

        // 子进程：守护化
        if (posix_setsid() === -1) {
            exit(1);
        }
        $pid2 = pcntl_fork();
        if ($pid2 === -1) exit(1);
        if ($pid2 > 0) exit(0);

        $daemonPid = posix_getpid();
        $this->writePid('__watcher', $daemonPid);

        // 重定向标准 IO 到日志（startSingle/stopSingle 内部会 echo）
        $watchLog = $this->logsDir . '/watcher.log';
        fclose(STDIN); fclose(STDOUT); fclose(STDERR);
        $stdIn = fopen('/dev/null', 'r');
        $stdOut = fopen($watchLog, 'a');
        $stdErr = fopen($watchLog, 'a');

        $running = true;
        pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
        pcntl_signal(SIGINT, function () use (&$running) { $running = false; });

        $this->log('=== Bot watcher started ===', 'manager');

        while ($running) {
            pcntl_signal_dispatch();

            try {
                $this->reconcileOnce();
            } catch (\Throwable $e) {
                // 任何异常（含 DB 不可用）都不退出，下个 tick 重试
                $this->log('watcher 对账异常: ' . $e->getMessage(), 'manager');
            }

            // 可中断 sleep：每秒醒来处理信号，到点立即对账
            for ($i = 0; $i < $interval && $running; $i++) {
                sleep(1);
                pcntl_signal_dispatch();
            }
        }

        $this->log('=== Bot watcher stopped ===', 'manager');
        $this->removePid('__watcher');
        exit(0);
    }

    /**
     * 执行一次表与进程的对账
     */
    private function reconcileOnce(): void
    {
        $bots = $this->getAllBots();
        if (empty($bots)) {
            return; // DB 查询失败或无记录，不动作
        }

        foreach ($bots as $bot) {
            $name = $bot['bot_name'];
            $isActive = (int) $bot['is_active'] === 1;
            $pid = $this->readPid($name);
            $alive = $this->isRunning($pid);

            if ($isActive && !$alive) {
                if ($pid) $this->removePid($name);
                $this->log("检测到 {$name} 应运行但未运行，自动拉起", 'manager');
                $code = $this->startSingle($name);
                if ($code === 0) {
                    $this->log("已自动拉起 {$name}", 'manager');
                } else {
                    $this->log("自动拉起 {$name} 失败 (code={$code})", 'manager');
                }
            } elseif (!$isActive && $alive) {
                $this->log("检测到 {$name} 已停用但进程存活，自动停止", 'manager');
                $this->stopSingle($name);
                $this->log("已自动停止 {$name}", 'manager');
            }
        }
    }

    /**
     * 停止监控者
     */
    public function stopWatcher(): int
    {
        $wPid = $this->readPid('__watcher');
        if (!$this->isRunning($wPid)) {
            $this->removePid('__watcher');
            $this->output("监控者未运行", 'manager');
            return 0;
        }
        posix_kill($wPid, SIGTERM);
        $waited = 0;
        while ($this->isRunning($wPid) && $waited < 8) {
            sleep(1);
            $waited++;
        }
        if ($this->isRunning($wPid)) posix_kill($wPid, SIGKILL);
        $this->removePid('__watcher');
        $this->output("监控者已停止", 'manager');
        return 0;
    }

    /**
     * Worker：前台运行单个 Bot（供 Swoole supervisor 以子进程 exec 调用，非守护化）
     */
    public function runWorker(string $botName): int
    {
        $this->setCurrentBot($botName);
        $config = $this->getBotConfig($botName);
        if (!$config) {
            fwrite(STDERR, "错误: Bot '{$botName}' 不存在或未激活\n");
            return 2;
        }
        if (!is_dir($config['bot_dir'])) {
            mkdir($config['bot_dir'], 0755, true);
        }
        Utils\BotLog::init($this->baseDir, $botName);
        $GLOBALS['bot_config'] = $config;
        $this->runBotWorker($config, $botName);
        return 0;
    }

    /**
     * Swoole 协程监管者：
     *   - 每个 is_active=1 的 Bot 作为一个受管子进程（exec `worker`）
     *   - 协程 wait 即时感知子进程退出，按指数退避自动重启（崩溃秒级拉起）
     *   - 周期对账 bots 表：新增即拉起、停用/删除即优雅停止
     * 相比 watch 的定时轮询，崩溃恢复更快、生命周期统一由监管进程持有。
     */
    public function supervise(?int $scan = 10): int
    {
        $scan = ($scan && $scan >= 3) ? $scan : 10;

        // 单实例
        $sPid = $this->readPid('__supervisor');
        if ($this->isRunning($sPid)) {
            $this->output("监管者已在运行 (PID: {$sPid})", 'manager');
            return 0;
        }
        // 旧 watcher 仍在运行时拒绝，避免双重拉起
        $wPid = $this->readPid('__watcher');
        if ($this->isRunning($wPid)) {
            $this->output("旧监控者仍在运行，请先执行 php manager.php unwatch", 'manager');
            return 1;
        }

        // 用 Swoole 安全守护（内部完成 setsid+二次fork，且在协程初始化前调用不会冲突）
        // nochdir=true 保留当前目录；noclose=true 保留标准 IO，下面自行重定向到日志
        $daemonPid = \Swoole\Process::daemon(true, true);
        if (!$daemonPid) {
            fwrite(STDERR, "错误: 无法守护化\n");
            return 1;
        }
        $this->writePid('__supervisor', $daemonPid);

        // 重定向标准 IO；worker 子进程通过 exec 独立运行，不继承此 fd
        $svLog = $this->logsDir . '/supervisor.log';
        fclose(STDIN); fclose(STDOUT); fclose(STDERR);
        $stdIn = fopen('/dev/null', 'r');
        $stdOut = fopen($svLog, 'a');
        $stdErr = fopen($svLog, 'a');

        // 捕获守护阶段致命错误，避免静默退出
        register_shutdown_function(function () use ($svLog) {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                @file_put_contents($svLog, '[fatal] ' . $e['message'] . " @{$e['file']}:{$e['line']}\n", FILE_APPEND);
            }
        });

        $this->log('=== Swoole supervisor started ===', 'manager');

        $baseDir = $this->baseDir;
        $self = $this;

        \Swoole\Coroutine\run(function () use ($scan, $baseDir, $self) {
            // name => {desired,pid,phase(stopped/running/restarting),restarts,proc,startedAt}
            $state = [];
            $pidIndex = []; // pid => name
            $stopping = false;

            $spawn = function (string $name) use (&$state, &$pidIndex, $baseDir, $self) {
                $process = new \Swoole\Process(
                    function ($p) use ($name, $baseDir) {
                        $p->exec(PHP_BINARY, [$baseDir . '/manager.php', 'worker', $name]);
                    },
                    false, 0, false
                );
                $pid = $process->start();
                if ($pid > 0) {
                    $state[$name]['proc'] = $process;
                    $state[$name]['pid'] = $pid;
                    $state[$name]['phase'] = 'running';
                    $state[$name]['startedAt'] = time();
                    $pidIndex[$pid] = $name;
                    $self->log("supervisor 拉起 {$name} (pid={$pid})", 'manager');
                } else {
                    $state[$name]['phase'] = 'stopped';
                    $self->log("supervisor 拉起 {$name} 失败", 'manager');
                }
            };

            // 协程①：即时回收退出子进程并按退避重启
            go(function () use (&$state, &$pidIndex, &$stopping, $spawn, $self) {
                while (!$stopping) {
                    $r = \Swoole\Process::wait(true);
                    if (!$r) { \Swoole\Coroutine::sleep(0.5); continue; }
                    $name = $pidIndex[$r['pid']] ?? null;
                    if ($name === null) continue;
                    unset($pidIndex[$r['pid']]);
                    $state[$name]['pid'] = 0;
                    $state[$name]['phase'] = 'stopped';
                    $state[$name]['proc'] = null;

                    if (!$state[$name]['desired']) continue;

                    $lived = time() - $state[$name]['startedAt'];
                    if ($lived >= 60) $state[$name]['restarts'] = 0;
                    $state[$name]['restarts']++;
                    $delay = min(2 ** min($state[$name]['restarts'], 5), 30);
                    $self->log("{$name} 退出(code={$r['code']},sig={$r['signal']})，{$delay}s 后重启", 'manager');

                    $state[$name]['phase'] = 'restarting';
                    go(function () use ($name, $delay, $spawn, &$state) {
                        \Swoole\Coroutine::sleep($delay);
                        if ($state[$name]['desired'] && $state[$name]['phase'] === 'restarting') {
                            $spawn($name);
                        }
                    });
                }
            });

            // 协程②：周期对账 bots 表
            go(function () use ($scan, &$state, $spawn, $self, &$stopping) {
                while (!$stopping) {
                    \Swoole\Coroutine::sleep($scan);
                    if ($stopping) break;
                    $bots = $self->getAllBots();
                    if (empty($bots)) continue;
                    $seen = [];
                    foreach ($bots as $bot) {
                        $name = $bot['bot_name'];
                        $seen[$name] = true;
                        $desired = (int)$bot['is_active'] === 1;
                        if (!isset($state[$name])) {
                            $state[$name] = [
                                'desired' => $desired, 'pid' => 0, 'phase' => 'stopped',
                                'restarts' => 0, 'proc' => null, 'startedAt' => 0,
                            ];
                        }
                        $st = &$state[$name];
                        $st['desired'] = $desired;
                        if ($desired && !$st['pid'] && $st['phase'] === 'stopped') {
                            $spawn($name);
                        } elseif (!$desired && $st['pid']) {
                            $self->log("{$name} 已停用，发送停止信号", 'manager');
                            if ($st['proc'] !== null) $st['proc']->kill(SIGTERM);
                        }
                        unset($st);
                    }
                    // DB 中已删除的 Bot：停止残留子进程
                    foreach ($state as $name => $st) {
                        if (!isset($seen[$name]) && $st['pid']) {
                            $st['desired'] = false;
                            if ($st['proc'] !== null) $st['proc']->kill(SIGTERM);
                            $self->log("{$name} 已从数据库删除，停止子进程", 'manager');
                        }
                    }
                }
            });

            // 信号：优雅停止监管者与全部子进程
            \Swoole\Process::signal(SIGTERM, function () use (&$stopping, &$state) {
                $stopping = true;
                foreach ($state as $st) {
                    if ($st['pid']) { $st['desired'] = false; if ($st['proc'] !== null) $st['proc']->kill(SIGTERM); }
                }
            });
            \Swoole\Process::signal(SIGINT, function () use (&$stopping, &$state) {
                $stopping = true;
                foreach ($state as $st) {
                    if ($st['pid']) { $st['desired'] = false; if ($st['proc'] !== null) $st['proc']->kill(SIGTERM); }
                }
            });

            // 监管进程自身常驻（被信号置 stopping 后，等其余协程退出）
            while (!$stopping) {
                \Swoole\Coroutine::sleep(1);
            }
            \Swoole\Coroutine::sleep(2);
        });

        $this->log('=== Swoole supervisor stopped ===', 'manager');
        $this->removePid('__supervisor');
        exit(0);
    }

    /**
     * 停止 Swoole 监管者
     */
    public function stopSupervisor(): int
    {
        $sPid = $this->readPid('__supervisor');
        if (!$this->isRunning($sPid)) {
            $this->removePid('__supervisor');
            $this->output("监管者未运行", 'manager');
            return 0;
        }
        posix_kill($sPid, SIGTERM);
        $waited = 0;
        while ($this->isRunning($sPid) && $waited < 12) {
            sleep(1);
            $waited++;
        }
        if ($this->isRunning($sPid)) posix_kill($sPid, SIGKILL);
        $this->removePid('__supervisor');
        $this->output("监管者已停止", 'manager');
        return 0;
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
     * 查看日志：从统一通道及历史归档中精确过滤该 Bot 的行（最近 N 行）
     */
    public function logs(string $botName, int $lines = 50): int
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $botName)) {
            echo "无效的 Bot 名称\n";
            return 1;
        }

        // 数据来源：历史归档（日期+序号正序）在前，当天 bots.log 在最后
        $files = glob($this->logsDir . '/bot/bots-*.log') ?: [];
        sort($files);
        $todayFile = $this->logsDir . '/bots.log';
        if (is_file($todayFile)) {
            $files[] = $todayFile;
        }

        if (empty($files)) {
            echo "没有任何日志文件\n";
            return 1;
        }

        echo "最近 {$lines} 行 [{$botName}] 日志（含历史归档）:\n";
        echo str_repeat('=', 50) . "\n";

        // 锚定行首：[日期 时间] [bot名]，避免误匹配消息内容
        $pattern = '^\[[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\] \['
            . $botName . '\]';

        $cmd = 'grep -hE ' . escapeshellarg($pattern) . ' '
            . implode(' ', array_map('escapeshellarg', $files))
            . ' | tail -n ' . (int)$lines;

        system($cmd);

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

    case 'watch':
        $watchInterval = isset($argv[2]) ? (int)$argv[2] : 30;
        exit($manager->watch($watchInterval));

    case 'unwatch':
        exit($manager->stopWatcher());

    case 'supervise':
        $scanInterval = isset($argv[2]) ? (int)$argv[2] : 10;
        exit($manager->supervise($scanInterval));

    case 'unsupervise':
        exit($manager->stopSupervisor());

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
        echo "  watch     [间隔秒]    启动常驻监控者，自动拉起新增/掉线的 Bot、停用已禁用的 Bot（默认 30s）\n";
        echo "  unwatch               停止常驻监控者\n";
        echo "  supervise [扫描秒]    启动 Swoole 协程监管者：直接持有各 Bot 子进程，崩溃秒级重启（默认 10s 对账）\n";
        echo "  unsupervise           停止 Swoole 监管者\n";
        echo "  list                  列出所有 Bot\n";
        echo "  logs      <bot_name>  [行数] 查看日志（默认 50 行）\n";
        exit(0);
}
