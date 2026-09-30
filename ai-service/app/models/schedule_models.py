"""Pydantic 排产入参/出参模型（与 Laravel AutoScheduleService 载荷契约对齐）"""

from __future__ import annotations

from datetime import date, datetime
from typing import Any, Literal, Optional

from pydantic import BaseModel, Field, field_validator

JobStatus = Literal["queued", "pending", "running", "done", "failed"]


class OpIn(BaseModel):
    """单工序参数（时长由 PHP 按权威产能口径预先算好，Python 只做排布）"""

    seq: int
    op_type: str
    op_name: str = ""
    duration_seconds: int = Field(ge=60, description="工序时长（秒，含换模）")
    machine_type: Optional[str] = None
    resource_type: Optional[str] = None
    team_id: Optional[int] = None
    is_molding: bool = False
    capacity_per_hour: float = 0
    mold_change_min: int = 0


class TaskIn(BaseModel):
    """生产任务（产品级）"""

    id: int
    task_no: str
    product_code: str = ""
    product_name: str = ""
    qty: int = Field(gt=0)
    priority: int = Field(ge=0, le=5)
    delivery_date: Optional[date] = None
    mold_id: Optional[int] = None
    mold_code: str = ""
    order_no: str = ""
    molding_op: Optional[OpIn] = None
    ops: list[OpIn] = Field(default_factory=list)
    eligible_machine_ids: Optional[list[int]] = Field(
        default=None,
        description="后端按工艺、模具、锁模力和机台状态过滤后的候选机台，未提供时回退类型匹配",
    )
    duration_seconds_by_machine: dict[int, int] = Field(
        default_factory=dict,
        description="成型工序按候选机台产能计算的时长（秒），缺失时回退 duration_seconds",
    )

    @field_validator("ops")
    @classmethod
    def _sort_ops(cls, v: list[OpIn]) -> list[OpIn]:
        return sorted(v, key=lambda op: op.seq)

    @property
    def duration_seconds(self) -> int:
        """求解用时长 = 成型工序时长（后续链式工序由 PHP 端续排）"""
        if self.molding_op is not None:
            return self.molding_op.duration_seconds
        molding = next((op for op in self.ops if op.is_molding), None)
        if molding is not None:
            return molding.duration_seconds
        return max(op.duration_seconds for op in self.ops) if self.ops else 3600


class MachineIn(BaseModel):
    id: int
    code: str
    name: str = ""
    type: str = "injection"
    workshop: str = ""
    capacity_per_hour: float = 0
    shift_mode: str = "two_shift"


class MoldIn(BaseModel):
    id: int
    code: str = ""
    type: str = "injection"


class OccupiedIntervalIn(BaseModel):
    """数据库中已有排程的资源占用（算法只读，不会改写）"""

    task_id: Optional[int] = None
    machine_id: int
    mold_id: Optional[int] = None
    start: datetime
    end: datetime

    @field_validator("end")
    @classmethod
    def _end_after_start(cls, value: datetime, info) -> datetime:
        start = info.data.get("start")
        if start is not None and value <= start:
            raise ValueError("占用区间结束时间必须晚于开始时间")
        return value


class ScheduleSettings(BaseModel):
    time_limit_sec: int = Field(default=30, ge=1, le=120)
    weight_makespan: float = Field(default=1.0)
    weight_tardiness: float = Field(default=5.0)
    horizon_buffer_hours: int = Field(default=72, description="求解水平线缓冲（任务时长超过其 72 倍）")
    start_at: Optional[datetime] = Field(
        default=None,
        description="业务排产起点；由 Laravel 传入，未提供时才使用算法服务本机时间",
    )


class ScheduleRequest(BaseModel):
    """POST /schedule/jobs 载荷"""

    tasks: list[TaskIn] = Field(min_length=1, max_length=1000)
    machines: list[MachineIn] = Field(min_length=1)
    molds: list[MoldIn] = Field(default_factory=list)
    occupied_intervals: list[OccupiedIntervalIn] = Field(
        default_factory=list,
        description="已有排程占用区间，算法将其作为固定资源约束",
    )
    settings: ScheduleSettings = Field(default_factory=ScheduleSettings)


class Assignment(BaseModel):
    task_id: int
    machine_id: int
    plan_start: str  # ISO 8601（PHP 端回写重建工序链）
    priority: int = 3
    duration_minutes: int = 0


class SolutionMeta(BaseModel):
    makespan_hours: float = 0.0
    total_tardiness_hours: float = 0.0
    algorithm: str = "heuristic_greedy"
    elapsed_ms: int = 0
    objective: float = 0.0
    opt_time_sec: float = 0.0
    solver_note: str = ""


class ScheduleSolution(BaseModel):
    assignments: list[Assignment]
    meta: SolutionMeta


class JobOut(BaseModel):
    job_id: str
    status: JobStatus
    task_count: int = 0
    machine_count: int = 0
    solution: Optional[ScheduleSolution] = None
    meta: Optional[dict[str, Any]] = None
    error: Optional[str] = None
    created_at: str = ""
    finished_at: Optional[str] = None
