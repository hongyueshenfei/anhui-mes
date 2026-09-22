"""健康检查：GET /health"""

from __future__ import annotations

import time

from fastapi import APIRouter

router = APIRouter(tags=["health"])


@router.get("/health")
def health() -> dict:
    try:
        import ortools  # noqa: F401

        solver = "ortools"
    except Exception:
        solver = "heuristic_fallback"
    return {
        "status": "ok",
        "service": "hongyue-ai-schedule",
        "solver": solver,
        "time": time.strftime("%Y-%m-%d %H:%M:%S"),
    }