# 04 消息流程规格

按现网 PHP 行为逐流程定义。角色判定：`isAdmin = (from.id==super || from.id ∈ normal)`，
`isSuper = (from.id==super)`。

## 4.1 流程一：用户 `/start`（对齐 StartCommand）

前置：发送者非超管（超管 /start = 无任何响应）。

```
1. verification_codes.clear(bot, user)             # 删旧码
2. item = captcha_pool.acquire()                   # 事务 FOR UPDATE，置 used
3a. item != nil:
      verification_codes.save(user, code=item.code, answer=item.answer, expires+5min)
      sendPhoto(chat, photo=item.file_id, caption=欢迎+答题提示)
3b. item == nil:   # 池空：降级实时生成
      log(POOL, "验证码池为空，降级实时生成")
      q = genMath()          # a,b ∈ [10,99]，answer=str(a+b)
      verification_codes.save(user, q.question, q.answer, +5min)
      img = render(q.question)
      sendPhoto(chat, photo=img文件, caption=同文案)
      删除临时图片
```

固定文案（caption）：

```
👋 欢迎使用！

🤖 请计算图片中的数学题
直接回复答案即可
```

实时降级渲染规格见 [05](05-captcha-and-pool.md)（Go 纯 Go 绘制，风格与池图一致）。
题面格式：`"37 + 58 = ?"`，code 列即题面字符串、answer 列即和。

要点：
- acquire 抛错（DB/事务异常）等同池空 → 走实时降级（但实时也依赖 save；save 失败仍尝试发图，
  与 PHP「尽力而为」一致——PHP 中 save 若失败会冒泡到 update 级 catch。Go 规格：
  save 失败记日志，仍发题但用户将无法校验成功；见开放问题，是否改为直接提示稍后再试）。
- 已验证用户 /start 也强制换题（不看 user_verification）。

## 4.2 流程二：未验证用户消息与答案校验（对齐 Genericmessage + checkAnswer）

未验证（非管理员）普通消息：

```
text = trim(message.text 或 "")
if text 匹配 ^\d+$:  → checkAnswer
else:
      sendMessage(user, "⚠️ 请先完成验证\n发送 /start 获取验证码")
      （不转发、不归档）
```

checkAnswer：

```
ok = verification_codes.verify(bot,user,answer)
     SQL: bot+user+answer 精确匹配 AND expires_at > NOW(); 命中→删除该用户码
if ok:
      user_verification.markVerified(user)            # upsert
      sendMessage(chat, "✅ 验证成功！\n\n欢迎使用，现在可以正常使用了。")
      notifyAdminsUserVerified(user)                  # 并发扇出，见下
else:
      sendMessage(chat, "❌ 答案错误或已过期\n请发送 /start 重新获取验证码")
```

notifyAdminsUserVerified（先回用户再通知，避免用户等扇出）：

- 组装文本（HTML），字段来自当前 message.from：
```
✅ 新用户验证通过

👤 用户信息
├ ID: <code>{user_id}</code>
├ 用户名: {@username 或 无}
├ 姓名: {first last，空→无}
└ 时间: {Y-m-d H:i:s}

💡 可直接回复此用户的消息
```
- 目标 = unique([super] + normals) 去空。
- 并发 sendMessage（parse_mode=HTML）。等价 TgMulti.sendToChats。单条失败仅影响该目标，整体不抛。

## 4.3 流程三：已验证用户消息 → 转发管理员（核心客服链路）

顺序（对齐 Genericmessage.execute）：

```
# 1) 先给用户自动回复（避免用户等转发耗时）
reply = config auto_reply_message(bot 级优先，否则全局)；缺省兜底文本 "消息已转发，请等待回复。"
if reply 非空: sendMessage(user, reply)

# 2) 归档上行（失败静默）
message_log: direction='in', sender_id=user, user_id=user, msg_type, text=text||caption

# 3) 转发给全部管理员（并发）
targets = unique([super]+normals)
forwarded = TgMulti.forwardToChats(token, targets, from_chat=chat, message_id)
            # 并发 forwardMessage；返回 chatId -> 管理员侧 message_id（仅成功项）
for (adminID, fwdMsgID) in forwarded:
      forward_map.save(bot, forwarded=fwdMsgID, original=message.message_id, user)
```

