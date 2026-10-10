# 00 总览

## 0.1 现有系统是什么

一个多 Telegram Bot 的**客服中转**系统：

- 终端用户向 Bot 私聊发消息（必须先通过加法图形验证码）。
- Bot 把用户消息**转发（forwardMessage）**给一个超级管理员和若干普通管理员。
- 管理员在自己的 Telegram 客户端里**对转发消息点「回复」**，Bot 通过 `reply_to_message`
  找回原用户，把回复内容发回用户。
- Node.js + Express 管理 API（端口 10001，前缀 `/server/api`）+ Vue3/Element Plus 前端，
  提供 Bot CRUD、转发目标管理、用户/消息浏览、仪表盘、RBAC。

## 0.2 PHP 侧构成（重写对象）

| 文件 | 职责 |
|---|---|
| `manager.php` | 每 Bot 双 fork 守护进程；getUpdates 轮询主循环；fork 验证码池 worker；心跳；start/stop/restart/status/watch |
| `commands/UserCommands/StartCommand.php` | `/start`：超管跳过；优先取 captcha_pool 的 file_id；池空则 GD 实时渲染降级 |
| `commands/UserCommands/GenericmessageCommand.php` | 普通消息：管理员回复映射、验证码答案校验、转发扇出、自动回复、验证通过通知 |
| `commands/UserCommands/MychatmemberCommand.php` | 用户拉黑 Bot（kicked）时删除 user_verification |
| `model/*.php` | CaptchaPool / Heartbeat / MessageLog / VerificationCode / UserVerification / ForwardMap |
| `utils/CaptchaPoolWorker.php` | 常驻：GD 生成 → curl_multi 并发 sendPhoto 存 file_id → 落库；429 退避；prune |
| `utils/TgMulti.php` | curl_multi 并发扇出（forwardMessage / sendMessage） |
| `utils/Config.php` | config 表读取，60s 缓存，JSON 值解析 |
| `utils/DbManager.php` | PDO 单例，fork 后重建 |
| `control_worker.php` | 轮询 bot_control 表执行 manager.php 命令（容器→宿主桥梁） |

## 0.3 环境事实（硬约束）

1. 服务器 `101.200.84.19`，Linux x86_64，**未安装 Go**。
   - 交付方式 A：本地 `go1.23.4` 交叉编译静态单二进制 `GOOS=linux GOARCH=amd64 CGO_ENABLED=0`。
   - 交付方式 B（本次新增要求）：Go 运行时**容器化**，多阶段 Dockerfile，与 Node/Vue 同 compose 编排。
   - 两者不冲突：镜像内即静态二进制；交叉编译单二进制作为无容器兜底手段保留。
2. MySQL 8 同机部署。Go 连接 `127.0.0.1:3306`，DB 名、账号、密码以服务器
   `config/global.php`（gitignore）为准；本规格一律使用占位符（`<DB_NAME>` 等），**不落凭据**。
3. Telegram **必须走代理，不可直连**：config 全局配置 `http_proxy=http://127.0.0.1:9980`（本机 Clash）。
4. Node 管理 API（10001，`/server/api`）与 Vue 前端**保留并继续可用**；Go 对数据库的写入必须兼容其读取。
5. 宿主 nginx：`8881` SSL（域名 `www.95qw.com`），现状 `/server/` → Node、`/` → 前端；**新增 `/ws` → Go**。
6. 测试**只能用 bot1**；禁止依赖 bot2/bot3 做实测（禁止改动其配置/产生依赖其状态的用例）。

## 0.4 目标

- Go 单一二进制（botd）内以 goroutine 同时运行所有 Bot：每 Bot 一个轮询循环 + 一个验证码池维护循环。
- 行为与现网 PHP 等价（消息不丢于迁移、验证码/转发/回复链路一致、心跳与仪表盘读数一致）。
- 内置 supervisor 取代 watcher/daemon/pid/control_worker 机制；消费 `bot_control` 表保持管理端操作可用。
- 提供 `/ws` 实时推送（状态、计数、新消息事件）。
- 纯 Go 渲染验证码（不依赖 GD/CGO），字体随二进制 `go:embed`。
- 容器化交付，compose 编排。

## 0.5 非目标（本轮）

- 不重写 Node 管理 API 的业务能力（仅可能做少量 SQL 适配，见 01/10）。
- 不写前端代码（仅产出融合页信息架构与视觉方向，见 09）。
- 不做 webhook 模式（仍 getUpdates）；不做多语言 Bot 文案系统；不做支付/inline 等未用能力。
- 不迁移历史数据（Longman `message`/`chat`/`telegram_update` 等历史表只读冻结，见 01.6）。

## 0.6 术语

- **超管（super）**：`bot_admin_rela.admin_type='super'` 的 TG 账号，每 Bot 至多一个。
- **普通管理员（normal）**：`admin_type='normal'`。
- **offset / last_update_id**：getUpdates 确认位点；持久化在 config 表（键 `last_update_id`）。
- **池（captcha_pool）**：预生成并已上传的验证码，仅存 Telegram `file_id`。
- **botd**：Go 运行时二进制/服务名（占位，可改名）。
