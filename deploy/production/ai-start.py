"""生产启动时强制共享 Redis 和 OR-Tools 就绪。"""
import os

import ortools
import redis

if not os.environ.get('AI_SERVICE_TOKEN') or not os.environ.get('REDIS_URL'):
    raise SystemExit('AI 生产配置不完整')
redis.Redis.from_url(os.environ['REDIS_URL'], socket_connect_timeout=5).ping()
os.execvp('uvicorn', ['uvicorn', 'app.main:app', '--host', '0.0.0.0', '--port', '8001', '--workers', '2'])
