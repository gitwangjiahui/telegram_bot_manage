# 02 运行时架构

## 2.1 顶层组件

单进程 botd，一个 `Supervisor` 管理全部 Bot：

```
main
 └─ Supervisor
     ├─ BotRegistry        (内存：botName -> *BotRunner)
     ├─ Reconciler         (周期对账 bots.is_active ↔ 运行实例，取代 watcher)
     ├─ ControlQueueLoop   (消费 bot_control 表)
     ├─ Heartbeat 已并入各 BotRunner
     ├─ WSServer           (/ws，见 08)
     └─ EventBus           (进程内事件：状态/计数/新消息 → WS 扇出)
          每个 active bot:
            BotRunner
              ├─ Poller        (getUpdates 长轮询 + offset 持久化)
              ├─ Processor     (update 分发，见 03/04)
              ├─ PoolMaintainer (验证码池补货 goroutine，见 05)
              └─ Reporter      (心跳 ticker + 当日计数)
```

全部为 goroutine + channel/context，无 fork、无 PID 文件、无子进程。

## 2.2 Bot 生命周期（取代 daemon/pid/manager start）

BotRunner 状态机：

```
created → starting → polling ⇄ backoff(网络/业务错误)
                       │
                       ├─ db-wait (DB 不可用，无限等待)
                       └─ stopping → stopped
```

- `start`：加载 bots+bot_admin_rela（01.1），构造 token 专属 Telegram 客户端（共享 HTTP transport/代理，
  见 06），读 offset，启动 Poller / PoolMaintainer / Reporter。
- `stop`：cancel 该 bot context → 中断在途 getUpdates → PoolMaintainer 退出 →
  写一次心跳 `status='stopped'`（对齐 markStopped）→ 从 Registry 摘除。
- `restart`：stop 完成后 start（等待 stop 实际结束，避免双 getUpdates 造成 409）。

**409 Conflict 约束**：同一 token 任意时刻只能有一个 getUpdates 消费者。迁移期必须确保 PHP 守护已停。
Supervisor 自身通过「同 bot 单 runner + restart 先停后启」保证。

## 2.3 启动引导（main）

1. 加载配置：env/flag → DB DSN（占位符来自部署环境，见 07）。
2. 建 DB 连接池并 `SELECT 1` 探活；**DB 不可用不退出**（进入后述重试）。
3. 初始化 Config 缓存（读全局 http_proxy 等）。
4. 启动 WSServer、EventBus、ControlQueueLoop、Reconciler。
5. 初始对账：对 `is_active=1` 的全部 Bot 执行 start。
6. 安装信号处理：SIGTERM/SIGINT → 整个 Supervisor context cancel → 所有 runner 优雅停止 → 进程退出。

与 PHP 对齐的关键韧性：**DB 不可用、Telegram 网络不可用都不得导致进程退出**。

## 2.4 主循环（对齐 manager.php 循环语义）

Poller 单次迭代（有 offset 后走手动 getUpdates；见 03）：

1. `ctx.Done()` 则退出。
2. 确保 DB 可用；不可用进入 DB 等待（见 2.5），恢复后重建依赖 DB 的对象、清零失败计数。
3. `getUpdates(offset=last+1, timeout=8)`（PHP 用 timeout=8；HTTP 总 timeout=60）。
4. 响应 ok：
   - 有 updates：逐个 process（单条异常只记日志，不影响其他 update）；
     取本批最大 update_id，持久化到 config(`last_update_id`)。
   - 无 updates：空轮询 sleep 0.3s（对齐 usleep 300000）；有更新则立即下一轮。
5. 响应非 ok / 传输错误：按错误三分类处理（2.8）。
6. 周期/每轮末上报心跳（Reporter）。

首次运行（该 bot 无 `last_update_id`）：PHP 走 `$botManager->run()`（Longman 内部从
`telegram_update` 表或 0 offset 起步并回写）。Go 无 telegram_update 依赖，规格统一为：

- 无 offset 记录时从 offset=0（缺省，Telegram 从队头/首条未确认开始）拉取，首批成功后即持久化。
- **迁移注意**：切换到 Go 前若存在未确认积压，首轮会一次性收到历史积压（limit 默认 ≤100）。
  建议上线前用 `clear_updates.php` 等价手段对 bot1 清积压，或接受首批回放（在 10 测试计划中固定做法）。

## 2.5 DB 连接与故障处理

- 使用 `database/sql` 连接池：建议 MaxOpenConns ~ 20、MaxIdleConns 5、ConnMaxLifetime 30m。
- 健康探活 `SELECT 1`；驱动自动剔除坏连接。
- 对齐 `ensureDbConnection`：
  - DB 不可用时**无限重试**，等待 `min(dbFails*2, 30)s`，等待期间 1s tick 响应 ctx 取消。
  - 日志降频：首次及第 10 的倍数次打印（对齐）。
  - 恢复后：dbFails=0、业务错误计数清零、重建依赖 DB 的处理器。
