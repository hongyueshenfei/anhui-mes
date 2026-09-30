"""排产求解器（cp_sat_solver）

策略（架构 §8：100 任务 ≤30s；失败可回退）：
1. 优先 OR-Tools CP-SAT：每任务可选机台区间变量 + 同机台 NoOverlap + 同模具互斥 +
   最小化 完工周期 + 加权拖期；time_limit_sec 超时熔断。
2. ortools 未安装 / 导入失败 / 超时 / 求解失败 → 自动降级内置贪心启发式
   （优先级+EDD 排序 → 最早可用机台 → 模具互斥），保证服务始终可算可测。

升级点（后续方向）：
- 序依赖换模时间（同类连续生产减换模）→ 当前时长已含换模（PHP 权威口径）
- 班制日历由 PHP 计算，求解器按 24h 连续处理（职责分离）
"""

from __future__ import annotations

import logging
import time
from typing import Optional

from app.services.scheduler.loader import InternalModel

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# 贪心启发式（保底算法，确定性）
# ---------------------------------------------------------------------------
def heuristic_solve(model: InternalModel) -> dict[int, tuple[int, int]]:
    """贪心：优先级升序（P0 最紧急）+ 交期升序（EDD）排序；每任务选最早完成的可用机台。

    约束：同机台无重叠（machine_until）；同模具不并发（mold_until）。
    返回 {task_id: (machine_id, start_epoch_min)}
    """
    ordered = sorted(
        model.tasks,
        key=lambda t: (
            t.priority,
            t.delivery_epoch_min if t.delivery_epoch_min is not None else 10**12,
            t.duration_minutes,
        ),
    )

    machine_until: dict[int, int] = {m.id: model.start_epoch_min for m in model.machines}
    mold_until: dict[int, int] = {}
    for occupied in model.occupied_intervals:
        if occupied.end_epoch_min <= model.start_epoch_min:
            continue
        machine_until[occupied.machine_id] = max(
            machine_until.get(occupied.machine_id, model.start_epoch_min),
            occupied.end_epoch_min + 30,
        )
        if occupied.mold_id is not None:
            mold_until[occupied.mold_id] = max(
                mold_until.get(occupied.mold_id, model.start_epoch_min),
                occupied.end_epoch_min,
            )
    placement: dict[int, tuple[int, int]] = {}

    for task in ordered:
        best: Optional[tuple[int, int, int]] = None  # (completion, start, machine_id)

        for machine_id in task.eligible_machines:
            ready = machine_until.get(machine_id, model.start_epoch_min)
            if task.mold_id is not None:
                ready = max(ready, mold_until.get(task.mold_id, model.start_epoch_min))
            start = max(ready, model.start_epoch_min)
            completion = start + task.duration_for(machine_id)

            if best is None or completion < best[0]:
                best = (completion, start, machine_id)

        assert best is not None, f"任务 {task.task_no} 无可排机台"
        completion, start, machine_id = best
        # 同一资源切换任务固定预留30分钟；模具本身只按实际完工时间互斥。
        machine_until[machine_id] = completion + 30
        if task.mold_id is not None:
            mold_until[task.mold_id] = completion
        placement[task.id] = (machine_id, start)

    return placement


def heuristic_metrics(model: InternalModel, placement: dict[int, tuple[int, int]]) -> tuple[int, int]:
    """完工周期（相对：max_end − min_start）与总拖期（分钟）"""
    starts = [placement[t.id][1] for t in model.tasks]
    ends = [placement[t.id][1] + t.duration_for(placement[t.id][0]) for t in model.tasks]
    makespan = max(ends, default=0) - min(starts, default=0)
    tardiness = 0
    for task in model.tasks:
        if task.delivery_epoch_min is None:
            continue
        end = placement[task.id][1] + task.duration_for(placement[task.id][0])
        tardiness += max(0, end - task.delivery_epoch_min)
    return makespan, tardiness


