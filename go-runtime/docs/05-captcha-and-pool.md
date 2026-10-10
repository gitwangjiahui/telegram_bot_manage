# 05 验证码与预生成池

## 5.1 题目生成（两处统一）

```
a = random integer in [10,99]   (两端含)
b = random integer in [10,99]
question = "{a} + {b} = ?"      例 "47 + 25 = ?"
answer   = str(a+b)             例 "72"
```

Go：`crypto/rand` 或 `math/rand/v2`；务必**每次真随机**（Go 1.20+ 自动播种，无需手动 seed）。
答案取值范围 20~198，answer 列 VARCHAR(10) 足够。

## 5.2 图片渲染规格（纯 Go，不用 GD/CGO）

复刻 PHP GD 风格（`utils/CaptchaPoolWorker::renderImage` / `StartCommand::generateImage`）：

| 属性 | 值 |
|---|---|
| 画布 | 300 × 100 px |
| 背景 | RGB(240,240,240) 填充 |
| 干扰线 | 5 条；颜色 RGB 各通道 random[100,200]；两端点 random 落在画布内 |
| 文字色 | RGB 各通道 random[30,80] |
| 字形 | PHP GD `imagestring(font=5)` 内置位图字体；Go 需用 TTF 经 `golang.org/x/image/font/opentype` 绘制 |
| 字号/位置 | 视觉上近似 GD font5（约 9px 字高、字宽 ~8px）；水平垂直居中 |
| 编码 | PNG |

字体说明与偏差：
- PHP 用 GD 编译进的位图字体；Go 无等价内置位图字体，应 `go:embed` 一个等宽/无衬线 TTF
  （建议开源字体如 GoMono / DejaVuSansMono 子集，随二进制发布，无外部文件依赖）。
- 字号需据所选 TTF 调整到「题面约占画布中部、字高 ~14~18px（TTF 点数值）」以保持观感接近。
  这是**允许的视觉差异**（题面可读性不变），在测试中以「人能清晰读出、OCR/肉眼可辨」验收。
- 居中：按 `text.MeasureString` / font 度量计算起点，等价 PHP 按 `imagefontwidth*len` 算 x。

实时降级（/start 池空）与池渲染共用同一渲染函数（现网两处代码略有重复，Go 合并为一个 renderer）。

## 5.3 PoolMaintainer（取代 CaptchaPoolWorker 子进程）

每 Bot 一个 goroutine，随 BotRunner start 启动、stop 退出。循环：

```
每 LOOP_TICK（对齐 10s，ctx 可中断）:
   target = config.verify_code_pre_gen_num (缺省 100，<=0 用 100)
   avail  = captcha_pool.countAvailable()
   if avail < target:
        made = refill(target - avail)
        log "补货完成 需N 成M 余量A"
   pruneUsed(3)
```

异常（DB/网络）不退出 goroutine，下一轮重试（对齐）。无需「父进程死亡检测」——
它与 BotRunner 同 ctx，runner stop 时 ctx 取消，goroutine 自然结束。

### refill

```
need = target - avail
batchSize = 存储频道 ? 8 : 3     # BATCH_CHANNEL=8 / BATCH_CHAT=3（对齐）
while need > 0 and ctx alive:
   size = min(batchSize, need)
   batch = [ size 个 {question, answer, pngBytes} ]   # 内存中生成，不落盘
   res = uploadBatchConcurrently(batch)
   insertMany(res.items)                              # 落 captcha_pool
   if res.retry_after != nil:
        等待 retry_after 秒（ctx 可中断）；continue     # 本批 need 不递减
   need -= size
```

存储目标（对齐 manager.php spawnPoolWorker）：

```
storageChat = config.captcha_chat_id   # 推荐：bot 任管理员的静音私有频道
deleteAfter = false
若 captcha_chat_id <= 0:
   storageChat = super_admin_id
   deleteAfter = true
```

- 若 token 空或 storageChat<=0（无超管也未配频道）→ 不启动池维护（记日志「验证码池未启动：缺少 token 或存储聊天」），
  /start 将一直走实时降级。
- batchSize 依据 deleteAfter 选择（超管私聊限速更严 → 3；频道 → 8）。

## 5.4 并发上传（取代 curl_multi uploadBatch）

Go：`errgroup` / 带缓冲信号量并发，对每个 captcha：

```
POST https://api.telegram.org/bot{token}/sendPhoto
     multipart/form-data: chat_id=storageChat; photo=captcha.png (PNG bytes)
```

- 内存字节直接作为 multipart 文件（`bytes.Reader` + `CreateFormFile`），**无需临时文件**（比 PHP 干净）。
- 超时：对齐 cURL 30s（单请求），走同一 HTTP client/代理。
- 解析：`ok=true` 取 `result.photo` 数组**最后一项 file_id**；记录
  `{file_id, code=question, answer}`，并收集 `result.message_id` 供删除。
- `error_code=429`：取 `parameters.retry_after`（缺省 1），整批按此退避（对齐：任一 429 即设 retryAfter）。
- 其他错误（400/403/网络）：该项失败不计入成功；网络类错误归类（maintainer 下轮重试，不退避进程）。

成功项落库后：

- `deleteAfter=true`（超管私聊兜底）→ 对成功项的 message_id 并发调用 **deleteMessage**
  （chat_id=storageChat, message_id），避免在超管聊天堆积。删除失败仅日志。
- 频道模式不删（消息保留在静音频道，file_id 持续有效）。

⚠ file_id 有效性：只要该存储聊天/频道不被删除、Bot 未被移出，file_id 一直可用于同 Bot 发送。
若 Bot 被移出存储频道，池里 file_id 可能失效 → sendPhoto(file_id) 给用户会 400。
建议在 /start 发送 file_id 遇 400 bad request: wrong file_id 时**自动降级实时生成**（新增韧性，见开放问题）。

## 5.5 prune

每轮 `DELETE FROM captcha_pool WHERE bot_name=? AND status='used'
AND used_at IS NOT NULL AND used_at < NOW() - INTERVAL 3 DAY`（对齐 keepDays=3）。

## 5.6 acquire（/start 使用，再述并发安全）

- 事务隔离（InnoDB 默认 RR），`SELECT ... WHERE bot=? AND status='available'
  ORDER BY id LIMIT 1 FOR UPDATE` → UPDATE used/used_at → 提交返回。
- 高并发 /start 时行锁串行化，不会把同一条发给两个用户（对齐）。
- Go 连接池下，事务占独立连接；确保事务函数内只使用该 tx，不复用全局句柄。

## 5.7 资源与规模

- 默认每 Bot 池目标 100；3 个 Bot 上限即 300 张约几百 KB~数 MB，DB 存 file_id（非图片），存储压力小。
- PNG 生成 CPU 很轻；补货为偶发，不与消息收发抢占（独立 goroutine，受 Go 调度）。
