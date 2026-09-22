import type { PoolTask, TaskSchedule, TimelineData } from '@/types/schedule'

/**
 * 后端甘特数据 ↔ 前端甘特视图适配
 */

/** 甘特行（机台/产线） */
export interface GanttRow {
  key: string
  kind: 'machine'
  id: number
  resourceType: string
  label: string
  sub: string
  /** 所属车间（中文名或键值，用于甘特行按车间分组） */
  workshop: string
  shiftMode: string
  /** 当前甘特窗口内的计划负荷（按班制折算的有效工作分钟） */
  loadPct: number
  loadStatus: 'low' | 'normal' | 'high'
  bars: GanttBar[]
}

export interface GanttBar {
  id: number
  taskId: number
  taskNo: string
  seq: number
  opType: string
  opName: string
  productCode: string
  productName: string
  orderNo: string
  machineId: number | null
  moldId: number | null
  moldCode: string
  qty: number
  /** 该工序净报工数量，用于甘特进度展示 */
  reportedQty: number
  /** 该工序按计划量计算的报工进度 */
  progressPct: number
  start: string
  end: string
  status: string
  source: string
  isLocked: boolean
  isOverlap: boolean
  isInterrupted: boolean
  isConflict: boolean
  /** APS一期：预计结束（报工滚动后晚于计划时，甘特画虚线延长段） */
  forecastEnd: string | null
  /** APS一期：实际起止（报工推断；存在时甘特画「实际」绿色细条，与计划条双线对照） */
  actualStart: string | null
  actualEnd: string | null
  /** 每条报工对应的实际生产段，段与段之间保留空白 */
  actualSegments: Array<{ start: string; end: string; qty: number }>
  /** APS一期：冻结区标记 */
  isFrozen: boolean
  note: string | null
}

/** 时间线 → 甘特行集合（机台/产线，按车间分组） */
export function toRows(timeline: TimelineData): GanttRow[] {
  const windowStart = timelineBoundary(timeline.start, false)
  const windowEnd = timelineBoundary(timeline.end, true)
  const machineRows: GanttRow[] = (timeline.machines ?? []).map((m) => ({
    key: `machine:${m.id}`,
    kind: 'machine',
    id: m.id,
    resourceType: m.type,
    label: `${m.code} ${m.name}`,
    sub: `${machineTypeLabel(m.type)} · ${m.capacity_per_hour || '-'}件/h`,
    workshop: m.workshop ?? '',
    shiftMode: m.shift_mode,
    ...loadOf(m.schedules ?? [], m.shift_mode, windowStart, windowEnd),
    bars: (m.schedules ?? []).map(toBar),
  }))

  // 前端补充后端冲突标记，口径与后端一致：
  // 同机台/产线严格重叠或切换间隔不足 30 分钟；同模具并发也算冲突。
  const switchGapMs = 30 * 60 * 1000
  const allBars = machineRows.flatMap((row) => row.bars)
  for (let i = 0; i < allBars.length; i += 1) {
    for (let j = i + 1; j < allBars.length; j += 1) {
      const a = allBars[i]
      const b = allBars[j]
      if (a.taskId === b.taskId || a.isInterrupted || b.isInterrupted) continue
      const aStart = toDate(a.start).getTime()
      const aEnd = toDate(a.end).getTime()
      const bStart = toDate(b.start).getTime()
      const bEnd = toDate(b.end).getTime()
      const sameMachine = a.machineId !== null && a.machineId === b.machineId
      const sameMold = a.moldId !== null && a.moldId > 0 && a.moldId === b.moldId
      const conflict = sameMachine
        ? aStart < bEnd + switchGapMs && bStart < aEnd + switchGapMs
        : sameMold && aStart < bEnd && bStart < aEnd
      if (conflict) {
        a.isConflict = true
        b.isConflict = true
      }
    }
  }

  // 固定工序资源顺序：注塑 → 吹塑 → 喷漆 → 效果 → 包装。
  // 按资源类型排序，不依赖种子库的自增 ID，避免不同环境中包装线跑到中间。
  const resourceOrder: Record<string, number> = {
    injection: 1,
    blow: 2,
    paint_line: 3,
    effect_line: 4,
    pack_line: 5,
  }
  machineRows.sort((a, b) => (resourceOrder[a.resourceType] ?? 99) - (resourceOrder[b.resourceType] ?? 99) || a.id - b.id)

  return machineRows
}

/** 时间线边界按本地日期处理，避免 YYYY-MM-DD 被浏览器当成 UTC 导致负荷跨天偏移。 */
function timelineBoundary(value: string, endOfDay: boolean): Date {
  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(value)
    ? `${value} ${endOfDay ? '23:59:59' : '00:00:00'}`
    : value
  return toDate(normalized)
}

