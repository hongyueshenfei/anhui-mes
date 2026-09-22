"""排产求解器单测（T1/T2/T3 场景：机台无重叠、模具互斥、EDD 优先、性能）"""

from __future__ import annotations

import time
from datetime import date, datetime, timedelta

import pytest

from app.models.schedule_models import (
    MachineIn,
    MoldIn,
    OccupiedIntervalIn,
    OpIn,
    ScheduleRequest,
    ScheduleSettings,
    TaskIn,
)
from app.services.scheduler import cp_sat_solver, loader, output


def _op(seq: int = 10, op_type: str = "molding_injection", machine_type: str = "injection", duration: int = 3600) -> OpIn:
    return OpIn(
        seq=seq,
        op_type=op_type,
        op_name="注塑成型",
        duration_seconds=duration,
        machine_type=machine_type,
        is_molding=True,
        capacity_per_hour=380,
        mold_change_min=30,
    )


def _task(
    tid: int,
    qty: int = 1900,
    priority: int = 3,
    delivery_date: date | None = None,
    mold_id: int | None = 1,
    duration_seconds: int = 3600 * 6,
) -> TaskIn:
    return TaskIn(
        id=tid,
        task_no=f"TSK-{tid:04d}",
        product_code="P-0001",
        product_name="直口杯 500ml",
        qty=qty,
        priority=priority,
        delivery_date=delivery_date,
        mold_id=mold_id,
        mold_code=f"M-{mold_id:03d}" if mold_id else "",
        molding_op=_op(duration=duration_seconds),
        ops=[_op(duration=duration_seconds)],
    )


def _req(
    tasks: list[TaskIn],
    machines: list[MachineIn] | None = None,
    molds: list[MoldIn] | None = None,
    occupied_intervals: list[OccupiedIntervalIn] | None = None,
    start_at: datetime | None = None,
) -> ScheduleRequest:
    return ScheduleRequest(
        tasks=tasks,
        machines=machines
        or [
            MachineIn(id=1, code="I-01", type="injection", workshop="injection"),
            MachineIn(id=2, code="I-02", type="injection", workshop="injection"),
            MachineIn(id=3, code="B-01", type="blow", workshop="blow"),
        ],
        molds=molds or [MoldIn(id=1, code="M-001"), MoldIn(id=2, code="M-002"), MoldIn(id=3, code="M-003")],
        occupied_intervals=occupied_intervals or [],
        settings=ScheduleSettings(start_at=start_at),
    )


# ---------------------------------------------------------------------------
# T1：机台无重叠
# ---------------------------------------------------------------------------
def test_no_overlap_per_machine():
    tasks = [_task(i, duration_seconds=3600 * 5) for i in range(1, 21)]  # 20 任务 × 5h
    req = _req(tasks)
    model = loader.build_model(req)
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)

    # 按机台分组验证无重叠
    by_machine: dict[int, list[tuple[int, int]]] = {}
    for task in model.tasks:
        mid, start = placement[task.id]
        by_machine.setdefault(mid, []).append((start, start + task.duration_minutes))

    for mid, spans in by_machine.items():
        spans.sort()
        for i in range(1, len(spans)):
            assert spans[i][0] >= spans[i - 1][1], f"机台 {mid} 上任务重叠：{spans[i - 1]} vs {spans[i]}"


def test_cp_sat_no_overlap_with_distinct_molds():
    """回归：CP-SAT 路径必须约束同一机台，不能只依赖同模具互斥。"""
    try:
        from ortools.sat.python import cp_model  # noqa: F401
    except Exception:
        pytest.skip("ortools 未安装，跳过 CP-SAT 专项回归")

    tasks = [_task(i, mold_id=(i % 3) + 1, duration_seconds=3600 * 2) for i in range(1, 7)]
    model = loader.build_model(
        _req(
            tasks,
            machines=[MachineIn(id=1, code="I-01", type="injection")],
        )
    )
    placement = cp_sat_solver.cp_sat_solve(model, time_limit_sec=10)

    assert placement is not None, "CP-SAT 专项回归不能降级到启发式"
    spans = sorted(
        (placement[task.id][1], placement[task.id][1] + task.duration_minutes)
        for task in model.tasks
    )
    for previous, current in zip(spans, spans[1:]):
        assert current[0] >= previous[1], f"同一机台任务重叠：{previous} vs {current}"


