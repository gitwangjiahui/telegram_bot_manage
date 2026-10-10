# 03 Update 处理规格

## 3.1 getUpdates 请求参数

| 参数 | 值 | 依据 |
|---|---|---|
| offset | persisted_offset + 1 | 确认位点推进 |
| timeout | 8（秒） | PHP 短轮询模式（虽称长轮询，实际 8s） |
| allowed_updates | 不设置（收所有默认类型）或明确列出所需 | 见下 |
| limit | 一批最多 100 | Telegram 默认 |

Go 客户端总超时 60s（对齐 Guzzle timeout=60, connect_timeout=10）；proxy 来自 config.http_proxy。

`allowed_updates`：PHP 未设（默认收所有）。Go 为减少无关 update 可明确白名单，但要保证
`message` 和 `my_chat_member` 在列：

```
["message", "my_chat_member"]
```
注意：一旦显式设置，Telegram 会按白名单推送并记录；建议 Go 统一用上述白名单（只处理私聊消息+状态）。
是否排除渠道帖子等见开放问题（当前系统仅需这两类）。

说明：现有业务均为私聊；群/频道帖子不处理，白名单因此只含两类。

## 3.2 Offset 持久化时序（关键，决定消息确认点）

PHP（有 offset 后）：**先处理，再写 last_update_id**——即 getUpdates(offset=N) 处理完这批后
才把 N 重/写到 config。若中途崩溃，这批已处理但未确认的消息会**重复送达**（at-most-once 推进,
实际更接近「处理先于确认 → 可重复」）。

Go 严格复刻该时序：

```
last = config.last_update_id
r = getUpdates(offset = last + 1, timeout=8)
if r.ok:
   max_id = 0
   for u in r.result:
       max_id = max(max_id, u.update_id)
       try: processUpdate(u) except: 记日志(该条不影响其他条)
   if max_id>0:
       config.last_update_id = max_id   (按 bot_id，仅写这一个键)
```

- 单条 process 失败**不阻塞**其余 update，且该条 update_id 仍随批确认（对齐 PHP：单条 catch only log）。
 ⚠ 注意：这意味着单条失败会被「跳过」（下次不再收到）。Go 保留此行为（用户可重新发起）。
- 写 offset 失败（DB 错误）：不推进内存 offset，下轮以原 offset+1 重拉 → 处理可重复但不丢。
- getUpdates itself fails before producing results → 不推进 offset, no confirmed-away loss.
- 写 offset 成功 → 下次取 max+1。

## 3.3 Update 分发决策树

```
Update
 ├─ my_chat_member present → MyChatMemberHandler (拉黑删除验证)
 ├─ message present:
 │    text 以 "/start" 起 (private) → StartHandler
 │    否则 → GenericMessageHandler
 │      （群消息现网同样会进入转发分支，Go 是否对非 private 直接忽略见开放问题）
 └─ other update types → 忽略(noop)
```

### 3.3.1 /start 判定细节

- private 私聊，text == `/start`（可能带 payload `/start xxx`，现网未使用 payload，但 Go 应
  容忍：只要首 token === '/start' 即触发 StartHandler，payload 忽略）。
- 超管发 /start → **emptyResponse（什么都不发）**，对齐 StartCommand。
- 超管的普通消息：见 04.5（超管无 reply 时提示/转发）。
- 管理员/用户 /start → 即便已验证也重新发验证码（PHP 不检查 verified，每次 /start 都换题）。
  Go 保留：任意非超管 /start → 先 clear 旧码 → 发新题。

## 3.4 Processor 需要的 Message 字段（接口契约）

Go 内部 Message 结构至少包含（对应 TG Message）：

```
message_id: int64
date: int64 (unix)
chat: { id:int64, type, username?, first_name?, last_name? }
from: { id:int64, is_bot, first_name, last_name, username, language_code? }
text?: string
caption?: string
reply_to_message?: Message
forward_from?: { id, is_bot, first_name, last_name, username }
forward_sender_name?: string   # 隐藏转发来源只给名字
photo?: [{file_id,file_unique_id,width,height,file_size}]
voice/video/document/sticker/animation/audio/video_note/contact/location: 各自结构
entities?: [...]
```

- `from.id` vs `chat.id`：private 中两者相等；判定发送者用 `from.id`，目标用 `chat.id`。
- Reply 链只支持 text/photo 下行（见 04.3），其余类型用户会收不到但仍归档/映射——保持 PHP 行为。
- forward_from.id 用于回复时识别原用户（管理员侧转发消息自带 `forward_from`=用户）。

## 3.5 并发模型

- 每个 BotRunner 内 **Poller 与 Processor 合并为一个 goroutine**（对齐 PHP：拉到即处理，顺序确认）。
  这样 offset 推进与处理顺序一致，便于复刻 At-most-once-ish 行为。
- 处理中的外部调用（自动回复、forward 扇出、send 回复）可在该条处理内并发（TgMulti 等价物），
  但要**等待全部完成**才结束该条，因为 PHP 同样等待（虽然 archive after but wait）。
  Wait — actually PHP GenericMessage returns after forwardToAllAdmins; auto-reply awaited too. Yes await.
- 不要把整条 update 丢进无限 worker pool 后提前确认 offset（否则崩溃时已确认但未处理 → 丢消息）。
  If Go ever pipelines, it must gate offset advancement on completion of all prior processing.
- PoolMaintainer、Reporter 是独立 goroutine，与收发解耦。

## 3.6 Edge cases

| 情况 | 规格 |
|---|---|
| 空 result（timeout 返回 ok 空） | 0.3s sleep 后重试，offset 不变 |
| batch 内 update 类型多样 | 按决策树，未知 noop，仍确认 |
| 401/409 | 业务错误退避/熔断；409 提示「存在其他消费者」（保证 PHP 已停、botd 没双开） |
| proxy 变更 | 每 N 轮/缓存失效后重取 http_proxy，重建 dialer（见 06） |
| chat not found (400) | 业务错误，记 last_error；不重试那条，但随批确认 |
| 429 | 按 retry_after 等待，不计 errors |
| user blocks after verification; later sends impossible; my_chat_member handles kick |