- 转发使用 Telegram **forwardMessage**（保留原发送者，管理员侧消息带 `forward_from=用户`）。
- 自动回复解析：bot 级值非空优先，否则全局值；两者都空则用硬编码兜底（对齐 `?? '消息已转发…'`）。
  注意 PHP `getAutoReply` 自身回退全局但不做硬编码；硬编码在调用处。Go 保持两级 + 硬编码兜底。
- message_log 与 forward_map 的写入互不影响主链路；全部失败静默/仅日志。

## 4.4 流程四：管理员回复 → 回发用户（对齐 handleAdminReply）

触发：发送者 isAdmin **且** message.reply_to_message 存在。

```
replyTo = message.reply_to_message

# (A) 超管回复的是「普通管理员同步上来的消息」→ 回给该普通管理员
syncID = parseSyncMessage(replyTo)     # text/caption 以 "[转发自管理员 {id}]" 开头
if syncID 且 当前发送者是超管:
      sendReplyToUser(message, syncID)
      return

# (B) 常规：找回被回复消息对应的原用户
target = nil
if replyTo.forward_from != nil:  target = replyTo.forward_from.id
else:                            target = forward_map.getUserId(replyTo.message_id)
if target == nil: return        # 找不到映射：静默(emptyResponse)

sendReplyToUser(message, target)

# (C) 普通管理员回复 → 同步抄送超管
if 发送者非超管 且 super 存在:
      forwardReplyToSuperAdmin(message, adminID=发送者, target)
```

### sendReplyToUser（仅支持 text / photo）

```
if message.photo:
      file_id = photo 数组中最后(最大)一项
      sendPhoto(target, photo=file_id, caption=message.caption 或 "")
elif message.text:
      sendMessage(target, text=message.text)
# 其他类型：什么都不发（PHP 行为）
# 归档下行（失败静默）：
message_log direction='out', sender_id=message.from.id, user_id=target, type, text
```

注意：回复是**重新发送（send）而非 forward**，用户侧看到来自 Bot 的消息。
photo 复用管理员消息里的 file_id（同 Bot token 下有效）。

### forwardReplyToSuperAdmin（普通管理员 → 超管抄送）

```
header = "[转发自管理员 {adminID}] 回复了用户 {target}\n管理员: {firstName 或 '管理员{id}'}\n─────────────\n\n"
if text:  sendMessage(super, header + text)
elif photo: sendPhoto(super, photo=最大file_id, caption=header + (caption||""))
```

### parseSyncMessage

`replyTo.text || replyTo.caption`，正则 `^\[转发自管理员 (\d+)\]` → 返回 id。
用途：超管在客户端里对「管理员同步抄送」再点回复，形成超管→该管理员的闭环。

### Go 回复映射的来源

- 首选 `reply_to_message.forward_from.id`（forwardMessage 天然带），与 PHP 一致。
- 无 forward_from（如隐藏转发/频道场景）→ forward_map 反查（Go 已写该表）。
- 因此 Go **不需要** Longman `message` 表即可完成回复闭环；forward_map 已足够。

## 4.5 管理员主动发消息（无 reply_to_message）

对齐 Genericmessage：管理员发消息但**没有点回复** →

```
sendMessage(发送者, "长按对方消息选择回复，对方才能收到消息哦")
```

不转发、不归档。超管同样适用（超管无 reply 也只收到该提示）。

## 4.6 用户拉黑 Bot（对齐 MychatmemberCommand）

`my_chat_member.new_chat_member.status == 'kicked'`：

```
user_verification.remove(bot, user)
# 内存验证缓存同步置为未验证
```

其他状态（member / restricted 等）不处理。

## 4.7 流程中的失败处理总表

| 环节 | 失败处理 |
|---|---|
| 自动回复 send | 记日志/错误归类；不阻塞归档与转发（PHP 中会冒泡到 update catch → Go 建议：自动回复失败不阻断转发，见开放问题） |
| message_log 写 | 静默 |
| forwardMessage 扇出 | 逐项成败；失败项不写映射；整体网络错→错误分类；429→退避 |
| forward_map 写 | 仅日志（不影响用户已收到转发的事实） |
| 管理员回复 send 给用户 | 失败（如用户已拉黑）按错误归类/日志；PHP 未对 403 特判，Go 同样不特判 |
| 抄送超管 | 失败仅日志 |
| verification 写 | 建议：标记失败仍通知成功（用户视角优先），但下次判定仍未验证——见开放问题 |