def test_machine_specific_duration_is_used():
    """不同产能机台必须使用各自工时，求解与输出不能沿用固定首台工时。"""
    task = _task(1, duration_seconds=3600 * 10).model_copy(
        update={"duration_seconds_by_machine": {1: 3600 * 10, 2: 3600}}
    )
    model = loader.build_model(
        _req(
            [task],
            machines=[
                MachineIn(id=1, code="I-01", type="injection", capacity_per_hour=100),
                MachineIn(id=2, code="I-02", type="injection", capacity_per_hour=1000),
            ],
        )
    )

    internal_task = model.tasks[0]
    assert internal_task.duration_for(1) == 600
    assert internal_task.duration_for(2) == 60

    placement = cp_sat_solver.heuristic_solve(model)
    assert placement[task.id][0] == 2, "保底算法应优先选择实际完工更快的机台"
    makespan, tardiness = cp_sat_solver.heuristic_metrics(model, placement)
    solution = output.build_output(model, placement, ["heuristic_greedy"], 0, makespan, tardiness)
    assert solution.assignments[0].duration_minutes == 60

    try:
        from ortools.sat.python import cp_model  # noqa: F401
    except Exception:
        return
    cp_placement = cp_sat_solver.cp_sat_solve(model, time_limit_sec=10)
    assert cp_placement is not None, "CP-SAT 专项回归不能降级到启发式"
    assert cp_placement[task.id][0] == 2, "CP-SAT 应按选中机台的真实工时优化"


def test_explicit_machine_candidates_are_enforced():
    """后端传入的模具/资源候选机台必须成为算法硬约束。"""
    task = _task(1).model_copy(update={"eligible_machine_ids": [1]})
    model = loader.build_model(
        _req(
            [task],
            machines=[
                MachineIn(id=1, code="I-01", type="injection"),
                MachineIn(id=2, code="I-02", type="injection"),
            ],
        )
    )

    assert model.tasks[0].eligible_machines == [1]
    assert cp_sat_solver.heuristic_solve(model)[task.id][0] == 1

    empty_candidates = task.model_copy(update={"eligible_machine_ids": []})
    with pytest.raises(loader.LoadError, match="无可执行机台"):
        loader.build_model(
            _req(
                [empty_candidates],
                machines=[
                    MachineIn(id=1, code="I-01", type="injection"),
                    MachineIn(id=2, code="I-02", type="injection"),
                ],
            )
        )