- "gone away" 等：依赖驱动重连；写操作遇到连接错误按 DB 错误归类（不消耗消息，重试）。

## 2.6 心跳 Reporter（对齐 sendHeartbeat）

每轮循环末以及至少每 10s（ticker 兜底，防止长轮询期间心跳超 90s 被判死）：

```
captcha_available = captcha_pool.countAvailable(bot)
today_in/out      = message_log 当天 SUM(方向)  (bot_id)
```

- 三项聚合各自失败用默认 0，互不影响（对齐 try/catch 分块）。
- upsert bot_heartbeat：pid(容器内)、started_at、last_update_id(当前 offset)、status='polling'、
  today_in/out、captcha_available、last_error(≤500)、last_error_at。
- 心跳写入失败静默（对齐 Heartbeat::touch）。
- last_error / last_error_at 更新规则（建议固定）：发生网络/业务/DB 错误时设置；
  连续成功后清空 last_error（设 NULL）。PHP 当前不主动清空，Go 是否清空见开放问题。

## 2.7 热点缓存

- 验证状态：user_verification 加**内存缓存**（key bot+user，TTL 或写时失效）。
  失效时机：markVerified 后立即置真；my_chat_member kicked 删除后置假；重启从 DB 重建。
  风险：管理端若直接改库（如封禁用户），缓存最多滞后 TTL。建议 TTL 60s，并允许 WS/控制通道主动失效。
- bot 管理员集合、auto_reply、池目标量：随 Config 缓存（60s）。
  **管理员变更（Node 写 bot_admin_rela）需在至多 60s 内生效**；可由 WS 管理事件主动刷新。

## 2.8 错误三分类（对齐 isDbError / isNetworkError / 业务错误）

Go 用类型 + HTTP 状态码 + 错误字符串判定，等价映射：

### A. DB 错误（无限等待恢复，不退出，不推进 offset）
判定：`*mysql.MySQLError` 之外的连接级错误、driver.ErrBadConn、错误文本含
`SQLSTATE`/`gone away`/`Connection refused`/`can't connect`/`Lost connection`。
处理：dbFails++，reset 池/标记重建，等待退避后 continue。

### B. Telegram 网络错误（请求未到达/无响应，无限重试，不退出）
判定：传输层错误——DNS 失败、连接拒绝/重置、i/o timeout、TLS 握手失败、EOF、
context deadline（仅针对 HTTP 客户端自身超时）。等价 cURL 6/7/18/28/35/52/56。
处理：netFails++；日志首次及每 10 次；`sleep min(netFails*2,30)`；恢复后重建客户端（可选）。

### C. 业务错误（收到 Telegram 响应的真错误，计入熔断）
HTTP 200 但 ok=false，或 HTTP 4xx 携带 Telegram error_code：
- 401 Unauthorized：token 无效。
- 409 Conflict：已有其他 getUpdates 消费者（迁移期/多开）。
- 400 Bad Request：参数错误（如 chat 不存在、消息不可转发）。
- 其余非网络类。

处理：errors++，记日志（含 error_code/description），重建处理器，
`sleep min(errors*2,30)`；**errors ≥ 10 → 该 BotRunner 退出主循环**（对齐熔断）。
退出后由 Reconciler 依据 is_active 决定是否重新拉起（见 2.9）——
注意这可能与「熔断退出」形成快速重启循环；规格：熔断退出后 Reconciler 仍会拉起，
但叠加一个冷却（如 30~60s），避免热循环（见开放问题）。

`netFails` 与 `errors` 互斥复位（网络成功 errors=0/netFails=0；业务错误时 netFails=0），对齐 PHP。

### 429 Too Many Requests 的特殊性
429 是收到响应的业务层限速，但**不按熔断处理**：读取 `parameters.retry_after` 精确等待后重试，
不增加 errors（与验证码池 worker 一致；普通 API 调用同理，见 06.4）。

## 2.9 Reconciler（取代 manager.php watch）

周期（默认 30s，可配 ≥5s）：

- 读全部 bots(bot_name,is_active)。
- is_active=1 且 Registry 无运行实例 → start（含熔断冷却判断）。
- is_active=0 但有运行实例 → stop。
- 任何异常（含 DB 不可用）不退出，下个 tick 重试。

单实例由 botd 单进程保证，无需 `__watcher.pid`。

## 2.10 日志

- 结构化（slog），level 可配（debug/info/error）。字段至少：time、level、bot、component、msg、err。
- 延续 PHP 三通道概念到文件（可选，容器下默认 stdout）：运行日志、HTTP 成功、HTTP 失败。
- **禁止打印**：完整 api_key、用户敏感内容（默认不打印消息正文，debug 开关显式开启时才可）。
- Token 展示需脱敏（前 5 后 4），供 WS/日志使用（对齐 Node maskToken）。
