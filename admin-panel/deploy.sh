#!/usr/bin/env bash
# TG 机器人管理后台生产部署脚本（只拉取 GHCR 镜像，不在服务器构建）
# 用法:
#   bash deploy.sh            # 拉取最新镜像并滚动更新
#   bash deploy.sh migrate    # 执行数据库迁移（建表/权限/初始管理员）
#   bash deploy.sh backfill [--apply]  # 旧消息回填
#   bash deploy.sh logs <svc> # 查看 backend/frontend 日志
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DEPLOY_DIR"

COMPOSE="docker compose -f docker-compose.prod.yml"

case "${1:-up}" in
  up)
    [ -f .env ] || { echo "缺少 .env，请参照 .env.example 创建"; exit 1; }
    echo "==> 拉取最新镜像"
    $COMPOSE pull
    echo "==> 滚动更新"
    $COMPOSE up -d
    docker image prune -f >/dev/null 2>&1 || true
    sleep 4
    CODE=$(curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1:27402/api/health --max-time 5 || echo "000")
    echo "后端 /api/health HTTP $CODE"
    CODE=$(curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1:27410/ --max-time 5 || echo "000")
    echo "前端页面 HTTP $CODE"
    echo "访问: http://<服务器IP>:27410"
    ;;
  migrate)
    $COMPOSE exec backend node src/scripts/migrate.js
    ;;
  backfill)
    $COMPOSE exec backend node src/scripts/backfill.js "${2:-}"
    ;;
  restart)
    $COMPOSE restart "${2:-}"
    ;;
  stop)
    $COMPOSE down
    ;;
  logs)
    $COMPOSE logs -f --tail=200 "${2:-}"
    ;;
  ps)
    $COMPOSE ps
    ;;
  *)
    echo "用法: bash deploy.sh [up|migrate|backfill|restart|stop|logs|ps]"
    exit 1
    ;;
esac
