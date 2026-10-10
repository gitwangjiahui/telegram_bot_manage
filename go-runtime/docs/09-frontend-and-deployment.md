# 09 容器化与部署（后端运行时）

前端融合页与 UI 重设计由主代理负责，本文档只覆盖 **Go 运行时（botd）的容器化与部署**。

## 9.1 交付形态

两种等价途径，以容器为主：

1. **容器（主交付）**：多阶段 Dockerfile，与现有 Node/Vue 同一 docker compose 编排、同一宿主部署。
2. **单二进制（兜底）**：本地 go1.23.4 交叉编译
   `GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o botd ./cmd/botd`，
   scp 到服务器即可直接运行（静态、无 libc 依赖）。服务器无需安装 Go。

## 9.2 多阶段 Dockerfile（规格，不写实现代码）

位置建议：`go-runtime/Dockerfile`。阶段：

- **builder**
  - FROM `golang:1.23`（对齐本地 1.23.4）
  - 拷贝 go.mod / go.sum，`go mod download`（利用层缓存）
  - 拷贝源码与 embed 资源（字体），静态编译：
    `CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="-s -w" -o /out/botd ./cmd/botd`
- **runtime**
  - 基础镜像选精简且含 CA 证书、带 TZData 的镜像：`gcr.io/distroless/static-debian12`
    （或 `alpine` + `ca-certificates`、`tzdata`）。distroless 无 shell，攻击面最小。
  - 拷贝 `/out/botd`；设置非 root 用户（distroless 默认 nonroot）。
  - `ENV TZ=Asia/Shanghai`
  - `EXPOSE <BOTD_PORT>`
  - `ENTRYPOINT ["/botd"]`
  - 健康检查（若镜像含可执行；distroless 无 shell 时用单独的 wget 或省略，改由外部 /healthz 探活）。

要点：镜像内只有静态二进制 + 内嵌字体，无 GD、无外部字体文件、无配置文件（配置走 env + DB）。

## 9.3 docker compose（与 Node/Vue 统一编排）

在现有生产编排基础上新增 `botd` 服务。沿用现网关键约束 `network_mode: host`：

```yaml
services:
  botd:
    image: ghcr.io/<org>/botd:${BOTD_TAG:-latest}
    container_name: tg-botd
    restart: unless-stopped
    network_mode: host            # 与 backend/frontend 一致；127.0.0.1:3306 / :9980 直通
    env_file:
      - .env                      # DB_*、JWT_SECRET 等；不含明文入库
    environment:
      TZ: Asia/Shanghai
      BOTD_LISTEN: "127.0.0.1:<BOTD_PORT>"
    volumes:
      - ./logs/botd:/logs         # 若选择落文件；默认结构化日志走 stdout(docker logs)
    depends_on: []                # host 网络下不强制
```

- 用 host 网络的原因：同机 MySQL（127.0.0.1:3306）、Clash（127.0.0.1:9980）均绑回环；
  host 模式零改动即可达，且与现有 Node 服务一致。
- 镜像构建与推送：可复用现有 GitHub Actions 模式（新增一个 workflow：build/push botd 镜像）。
  本地也可 `docker build` 后 `docker save | ssh ... docker load`，供不开 Actions 时使用。
- .env 增补键（不放真实值）：`DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD`、`JWT_SECRET`、
  `BOTD_LISTEN`、`LOG_LEVEL` 等（清单见 07.3）。DB 凭据须与服务器 `config/global.php` 一致，
  但本规格与 .env.example 只写占位符。

## 9.4 nginx 变更（宿主 8881 SSL）

在现有 `admin-panel/deploy/nginx-8881.conf` 增加 `/ws` 反代（配置片段见 08.1）。
现有 `/server/`（Node）与 `/`（前端）不变。reload nginx 生效。

## 9.5 上线/回滚顺序（割接 PHP→Go，仅 bot1 先行）

1. 部署 botd 镜像/compose，但先**不启动 bot1 轮询**（或让 bot1 在 bot_control 上保持 stop），
   确认连库、读 config、WS 端口、/healthz 正常。
2. 停 PHP：`php manager.php stop bot1`（确保 409 不会发生）；可选 `php clear_updates.php bot1` 清积压。
3. 通过 Node 界面/`bot_control` 对 bot1 下发 start（botd 消费）。
4. 验证：心跳 90s 内转 running、bot1 私聊 /start→答题→转发→管理员回复闭环、仪表盘计数、/ws 事件。
5. bot2/bot3 本轮不割（保持 PHP 或停用按现状），不对其做实测依赖。
6. 回滚：bot_control 对 bot1 stop → `php manager.php start bot1`；botd 与 PHP 不同时轮询同一 token。

注意：若 botd 与 PHP 同时在线跑全部 bot，必须确保它们**不消费同一 token**（否则 409）。
割接期可让 botd 仅启用 bot1（见开放问题：是否支持 env 限定 bot 白名单 `BOTD_ONLY=bot1`，建议支持）。

## 9.6 观测

- 容器 stdout 结构化日志，由 docker/journal 采集；可选挂 /logs 落文件。
- `/healthz`（HTTP 200，含 DB 探活结果、实例数）供宿主或监控探测。
- 存活判定仍以 Node 仪表盘读 bot_heartbeat 为准（口径不变）。
