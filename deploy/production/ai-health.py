"""验证服务鉴权、OR-Tools 和共享 Redis，避免多 worker 静默退回内存。"""
import json
import os
import urllib.request

import redis

if not os.environ.get("AI_SERVICE_TOKEN") or not os.environ.get("REDIS_URL"):
    raise SystemExit("AI 生产配置不完整")
redis.Redis.from_url(os.environ["REDIS_URL"]).ping()
request = urllib.request.Request(
    "http://127.0.0.1:8001/health",
    headers={"X-AI-Token": os.environ["AI_SERVICE_TOKEN"]},
)
with urllib.request.urlopen(request, timeout=5) as response:
    body = json.load(response)
if body.get("status") != "ok" or body.get("solver") != "ortools":
    raise SystemExit("AI 求解器未就绪")
