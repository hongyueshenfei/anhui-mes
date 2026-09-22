"""求解结果序列化（output）

内部求解产出 {task_id: (machine_id, start_epoch_min)}，
输出层转换为 ISO 8601（PHP 端接收后重建完整工序链）。
"""

from __future__ import annotations

from datetime import datetime
from zoneinfo import ZoneInfo

from app.models.schedule_models import Assignment, ScheduleSolution, SolutionMeta
from app.services.scheduler.loader import InternalModel, TZ


def format_iso(epoch_min: int) -> str:
    return datetime.fromtimestamp(epoch_min * 60, tz=TZ).strftime("%Y-%m-%d %H:%M:%S")


def build_output(
    model: InternalModel,
    placement: dict[int, tuple[int, int]],  # task_id -> (machine_id, start_epoch_min)
    algorithms_used: list[str],
    elapsed_ms: int,
    makespan_minutes: int,
    total_tardiness_minutes: int,
    opt_time_sec: float = 0.0,
    note: str = "",
) -> ScheduleSolution:
    assignments: list[Assignment] = []
    deadline_map = {t.id: t.delivery_epoch_min for t in model.tasks}

    for t in model.tasks:
        machine_id, start_min = placement[t.id]
        deadline = deadline_map.get(t.id)
        assignments.append(
            Assignment(
                task_id=t.id,
                machine_id=machine_id,
                plan_start=format_iso(start_min),
                priority=t.priority,
                duration_minutes=t.duration_for(machine_id),
            )
        )

    assignments.sort(key=lambda a: (a.plan_start, a.task_id))

    meta = SolutionMeta(
        makespan_hours=round(makespan_minutes / 60, 2),
        total_tardiness_hours=round(total_tardiness_minutes / 60, 2),
        algorithm="+".join(algorithms_used) if algorithms_used else "unknown",
        elapsed_ms=elapsed_ms,
        objective=round(makespan_minutes + 5.0 * total_tardiness_minutes, 2),
        opt_time_sec=round(opt_time_sec, 2),
        solver_note=note,
    )
    return ScheduleSolution(assignments=assignments, meta=meta)
