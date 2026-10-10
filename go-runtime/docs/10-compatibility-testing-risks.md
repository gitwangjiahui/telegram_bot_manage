# 10 兼容性、测试计划与风险

## 10.1 必须保持的兼容契约（Go 写 → Node 读）

1. `bot_heartbeat`：字段齐全、90s 内刷新；Node 以心跳新鲜度判存活。
2. `message_log`：uk(bot_name,message_id,direction) 不冲突；sender_id、msg_type、text_content 口径一致；
   Node 后台回复 sender_id=0 的记录 Go 无需特殊处理（不重复归档）。
3. `forward_map`：每成功转发写一行，uk(bot_name,forwarded_msg_id)。
4. `bot_control`：claim(running)/finish(done|error,result≤240)，启动恢复残留 running。
5. config 值解析（JSON-or-raw）、60s 缓存语义。
6. 验证码/验证表的过期（+5min）、先 clear 后 insert、kicked 删除验证。

## 10.2 关键兼容决策（需主代理拍板）

- **Longman 历史表（user/chat/message/telegram_update）**：Go 默认不写。后果：
  Node「历史消息会话/转发记录-内容联查 message」在 Go 上线后不再有新内容；
  message_log/forward_map 部分正常。
  - 选项 A：Go 最小补写 user+message（保真、成本高、需复刻 Longman 序列化与 reply 外键）。
  - 选项 B：改 Node 查询，新数据切到 message_log/forward_map（Go 最干净，动 Node）。
  - 本规格默认 B，并把 A 作为备选记录。
- 仪表盘 today 计数只依赖 message_log，Go 已写，不受该决策影响。

## 10.3 bot1 实测计划（唯一允许实测的 bot）

环境：生产 101.200.84.19；MySQL 同机；Clash 9980；仅 bot1。

| # | 用例 | 预期 |
|---|---|---|
| 1 | botd 启动、DB 探活 | 不退出；DB 故障模拟(停 MySQL/改端口)→无限等待，恢复自愈 |
| 2 | 停 PHP bot1，botd start bot1 | 无 409；心跳 ≤90s 转 running；仪表盘显示运行 |
| 3 | 用户 bot1 私聊 /start | 收到带加法题图片（优先 file_id 池）；verification_codes 有行、expires +5min |
| 4 | 验证码池余量 | 心跳 captcha_available；不足时 PoolMaintainer 并发补货；频道/超私聊两种模式 |
| 5 | 池空降级 | 人为清空池 → /start 仍能实时生成发题 |
| 6 | 答案错误/过期 | 收到错误提示；过期(改 expires/等待)同 |
| 7 | 答案正确 | 成功提示；user_verification 落库；所有管理员并发收到验证通过通知 |
| 8 | 已验证发文本 | 先收自动回复；管理员侧收到 forward；forward_map 每行；message_log in +1 |
| 9 | 已验证发图片/语音等 | 正确转发；msg_type 正确；text 取 caption |
| 10 | 管理员点回复（文本/图片） | 用户收到 Bot 下发；普通管理员回复抄送超管；message_log out +1 |
| 11 | 超管回复「[转发自管理员 id]」消息 | 回送给该普通管理员 |
| 12 | 管理员不点回复直接发 | 收到「长按对方消息选择回复…」提示 |
| 13 | 用户拉黑 Bot | my_chat_member kicked → user_verification 行被删 |
| 14 | 网络故障（停 Clash/改代理） | 网络类无限重试不退出，恢复自愈；last_error 体现 |
| 15 | 连续业务错误（如临时配错 token） | 退避；满 10 次熔断，Reconciler 冷却后拉起 |
| 16 | 429（构造高频） | 按 retry_after 等待，不熔断 |
| 17 | WS /ws | 鉴权、hello 快照、状态/消息事件、节流、断连清理 |
| 18 | 割接重复投递 | 杀进程于「处理后/写 offset 前」→ 该批可重复但不丢（验证 at-least-once 语义） |

禁止：用 bot2/bot3 发消息验证、改其配置、假设其管理员/用户存在。多 bot 逻辑用代码审查+
(bot1 上的多分片/单元测试) 保证，不做跨 bot 实测。

## 10.4 风险清单

| 风险 | 影响 | 缓解 |
|---|---|---|
| 409：PHP/Go 或 botd 双开同 token | 轮询互踢 | 割接顺序；restart 先停后启；env 限定 bot 白名单 |
| offset 先处理后确认 | 崩溃可重复 | 已明确 at-least-once；下游操作尽量幂等（forward_map/心跳 upsert；message_log uk） |
| file_id 失效（存储频道被删/Bot 被移出） | /start 发图 400 | 发送遇 wrong file_id 自动降级实时生成（建议） |
| 容器内 127.0.0.1 非宿主 | 连不上 MySQL/Clash | host 网络；否则显式宿主地址 |
| Longman 表停写 | Node 历史/转发内容联查缺新数据 | 10.2 决策；优先改 Node 查询 |
| 验证缓存与 DB 不一致 | 封禁延迟生效 | TTL 60s + 事件失效 |
| 字体/渲染与 GD 差异 | 验证码观感变化 | embed TTF、可辨可读即可；必要时多字体微调 |
| 熔断退出与 watcher 热循环 | 频繁重启 | Reconciler 拉起冷却 30~60s |
| JWT 校验 | WS 越权/连不上 | env 注入同 secret；fail-closed；不做 bot 级过滤(前端过滤) |
| 时区/parseTime | 计数/过期偏差 | DSN loc、TZ=Asia/Shanghai、MySQL 同 CST |
| 凭据泄露 | 安全 | env 注入、占位符、日志脱敏、不读 global.php |

## 10.5 开放问题（汇总，详见各文档）

1. admins GROUP_CONCAT 出现多个 super 时取首个还是最后。
2. status 在网络/DB 等待期是否仍写 polling（建议是）。
3. last_error 连续成功后是否清空（PHP 不主动清空）。
4. 熔断退出后 Reconciler 拉起的冷却时长（建议 30~60s）。
5. allowed_updates 是否锁定 ["message","my_chat_member"]；非私聊消息是否直接忽略。
6. 无代理空配置时是否允许直连（默认不允许，ALLOW_DIRECT 调试）。
7. 是否对单 chat 发送加令牌桶（先仅靠 429 退避）。
8. 验证码 save 失败、自动回复 send 失败时是否阻断主流程（建议均不阻断/尽力而为）。
9. file_id 发送遇 wrong file_id 是否自动降级（建议是）。
10. 是否支持 BOTD_ONLY bot 白名单用于割接（建议支持）。
11. Longman 历史表：A 补写 vs B 改 Node（默认 B）。
12. WS 是否在 botd 内做 bot scope 过滤（建议不做）。
13. 两套代理键 http_proxy / tg_proxy 是否统一（建议本轮不统一）。
