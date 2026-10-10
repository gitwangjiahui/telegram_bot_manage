# 01 数据模型

按「Go 运行时读写关系」梳理。Go 只访问其业务必需的表；历史库（Longman 自有表）默认**只读/不写**，
冻结策略见 01.6。所有列类型按 MySQL 8 / 现网实际为准。

## 1.1 `bots` — 机器人主档（读为主）

| 列 | 类型 | 语义 | Go 用法 |
|---|---|---|---|
| id | INT AI PK | Bot ID | 关联 config、message_log、bot_control |
| bot_name | VARCHAR(50) UNIQUE | 配置名（如 bot1） | 心跳/池/映射等表的分区键 |
| api_key | VARCHAR(255) | Bot Token | 构造 Telegram 客户端；**禁止出现在日志/API 明文** |
| bot_username | VARCHAR(100) | @username（可空） | 仅展示/校验 |
| is_active | TINYINT(1) | 1 运行开关 | supervisor 对账依据 |
| created_at / updated_at | TIMESTAMP | | 只读 |

启动一个 Bot 实例所需的查询（等价 manager.php `getBotConfig`）：

```sql
SELECT b.id, b.bot_name, b.api_key, b.bot_username, b.is_active,
       GROUP_CONCAT(CONCAT(r.admin_id, ':', r.admin_type)) AS admins
  FROM bots b
  LEFT JOIN bot_admin_rela r ON r.bot_id = b.id
 WHERE b.bot_name = ? AND b.is_active = 1
 GROUP BY b.id;
```

解析 `admins`：逗号分隔 `admin_id:admin_type`；`super` 唯一作为超管，其余为普通管理员列表。
Go 应容忍 admins 为 NULL、重复 super（以最后/任一为准，需在规格中固定为「首个 super」，见开放问题）。

## 1.2 `admins` / `bot_admin_rela` — 管理员

`admins`：id(BIGINT PK)、username、first_name、last_name、时间戳。Go 只读（展示名可选）。

`bot_admin_rela`：

| 列 | 语义 |
|---|---|
| bot_id INT, admin_id BIGINT | 关系 |
| admin_type ENUM('super','normal') | 角色；每 bot 一个 super（Node 端写 super 前会先删旧 super） |
| UNIQUE(bot_id, admin_id) | |

## 1.3 运行时核心表（Go 读写）

### `config` — 全局/按 Bot 配置

- `bot_id IS NULL` 全局；`bot_id` 指定 Bot。UNIQUE(bot_id, config_key)。
- Go 运行时使用的键见 [07](07-config-and-env.md)。关键：
  - `http_proxy`（全局，字符串，**非 JSON**）
  - `verify_code_pre_gen_num`（全局，池目标量，默认 100）
  - `captcha_chat_id`（全局，存储频道 ID；可空→用超私聊）
  - `last_update_id`（**按 bot_id**，offset 持久化）
  - `auto_reply_message`（全局/按 bot；字符串）
- 值解析规则（与 PHP `Config::parseValue` 一致）：先尝试 JSON decode；成功（含 `null`）用解析值，
  否则按原始字符串。注意：普通字符串如 URL 不是合法 JSON，保持原样。
- 缓存：PHP 为 60s TTL。Go 规格：60s TTL + **写后/事件失效**（控制操作、/ws 管理命令可触发 invalidate）。

### `bot_heartbeat` — 心跳（Go 写，Node 读）

主键 bot_name。列：pid, started_at, heartbeat_at, last_update_id, status,
today_in, today_out, captcha_available, last_error(TEXT), last_error_at。

- Go upsert（`INSERT ... ON DUPLICATE KEY UPDATE`），语义与 `Heartbeat::touch` 完全一致。
- Node 判定：`heartbeat_at >= NOW() - 90s` 为存活。**Go 心跳间隔 ≤ 10s**（建议：每轮循环末 + 至少每 10s 一次）。
- `pid`：容器内进程号对宿主无意义，但 Node 直接展示该列。规格：写入容器内 PID（=1 常态），
  不影响存活判定；可在 last_error 或新字段中体现容器（不改表）。
- `status`：`polling`（运行）、`stopped`（实例正常停止，对齐 `markStopped`）。
  网络/DB 等待期仍写 `polling` 并在 last_error 体现原因（建议，见开放问题）。
