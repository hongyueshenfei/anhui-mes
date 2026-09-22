"""AI 计算微服务入口（FastAPI）"""

from __future__ import annotations

import os

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware

from app.api import health, quote, schedule

app = FastAPI(title="虹越智造平台 · AI 计算服务", version="3.0.0", description="排产求解（CP-SAT/启发式）+ 报价辅助（DeepSeek）")


@app.middleware("http")
async def internal_token_check(request: Request, call_next):
    """内部共享 Token 鉴权（与 Laravel config/ai.php AI_SERVICE_TOKEN 一致；未配置时跳过）"""
    token = os.getenv("AI_SERVICE_TOKEN", "")
    if token:
        provided = request.headers.get("X-AI-Token", "")
        if provided != token:
            from fastapi.responses import JSONResponse

            return JSONResponse(status_code=401, content={"detail": "invalid internal token"})
    return await call_next(request)


app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(schedule.router)
app.include_router(quote.router)