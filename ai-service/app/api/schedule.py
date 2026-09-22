"""排产 API：POST /schedule/jobs、GET /schedule/jobs/{job_id}"""

from __future__ import annotations

import logging
import time
from concurrent.futures import ThreadPoolExecutor
from typing import Optional

from fastapi import APIRouter, HTTPException

from app.models.schedule_models import JobOut, ScheduleRequest
from app.services.job_store import store
from app.services.scheduler import cp_sat_solver, loader, output

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/schedule", tags=["schedule"])

# 求解执行器：job 异步执行，前端轮询
_executor = ThreadPoolExecutor(max_workers=2)


def _run_job(job_id: str, req: ScheduleRequest) -> None:
    """后台执行：校验 → 求解（CP-SAT 优先/启发式保底）→ 回写结果"""
    started_at = time.monotonic()
    try:
        store.update(job_id, status="running")

        model = loader.build_model(req)
        placement, algorithms, opt_time = cp_sat_solver.solve(model, time_limit_sec=req.settings.time_limit_sec)

        makespan, tardiness = cp_sat_solver.heuristic_metrics(model, placement)
        elapsed_ms = int((time.monotonic() - started_at) * 1000)

        solution = output.build_output(
            model=model,
            placement=placement,
            algorithms_used=algorithms,
            elapsed_ms=elapsed_ms,
            makespan_minutes=makespan,
            total_tardiness_minutes=tardiness,
            opt_time_sec=opt_time,
            note="CP-SAT 精确求解" if "cp_sat" in algorithms else "内置贪心启发式（ortools 未启用/不可用）",
        )

        store.update(
            job_id,
            status="done",
            solution=solution.model_dump(),
            meta=solution.meta.model_dump(),
            error=None,
        )
    except loader.LoadError as exc:
        logger.warning("job %s 入参校验失败：%s", job_id, exc)
        store.update(job_id, status="failed", error=str(exc))
    except Exception as exc:  # noqa: BLE001 - 任意异常都回写 failed，保证轮询可收敛
        logger.exception("job %s 求解异常", job_id)
        store.update(job_id, status="failed", error=f"求解异常：{exc}")


@router.post("/jobs", response_model=JobOut)
def submit_schedule(req: ScheduleRequest) -> JobOut:
    """提交排产任务 → 返回 job_id（异步求解，轮询 GET /schedule/jobs/{id}）"""
    job_id = store.create(task_count=len(req.tasks), machine_count=len(req.machines))
    _executor.submit(_run_job, job_id, req)
    return store.get(job_id)  # type: ignore[return-value]


@router.get("/jobs/{job_id}", response_model=JobOut)
def poll_schedule(job_id: str) -> JobOut:
    """轮询求解结果（pending/running/done/failed + 排程 JSON）"""
    job: Optional[JobOut] = store.get(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail=f"job {job_id} 不存在")
    return job