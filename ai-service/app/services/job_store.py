"""job 状态存储（job_store）

- 内存 dict 实现（单进程/测试可用）
- 可选 Redis（环境变量 REDIS_URL 存在时启用，KEY 前缀 ai:schedule:job:<id>）
- job 生命周期：queued → running → done（含 solution）| failed（含 error）
"""

from __future__ import annotations

import json
import os
import threading
import uuid
from datetime import datetime, timezone
from typing import Any, Optional

from app.models.schedule_models import JobOut


class JobStore:
    """线程安全的内存 job 存储（Redis 可用时自动切换）"""

    def __init__(self) -> None:
        self._lock = threading.RLock()
        self._jobs: dict[str, dict[str, Any]] = {}
        self._redis = None
        self._redis_prefix = "ai:schedule:job:"
        self._try_connect_redis()

    def _try_connect_redis(self) -> None:
        redis_url = os.getenv("REDIS_URL", "")
        if not redis_url:
            return
        try:
            import redis  # type: ignore

            self._redis = redis.Redis.from_url(redis_url, decode_responses=True)
            self._redis.ping()
        except Exception:
            self._redis = None

    # ---------- 内存实现 ----------

    def create(self, task_count: int, machine_count: int) -> str:
        job_id = uuid.uuid4().hex[:16]
        payload = {
            "job_id": job_id,
            "status": "queued",
            "task_count": task_count,
            "machine_count": machine_count,
            "solution": None,
            "meta": None,
            "error": None,
            "created_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
            "finished_at": None,
        }
        with self._lock:
            self._jobs[job_id] = payload
        self._persist(job_id, payload)
        return job_id

    def update(self, job_id: str, **fields: Any) -> None:
        with self._lock:
            if job_id not in self._jobs:
                return
            self._jobs[job_id].update(fields)
            if fields.get("status") in ("done", "failed") and "finished_at" not in fields:
                self._jobs[job_id]["finished_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            self._persist(job_id, self._jobs[job_id])

    def get(self, job_id: str) -> Optional[JobOut]:
        with self._lock:
            payload = self._jobs.get(job_id)
            if payload is None and self._redis is not None:
                payload = self._redis.get(self._redis_prefix + job_id)
                if payload:
                    payload = json.loads(payload)
        if payload is None:
            return None
        return JobOut(**payload)

    # ---------- Redis 持久化（可选） ----------

    def _persist(self, job_id: str, payload: dict[str, Any]) -> None:
        if self._redis is None:
            return
        try:
            ttl = 3600 * 24
            self._redis.setex(self._redis_prefix + job_id, ttl, json.dumps(payload, ensure_ascii=False))
        except Exception:
            pass


# 全局单例
store = JobStore()