def test_existing_machine_occupancy_is_reserved_for_cp_sat():
    """回归：算法不能只避开本次待排任务，还必须避开数据库已有排程。"""
    try:
        from ortools.sat.python import cp_model  # noqa: F401
    except Exception:
        pytest.skip("ortools 未安装，跳过 CP-SAT 专项回归")

    now = datetime.now(loader.TZ)
    base = now.replace(hour=8, minute=0, second=0, microsecond=0)
    if base <= now:
        base = base + timedelta(days=1)
    occupied_end = base + timedelta(hours=2)
    request = _req(
        [_task(1, mold_id=1, duration_seconds=3600)],
        machines=[MachineIn(id=1, code="I-01", type="injection")],
        occupied_intervals=[
            OccupiedIntervalIn(
                task_id=99,
                machine_id=1,
                mold_id=3,
                start=base,
                end=occupied_end,
            )
        ],
    )
    model = loader.build_model(request)
    placement = cp_sat_solver.cp_sat_solve(model, time_limit_sec=10)

    assert placement is not None, "CP-SAT 专项回归不能降级到启发式"
    assert placement[1][1] >= int(occupied_end.timestamp() // 60) + 30


def test_explicit_business_start_at_overrides_algorithm_machine_clock():
    """Laravel 业务时间固定时，算法不能使用自己的操作系统日期作为排产起点。"""
    business_now = datetime(2026, 9, 7, 9, 0, tzinfo=loader.TZ)
    model = loader.build_model(_req([_task(1)], start_at=business_now))

    expected_start = datetime(2026, 9, 8, 8, 0, tzinfo=loader.TZ)
    assert model.start_epoch_min == int(expected_start.timestamp() // 60)


# ---------------------------------------------------------------------------
# T1b：模具互斥（同模具跨机台不并发）
# ---------------------------------------------------------------------------
def test_mold_exclusivity_across_machines():
    # 10 个任务共用同一模具 M-001（分布在多台机台上也不得并发）
    tasks = [_task(i, mold_id=1, duration_seconds=3600 * 4) for i in range(1, 11)]
    req = _req(tasks)
    model = loader.build_model(req)
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)

    spans = []
    for task in model.tasks:
        start = placement[task.id][1]
        spans.append((start, start + task.duration_minutes))
    spans.sort()
    for i in range(1, len(spans)):
        assert spans[i][0] >= spans[i - 1][1], "同一模具不可同时用于两个任务"


# ---------------------------------------------------------------------------
# T2：EDD/优先级排序（紧交期高权重 → 高优先级先排）
# ---------------------------------------------------------------------------
def test_higher_priority_scheduled_earlier():
    base = date.today() + timedelta(days=30)
    high = _task(1, priority=5, delivery_date=base + timedelta(days=1), duration_seconds=3600 * 6)
    low = _task(2, priority=1, delivery_date=base + timedelta(days=2), duration_seconds=3600 * 6)
    req = _req([low, high])  # 故意乱序提交
    model = loader.build_model(req)
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)

    start_high = placement[high.id][1]
    start_low = placement[low.id][1]
    assert start_high <= start_low, "高优先级任务应早于低优先级开始"


def test_edds_urgent_delivery_first_given_same_priority():
    base = date.today() + timedelta(days=30)
    urgent = _task(1, priority=3, delivery_date=base + timedelta(days=1), duration_seconds=3600 * 5)
    relaxed = _task(2, priority=3, delivery_date=base + timedelta(days=10), duration_seconds=3600 * 5)
    req = _req([relaxed, urgent])
    model = loader.build_model(req)
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)

    assert placement[urgent.id][1] <= placement[relaxed.id][1], "同优先级下交期紧的任务先排（EDD）"


def test_delivery_tardiness_reported():
    base = date.today() + timedelta(days=30)
    late = _task(1, priority=3, delivery_date=base + timedelta(days=1), duration_seconds=3600 * 6)
    tasks = [_task(i + 2, duration_seconds=3600 * 6) for i in range(8)]
    model = loader.build_model(_req([late] + tasks))
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)

    _, tardiness = cp_sat_solver.heuristic_metrics(model, placement)
    assert tardiness >= 0


# ---------------------------------------------------------------------------
# T7 口径：换模时间计入机台占用（时长含换模 → 无重叠即占用）
# ---------------------------------------------------------------------------
def test_mold_change_time_counts_as_occupation():
    tasks = [
        _task(1, duration_seconds=3600 * 5 + 1800),  # 5h 加工 + 30min 换模
        _task(2, duration_seconds=3600 * 5 + 1800),
    ]
    req = _req(tasks, machines=[MachineIn(id=1, code="I-01", type="injection")])
    model = loader.build_model(req)
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)

    spans = sorted((placement[t.id][1], placement[t.id][1] + t.duration_minutes) for t in model.tasks)
    gap = spans[1][0] - spans[0][1]
    assert gap >= 0, "换模时间计入机台占用（任务不会在换模期间开工）"
    # 任务时长已含换模（330min = 5h 加工 + 30min 换模），第二任务只能在第一任务结束后开始
    assert spans[1][0] - spans[0][0] >= 5 * 60 + 30, "第二任务只能在第一任务（含换模）结束后开始"