# ---------------------------------------------------------------------------
# OR-Tools CP-SAT（可选升级路径）
# ---------------------------------------------------------------------------
def cp_sat_solve(model: InternalModel, time_limit_sec: int) -> Optional[dict[int, tuple[int, int]]]:
    """CP-SAT 求解；ortools 不可用/超时/无解返回 None（触发降级）"""
    try:
        from ortools.sat.python import cp_model  # type: ignore
    except Exception as exc:  # pragma: no cover - 降级路径
        logger.info("ortools 不可用（%s），使用贪心启发式", exc)
        return None

    start_wall = time.time()
    horizon = model.horizon_minutes
    start = model.start_epoch_min

    cm = cp_model.CpModel()

    # (task_id, machine_id) -> 可选区间变量（0=未选中）
    intervals: dict[tuple[int, int], object] = {}
    starts: dict[tuple[int, int], object] = {}
    ends: dict[tuple[int, int], object] = {}
    selected: dict[tuple[int, int], object] = {}  # 机台选择布尔变量
    task_starts: dict[int, object] = {}
    task_ends: dict[int, object] = {}

    for task in model.tasks:
        for machine_id in task.eligible_machines:
            key = (task.id, machine_id)
            s = cm.new_int_var(start, horizon, f"s_{task.id}_{machine_id}")
            e = cm.new_int_var(start, horizon, f"e_{task.id}_{machine_id}")
            occupied_end = cm.new_int_var(start, horizon, f"occupied_e_{task.id}_{machine_id}")
            # NoOverlap 占用区间包含任务后的30分钟切换准备，但输出结束时间仍是实际加工结束。
            occupied = task.duration_for(machine_id) + 30
            dur = cm.new_int_var(occupied, occupied, f"d_{task.id}_{machine_id}")
            b = cm.new_bool_var(f"b_{task.id}_{machine_id}")
            cm.add(e == s + task.duration_for(machine_id))
            iv = cm.new_optional_interval_var(s, dur, occupied_end, b, f"iv_{task.id}_{machine_id}")
            intervals[key] = iv
            starts[key] = s
            ends[key] = e
            selected[key] = b

        # 每任务恰选一台机台
        cm.add_exactly_one([selected[(task.id, mid)] for mid in task.eligible_machines])
        task_start = cm.new_int_var(start, horizon, f"task_start_{task.id}")
        task_end = cm.new_int_var(start, horizon, f"task_end_{task.id}")
        task_starts[task.id] = task_start
        task_ends[task.id] = task_end
        for machine_id in task.eligible_machines:
            cm.add(task_start == starts[(task.id, machine_id)]).only_enforce_if(selected[(task.id, machine_id)])
            cm.add(task_end == ends[(task.id, machine_id)]).only_enforce_if(selected[(task.id, machine_id)])

    # 同一机台 NoOverlap（可选区间未选中不占资源）
    machine_intervals_by_id: dict[int, list] = {}
    for occupied in model.occupied_intervals:
        occupied_start = max(occupied.start_epoch_min, start)
        if occupied.end_epoch_min <= occupied_start:
            continue
        # 已有排程结束后仍需保留固定切换安全间隔，和新任务区间口径一致。
        occupied_end = min(horizon, occupied.end_epoch_min + 30)
        if occupied_end <= occupied_start:
            continue
        fixed = cm.new_fixed_size_interval_var(
            occupied_start,
            occupied_end - occupied_start,
            f"occupied_machine_{occupied.machine_id}_{occupied_start}",
        )
        machine_intervals_by_id.setdefault(occupied.machine_id, []).append(fixed)

    for machine in model.machines:
        # model.machines 保存的是 MachineIn 对象，intervals 的 key 保存的是机台 id。
        # 直接拿对象和整数比较会导致 machine_intervals 永远为空，CP-SAT 实际不会
        # 添加机台互斥约束，最终可能把不同模具的任务重叠排到同一台机台上。
        machine_id = machine.id
        machine_intervals = machine_intervals_by_id.get(machine_id, []) + [
            iv for (_tid, mid), iv in intervals.items() if mid == machine_id
        ]
        if machine_intervals:
            cm.add_no_overlap(machine_intervals)

    # 同一模具互斥（同模具任务不可并发，跨机台）
    by_mold: dict[int, list] = {}
    mold_of: dict[int, Optional[int]] = {t.id: t.mold_id for t in model.tasks}
    for occupied in model.occupied_intervals:
        if occupied.mold_id is None:
            continue
        occupied_start = max(occupied.start_epoch_min, start)
        if occupied.end_epoch_min <= occupied_start:
            continue
        occupied_end = min(horizon, occupied.end_epoch_min)
        if occupied_end <= occupied_start:
            continue
        fixed = cm.new_fixed_size_interval_var(
            occupied_start,
            occupied_end - occupied_start,
            f"occupied_mold_{occupied.mold_id}_{occupied_start}",
        )
        by_mold.setdefault(occupied.mold_id, []).append(fixed)

    for (tid, _mid), iv in intervals.items():
        mold_id = mold_of.get(tid)
        if mold_id is not None:
            by_mold.setdefault(mold_id, []).append(iv)
    for ivs in by_mold.values():
        if len(ivs) > 1:
            cm.add_no_overlap(ivs)

    # 目标：α×makespan + β×总拖期（整数化系数 100/500）
    makespan = cm.new_int_var(start, horizon, "makespan")
    cm.add_max_equality(makespan, list(task_ends.values()))

    tardiness_parts: list = []
    for task in model.tasks:
        if task.delivery_epoch_min is None:
            continue
        task_end = task_ends[task.id]
        late = cm.new_int_var(0, horizon, f"late_{task.id}")
        cm.add_max_equality(late, [task_end - task.delivery_epoch_min, cm.new_constant(0)])
        tardiness_parts.append(late)

    obj_makespan = cm.new_int_var(0, horizon * 100, "obj_makespan")
    cm.add_multiplication_equality(obj_makespan, [makespan, cm.new_constant(100)])
    obj_tardiness = cm.new_int_var(0, horizon * 500, "obj_tardiness")
    cm.add_multiplication_equality(
        obj_tardiness,
        [sum(tardiness_parts) if tardiness_parts else cm.new_constant(0), cm.new_constant(500)],
    )
    # 在相同完工/拖期结果下，P0 最紧急的任务优先开工。
    # 反向权重让数值越小的 priority 对开始时间惩罚越大。
    priority_tie_break = sum(task_starts[t.id] * (6 - int(t.priority)) for t in model.tasks)
    cm.minimize(obj_makespan + obj_tardiness + priority_tie_break)

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = time_limit_sec
    solver.parameters.num_search_workers = 8
    solver.parameters.log_search_progress = False

    status = solver.solve(cm)
    opt_time = time.time() - start_wall

    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        result: dict[int, tuple[int, int]] = {}
        for task in model.tasks:
            for machine_id in task.eligible_machines:
                if solver.value(selected[(task.id, machine_id)]) == 1:
                    result[task.id] = (machine_id, solver.value(starts[(task.id, machine_id)]))
                    break
        if len(result) == len(model.tasks):
            logger.info("CP-SAT 求解完成：%s（%.2fs）", status, opt_time)
            return result

    logger.warning("CP-SAT 超时/失败（status=%s），降级贪心启发式", status)
    return None


def solve(model: InternalModel, time_limit_sec: int = 30) -> tuple[dict[int, tuple[int, int]], list[str], float]:
    """统一入口：CP-SAT 优先，失败回退启发式。返回 (placement, algorithms, opt_time_sec)"""
    start_wall = time.time()

    placement = cp_sat_solve(model, time_limit_sec)
    algorithms: list[str] = []

    if placement is not None:
        algorithms.append("cp_sat")
    else:
        placement = heuristic_solve(model)
        algorithms.append("heuristic_greedy")

    return placement, algorithms, time.time() - start_wall
