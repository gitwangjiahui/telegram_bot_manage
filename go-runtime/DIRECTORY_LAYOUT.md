# Go 工程目录骨架（botd）

不含 Go 实现代码；以下为目标目录结构与各包职责边界。空目录用 `.gitkeep` 占位。

```
go-runtime/
├── README.md                         # 文档索引
├── DIRECTORY_LAYOUT.md               # 本文件
├── go.mod                            # （实现阶段生成；module 名占位，如 github.com/jh/telegram-bots/botd）
├── Dockerfile                        # 多阶段：golang:1.23 → distroless/static（见 09）
├── .dockerignore
├── .env.example                      # 仅占位符，无真实凭据（见 07.3）
│
├── cmd/
│   └── botd/
│       └── main.go                   # 仅做：加载配置→构建 Supervisor→阻塞等待信号
│
├── internal/
│   ├── config/                       # env/flag 加载、DSN 组装、Config 表缓存(60s TTL+失效)
│   │   ├── loader                    # 进程配置（env/flag）
│   │   └── appconfig                 # config 表读取/解析(JSON-or-raw)/缓存
│   │
│   ├── storage/                      # 数据访问（database/sql + MySQL）
│   │   ├── db                        # 连接池、健康探活、DSN
│   │   ├── bots                      # bots / admins / bot_admin_rela 查询
│   │   ├── logrepo                   # message_log（safe record，uk upsert，计数）
│   │   ├── maprepo                   # forward_map（save/getUserId）
│   │   ├── verifyrepo                # verification_codes / user_verification
│   │   ├── captchapool               # captcha_pool（acquire 事务/insertMany/prune/count）
│   │   └── controlqueue              # bot_control（claim/finish/恢复 running/轮询）
│   │
│   ├── tg/                           # Telegram 客户端
│   │   ├── client                    # HTTP 调用、信封、超时、JSON/multipart
│   │   ├── transport                 # 共享 transport、http_proxy 代理、动态切换
│   │   ├── fanout                    # TgMulti 等价：forwardToChats/sendToChats
│   │   ├── ratelimit                 # 429 retry_after 退避（可选令牌桶）
│   │   └── types                     # Update/Message/User/Chat/PhotoSize 等 DTO
│   │
│   ├── captcha/                      # 验证码
│   │   ├── mathgen                   # 两位数加法出题
│   │   ├── render                    # 纯 Go PNG 渲染（embed TTF，300x100/干扰线）
│   │   └── assets/                   # go:embed 字体文件（开源 TTF）
│   │
│   ├── runtime/                      # 运行时编排
│   │   ├── supervisor                # 注册表、start/stop/restart、信号
│   │   ├── runner                    # BotRunner：组合 poller/processor/reporter/pool
│   │   ├── poller                    # getUpdates 长轮询 + offset 时序
│   │   ├── processor                 # update 分发（03 决策树）
│   │   ├── reporter                  # 心跳 ticker + 当日计数聚合
│   │   ├── reconcile                 # 周期对账 bots.is_active（取代 watcher）
│   │   ├── errors                    # 错误三分类（DB/网络/业务）
│   │   └── vcache                    # user_verification 内存缓存
│   │
│   ├── handlers/                     # 业务处理器（04 各流程）
│   │   ├── start                     # /start（池取/降级）
│   │   ├── message                   # 普通消息、转发扇出、自动回复、归档
│   │   ├── adminreply                # 管理员回复、抄送超管、同步消息
│   │   ├── verification              # checkAnswer、markVerified、通知扇出
│   │   └── chatmember                # my_chat_member kicked
│   │
│   ├── ws/                           # WebSocket /ws
│   │   ├── server                    # 升级、路由、鉴权(JWT)
│   │   ├── hub                       # 注册/广播/慢消费者/控制帧 ping
│   │   ├── events                    # EventBus 事件类型与节流策略
│   │   └── auth                      # JWT 验签（env JWT_SECRET，fail-closed）
│   │
│   ├── httpx/                        # botd 自身 HTTP：/healthz
│   └── logging/                      # slog 初始化、脱敏、token mask
│
├── deploy/
│   ├── compose.botd.yml              # botd 服务片段（host 网络），并入生产 compose
│   ├── nginx-ws.conf                 # /ws 反代片段（见 08.1）
│   └── env.botd.example              # botd 环境变量占位（同 .env.example 说明）
│
└── docs/                             # 规格文档 00~10
```

## 包依赖方向（约束，防止循环/穿透）

```
cmd → runtime
runtime → handlers, storage, tg, captcha, ws, config, logging
handlers → storage, tg, captcha, config (不直接依赖 ws，只通过事件接口)
ws       → config, logging, events      （不依赖 handlers/storage 写操作）
storage  → config, logging
tg       → config, logging
captcha  → logging（assets 内嵌）
```

- `handlers` 不 import `runtime`；事件回传由 runner 注入的回调/事件发布接口完成。
- `storage` 不 import `tg`/`handlers`；只表达数据。
- 所有包共享 `internal/config` 的配置值与 `internal/logging`，不自行读环境变量。

## 每个包对外暴露的最小接口（实现时按此设计，非代码）

- `storage.CaptchaPool`：CountAvailable / Acquire (tx) / InsertMany / PruneUsed。
- `storage.ControlQueue`：RecoverRunning / Claim / Finish / NextPending。
- `tg.Client`：GetUpdates / SendMessage / SendPhoto(file_id|upload) / ForwardMessage / DeleteMessage / GetMe。
- `tg.Fanout`：ForwardToChats → map[id]msgID；SendToChats → 成功数。
- `captcha.Renderer`：Render(question) → (png []byte)。
- `runtime.Supervisor`：Start/Stop/Restart(botName)、Registry 快照、Close()。
- `ws.Hub`：Publish(event)、ServeWS(conn)。
