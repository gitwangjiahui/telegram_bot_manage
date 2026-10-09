# Telegram 机器人管理后台

前后端分离，与 PHP 机器人守护进程共用同一个 MySQL、互不干扰：
只新增表（`admin_*` 权限表、`message_log`），不修改现有表结构。

## 仓库划分（GitHub Org: JH-TgBotManage）

| 仓库 | 内容 | 产物 |
|---|---|---|
| `Server` | PHP 机器人守护进程（本仓库主项目） | — |
| `AdminServer` | Node.js + Express 后端 | GHCR `ghcr.io/jh-tgbotmanage/adminserver` |
| `Admin` | Vue3 + Element Plus 管理页面 | GHCR `ghcr.io/jh-tgbotmanage/admin` |

## 功能

仪表盘统计 / 机器人管理（CRUD、启停、Token 校验）/ 用户管理（验证用户、取消验证、机器人管理员）/ 转发设置（每 Bot 的转发目标名单，即时生效）/ 配置管理（全局与 Bot 级 config）/ 历史消息（仿聊天界面，会话列表 + 气泡对话 + 后台直接回复）/ 系统管理（后台账号、角色、权限点、Bot 数据范围 RBAC）。

## CI/CD

`AdminServer`、`Admin` 仓库的 GitHub Actions 在 push master 时自动构建镜像并推送 GHCR
（tag：`latest`、`sha-*`、分支名）。服务器只拉取镜像，不在本机构建。

## 生产部署

网络：宿主机 host 网络。

| 组件 | 端口 |
|---|---|
| 后端 API | 10001（路由前缀 `/server/api`） |
| 前端容器（Nginx 静态） | 27410 |
| 对外入口（宿主 Nginx SSL） | 8881：`/` → 前端，`/server/` → 后端 |

```bash
cd admin-panel
cp .env.example .env        # 填 DB 密码、JWT_SECRET
# 宿主 Nginx 配置见 deploy/nginx-8881.conf，reload 后：
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec backend node src/scripts/migrate.js
```

访问 `https://www.95qw.com:8881`，默认账号 `admin` / `admin123`（登录后修改）。

### GHCR 拉取凭据

镜像默认私有。两种方式：
1. 把两个 package 设为 Public（GitHub 包设置页），服务器免登录直接拉；
2. 服务器 `docker login ghcr.io`，使用带 `read:packages` 权限的 token。

## 消息归档

- `model/MessageLog.php` 在用户上行、管理员回复时写 `message_log`，全程
  `\Throwable` 静默失败，归档异常不影响机器人收发。
- 部署新 PHP 代码需 `git pull && php manager.php restart`。
- 旧消息回填：`docker compose -f docker-compose.prod.yml exec backend node src/scripts/backfill.js --apply`

## 本地开发

```bash
cd backend  && npm install && API_BASE_PATH=/api node src/scripts/migrate.js && API_BASE_PATH=/api npm start
cd frontend && npm install && npm run dev   # vite 已把 /server 代理到 10001
```