- last_error 截断 500 字符（对齐 mb_substr）。
- today_in/today_out 来源：message_log 当天计数（见下、02.6）。

### `message_log` — 消息归档（Go 写，Node 读/仪表盘）

| 列 | 语义 |
|---|---|
| id BIGINT AI | |
| bot_id INT, bot_name VARCHAR(50) | 双写冗余 |
| user_id BIGINT | 会话用户（in=发送者；out=目标用户） |
| message_id BIGINT | TG message_id |
| direction ENUM('in','out') | in 用户上行；out 管理员下行 |
| sender_id BIGINT | 实际发送者 TG ID。Node 后台回复写 **0** |
| msg_type VARCHAR(32) | 见类型表 |
| text_content MEDIUMTEXT | text 或 caption，≤5000 字符截断 |
| raw_content MEDIUMTEXT | Go 本轮不写（NULL） |
| created_at | |
| UNIQUE(bot_name, message_id, direction) | 冲突时仅更新 text_content |

Go 写入必须与 `MessageLog::safeRecord` 等价：**任何失败静默，绝不影响收发**；用
`INSERT ... ON DUPLICATE KEY UPDATE text_content=VALUES(text_content)`。

msg_type 映射（Go 直接从 update JSON 判定，优先级同 PHP detectType）：

```
photo > voice > video > video_note > document > sticker > animation > audio > contact > location
> 有 text → text；否则 other
```
（message_log 的 PHP 探测顺序如上；Node 的 TYPE_CASE 顺序略有差异，Go 以 message_log 既有取值为准。）

仪表盘聚合（Go 心跳内复刻）：

```sql
SELECT SUM(direction='in'), SUM(direction='out')
  FROM message_log WHERE bot_id=? AND created_at >= CURDATE();
```

### `forward_map` — 转发映射（Go 写，Node 读）

列：id, bot_name, forwarded_msg_id, original_msg_id, user_id, created_at。
UNIQUE(bot_name, forwarded_msg_id)。

- 每成功一次 forwardMessage（给某管理员）写一行：`forwarded=管理员侧 message_id`，`original=用户消息 id`，user_id。
- upsert：`ON DUPLICATE KEY UPDATE user_id=?`（对齐）。
- 管理员回复时反查：Node 用此表 + Longman `message`；Go 回复链路**自身不靠该表反查**
  （Go 从 reply 的 forward_from / 自身内存+DB 映射判定，见 04.3），但**必须照常写**以兼容 Node 转发记录页。

### `verification_codes` — 待答验证码（Go 读写）

列：bot_name, user_id, code, answer, created_at, expires_at；索引(bot_name,user_id)。

- `/start`：先按 (bot_name,user_id) 清旧码，再插入，`expires_at = NOW()+5min`。
- 校验：精确匹配 bot_name+user_id+answer 且 `expires_at > NOW()`；命中后删除该用户码。
- 该表无唯一约束；Go 保持「先 clear 再 insert」语义，避免多行。

### `user_verification` — 已验证用户（Go 读写，Node 读）

UNIQUE(bot_name,user_id)；verified_at。

- 校验通过：upsert（`ON DUPLICATE KEY UPDATE verified_at=NOW()`）。
- 判定：存在行即可。
- 用户拉黑 Bot（my_chat_member.new_chat_member.status='kicked'）：删除该行。
- 热路径（每条消息都要判定）建议加内存缓存（见 02.7），DB 为权威。

### `captcha_pool` — 预生成验证码（Go 池维护写，/start 事务读）

| 列 | 语义 |
|---|---|
| id BIGINT AI | |
| bot_name | |
| file_id VARCHAR(255) | sendPhoto 到存储聊天返回的 file_id |
| code VARCHAR(50) | 题面，如 `37 + 58 = ?` |
| answer VARCHAR(10) | 答案字符串 |
| status ENUM('available','used') | |
| created_at, used_at | |
| INDEX(bot_name,status) | |

操作（对齐 model/CaptchaPool.php）：

- `countAvailable()`：`COUNT(*) ... WHERE bot_name=? AND status='available'`。
- `acquire()`：事务内 `SELECT ... FOR UPDATE ORDER BY id LIMIT 1` → 置 used/used_at → 返回 file_id/code/answer。
  Go 数据库/sql + InnoDB 可直接复刻；事务失败需回滚并向上抛（/start 据此降级实时生成）。
