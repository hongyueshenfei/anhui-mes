"""入参校验与内部模型装配（loader）

职责：
- 校验任务/机台/模具数据的业务一致性（机台类型可执行性、模具引用存在性）
- 计算全局时间水平线（horizon）：从最早可开始时刻到所有任务（含优先级顺延缓冲）能完成的上界
- 输出内部调度模型（内部模型独立于 OR-Tools，启发式与 CP-SAT 共用）
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, datetime, timezone
from typing import Optional
from zoneinfo import ZoneInfo

from app.models.schedule_models import MachineIn, MoldIn, ScheduleRequest, TaskIn

DEFAULT_START = datetime(2025, 1, 1, 8, 0, 0, tzinfo=ZoneInfo("Asia/Shanghai"))

# 业务时区：与 Laravel 端 config('app.timezone') = Asia/Shanghai 保持一致
TZ = ZoneInfo("Asia/Shanghai")


@dataclass
class InternalTask:
    id: int
    task_no: str
    priority: int
    duration_minutes: int
    delivery_epoch_min: Optional[int]
    mold_id: Optional[int]
    eligible_machines: list[int] = field(default_factory=list)
    duration_minutes_by_machine: dict[int, int] = field(default_factory=dict)

    def duration_for(self, machine_id: int) -> int:
        return self.duration_minutes_by_machine.get(machine_id, self.duration_minutes)


@dataclass
class InternalOccupation:
    machine_id: int
    mold_id: Optional[int]
    start_epoch_min: int
    end_epoch_min: int


@dataclass
class InternalModel:
    tasks: list[InternalTask]
    machines: list[MachineIn]
    molds: list[MoldIn]
    horizon_minutes: int
    start_epoch_min: int
    occupied_intervals: list[InternalOccupation] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)

    def machine_by_id(self, machine_id: int) -> Optional[MachineIn]:
        for m in self.machines:
            if m.id == machine_id:
                return m
        return None


class LoadError(ValueError):
    """入参业务校验失败"""


def _epoch_min(dt: datetime) -> int:
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=TZ)
    return int(dt.timestamp() // 60)


def _delivery_deadline(delivery_date: Optional[date], start_epoch_min: int) -> Optional[int]:
    """交期 → 截止时间（当日 17:00 视为交付截止；缺省 None=不限）"""
    if delivery_date is None:
        return None
    deadline = datetime(delivery_date.year, delivery_date.month, delivery_date.day, 17, 0, tzinfo=TZ)
    epoch = _epoch_min(deadline)
    return max(epoch, start_epoch_min)


def build_model(req: ScheduleRequest) -> InternalModel:
    errors: list[str] = []
    machines_by_id = {m.id: m for m in req.machines}
    molds_by_id = {m.id: m for m in req.molds}

    # 起始时间以 Laravel 传入的业务时间为准；只有旧调用方未传时才回退到
    # 算法服务本机时间，避免本地固定业务日期与算法服务器日期漂移。
    now = req.settings.start_at
    if now is None:
        now = datetime.now(TZ)
    elif now.tzinfo is None:
        now = now.replace(tzinfo=TZ)
    else:
        now = now.astimezone(TZ)

    # 起始时间：业务日 08:00 或更晚（排产不早于当前）
    start_dt = now.replace(hour=8, minute=0, second=0, microsecond=0)
    if start_dt < now:
        start_dt = start_dt.replace(day=start_dt.day + 1)
    start_epoch_min = _epoch_min(start_dt)

    tasks: list[InternalTask] = []
    total_duration_minutes = 0
    occupied_intervals: list[InternalOccupation] = []

    for occupied in req.occupied_intervals:
        start_epoch = _epoch_min(occupied.start.astimezone(TZ) if occupied.start.tzinfo else occupied.start)
        end_epoch = _epoch_min(occupied.end.astimezone(TZ) if occupied.end.tzinfo else occupied.end)
        if end_epoch <= start_epoch:
            errors.append(f"机台 {occupied.machine_id} 存在无效占用区间")
            continue
        occupied_intervals.append(
            InternalOccupation(
                machine_id=occupied.machine_id,
                mold_id=occupied.mold_id,
                start_epoch_min=start_epoch,
                end_epoch_min=end_epoch,
            )
        )

    for t in req.tasks:
        eligible: list[int] = []
        if t.eligible_machine_ids is not None:
            allowed = set(t.eligible_machine_ids)
            eligible = [m.id for m in req.machines if m.id in allowed]
        else:
            target_op = t.molding_op
            if target_op is None and t.ops:
                target_op = t.ops[0]
            wanted_type = target_op.machine_type if target_op is not None else None
            if wanted_type is None and target_op is not None:
                wanted_type = target_op.resource_type
            eligible = (
                [m.id for m in req.machines if m.type == wanted_type]
                if wanted_type
                else [m.id for m in req.machines]
            )

        if not eligible:
            errors.append(f"任务 {t.task_no} 无可执行机台（类型匹配失败）")
            continue

        if t.mold_id is not None and t.mold_id not in molds_by_id:
            errors.append(f"任务 {t.task_no} 引用模具 {t.mold_id} 不在提交的模具清单中")
            continue

        duration_by_machine = {
            machine_id: (max(60, t.duration_seconds_by_machine.get(machine_id, t.duration_seconds)) + 59) // 60
            for machine_id in eligible
        }
        duration_minutes = max(duration_by_machine.values(), default=60)
        total_duration_minutes += duration_minutes

        tasks.append(
            InternalTask(
                id=t.id,
                task_no=t.task_no,
                priority=t.priority,
                duration_minutes=duration_minutes,
                delivery_epoch_min=_delivery_deadline(t.delivery_date, start_epoch_min),
                mold_id=t.mold_id,
                eligible_machines=eligible,
                duration_minutes_by_machine=duration_by_machine,
            )
        )

    if not tasks:
        raise LoadError("没有可排任务：" + "; ".join(errors) if errors else "没有可排任务")

    # 水平线：单机串行完成全部任务 + 缓冲
    horizon_minutes = start_epoch_min + total_duration_minutes * 2 + 72 * 60
    if occupied_intervals:
        horizon_minutes = max(
            horizon_minutes,
            max(item.end_epoch_min for item in occupied_intervals) + 30,
        )
    horizon_minutes = min(horizon_minutes, start_epoch_min + 120 * 24 * 60)  # 上限 120 天

    return InternalModel(
        tasks=tasks,
        machines=req.machines,
        molds=req.molds,
        horizon_minutes=horizon_minutes,
        start_epoch_min=start_epoch_min,
        occupied_intervals=occupied_intervals,
        errors=errors,
    )