/** 按车间班制计算有效工作分钟；白班只算 08:00~17:00，两班制按连续生产窗口计算。 */
function workingMinutesBetween(start: Date, end: Date, shiftMode: string): number {
  if (end.getTime() <= start.getTime()) return 0
  if (shiftMode !== 'day') return (end.getTime() - start.getTime()) / 60000

  let cursor = new Date(start)
  let total = 0
  for (let guard = 0; guard < 370 && cursor.getTime() < end.getTime(); guard += 1) {
    const dayStart = new Date(cursor)
    dayStart.setHours(0, 0, 0, 0)
    const workStart = new Date(dayStart)
    workStart.setHours(8, 0, 0, 0)
    const workEnd = new Date(dayStart)
    workEnd.setHours(17, 0, 0, 0)
    const overlapStart = Math.max(cursor.getTime(), workStart.getTime())
    const overlapEnd = Math.min(end.getTime(), workEnd.getTime())
    if (overlapEnd > overlapStart) total += (overlapEnd - overlapStart) / 60000

    cursor = new Date(dayStart)
    cursor.setDate(cursor.getDate() + 1)
  }
  return total
}

function loadOf(
  schedules: TaskSchedule[],
  shiftMode: string,
  windowStart: Date,
  windowEnd: Date,
): Pick<GanttRow, 'loadPct' | 'loadStatus'> {
  const capacityMinutes = workingMinutesBetween(windowStart, windowEnd, shiftMode)
  const plannedMinutes = schedules.reduce((total, schedule) => {
    const start = Math.max(toDate(schedule.plan_start).getTime(), windowStart.getTime())
    const end = Math.min(toDate(schedule.plan_end).getTime(), windowEnd.getTime())
    return total + workingMinutesBetween(new Date(start), new Date(end), shiftMode)
  }, 0)
  const loadPct = capacityMinutes > 0 ? Math.round((plannedMinutes / capacityMinutes) * 100) : 0
  return {
    loadPct,
    loadStatus: loadPct > 100 ? 'high' : loadPct < 60 ? 'low' : 'normal',
  }
}

/** 机台/产线类型中文名 */
export function machineTypeLabel(type: string): string {
  return ({ injection: '注塑机', blow: '吹塑机', paint_line: '喷漆线', effect_line: '效果线', pack_line: '包装线' } as Record<string, string>)[type] ?? type
}

export function toBar(s: TaskSchedule): GanttBar {
  return {
    id: s.id,
    taskId: s.task_id,
    taskNo: s.task_no ?? '',
    seq: s.seq,
    opType: s.op_type,
    opName: s.op_name ?? s.op_type,
    productCode: s.product_code ?? '',
    productName: s.product_name ?? '',
    orderNo: s.order_no ?? '',
    machineId: s.machine_id ?? null,
    moldId: s.mold_id ?? null,
    moldCode: s.mold_code ?? '',
    qty: s.qty,
    reportedQty: Math.max(0, Number(s.reported_qty ?? s.done_qty ?? 0)),
    progressPct: Math.max(0, Math.min(100, Number(s.progress_pct ?? (s.qty > 0 ? ((s.reported_qty ?? s.done_qty ?? 0) / s.qty * 100) : 0)))),
    start: s.plan_start,
    end: s.plan_end,
    // 没有完整实际起止时间的“已完成”记录只作为计划展示，避免误画成历史灰色虚线。
    status: s.status === 'done' && !(s.actual_start && s.actual_end) ? 'pending' : s.status,
    source: s.source,
    isLocked: Boolean(s.is_locked),
    isOverlap: Boolean(s.is_overlap),
    isInterrupted: Boolean(s.is_interrupted),
    isConflict: Boolean(s.is_conflict),
    forecastEnd: s.forecast_end && toDate(s.forecast_end).getTime() > toDate(s.plan_end).getTime() ? s.forecast_end : null,
    actualStart: s.actual_start ?? null,
    actualEnd: s.actual_end ?? null,
    actualSegments: s.actual_segments ?? [],
    isFrozen: Boolean(s.is_frozen),
    note: s.note ?? null,
  }
}

/** 任务池条目展示格式 */
export function poolBrief(p: PoolTask): string {
  return `${p.product_name}（${p.product_code}）× ${p.qty}${p.estimated_hours ? ' · 约' + p.estimated_hours + 'h' : ''}`
}

/** 时间字符串 → Date */
export function toDate(iso: string): Date {
  return new Date(iso.replace(' ', 'T'))
}

/** Date → 后端期望时间字符串 */
export function toIso(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`
}

/** 行是否机台 */
export function isMachineRow(row: GanttRow): boolean {
  return row.kind === 'machine'
}
