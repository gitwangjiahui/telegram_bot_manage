# 08 WebSocket（/ws）

目的：把 botd 的实时状态/计数/消息事件推给管理前端，作为 Node 轮询之外的实时通道。
本轮只定义协议与后端行为；前端由主代理另行实现。

## 8.1 接入与路径

- nginx 新增 `location /ws/`（或 `/ws`）反代到 botd 监听端口，启用 WebSocket 升级：

```nginx
location /ws/ {
    proxy_pass http://127.0.0.1:<BOTD_PORT>;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;   # 长连
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

- botd 同端口可同时提供 `/ws` 与（可选）`/healthz`。

## 8.2 鉴权

首选：**复用 Node 的 JWT**。前端登录后持有 Node 颁发的 token，连接时以 `?token=` 或
`Sec-WebSocket-Protocol` / `Authorization` 头携带。botd 用与 Node 相同的 `JWT_SECRET`（由 env 注入）
验签，校验有效期。botd 不查 RBAC 表。

- 若 env 未提供 JWT_SECRET：规格默认拒绝 WS（fail-closed）；可在纯内网调试时通过 env
  `WS_AUTH=off` 显式关闭（生产不允许）。
- 权限范围（bot scope）的精细过滤本轮可不做：WS 推送全量 bot 事件，前端按已有权限过滤展示。
  是否在 botd 内按角色过滤见开放问题（需要读 RBAC，成本高，建议不做）。

## 8.3 协议（JSON 文本帧）

客户端 → 服务端（可选，最小实现可只做单向推送）：

```json
{ "type": "subscribe", "channels": ["status", "messages"] }
```
channels：`status`（运行状态/心跳摘要）、`messages`（新消息/转发/回复事件）。缺省订阅全部。
另支持 `{ "type": "ping" }`，服务端回 `{ "type":"pong" }`。

服务端 → 客户端信封：

```json
{ "type": "<event>", "ts": 1760000000, "data": { ... } }
```

事件类型：

| type | 触发 | data 关键字段 |
|---|---|---|
| `hello` | 连接建立 | 协议版本、服务器时间、当前全量 bot 状态快照 |
| `bot_status` | 心跳/状态变化（建议**变化时**或每 ≤5s 节流推送） | bot_name, is_running, status, pid, last_update_id, captcha_available, today_in, today_out, uptime, last_error, last_error_at, age_seconds |
| `bot_lifecycle` | start/stop/restart、控制命令状态变化 | bot_name, action, state(starting/polling/stopped/error), control_id?, result? |
| `message_new` | 上行归档后 | bot_name, user_id, message_id, msg_type, text_content(可截断/按权限), sender_id, created_at |
| `message_out` | 管理员回复发出后 | bot_name, user_id, target_user_id, message_id, msg_type, created_at |
| `user_verified` | 用户验证通过 | bot_name, user_id |
| `pong` | 应答 ping | |

字段语义与 DB/仪表盘一致；is_running 由「最近心跳 ≤90s」推导（与 Node 同口径）。

## 8.4 推送策略与性能

- 进程内 EventBus：BotRunner/Reporter 发布事件 → Hub 扇出给所有连接。
- bot_status 高频心跳不要逐帧发送：按「状态变化即推 + 每 5s 节流摘要」；数字类（today_*）
  可用 5s 节流，避免连接多时放大。
- Hub 负责连接注册、广播、慢消费者处理：单连接发送缓冲（如 256）满则断开该客户端（不阻塞总线）。
- 心跳保活：服务端每 30s 发 ping（WebSocket control frame），无响应/断开则清理。
- 规模：管理端连接数很少（数个），无横向扩展需求；不引入外部消息总线。

## 8.5 与 Node 的关系

- WS 是**附加实时层**，Node `/server/api` 仍是 CRUD 与历史查询的权威；前端可 WS 收事件后回打 Node API 取详情。
- bot_control 的命令结果既写表（Node 可查 control-last），也通过 `bot_lifecycle` 实时推送。
- botd 不替代 Node 的任何 HTTP 业务路由。