# ---------------------------------------------------------------------------
# 性能：100 任务 ≤ 30s（验收）
# ---------------------------------------------------------------------------
def test_100_tasks_solve_within_30s():
    tasks = [_task(i, mold_id=(i % 3) + 1, duration_seconds=3600 * (2 + i % 4)) for i in range(1, 101)]
    req = _req(tasks)
    model = loader.build_model(req)

    started = time.monotonic()
    placement, algorithms, _ = cp_sat_solver.solve(model, time_limit_sec=25)
    elapsed = time.monotonic() - started

    assert len(placement) == 100
    assert elapsed < 30, f"100 任务求解耗时 {elapsed:.1f}s 超过 30s 上限"
    assert algorithms[0] in ("cp_sat", "heuristic_greedy")


# ---------------------------------------------------------------------------
# 输出序列化
# ---------------------------------------------------------------------------
def test_output_iso_format_and_ordering():
    tasks = [_task(1, duration_seconds=3600), _task(2, duration_seconds=3600)]
    model = loader.build_model(_req(tasks))
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)
    makespan, tardiness = cp_sat_solver.heuristic_metrics(model, placement)
    assert makespan > 0, "完工周期应大于 0"

    solution = output.build_output(model, placement, ["heuristic_greedy"], 12, makespan, tardiness)
    assert len(solution.assignments) == 2
    assert solution.meta.algorithm == "heuristic_greedy"
    for a in solution.assignments:
        assert a.plan_start.startswith("20"), f"plan_start 应为 ISO：{a.plan_start}"
    assert solution.assignments[0].plan_start <= solution.assignments[1].plan_start


# ---------------------------------------------------------------------------
# QA P2：makespan 应为相对完工周期（max_end − min_start），而非绝对 epoch
# ---------------------------------------------------------------------------
def test_makespan_is_relative_not_absolute_epoch():
    tasks = [_task(i, duration_seconds=3600 * 4) for i in range(1, 6)]
    model = loader.build_model(_req(tasks))
    placement, _, _ = cp_sat_solver.solve(model, time_limit_sec=10)

    makespan, _ = cp_sat_solver.heuristic_metrics(model, placement)

    total_duration = sum(t.duration_minutes for t in model.tasks)
    # 5 任务 × 240min（单机串行）= 1200min；任务切换还需预留 30 分钟
    max_duration = total_duration + 30 * (len(model.tasks) - 1)
    assert 0 < makespan <= max_duration, f"相对完工周期应 ∈ (0, {max_duration}]，实际 {makespan}"
    assert makespan < 100000, "绝对 epoch 会得到数十万分钟级数值，相对完工周期不应如此"


# ---------------------------------------------------------------------------
# 入参校验
# ---------------------------------------------------------------------------
def test_task_without_eligible_machine_rejected():
    # 只有吹塑机，任务需注塑 → 无可排任务 → LoadError
    tasks = [_task(1, duration_seconds=3600)]
    req = _req(tasks, machines=[MachineIn(id=3, code="B-01", type="blow")])
    with pytest.raises(loader.LoadError):
        loader.build_model(req)


def test_load_error_reported_as_failed_job():
    from app.api import schedule as schedule_api
    from app.services.job_store import store as job_store

    req = _req([_task(1)], machines=[MachineIn(id=3, code="B-01", type="blow")])
    job_id = job_store.create(1, 1)
    schedule_api._run_job(job_id, req)
    result = job_store.get(job_id)
    assert result is not None and result.status == "failed"
    assert result.error is not None