- 批量插入（逐条 prepare execute，与 PHP 相同；Go 可改单条多值 INSERT 提效，列结构不变）。
- `pruneUsed(3)`：删除 used 且 used_at < NOW()-3 天。

### `bot_control` — 进程控制命令队列（Node 写，Go 消费）

列：id, bot_id, action ENUM(start/stop/restart), status ENUM(pending/running/done/error),
result VARCHAR(255), requested_by, created_at, executed_at。

Go supervisor 消费，取代宿主 control_worker.php：

1. 启动时把残留 `running` 复位为 `pending`（对齐）。
2. 轮询/定时（建议 1s）取最早 pending：先 claim（`UPDATE ... SET status='running', executed_at=NOW()
   WHERE id=? AND status='pending'`，受影响行=1 才执行）。
3. 在本进程内对相应 Bot 实例执行 start/stop/restart（goroutine 生命周期管理，无子进程）。
4. 回写 done/error + result（≤240 字符，对齐截断习惯），executed_at=NOW()。
5. 单实例由「botd 单进程」天然保证；若未来多副本需用 `GET_LOCK('bot_control_worker',...)`（保留此选项）。

## 1.4 RBAC 表（Go 不触碰）

`admin_users / admin_roles / admin_permissions / admin_role_perms / admin_user_roles /
admin_role_bots`：仅 Node 使用。/ws 鉴权若复用 Node JWT，需验签（见 08），不读这些表的细节。

## 1.5 表与 Go 读写矩阵

| 表 | 读 | 写 | 备注 |
|---|---|---|---|
| bots | ✅ | ❌ | 启动/对账 |
| admins | ✅(可选) | ❌ | |
| bot_admin_rela | ✅ | ❌ | 管理员列表 |
| config | ✅ | ✅(仅 last_update_id) | 其余键只读 |
| bot_heartbeat | ✅(可选) | ✅ | Node 依赖 |
| message_log | ✅(计数) | ✅ | 失败静默 |
| forward_map | ✅(可选) | ✅ | 兼容 Node 记录页 |
| verification_codes | ✅ | ✅ | |
| user_verification | ✅ | ✅(upsert/delete) | |
| captcha_pool | ✅(事务) | ✅ | 池 worker |
| bot_control | ✅ | ✅(claim/finish) | 取代 control_worker |
| RBAC 表 | ❌ | ❌ | |
| Longman 历史表 | 默认 ❌ | ❌ | 见 1.6 |

## 1.6 Longman 自有历史表的冻结决策

现网通过 `enableExternalMySql` 自动维护：`user`、`chat`、`user_chat`、`message`、
`telegram_update` 等（结构见 vendor/longman/.../structure.sql）。Node 的 messages/forward
页面**仍在读取 `message`/`user`**。

- Go **不接管**这些表的写入（避免与 Longman 行为差异和外键成本）。
- 迁移影响：Go 上线后，Node 的「历史消息/转发记录-内容联查 `message`」将**不再出现新数据**，
  只有基于 `message_log` / `forward_map` 的部分继续更新。
- 兼容方案（建议，最终由主代理在 10 中拍板）：
  1. Go 最小写入 `user`（upsert 用户资料）与 `message`（文本/媒体 JSON + user_id/reply_to_message），
     以维持 Node 会话查询——成本最高、最保真；
  2. 或调整 Node 查询，把新数据完全切到 message_log/forward_map——改 Node，Go 最干净。
- 本规格默认 **Go 不写 Longman 表（方案 2 方向）**，并在 10 中列为必须决策的兼容项。

## 1.7 字符集与时间

- 全库 utf8mb4；连接强制 `charset=utf8mb4`。
- 连接后设置会话 `wait_timeout/interactive_timeout`（PHP 设 28800）；Go 驱动建议
  `parseTime=true&loc=Asia%2FShanghai&timeout=...&readTimeout=...`，并启用连接池（见 02.5）。
- 时区统一 Asia/Shanghai；`CURDATE()`/`NOW()` 依赖 MySQL 会话时区（同机默认即 CST）。
