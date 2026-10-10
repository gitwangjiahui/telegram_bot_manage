# 06 Telegram 客户端

## 6.1 传输与代理

- Base URL：`https://api.telegram.org`，路径 `/bot<token>/<method>`。
- **强制代理**：全局 config `http_proxy`（现网 `http://127.0.0.1:9980`）。无代理配置时：
  规格默认仍不允许直连（见开放问题：是否在显式空配置时直连，以兼容非该服务器环境）。
- Go：
  - `http.Transport.Proxy = func() (返回 config http_proxy 解析的 *url.URL)`；HTTP 代理走 CONNECT 隧道承载 HTTPS。
  - 代理为 HTTP 型（Clash 混合端口也接受 HTTP CONNECT）；无需 socks5 拨号器（若未来给 socks5://，
    用 `golang.org/x/net/proxy` 扩展，先不实现）。
  - 共享 transport（连接池、TLS session resumption、MaxIdleConns、IdleConnTimeout）。
  - proxy 值随 config 缓存（60s）；检测到变化 → 构建新 transport（旧连接自然关闭）。

容器化注意（见 09）：botd 在容器内，`127.0.0.1:9980` 指向**容器自身**而非宿主 Clash。
必须让容器能访问宿主 Clash：
- 生产 compose 用 `network_mode: host`（与现有 Node 服务一致）→ 容器内 127.0.0.1 即宿主，9980 直通，**首选**；
- 或 bridge 网络下改用宿主地址 + Clash `allow-lan`（不推荐，改动 Clash 配置）。

## 6.2 超时与复用

对齐 Guzzle：`timeout=60`（整体），`connect_timeout=10`。

| 调用 | 建议超时 |
|---|---|
| getUpdates | 整体 60s（参数 timeout=8，正常 ~8s 返回） |
| sendMessage / sendPhoto(file_id) | 20s（TgMulti cURL 为 20） |
| sendPhoto(multipart 上传) | 30s（池上传 cURL 为 30） |
| forwardMessage | 20s |
| deleteMessage | 15s |

- 每 token 不需要独立 transport；**所有 Bot 共享一个 transport/代理**（目标主机相同），仅路径中的 token 不同。
- 客户端对象 per bot（持有 token），内部调用共享 `*http.Client`。

## 6.3 序列化

- getUpdates 的参数少，可用 urlencoded 或 JSON；send/forward 用 **JSON body**（`application/json`）最简单
  （Node tg.js 用 JSON；PHP TgMulti 用 urlencoded，TG 两者皆支持）。
- sendPhoto：
  - file_id 发送 → JSON body `{"chat_id":..,"photo":"<file_id>","caption":..}`（不必 multipart）。
  - 本地上传 → multipart/form-data（池上传；实时降级 /start 也走 multipart）。
- 响应统一信封：
```
{ ok: bool, result: <T>, error_code?: int, description?: string, parameters?: {retry_after?:int} }
```

## 6.4 限速与 429

- Telegram 全局约 30 msg/s/不同 chat、单 chat 1 msg/s（业务侧经验值）。现有系统靠：
  - 空轮询 0.3s 节流；扇出并发；池上传批量 8/3；429 精确退避。
- Go 规则：
  - 收到 429：读 `parameters.retry_after`（缺省 1），**等待后重试同一调用**，不增加业务熔断计数。
  - 对同一目标 chat 的主动发送可选加一个轻量令牌桶（1/s）以减少 429（新增，建议仅在扇出处；
    是否加见开放问题——为严格等价可先不加，仅靠 429 退避）。
  - getUpdates 不存在 30/s 问题（长轮询天然低频）。

## 6.5 扇出（TgMulti 等价物）

泛型「同方法多目标并发」：

```
Fanout(method, targets, buildParams(target)) → map[target]Result
  - 并发度 = len(targets)（管理员数量很少，通常 1~数个）
  - 每请求独立成败；网络错按网络类，429 各自退避（简单实现：整批等 max(retry_after) 重试失败项）
  - 返回仅成功项（forward: target→message_id；send: 成功计数）
```

具体：
- `forwardToChats`：forwardMessage，返回 adminChatID → result.message_id。
- `sendToChats`：sendMessage（验证通知，parse_mode=HTML），返回成功数。

## 6.6 用到的 Bot API 方法清单（Go 只需实现这些）

| 方法 | 用途 | 关键参数/返回 |
|---|---|---|
| getUpdates | 轮询 | offset, timeout=8；result=[Update] |
| getMe | （可选）健康/校验 | Node 已有 /check；Go 启动时可调用验证 token，建议做（仅日志，不阻断） |
| sendMessage | 文本 | chat_id, text, parse_mode?；result.message_id |
| sendPhoto | 图片 | chat_id, photo(file_id 或 multipart), caption；result.message_id, result.photo |
| forwardMessage | 转发 | chat_id(管理员), from_chat_id, message_id；result.message_id |
| deleteMessage | 池清理 | chat_id, message_id |

不使用：editMessageText、answerCallbackQuery、sendDocument（回复链路不支持）、webhook 系列、
inline、payments 等。对应 Update 类型只需 message、my_chat_member。

## 6.7 错误类型映射（供 02.8 使用）

| 现象 | Go 判定 | 分类 |
|---|---|---|
| DNS/拨号/TLS/超时/连接重置/EOF | url.Error 包裹的 net.Error / 字符串 | 网络 B |
| HTTP 429 | 响应信封 error_code=429 | 限速（退避，不熔断） |
| HTTP 401 | error_code=401 | 业务 C（token 无效） |
| HTTP 409 | error_code=409 | 业务 C（多消费者） |
| HTTP 400 | error_code=400 | 业务 C（含 wrong file_id / chat not found） |
| HTTP 403 | error_code=403 | 业务 C（bot blocked 等） |
| ok=false 其他 | description | 业务 C |
