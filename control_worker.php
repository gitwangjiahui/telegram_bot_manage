<?php
/**
 * Bot 控制队列执行器（必须在宿主机运行）
 *
 * 管理后端容器只写 bot_control 表，本脚本轮询 pending 命令并调用：
 *   php manager.php start|stop|restart <bot_name>
 *
 * 启动：
 *   nohup php control_worker.php >> logs/control-worker.log 2>&1 &
 */

declare(strict_types=1);

$root = __DIR__;
$global = require $root . '/config/global.php';
$mysql = $global['mysql'];

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $mysql['host'], $mysql['database']);
$pdo = new PDO($dsn, $mysql['user'], $mysql['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$ppid = getmypid();
register_shutdown_function(function () use ($ppid) {
    logline("control worker 退出 pid=$ppid");
});

logline('control worker 启动');

// 单实例保护：MySQL GET_LOCK 足够，worker 异常断开自动释放
$lockName = 'bot_control_worker';
$pdo->query("SELECT GET_LOCK('$lockName', 2)")->fetchColumn();

// 恢复上次 worker 异常退出时卡在 running 的命令
$pdo->exec("UPDATE bot_control SET status = 'pending', executed_at = NULL
             WHERE status = 'running'");

$idle = 0;
while (true) {
    $row = $pdo->query(
        'SELECT c.id, c.action, b.bot_name
           FROM bot_control c
           JOIN bots b ON b.id = c.bot_id
          WHERE c.status = "pending"
          ORDER BY c.id ASC
          LIMIT 1'
    )->fetch();

    if (!$row) {
        // 空闲时 1s 一次；有命令时尽快执行
        sleep(1);
        if (++$idle % 3600 === 0) {
            // 轻量保活日志，约每小时一行
            logline('worker alive');
        }
        continue;
    }
    $idle = 0;

    $id = (int) $row['id'];
    $action = $row['action'];
    $botName = $row['bot_name'];

    // 先认领，避免多 worker 重复执行（正常只有一个 worker）
    $claimed = claimCommand($pdo, $id);
    if (!$claimed) continue;

    logline("执行 #$id $action $botName");

    $cmd = sprintf(
        'cd %s && php manager.php %s %s 2>&1',
        escapeshellarg($root),
        escapeshellarg($action),
        escapeshellarg($botName)
    );
    exec($cmd, $output, $exitCode);
    $result = trim(implode("\n", $output));
    if ($result === '') $result = $exitCode === 0 ? 'ok' : "exit=$exitCode";
    if (strlen($result) > 240) $result = substr($result, 0, 237) . '...';

    finishCommand($pdo, $id, $exitCode === 0 ? 'done' : 'error', $result);
    logline("完成 #$id status=" . ($exitCode === 0 ? 'done' : 'error') . " result=$result");
}

function claimCommand(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare(
        'UPDATE bot_control
            SET status = "running", executed_at = NOW()
          WHERE id = ? AND status = "pending"'
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function finishCommand(PDO $pdo, int $id, string $status, string $result): void
{
    $stmt = $pdo->prepare(
        'UPDATE bot_control
            SET status = ?, result = ?, executed_at = NOW()
          WHERE id = ?'
    );
    $stmt->execute([$status, $result, $id]);
}

function logline(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}
