# Go Bot 运行时重写 — 规格与架构设计

本目录是将现有 PHP（manager.php + Longman TelegramBot + 三个 UserCommand + 验证码池子进程）
重写为 Go 单一运行时的**规格与设计文档**，不含 Go 实现代码。`cmd/`、`internal/` 仅为目录骨架。

## 阅读顺序

| 文档 | 内容 |
|---|---|
| [docs/00-overview.md](docs/00-overview.md) | 背景、环境事实、目标/非目标、术语 |
| [docs/01-data-model.md](docs/01-data-model.md) | 全部相关表结构、字段语义、读写矩阵 |
| [docs/02-runtime-architecture.md](docs/02-runtime-architecture.md) | 进程模型、监督者、主循环、错误三分类、心跳 |
| [docs/03-update-processing.md](docs/03-update-processing.md) | getUpdates 长轮询、offset 持久化、update 分发 |
| [docs/04-message-flows.md](docs/04-message-flows.md) | /start、验证码校验、转发、管理员回复全流程 |
| [docs/05-captcha-and-pool.md](docs/05-captcha-and-pool.md) | 验证码渲染、预生成池、并发上传、429 退避 |
| [docs/06-telegram-client.md](docs/06-telegram-client.md) | HTTP 客户端、代理、限速、扇出、用到的 API 清单 |
| [docs/07-config-and-env.md](docs/07-config-and-env.md) | config 表键、缓存、环境变量清单 |
| [docs/08-ws-api.md](docs/08-ws-api.md) | /ws WebSocket 设计 |
| [docs/09-frontend-and-deployment.md](docs/09-frontend-and-deployment.md) | Go 运行时容器化（多阶段 Dockerfile）、compose 编排、nginx /ws、割接与回滚 |
| [docs/10-compatibility-testing-risks.md](docs/10-compatibility-testing-risks.md) | 兼容决策、bot1 测试计划、风险清单 |

[DIRECTORY_LAYOUT.md](DIRECTORY_LAYOUT.md) 给出 Go 工程目录骨架与每个包的职责边界。

## 状态

- 规格版本：v1（2026-10-10）
- 待决策项见各文档"开放问题"及汇总（父代理最终 JSON 的 open_questions）。
