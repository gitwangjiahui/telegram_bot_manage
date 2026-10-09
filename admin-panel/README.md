# Telegram 机器人管理后台

前后端分离的管理系统，与现有 PHP 机器人守护进程**共用同一个 MySQL 但互不干扰**：
只新增表（`admin_*` 权限表、`message_log` 消息归档表），不修改任何现有表结构。

## 功能

| 模块 | 说明 |
|---|---|
| 仪表盘 | 机器人/用户/消息/转发统计 + 近 7 天趋势 |
| 机器人管理 | Bot 的增删改查、启停、Token 校验（getMe） |
| 用户管理 | 各 Bot 验证用户列表、搜索、取消验证、机器人管理员（bot_admin_rela）管理 |
| 转发设置 | 每个 Bot 的转发目标名单（即接收转发的 TG 账号），增删即时生效 |
| 配置管理 | 全局/Bot 级 `config` 表的键值配置编辑 |
| 历史消息 | 仿聊天界面：左侧 Bot×用户 会话列表，右侧气泡对话框，支持从后台直接回复用户 |
| 系统管理 | 后台账号、角色、权限点、按角色的 Bot 数据范围（RBAC） |

## 技术栈

- 后端：Node.js 22 + Express + mysql2 + JWT
- 前端：Vue 3 + Vite + Element Plus + Pinia
- 部署：Docker Compose（前端 Nginx 反代 `/api` 到后端）

## 快速开始（Docker）

```bash
cd admin-panel
cp .env.example .env        # 填写数据库密码、JWT_SECRET、初始管理员
docker compose up -d --build
```

首次启动后执行一次数据库迁移（建表 + 权限初始化 + 创建初始管理员）：

```bash
docker compose exec backend node src/scripts/migrate.js
```

访问：http://localhost:8080 （默认账号 `admin` / `admin123`，登录后请修改）

### 关于 Telegram API 代理

后端容器需要访问 `https://api.telegram.org`（Token 校验、后台回复用户）。
若网络不通，在 `.env` 中设置：

- Mac 本机：`TG_PROXY=http://host.docker.internal:7890`
- Linux 服务器：`TG_PROXY=http://宿主机IP:7890`

## 消息归档原理

- `model/MessageLog.php`（PHP 侧）在用户上行、管理员回复两个时机写 `message_log`，
  全程 `\Throwable` 包裹静默失败，**归档异常绝不影响机器人正常收发**。
- 需要把新代码部署到运行机器人的服务器（git pull + restart）后才开始归档新消息。
- 历史旧消息可选回填：

```bash
docker compose exec backend node src/scripts/backfill.js          # 预演
docker compose exec backend node src/scripts/backfill.js --apply  # 写入
```

## 权限模型（RBAC）

```
admin_users ── admin_user_roles ── admin_roles ── admin_role_perms ── admin_permissions
                                              └── admin_role_bots ── bots（数据范围）
```

- 权限点：菜单可见性 + 接口权限（如 `bot:edit`）
- 数据范围：角色绑定可见 Bot 列表；**不绑定任何 Bot = 全部可见**
- 内置 `super_admin` 角色：全权限、全 Bot，不可修改/删除

## 本地开发（不用 Docker）

```bash
# 后端
cd backend && npm install && node src/scripts/migrate.js && npm start
# 前端
cd frontend && npm install && npm run dev   # http://localhost:5173 已代理 /api
```
