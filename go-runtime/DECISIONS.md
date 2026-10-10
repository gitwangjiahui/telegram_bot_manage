# 主代理决策记录（对规格 13 个开放问题的拍板）

本文件优先级高于各 docs 里的"默认/建议"。实现以此为准。

| # | 议题 | 决策 |
|---|---|---|
| 1 | GROUP_CONCAT 多个 super | **取首个** super（Node 写 super 前会删旧，常态唯一） |
| 2 | 网络/DB 等待期 status | **仍写 `polling`**，原因写入 last_error |
| 3 | 连续成功后 last_error | **清空（NULL）**（比 PHP 更合理，避免陈旧报错误导） |
| 4 | 熔断退出后 Reconciler 拉起冷却 | **60s**（避免热循环） |
| 5 | allowed_updates / 非私聊 | 锁定 `["message","my_chat_member"]`；**只处理 private 私聊**，群/频道消息忽略 noop |
| 6 | 无代理空配置 | **默认禁止直连**；仅 env `ALLOW_DIRECT=true` 调试 |
| 7 | 单 chat 令牌桶 | **本轮不加**，靠 429 retry_after 退避 |
| 8 | save / 自动回复失败 | 自动回复失败**不阻断**转发；**验证码 save 失败则不发题**，回"系统繁忙，请稍后重试"（避免用户拿到无法校验的题）；其余尽力而为 |
| 9 | file_id 遇 wrong file_id | **自动降级实时生成** |
| 10 | BOTD_ONLY 白名单 | **支持**（割接期只跑 bot1） |
| 11 | Longman 历史表 | **方案 A：Go 最小补写 `user` + `message`**（保真，Node 历史/转发/图片联查不断）。`chat`/`user_chat`/`telegram_update` 不写 |
| 12 | WS bot scope 过滤 | **不做**，全量推送、前端按权限过滤 |
| 13 | http_proxy / tg_proxy 键 | **本轮不统一**，Go 用 `http_proxy` |

## 关于 #11 方案 A 的最小写入契约

Go 必须写以维持 Node 现网查询（见 messages.routes.js / forward.routes.js）：

### `user` 表（upsert）
- 字段：`id`(TG user id)、`is_bot`、`username`、`first_name`、`last_name`、`created_at`、`updated_at`
- 每次收到消息 `INSERT ... ON DUPLICATE KEY UPDATE` 资料与 updated_at。

### `message` 表
字段（对齐现网 Longman external MySQL 写入）：
`id`(自增 PK)、`chat_id`、`sender_id`(user_id)、`date`(datetime)、`text`、
`reply_to_message`(被回复消息的**本库自增 id**，可空)、
`photo`(JSON 字符串)、`caption`、`sticker`、`voice`、`video`、`document`、`animation`、
`audio`、`video_note`、`contact`、`location`、`entities`、`caption_entities`、
`created_at`、`updated_at`。

写入要点：
- 每条上行私聊消息写一行；`chat_id = chat.id`、`sender_id = from.id`。
- `reply_to_message`：Node 管理员回复链路用它关联 forward_map。
  若被回复消息不在本库（如管理员侧的转发消息），则为 NULL。
- 媒体列存与现网一致的 JSON 串（photo 取最大尺寸，含 file_id/file_unique_id/width/height/file_size）。
- 失败静默，绝不影响收发。
- **只补 user+message 两表**，不维护 chat/user_chat 外键与 telegram_update。

## 部署定值
- botd 监听：`127.0.0.1:19090`
- 镜像：`ghcr.io/jh-tgbotmanage/botd:latest`，容器名 `tg-botd`
- 割接：`BOTD_ONLY=bot1`，先灰度 bot1；bot2/bot3 保持现状不动
