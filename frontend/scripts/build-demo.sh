#!/usr/bin/env bash
# 虹越智造平台 · PC 演示版构建脚本
#
# 产物目录：frontend/dist_demo（已被 .gitignore 的 dist_*/ 规则忽略）
#
# 三个必须同时满足的条件：
#   1) VITE_DEMO_MODE=1 —— 开启演示模式，走 src/mock 的模拟数据（否则请求真实后端，无后端即空白）
#   2) VITE_TEST_NOW=2026-09-07T09:00:00+08:00 —— 与真实环境 hongyue/.env 的 APP_TEST_NOW 对齐
#      src/mock/data.ts 的 buildMockDb() 现在会在建库前调用 installTestClock()，
#      因此 mock 数据与视图时钟统一锚定到 09-07，演示版与真实系统的甘特落在同一个月。
#      （历史坑：installTestClock 原来只在 main.ts 里、晚于 ESM import 求值，
#        导致 mock 数据按真实当天生成，与固定时钟下的视图窗口错开数十天 → 甘特全空。）
#   3) 产物目录名必须是 dist_demo —— .gitignore 只忽略 dist_*/，写 dist-demo 会被提交进 git
#
# 用法：bash scripts/build-demo.sh
# 发布：WorkBuddy「发布为应用」指向 dist_demo，language=node、startCmd="node server.js"
set -euo pipefail

cd "$(dirname "$0")/.."

echo "[1/3] 构建（演示模式 + 时钟锚定 2026-09-07）"
VITE_DEMO_MODE=1 VITE_TEST_NOW='2026-09-07T09:00:00+08:00' npx vite build --outDir dist_demo --emptyOutDir

echo "[2/3] 注入单端口托管服务"
cp deploy/demo-server.js dist_demo/server.js
cat > dist_demo/package.json <<'JSON'
{
  "name": "hongyue-mes-demo",
  "version": "1.0.0",
  "private": true,
  "description": "虹越智造平台 · PC 演示版（mock 数据，单端口静态托管 + SPA fallback）",
  "scripts": {
    "start": "node server.js"
  }
}
JSON

echo "[3/3] 自检"
test -f dist_demo/index.html || { echo "[fail] 缺少 index.html"; exit 1; }
grep -q '演示模式' dist_demo/assets/index-*.js || { echo "[fail] 未检测到演示模式标记，可能未以 VITE_DEMO_MODE=1 构建"; exit 1; }
# 判据：固定日期必须被打进包里（否则时钟会回落到真实当天，与真实系统错月）。
if ! grep -q '2026-09-07' dist_demo/assets/index-*.js 2>/dev/null; then
  echo "[fail] 产物中没有固定时钟，演示版会按真实当天生成数据，与真实系统错月"
  exit 1
fi
echo "[ok] 产物就绪：$(du -sh dist_demo | cut -f1)  →  $(pwd)/dist_demo"
echo "[tip] 本地预览：cd dist_demo && PORT=8899 node server.js"
