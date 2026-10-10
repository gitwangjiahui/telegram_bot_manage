# 07 配置与环境变量

## 7.1 config 表键（bot_id IS NULL 全局，除非注明）

| config_key | 层级 | 类型 | 默认 | Go 用途 |
|---|---|---|---|---|
| `http_proxy` | 全局 | 字符串（**非 JSON**） | 现网 http://127.0.0.1:9980 | Telegram 代理 |
| `verify_code_pre_gen_num` | 全局 | int | 100 | 验证码池目标量 |
| `captcha_chat_id` | 全局 | int | 0（空→超私聊） | 池存储频道 |
| `auto_reply_message` | 全局 + 按 bot | 字符串 | 见下 | 转发前自动回复 |
| `last_update_id` | **按 bot_id** | int | 无 | getUpdates offset |
| `welcome_message` | 全局 | 字符串 | 见 sql/config_init.sql | Go 运行时**未使用**（/start 直接发题），只读忽略 |
| `tg_proxy` | 全局 | 字符串 | — | **Node 后端专用**（proxy.js 读此键）；Go 用 `http_proxy`，勿混用 |

注意系统里存在两套代理键：PHP/Go 读 `http_proxy`；Node 容器读 `tg_proxy`（env TG_PROXY 兜底）。
Go 不要改成 tg_proxy，避免破坏 PHP 语义（若后续统一键名需同步改三方，列为开放问题）。

auto_reply 缺省：
- sql/config_init.sql 的全局文案：`小助理已将消息转发给主人，主人看到后会立马回复你哦`。
- Go 调用处的硬编码兜底：`消息已转发，请等待回复。`（仅当 DB 无任何值时使用）。
- 解析顺序：bot 级非空 → 全局非空 → 硬编码兜底。

## 7.2 值解析与缓存

- 解析：JSON decode 成功（含 null）用解析值；否则原字符串（对齐 PHP parseValue）。
  - 对整数键（pre_gen_num/captcha_chat_id/last_update_id），Go 在读取后做字符串→int 转换，
    容忍值可能被存成 JSON 数字或裸数字字符串（两种都要能解析）。
- 缓存 TTL 60s。失效通道：
  - Bot 控制操作（start/stop/restart）后刷新该 bot 配置；
  - WS 下发 invalidate-config 管理消息（若实现，见 08）；
  - offset（last_update_id）写库后同步更新内存，不等 TTL。

## 7.3 环境变量 / 启动参数清单

DB 连接与运行参数**不写进规格的具体值**，由部署环境（compose env / flag）提供：

| 变量 | 用途 | 默认/说明 |
|---|---|---|
| `BOTD_LISTEN` | HTTP/WS 监听地址 | 如 `:9090`（nginx /ws 反代到此端口；具体端口在 compose 固定，见 09） |
| `DB_HOST` | MySQL 主机 | 生产 `127.0.0.1`（host 网络） |
| `DB_PORT` | 端口 | 3306 |
| `DB_NAME` | 库名 | 占位 `<DB_NAME>`，以服务器 config/global.php 为准 |
| `DB_USER` | 账号 | 占位 `<DB_USER>` |
| `DB_PASSWORD` | 密码 | 占位 `<DB_PASSWORD>`；**只从环境注入，不入库不入日志** |
| `TZ` | 时区 | `Asia/Shanghai` |
| `LOG_LEVEL` | 日志级别 | info（debug 可打印更多，但不打印 token/正文） |
| `RECONCILE_INTERVAL` | 对账周期秒 | 30 |
| `CONTROL_POLL_INTERVAL` | 控制队列轮询秒 | 1 |
| `ALLOW_DIRECT` | 是否允许无代理直连 Telegram | 默认 false（安全）；仅非生产调试置 true |

- 支持 flag 与 env 等价（flag 优先）。提供 `--config`（可选 yaml）不是必须；建议只做 env+flag。
- DSN 组装：`<user>:<pass>@tcp(<host>:<port>)/<name>?charset=utf8mb4&parseTime=true&loc=Asia%2FShanghai&timeout=5s&readTimeout=30s&writeTimeout=30s`。
- 不从 `config/global.php` 读取（那是 PHP/宿主路径，容器内没有该文件）；统一由 env 注入，
  部署文档指明「其取值须与服务器 config/global.php 保持一致」。

## 7.4 密钥卫生

- DB_PASSWORD、Bot api_key 均属机密：只从环境/DB 来；日志、WS、错误信息、metrics 标签中不得明文出现。
- Token 如需对外显示：脱敏（前 5 后 4，中间 ***），对齐 Node maskToken。
