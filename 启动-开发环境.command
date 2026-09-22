#!/bin/zsh
# ============================================================
# 虹越智造平台 · 开发环境一键启动
# 用途：拉起 MySQL + Laravel(8010) + 前端(5173, 真实模式)
# 用法：双击运行，或终端执行  ./启动-开发环境.command
# 访问：http://localhost:5173   （admin / 123456）
# 说明：服务已运行时自动跳过；日志在 .devlogs/ 目录
# ============================================================

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_DIR="$BASE_DIR/hongyue"
FRONTEND_DIR="$BASE_DIR/frontend"
MYSQLD="/opt/homebrew/opt/mysql/bin/mysqld"
MYSQL_DATA="/opt/homebrew/var/mysql"
LOG_DIR="$BASE_DIR/.devlogs"

mkdir -p "$LOG_DIR"

echo "=============================================="
echo "  虹越智造平台 · 开发环境启动"
echo "=============================================="

# ---------- 1. MySQL ----------
if lsof -i:3306 -sTCP:LISTEN >/dev/null 2>&1; then
  echo "[MySQL]     已运行 (3306)"
else
  echo "[MySQL]     启动中 ..."
  # 清理残留 socket/pid（防锁冲突）
  rm -f /tmp/mysql.sock /tmp/mysqlx.sock "$MYSQL_DATA/$(hostname).pid" 2>/dev/null
  nohup "$MYSQLD" --user="$(whoami)" --datadir="$MYSQL_DATA" \
    --socket=/tmp/mysql.sock --port=3306 --lower-case-table-names=2 \
    > "$LOG_DIR/mysql.log" 2>&1 &
  for i in {1..10}; do
    sleep 1
    mysqladmin ping >/dev/null 2>&1 && break
  done
  mysqladmin ping >/dev/null 2>&1 && echo "[MySQL]     启动成功" || echo "[MySQL]     启动失败，详见 $LOG_DIR/mysql.log"
fi

# ---------- 2. Laravel 后端 ----------
if lsof -i:8010 -sTCP:LISTEN >/dev/null 2>&1; then
  echo "[后端]      已运行 (8010)"
else
  echo "[后端]      启动中 ..."
  (cd "$BACKEND_DIR" && nohup php artisan serve --host=127.0.0.1 --port=8010 \
    > "$LOG_DIR/laravel.log" 2>&1 &)
  sleep 3
  lsof -i:8010 -sTCP:LISTEN >/dev/null 2>&1 && echo "[后端]      启动成功" || echo "[后端]      启动失败，详见 $LOG_DIR/laravel.log"
fi

# ---------- 3. 前端（真实模式） ----------
if lsof -i:5173 -sTCP:LISTEN >/dev/null 2>&1; then
  echo "[前端]      已运行 (5173)"
else
  echo "[前端]      启动中 ..."
  (cd "$FRONTEND_DIR" && nohup env VITE_DEMO_MODE=0 npm run dev \
    > "$LOG_DIR/vite.log" 2>&1 &)
  sleep 5
  lsof -i:5173 -sTCP:LISTEN >/dev/null 2>&1 && echo "[前端]      启动成功" || echo "[前端]      启动失败，详见 $LOG_DIR/vite.log"
fi

echo "----------------------------------------------"
echo "  访问 http://localhost:5173"
echo "  账号 admin / 密码 123456（真实数据库模式）"
echo "----------------------------------------------"
