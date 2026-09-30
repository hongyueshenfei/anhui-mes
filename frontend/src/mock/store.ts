/**
 * 演示模式 · 内存 Mock 数据库（可变副本 + 业务操作）
 *
 * 所有写操作（排产保存/插单/撤销/版本回滚/报工/入库/红灯关闭）直接在
 * 内存中生效并返回与后端一致的响应结构，保证演示可玩、可刷新回滚。
 */
import dayjs from 'dayjs'
import type { Machine, Mold, Product, RouteOp } from '@/types'
import type { AutoScheduleException, Order, OrderLine, ProdTask, ProgressStage, ScheduleSuggestion, ScheduleVersion, TaskSchedule } from '@/types/schedule'
import type { RedLight } from '@/types/dashboard'
import type { DevelopStageKey, DevelopTask } from '@/types/develop'
import type { ProductionException, WorkReport } from '@/types/production'
import type { MaterialPrice, ProcessRate, Quote, QuoteEstimate, QuoteLine, QuoteRuleItem } from '@/types/quote'
import type { OrderPickListPayload, OrderRouteOpDraft, OrderRouteOpsPayload, OrderRouteOpView } from '@/types/orderEdit'
import { buildMockDb, poolResourceForOp, type MockDb } from './data'
import { calcEstimate, productMaterials, productMoldCost, productProcesses } from '@/utils/PriceCalc'

/** 与后端错误响应同构的演示错误（axios 层 reject 时保持 status/data 形态） */
export class MockApiError extends Error {
  constructor(
    public status: number,
    public code: number,
    public bizData: unknown,
    message: string,
  ) {
    super(message)
    this.name = 'MockApiError'
  }
}

/** 深拷贝（mock 数据为纯 JSON，无函数） */
export function clone<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T
}

/** 演示数据库单例 */
export const db: MockDb = buildMockDb()

let scheduleSeq = 5000
let versionSeq = db.versions.length + 1 + 10
let reportSeq = db.reports.length + 10
let stockInSeq = db.stockIns.length + 10
let redLightSeq = db.redLights.length + 10
let auditSeq = db.auditLogs.length + 10
let exceptionSeq = db.exceptions.length + 10
let quoteSeq = db.quotes.length + 10

// 工序链结构已升级，隔离旧版单工序缓存，避免历史错误数据重新覆盖干净示例。
// v11 主动隔离此前含有超长间隔/缺少历史实际报工的演示排程快照，首次加载使用新的集中排产基线。
const MOCK_SCHEDULE_STORAGE_KEY = 'hy_mock_schedule_state_v11'
const TASK_SWITCH_GAP_MIN = 30

// 保留一份启动时的完整基线。演示模式的 localStorage 可能来自旧版或异常操作，
// 恢复时不能让不完整快照把过去已完工的排程全部遮掉。
const initialScheduleBaseline = clone(db.schedules)
const initialMachineBaseline = clone(db.machines)

/** 把旧版演示缓存中的人工产线恢复到当前业务基线。 */
function migrateMockLineBaseline(): void {
  const lineDefaults = [
    { code: 'P-01', name: '喷漆线', type: 'paint_line', workshop: 'paint', workshop_id: 3, capacity_per_hour: 1500 },
    { code: 'P-02', name: '效果线', type: 'effect_line', workshop: 'effect', workshop_id: 4, capacity_per_hour: 1500 },
    { code: 'P-03', name: '包装线', type: 'pack_line', workshop: 'pack', workshop_id: 5, capacity_per_hour: 3000 },
  ] as const
  const legacyCapacityByCode: Record<string, number[]> = { 'P-01': [150, 550], 'P-02': [140, 100, 540], 'P-03': [100, 1000] }
  const baselineByCode = new Map(initialMachineBaseline.map((machine) => [machine.code, machine]))
  const lineByCode = new Map(db.machines.filter((machine) => machine.code.startsWith('P-')).map((machine) => [machine.code, machine]))

  for (const line of lineDefaults) {
    let machine = lineByCode.get(line.code)
    if (!machine) {
      const baseline = baselineByCode.get(line.code)
      if (!baseline) continue
      const nextId = Math.max(0, ...db.machines.map((item) => item.id)) + 1
      machine = { ...clone(baseline), id: nextId }
      db.machines.push(machine)
      lineByCode.set(line.code, machine)
    }
    const isLegacyCapacity = legacyCapacityByCode[line.code]?.includes(Number(machine.capacity_per_hour))
    machine.name = line.name
    machine.type = line.type
    machine.workshop = line.workshop
    machine.workshop_id = line.workshop_id
    if (isLegacyCapacity || !Number(machine.capacity_per_hour)) machine.capacity_per_hour = line.capacity_per_hour
  }

  const lineIdByOpType: Record<string, number> = {}
  for (const opType of ['paint', 'effect', 'pack']) {
    const code = opType === 'paint' ? 'P-01' : opType === 'effect' ? 'P-02' : 'P-03'
    const machine = lineByCode.get(code)
    if (machine) lineIdByOpType[opType] = machine.id
  }
  for (const schedule of db.schedules) {
    const targetId = lineIdByOpType[schedule.op_type]
    if (targetId && schedule.machine_id !== targetId) {
      schedule.machine_id = targetId
      schedule.is_overlap = 0
      schedule.is_conflict = 0
    }
  }
}

function isInFreezeWindow(start: string): boolean {
  const today = dayjs().startOf('day')
  const plannedDay = dayjs(start).startOf('day')
  return !plannedDay.isBefore(today) && plannedDay.isBefore(today.add(3, 'day'))
}

/** 产线任务按资源顺排，修正历史演示数据和旧缓存中的相交工序。 */
function normalizeLineScheduleGaps(preserveBefore?: dayjs.Dayjs): void {
  const lineIds = new Set(db.machines.filter((machine) => machine.type === 'paint_line' || machine.type === 'effect_line' || machine.type === 'pack_line').map((machine) => machine.id))
  for (const machineId of lineIds) {
    let cursor: dayjs.Dayjs | null = null
    const rows = db.schedules
      .filter((schedule) => schedule.machine_id === machineId)
      .sort((a, b) => a.plan_start.localeCompare(b.plan_start))
    for (const row of rows) {
      const start = dayjs(row.plan_start)
      const end = dayjs(row.plan_end)
      // 已暂停排程保留原计划用于甘特参考，但释放机台/产线占用，不推动后续任务顺延。
      if (row.status === 'paused') continue
      if (row.status !== 'done' && preserveBefore && start.isBefore(preserveBefore)) {
        cursor = end.add(TASK_SWITCH_GAP_MIN, 'minute')
        continue
      }
      if (row.status !== 'done' && cursor && start.isBefore(cursor)) {
        const shift = cursor.diff(start, 'minute')
        row.plan_start = start.add(shift, 'minute').format('YYYY-MM-DD HH:mm:ss')
        row.plan_end = end.add(shift, 'minute').format('YYYY-MM-DD HH:mm:ss')
        if (row.forecast_start) row.forecast_start = dayjs(row.forecast_start).add(shift, 'minute').format('YYYY-MM-DD HH:mm:ss')
        if (row.forecast_end) row.forecast_end = dayjs(row.forecast_end).add(shift, 'minute').format('YYYY-MM-DD HH:mm:ss')
      }
      const adjustedEnd = dayjs(row.plan_end)
      cursor = adjustedEnd.add(TASK_SWITCH_GAP_MIN, 'minute')
    }
  }
  const overlapIds = new Set<number>()
  for (let i = 0; i < db.schedules.length; i += 1) {
    const a = db.schedules[i]
    if (a.machine_id === null || a.status === 'paused') continue
    for (let j = i + 1; j < db.schedules.length; j += 1) {
      const b = db.schedules[j]
      if (b.machine_id !== a.machine_id || a.task_id === b.task_id || b.status === 'paused') continue
      if (dayjs(a.plan_start).isBefore(dayjs(b.plan_end)) && dayjs(b.plan_start).isBefore(dayjs(a.plan_end))) {
        overlapIds.add(a.id)
        overlapIds.add(b.id)
      }
    }
  }
  for (const schedule of db.schedules) {
    schedule.is_overlap = overlapIds.has(schedule.id) ? 1 : 0
    schedule.is_conflict = schedule.is_overlap
  }
  for (const task of db.tasks) {
    const rows = db.schedules.filter((schedule) => schedule.task_id === task.id && !schedule.is_interrupted)
    if (rows.length > 0) {
      task.plan_start = rows.map((row) => row.plan_start).sort()[0]
      task.plan_end = rows.map((row) => row.plan_end).sort().reverse()[0]
    }
  }
}

/**
 * 演示模式同步 APS 建议层：正式排程变化后重算当前任务的延期条件，
 * 不自动修改计划；条件消失时把旧 pending 建议标记为已自动失效。
 */
function refreshMockSuggestions(taskIds: number[]): void {
  const ids = Array.from(new Set(taskIds.filter((id) => Number(id) > 0)))
  let nextSuggestionId = Math.max(0, ...db.suggestions.map((item) => item.id)) + 1

  for (const taskId of ids) {
    const task = db.tasks.find((item) => item.id === taskId)
    if (!task) continue

    const rows = db.schedules
      .filter((row) => row.task_id === taskId && !row.is_interrupted)
      .sort((a, b) => a.seq - b.seq)
    const pending = db.suggestions.find((item) => item.task_id === taskId && item.type === 'postpone' && item.status === 'pending')

    if (rows.length === 0) {
      if (pending) {
        pending.status = 'expired'
        pending.reason = '系统自动失效：当前排程调整后已不再满足顺延条件'
        pending.processed_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
      }
      continue
    }

    const lastForecastEnd = rows.reduce<dayjs.Dayjs | null>((latest, row) => {
      const current = dayjs(row.forecast_end ?? row.plan_end)
      return !latest || current.isAfter(latest) ? current : latest
    }, null)
    const reasons: string[] = []
    if (task.delivery_date && lastForecastEnd && lastForecastEnd.isAfter(dayjs(task.delivery_date).endOf('day'))) {
      reasons.push(`预计完工 ${lastForecastEnd.format('YYYY-MM-DD HH:mm')} 突破交期 ${task.delivery_date}`)
    }
    if (Number(task.deviation_pct) > 10) {
      reasons.push(`进度偏差 ${task.deviation_pct}% 超阈值 10%`)
    }

    if (reasons.length === 0) {
      if (pending) {
        pending.status = 'expired'
        pending.reason = '系统自动失效：当前排程调整后已不再满足顺延条件'
        pending.processed_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
      }
      continue
    }

    const orderLine = Object.values(db.orderLines).flatMap((lines) => lines).find((line) => line.id === task.order_line_id)
    const order = orderLine ? db.orders.find((item) => item.id === orderLine.order_id) : null
    const affected = rows.map((row) => ({
      schedule_id: row.id,
      seq: row.seq,
      op_name: row.op_name ?? row.op_type,
      plan_end: row.plan_end,
      forecast_end: row.forecast_end ?? null,
    }))
    const delayedOrders = [{
      order_id: order?.id ?? null,
      order_no: order?.order_no ?? null,
      task_no: task.task_no,
      product_code: task.product?.code ?? null,
      product_name: task.product?.name ?? null,
      delivery_date: task.delivery_date,
      forecast_end: lastForecastEnd?.format('YYYY-MM-DD HH:mm:ss') ?? null,
    }]

    if (pending) {
      pending.trigger_report_id = null
      pending.affected = affected
      pending.delayed_orders = delayedOrders
      pending.reason = reasons.join('；')
      pending.processed_at = null
    } else {
      const suggestion: ScheduleSuggestion = {
        id: nextSuggestionId++,
        type: 'postpone',
        trigger_report_id: null,
        task_id: task.id,
        affected,
        delayed_orders: delayedOrders,
        reason: reasons.join('；'),
        status: 'pending',
        created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
        task: { id: task.id, task_no: task.task_no, delivery_date: task.delivery_date },
      }
      db.suggestions.push(suggestion)
    }
  }
}

/** 全量刷新演示模式的 APS 建议层。 */
export function refreshAllMockSuggestions(): { task_count: number } {
  const taskIds = db.tasks
    .filter((task) => db.schedules.some((row) => row.task_id === task.id && !row.is_interrupted)
      || db.suggestions.some((item) => item.task_id === task.id && item.status === 'pending'))
    .map((task) => task.id)
  refreshMockSuggestions(taskIds)
  persistMockScheduleState()
  return { task_count: taskIds.length }
}

/** 刷新演示排程的冻结标识；报工实际时间允许按填报内容回溯或预录。 */
function refreshMockScheduleFlags(): void {
  for (const schedule of db.schedules) {
    schedule.is_frozen = schedule.status === 'pending' && isInFreezeWindow(schedule.plan_start) ? 1 : 0
  }
  // 实际数据已被清理的“已完成”任务不能继续显示历史灰色计划，回退为待执行蓝色计划。
  for (const task of db.tasks) {
    if (task.status !== 'done') continue
    const rows = db.schedules.filter((schedule) => schedule.task_id === task.id && !schedule.is_interrupted)
    const hasActualCompletion = rows.length > 0 && rows.every((schedule) => schedule.actual_start && schedule.actual_end)
    if (hasActualCompletion) continue
    task.status = 'scheduled'
    task.done_qty = 0
    for (const row of rows) {
      row.status = 'pending'
      row.done_qty = 0
    }
  }
}

/** 演示模式持久化排产结果，避免接受后刷新又恢复成初始示例数据。 */
function persistMockScheduleState(): void {
  if (typeof localStorage === 'undefined') return
  refreshMockScheduleFlags()
  localStorage.setItem(MOCK_SCHEDULE_STORAGE_KEY, JSON.stringify({
    machines: db.machines,
    schedules: db.schedules,
    tasks: db.tasks.map((task) => ({
      id: task.id,
      plan_start: task.plan_start,
      plan_end: task.plan_end,
      status: task.status,
      is_locked: task.is_locked,
      deviation_pct: task.deviation_pct,
    })),
    orders: db.orders.map((order) => ({
      id: order.id,
      status: order.status,
      started_at: order.started_at ?? null,
      completed_at: order.completed_at ?? null,
    })),
    pool: db.pool,
    versions: db.versions,
  }))
}

function restoreMockScheduleState(): void {
  if (typeof localStorage === 'undefined') return
  try {
    const raw = localStorage.getItem(MOCK_SCHEDULE_STORAGE_KEY)
    if (!raw) return
    const saved = JSON.parse(raw) as {
      machines?: MockDb['machines']
      schedules?: TaskSchedule[]
      tasks?: Array<Partial<ProdTask> & { id: number }>
      orders?: Array<Pick<Order, 'id' | 'status'> & { started_at?: string | null; completed_at?: string | null }>
      pool?: MockDb['pool']
      versions?: ScheduleVersion[]
    }
    // 空排程快照不能覆盖内置历史数据：这是异常保存/旧版本缓存的典型表现。
    // 直接作废该快照，让本次启动回到完整的演示排程基线。
    if (Array.isArray(saved.schedules) && saved.schedules.length === 0) {
      localStorage.removeItem(MOCK_SCHEDULE_STORAGE_KEY)
      return
    }
    if (Array.isArray(saved.schedules)) {
      db.schedules = saved.schedules
      // 仅保存了未来计划的旧快照会造成“含过去7天”整屏为空；补回基线中缺失的历史工序。
      const existingKeys = new Set(db.schedules.map((schedule) => `${schedule.task_id}:${schedule.seq}`))
      let nextId = Math.max(0, ...db.schedules.map((schedule) => schedule.id), ...initialScheduleBaseline.map((schedule) => schedule.id)) + 1
      for (const baseline of initialScheduleBaseline) {
        if (!dayjs(baseline.plan_start).isBefore(dayjs().startOf('day'))) continue
        const key = `${baseline.task_id}:${baseline.seq}`
        if (existingKeys.has(key)) continue
        db.schedules.push({ ...clone(baseline), id: nextId })
        existingKeys.add(key)
        nextId += 1
      }
    }
    if (Array.isArray(saved.machines)) db.machines = saved.machines
    if (Array.isArray(saved.machines) || Array.isArray(saved.schedules)) migrateMockLineBaseline()
    normalizeLineScheduleGaps()
    if (Array.isArray(saved.tasks)) {
      for (const savedTask of saved.tasks) {
        const task = db.tasks.find((item) => item.id === savedTask.id)
        if (task) Object.assign(task, savedTask)
      }
    }
    if (Array.isArray(saved.orders)) {
      for (const savedOrder of saved.orders) {
        const order = db.orders.find((item) => item.id === savedOrder.id)
        if (order) Object.assign(order, savedOrder)
      }
    }
    if (Array.isArray(saved.pool)) db.pool = saved.pool
    if (Array.isArray(saved.versions)) db.versions = saved.versions
    // 兼容上一版已保存的排程：历史/在产任务必须继续有对应实际条。
    for (const schedule of db.schedules) {
      const task = db.tasks.find((item) => item.id === schedule.task_id)
      if (!task || schedule.actual_start) continue
      if (task.status === 'in_progress' || task.status === 'done') schedule.actual_start = task.actual_start ?? schedule.plan_start
      if (task.status === 'done' && !schedule.actual_end) schedule.actual_end = task.actual_end ?? schedule.plan_end
    }
    // 清理旧版本残留的冲突颜色：只有同一机台不同任务真实相交才标红。
    const overlapIds = new Set<number>()
    for (let i = 0; i < db.schedules.length; i += 1) {
      const a = db.schedules[i]
      if (a.machine_id === null || a.status === 'paused') continue
      for (let j = i + 1; j < db.schedules.length; j += 1) {
        const b = db.schedules[j]
        if (b.machine_id !== a.machine_id || a.task_id === b.task_id || b.status === 'paused') continue
        if (dayjs(a.plan_start).isBefore(dayjs(b.plan_end)) && dayjs(b.plan_start).isBefore(dayjs(a.plan_end))) {
          overlapIds.add(a.id)
          overlapIds.add(b.id)
        }
      }
    }
    for (const schedule of db.schedules) {
      schedule.is_overlap = overlapIds.has(schedule.id) ? 1 : 0
      schedule.is_conflict = schedule.is_overlap
    }
    if (Array.isArray(saved.machines) || Array.isArray(saved.schedules)) {
      refreshMockScheduleFlags()
      const recalculatedTypes = new Set<'paint_line' | 'effect_line' | 'pack_line'>()
      for (const machine of db.machines) {
        if (machine.type === 'paint_line' || machine.type === 'effect_line' || machine.type === 'pack_line') {
          recalculatedTypes.add(machine.type)
        }
      }
      for (const type of recalculatedTypes) {
        const machine = db.machines.find((item) => item.type === type)
        if (machine) recalculateSchedulesForMachine(machine.id)
      }
    }
    scheduleSeq = Math.max(scheduleSeq, ...db.schedules.map((item) => item.id))
    versionSeq = Math.max(versionSeq, ...db.versions.map((item) => item.id))
  } catch {
    localStorage.removeItem(MOCK_SCHEDULE_STORAGE_KEY)
  }
}

normalizeLineScheduleGaps()
restoreMockScheduleState()
refreshMockScheduleFlags()
// 旧快照可能只保存了计划时间，不能覆盖报工台账推导出的工序完工量/状态。
// 启动后再次按当前报工和入库流水回算，保证甘特、报工、入库三页读到同一份关系。
for (const task of db.tasks) syncRuntimeTask(task)

// ==================== 通用 ====================

/** 计算排程结束时间：成型固定计入 30 分钟换模，合计时长预留 5%；两班制全天 24h，day 班 08:00-17:00 */
export function calcEnd(startIso: string, capacityPerHour: number, qty: number, moldChangeMin: number, shiftMode: 'two_shift' | 'day' = 'two_shift'): string {
  const workHoursPerDay = shiftMode === 'day' ? 9 : 24
  const changeHours = moldChangeMin > 0 ? 0.5 : 0
  const totalHours = (qty / capacityPerHour + changeHours) * 1.05
  let date = dayjs(startIso)
  let remaining = totalHours
  // 简化模型：day 班只统计工作窗口；两班制连续生产
  if (shiftMode === 'day') {
    while (remaining > 0) {
      const dayStart = date.hour(8).minute(0).second(0)
      const dayEnd = date.hour(17).minute(0).second(0)
      if (date.isBefore(dayStart)) {
        date = dayStart
        continue
      }
      const avail = dayEnd.diff(date, 'hour', true)
      if (avail <= 0) {
        date = date.add(1, 'day').hour(8).minute(0).second(0)
        continue
      }
      const used = Math.min(avail, remaining)
      remaining -= used
      date = date.add(used, 'hour')
      if (remaining > 0) date = dayStart.add(1, 'day')
    }
  } else {
    date = date.add(remaining, 'hour')
  }
  return date.format('YYYY-MM-DD HH:mm:ss')
}

function normalizeScheduleStart(start: dayjs.Dayjs, shiftMode: 'two_shift' | 'day'): dayjs.Dayjs {
  if (shiftMode !== 'day') return start
  if (start.hour() < 8) return start.hour(8).minute(0).second(0)
  if (start.hour() >= 17) return start.add(1, 'day').hour(8).minute(0).second(0)
  return start
}

function recalculateTaskChainInMemory(taskId: number, preserveBefore?: dayjs.Dayjs): boolean {
  const rows = db.schedules
    .filter((schedule) => schedule.task_id === taskId && !schedule.is_interrupted)
    .sort((a, b) => a.seq - b.seq)
  let cursor: dayjs.Dayjs | null = null
  let changed = false

  for (const row of rows) {
    const machine = row.machine_id === null ? undefined : db.machines.find((item) => item.id === row.machine_id)
    if (!machine) continue
    if (row.status === 'paused') continue
    if (row.status === 'done' || row.actual_end) {
      cursor = dayjs(row.plan_end)
      continue
    }
    if (preserveBefore && dayjs(row.plan_start).isBefore(preserveBefore)) {
      cursor = dayjs(row.plan_end)
      continue
    }

    const oldStart = row.plan_start
    const oldEnd = row.plan_end
    const requestedStart = cursor ?? dayjs(row.plan_start)
    const start = normalizeScheduleStart(requestedStart, machine.shift_mode)
    const changeover = Number(row.changeover_minutes ?? ((machine.type === 'injection' || machine.type === 'blow') ? machine.mold_change_min : 0))
    const end = dayjs(calcEnd(
      start.format('YYYY-MM-DD HH:mm:ss'),
      machine.capacity_per_hour,
      row.qty,
      changeover,
      machine.shift_mode,
    ))
    row.plan_start = start.format('YYYY-MM-DD HH:mm:ss')
    row.plan_end = end.format('YYYY-MM-DD HH:mm:ss')
    if (row.forecast_start === oldStart) row.forecast_start = row.plan_start
    if (row.forecast_end === oldEnd) row.forecast_end = row.plan_end
    changed = changed || oldStart !== row.plan_start || oldEnd !== row.plan_end
    cursor = end
  }

  const task = db.tasks.find((item) => item.id === taskId)
  if (task && rows.length > 0) {
    task.plan_start = rows.map((row) => row.plan_start).sort()[0] ?? task.plan_start
    task.plan_end = rows.map((row) => row.plan_end).sort().reverse()[0] ?? task.plan_end
  }
  return changed
}

/** 产能修改后同步重算所有引用该资源的未完工工单，并持久化到演示缓存。 */
export function recalculateSchedulesForMachine(machineId: number): { task_count: number; changed_count: number } {
  const machine = db.machines.find((item) => item.id === machineId)
  if (!machine) return { task_count: 0, changed_count: 0 }

  // 产线资源发生变化后，所有经过该类产线的工单都要参与重算；
  // 仅重算当前机台会漏掉同产线上的其他工单被顺延后的工序链。
  const resourceIds = machine.type === 'paint_line' || machine.type === 'effect_line' || machine.type === 'pack_line'
    ? new Set(db.machines
      .filter((item) => item.type === machine.type)
      .map((item) => item.id))
    : new Set([machineId])
  const taskIds = Array.from(new Set(db.schedules
    .filter((schedule) => schedule.machine_id !== null && resourceIds.has(schedule.machine_id) && !schedule.is_interrupted && schedule.status !== 'paused')
    .map((schedule) => schedule.task_id)))
    .filter((taskId) => db.tasks.some((task) => task.id === taskId && task.status !== 'done'))
  let changedCount = 0
  const effectiveFrom = dayjs().startOf('day')
  for (const taskId of taskIds) {
    if (recalculateTaskChainInMemory(taskId, effectiveFrom)) changedCount += 1
  }
  normalizeLineScheduleGaps(effectiveFrom)
  // 资源避让可能移动了后续产线工序，再沿工序链同步一次，避免任务链被拆开。
  for (const taskId of taskIds) recalculateTaskChainInMemory(taskId, effectiveFrom)
  normalizeLineScheduleGaps(effectiveFrom)
  persistMockScheduleState()
  return { task_count: taskIds.length, changed_count: changedCount }
}

/** 甘特图“重算排程”：按最新产能重算全部已排但未开工任务，不生成算法建议。 */
export function recalculateAllSchedules(): { task_count: number; changed_count: number } {
  const taskIds = Array.from(new Set(db.schedules
    .filter((schedule) => schedule.machine_id !== null && !schedule.is_interrupted && schedule.status !== 'paused')
    .map((schedule) => schedule.task_id)))
    .filter((taskId) => db.tasks.some((task) => task.id === taskId && task.status !== 'done'))
  const before = new Map(db.schedules.map((schedule) => [schedule.id, `${schedule.plan_start}|${schedule.plan_end}`]))
  const effectiveFrom = dayjs().startOf('day')

  for (const taskId of taskIds) recalculateTaskChainInMemory(taskId, effectiveFrom)
  normalizeLineScheduleGaps(effectiveFrom)
  // 资源避让可能移动了后续产线工序，再沿工序链同步一次，保持链路连续。
  for (const taskId of taskIds) recalculateTaskChainInMemory(taskId, effectiveFrom)
  normalizeLineScheduleGaps(effectiveFrom)

  const changedTaskIds = new Set<number>()
  let changedCount = 0
  for (const schedule of db.schedules) {
    const previous = before.get(schedule.id)
    const current = `${schedule.plan_start}|${schedule.plan_end}`
    if (previous !== undefined && previous !== current) {
      changedCount += 1
      changedTaskIds.add(schedule.task_id)
    }
  }
  refreshMockSuggestions(Array.from(changedTaskIds))
  persistMockScheduleState()
  return { task_count: changedTaskIds.size, changed_count: changedCount }
}

function genOrderNoSeq(): string {
  return `SO${dayjs().format('YYYYMMDD')}-${String(900 + Math.floor(Math.random() * 90))}`
}

function pushAudit(module: string, action: string, targetType: string, targetId: number | null, detail: Record<string, unknown>): void {
  auditSeq += 1
  db.auditLogs.unshift({
    id: auditSeq,
    user_id: 1,
    user: { id: 1, employee_no: 'admin', name: '系统管理员' },
    module,
    action,
    target_type: targetType,
    target_id: targetId,
    detail,
    ip: '127.0.0.1',
    created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
  })
}

/** 分页包装（与后端 Paginator 结构对齐） */
export function paginate<T>(items: T[], params: Record<string, unknown>): { current_page: number; data: T[]; total: number; per_page: number; last_page: number; from: number | null; to: number | null; links: unknown[]; first_page_url: string | null; last_page_url: string | null; next_page_url: string | null; prev_page_url: string | null; path: string } {
  const perPage = Math.min(Number(params.per_page ?? 15) || 15, 100)
  const page = Math.max(Number(params.page ?? 1) || 1, 1)
  const total = items.length
  const start = (page - 1) * perPage
  const slice = items.slice(start, start + perPage)
  return {
    current_page: page,
    data: clone(slice),
    first_page_url: null,
    from: total === 0 ? null : start + 1,
    last_page: Math.max(1, Math.ceil(total / perPage)),
    last_page_url: null,
    links: [],
    next_page_url: page < Math.ceil(total / perPage) ? `/api?page=${page + 1}` : null,
    path: '/api',
    per_page: perPage,
    prev_page_url: page > 1 ? `/api?page=${page - 1}` : null,
    to: total === 0 ? null : start + slice.length,
    total,
  }
}

// ==================== 排产操作 ====================

export interface DropItem {
  task_id: number
  machine_id: number
  plan_start: string
  mold_id?: number | null
}

/** 资源重叠检测（同一机台或同一模具时间交叉） */
function checkOverlap(item: DropItem, excludeScheduleId?: number): Array<{ task_id_a: number; task_no_a: string; start_a: string; end_a: string; task_id_b: number; task_no_b: string; start_b: string; end_b: string; message: string; resource: string }> {
  const task = db.tasks.find((t) => t.id === item.task_id)!
  const errors: Array<{ task_id_a: number; task_no_a: string; start_a: string; end_a: string; task_id_b: number; task_no_b: string; start_b: string; end_b: string; message: string; resource: string }> = []
  const candidates = buildScheduleChain(task, item)

  for (const candidate of candidates) {
    for (const s of db.schedules) {
      if (s.id === excludeScheduleId || s.task_id === item.task_id || s.machine_id !== candidate.machine_id || s.status === 'paused') continue
      const sStart = dayjs(s.plan_start)
      const sEnd = dayjs(s.plan_end)
      const gap = TASK_SWITCH_GAP_MIN
      const conflict = dayjs(candidate.plan_start).isBefore(sEnd) && sStart.isBefore(dayjs(candidate.plan_end).add(gap, 'minute'))
      if (conflict) {
        const machine = db.machines.find((m) => m.id === candidate.machine_id)
        errors.push({
          task_id_a: item.task_id,
          task_no_a: task.task_no,
          start_a: candidate.plan_start,
          end_a: candidate.plan_end,
          task_id_b: s.task_id,
          task_no_b: s.task_no ?? '',
          start_b: s.plan_start,
          end_b: s.plan_end,
          message: dayjs(candidate.plan_start).isBefore(sEnd) ? `机台 ${machine?.code ?? candidate.machine_id} 时间重叠` : `机台 ${machine?.code ?? candidate.machine_id} 切换间隔不足30分钟`,
          resource: `machine:${candidate.machine_id}`,
        })
      }
    }
  }
  // 模具占用检查
  const moldId = item.mold_id ?? task.mold_id
  if (moldId) {
    for (const candidate of candidates.filter((row) => row.mold_id === moldId)) {
      for (const s of db.schedules) {
        if (s.id === excludeScheduleId || s.task_id === item.task_id || s.mold_id !== moldId || s.status === 'paused') continue
        const sStart = dayjs(s.plan_start)
        const sEnd = dayjs(s.plan_end)
        if (dayjs(candidate.plan_start).isBefore(sEnd) && sStart.isBefore(dayjs(candidate.plan_end))) {
        const mold = db.molds.find((m) => m.id === moldId)
        errors.push({
          task_id_a: item.task_id,
          task_no_a: task.task_no,
          start_a: candidate.plan_start,
          end_a: candidate.plan_end,
          task_id_b: s.task_id,
          task_no_b: s.task_no ?? '',
          start_b: s.plan_start,
          end_b: s.plan_end,
          message: `模具 ${mold?.code ?? moldId} 占用中`,
          resource: `mold:${moldId}`,
        })
      }
      }
    }
  }
  return errors
}

/** 按工艺路线生成完整排程链；拖拽的机台只决定首道成型工序。 */
function buildScheduleChain(task: ProdTask, item: DropItem, force = false, markConflict = force): TaskSchedule[] {
  const firstMachine = db.machines.find((m) => m.id === item.machine_id)
  if (!firstMachine) return []
  const ops = (task.route_id ? db.routeOps[task.route_id] : undefined) ?? []
  const routeOps = ops.length > 0 ? ops.slice().sort((a, b) => a.seq - b.seq) : [{ seq: 1, op_type: firstMachine.type === 'blow' ? 'molding_blow' : 'molding_injection', op_name: firstMachine.type === 'blow' ? '吹塑成型' : '注塑成型', mold_change_min: 30 } as RouteOp]
  const mold = task.mold_id ? db.molds.find((m) => m.id === task.mold_id) : undefined
  const order = Object.values(db.orderLines).flatMap((lines) => lines).find((line) => line.id === task.order_line_id)
  const orderNo = order ? db.orders.find((o) => o.id === order.order_id)?.order_no ?? '' : ''
  let cursor = dayjs(item.plan_start)
  const rows: TaskSchedule[] = []
  for (const op of routeOps) {
    const isMolding = op.op_type === 'molding_injection' || op.op_type === 'molding_blow'
    const machine = isMolding
      ? (op.op_type === 'molding_blow' ? db.machines.find((m) => m.type === 'blow' && m.status === 'running') : firstMachine)
      : db.machines.find((m) => op.op_type === 'paint' ? m.id === 17 : op.op_type === 'effect' ? m.id === 18 : op.op_type === 'pack' ? m.id === 19 : false)
    if (!machine) continue
    const start = cursor.format('YYYY-MM-DD HH:mm:ss')
    const end = calcEnd(start, machine.capacity_per_hour, task.qty, isMolding ? 30 : 0, machine.shift_mode)
    const done = task.status === 'done' ? task.qty : (op.seq === 1 ? task.done_qty : 0)
    const completed = task.status === 'done' || (op.seq === 1 && task.actual_end)
    scheduleSeq += 1
    rows.push({
      id: scheduleSeq, task_id: task.id, task_no: task.task_no, seq: op.seq, route_op_id: op.id ?? op.seq,
      op_type: op.op_type, op_name: op.op_name, machine_id: machine.id, mold_id: isMolding ? (mold?.id ?? task.mold_id ?? null) : null,
      plan_start: start, plan_end: end, qty: task.qty, done_qty: done, status: completed ? 'done' : (op.seq === 1 && task.status === 'in_progress' ? 'running' : 'pending'),
      source: 'manual', is_locked: 1, is_overlap: markConflict ? 1 : 0, is_interrupted: 0, is_conflict: markConflict ? 1 : 0,
      forecast_start: start, forecast_end: end,
      actual_start: completed ? (task.actual_start ?? start) : (op.seq === 1 && task.status === 'in_progress' ? task.actual_start ?? start : null),
      actual_end: completed ? (task.actual_end ?? end) : null,
      is_frozen: !completed && isInFreezeWindow(start) ? 1 : 0, changeover_minutes: isMolding ? 30 : 0,
      product_code: task.product?.code, product_name: task.product?.name, order_no: orderNo,
      mold_code: isMolding ? (mold?.code ?? null) : null, note: force ? '强制排入（已显式确认）' : null,
    })
    cursor = dayjs(end)
  }
  return rows
}

/** 手工排程保存（保存即锁定；冲突抛 409） */
export function manualSave(items: DropItem[], force = false, note?: string, markConflict = force): { version: ScheduleVersion; tasks: ProdTask[]; conflicts: unknown[] } {
  const conflicts: unknown[] = []
  if (!force) {
    for (const item of items) {
      conflicts.push(...checkOverlap(item))
    }
    if (conflicts.length > 0) {
      throw new MockApiError(409, 409, { errors: conflicts }, '排程冲突：机台/模具时间重叠')
    }
  }
  const saved: ProdTask[] = []
  for (const item of items) {
    const task = db.tasks.find((t) => t.id === item.task_id)
    if (!task) continue
    // 一个任务可能已有多段工序；重新排程时必须整条替换，不能只删 seq=1。
    db.schedules = db.schedules.filter((s) => s.task_id !== task.id)
    const schedules = buildScheduleChain(task, item, force, markConflict)
    db.schedules.push(...schedules)
    const schedule = schedules[0]
    if (!schedule) continue
    task.plan_start = schedules.map((row) => row.plan_start).sort()[0]
    task.plan_end = schedules.map((row) => row.plan_end).sort().reverse()[0]
    task.status = 'scheduled'
    task.is_locked = 1
    // 任务池移除
    db.pool = db.pool.filter((p) => p.id !== task.id)
    saved.push(clone(task))
  }
  // 版本快照
  versionSeq += 1
  const version: ScheduleVersion = {
    id: versionSeq,
    version_no: versionSeq - 10,
    type: 'manual',
    status: 'applied',
    snapshot: {
      before: { schedules: [], tasks: {} },
      after: { schedules: clone(db.schedules), tasks: {} },
    },
    affected_orders: [],
    biz_key: `manual-${versionSeq}`,
    created_by: 1,
    created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
    applied_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
    rolled_back_at: null,
    creator: { id: 1, name: '系统管理员', employee_no: 'admin' },
  }
  db.versions.unshift(version)
  if (note) {
    pushAudit('scheduling', 'schedule_manual_save', 'task', items[0]?.task_id ?? null, { note })
  }
  refreshMockSuggestions(saved.map((task) => task.id))
  persistMockScheduleState()
  return { version, tasks: saved, conflicts }
}

/** 单道工序边界调整：左右手柄只改变当前排程行，其他工序保持原计划。 */
export function operationAdjust(item: {
  schedule_id: number
  machine_id: number
  plan_start: string
  plan_end?: string
  edge?: 'start' | 'end'
  note?: string
}): { version: ScheduleVersion; task: ProdTask; schedule: TaskSchedule; conflicts: unknown[] } {
  const schedule = db.schedules.find((row) => row.id === item.schedule_id)
  const task = schedule ? db.tasks.find((row) => row.id === schedule.task_id) : undefined
  if (!schedule || !task) throw new MockApiError(404, 404, { errors: [] }, '工序排程不存在，无法调整')

  const start = item.edge === 'end' ? dayjs(schedule.plan_start) : dayjs(item.plan_start)
  const end = item.edge === 'start'
    ? dayjs(schedule.plan_end)
    : dayjs(item.plan_end ?? calcEnd(item.plan_start, db.machines.find((machine) => machine.id === item.machine_id)?.capacity_per_hour ?? 1000, schedule.qty, schedule.changeover_minutes ?? 0, 'day'))
  if (!start.isBefore(end)) {
    throw new MockApiError(422, 422, { errors: [{ code: 'invalid_time_range', message: '调整后的工序结束时间必须晚于开始时间，当前工序未移动' }] }, '当前工序结束时间必须晚于开始时间')
  }

  const beforeSchedules = clone(db.schedules)
  schedule.machine_id = item.machine_id
  schedule.plan_start = start.format('YYYY-MM-DD HH:mm:ss')
  schedule.plan_end = end.format('YYYY-MM-DD HH:mm:ss')
  schedule.forecast_start = schedule.plan_start
  schedule.forecast_end = schedule.plan_end
  schedule.source = 'manual'
  schedule.is_overlap = 0
  schedule.is_conflict = 0
  schedule.is_frozen = 0
  schedule.is_locked = 1
  if (item.note) schedule.note = item.note

  const ownSchedules = db.schedules.filter((row) => row.task_id === task.id && !row.is_interrupted)
  task.plan_start = ownSchedules.map((row) => row.plan_start).sort()[0] ?? task.plan_start
  task.plan_end = ownSchedules.map((row) => row.plan_end).sort().reverse()[0] ?? task.plan_end
  task.status = 'scheduled'
  task.is_locked = 1

  versionSeq += 1
  const version: ScheduleVersion = {
    id: versionSeq,
    version_no: versionSeq - 10,
    type: 'manual',
    status: 'applied',
    snapshot: { before: { schedules: beforeSchedules, tasks: {} }, after: { schedules: clone(db.schedules), tasks: {} } },
    affected_orders: [],
    biz_key: `manual-operation-${schedule.id}-${versionSeq}`,
    created_by: 1,
    created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
    applied_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
    rolled_back_at: null,
    creator: { id: 1, name: '系统管理员', employee_no: 'admin' },
  }
  db.versions.unshift(version)
  pushAudit('scheduling', 'schedule_operation_adjust', 'schedule', schedule.id, { note: item.note, edge: item.edge ?? null })
  refreshMockSuggestions([task.id])
  persistMockScheduleState()
  return { version, task: clone(task), schedule: clone(schedule), conflicts: [] }
}

/** 插单预览（纯计算） */
export function insertPreview(item: DropItem, strategy: 'wait' | 'interrupt' = 'wait'): unknown {
  const conflicts = checkOverlap(item)
  const task = db.tasks.find((t) => t.id === item.task_id)
  const machine = db.machines.find((m) => m.id === item.machine_id)
  if (!task || !machine) throw new MockApiError(404, 404, null, '任务或机台不存在')
  const endIso = calcEnd(item.plan_start, machine.capacity_per_hour, task.qty, machine.mold_change_min, machine.shift_mode)

  const changed: Array<{ task_id: number; task_no: string; old_end: string | null; new_end: string | null; delay_hours: number; reason: string }> = []
  const affectedOrders = new Map<number, { order_id: number; order_no: string; customer_name: string; delay_hours: number; task_count: number; tasks: unknown[] }>()
  let interrupted: null | { task_id: number; task_no: string; done_qty: number; remaining_qty: number } = null

  if (strategy === 'interrupt' && conflicts.length > 0) {
    const first = conflicts[0] as { task_id_b: number; task_no_b: string }
    const hitTask = db.tasks.find((t) => t.id === first.task_id_b)
    if (hitTask) {
      interrupted = { task_id: hitTask.id, task_no: hitTask.task_no, done_qty: hitTask.done_qty, remaining_qty: hitTask.qty - hitTask.done_qty }
    }
  }

  for (const c of checkOverlap(item)) {
    const t = db.tasks.find((x) => x.id === (c as { task_id_b: number }).task_id_b)
    if (!t) continue
    const oldEnd = t.plan_end
    const newEnd = oldEnd ? dayjs(oldEnd).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : null
    changed.push({ task_id: t.id, task_no: t.task_no, old_end: oldEnd, new_end: newEnd, delay_hours: 8, reason: `插单 ${task.task_no} 顺延` })
    const orderId = db.orderLines ? Number(String(t.order_line_id).slice(0, -1)) || 0 : 0
    void orderId
  }

  return {
    task: { id: task.id, task_no: task.task_no, product_code: task.product?.code, product_name: task.product?.name, qty: task.qty, priority: task.priority },
    strategy,
    insert_chain: [],
    interrupted,
    changed_tasks: changed,
    affected_orders: Array.from(affectedOrders.values()),
    plan_end: endIso,
    conflicts_count: conflicts.length,
  }
}

/** 插单确认（顺延受影响任务 + 新排程） */
export function insertConfirm(item: DropItem, strategy: 'wait' | 'interrupt' = 'wait'): { version: ScheduleVersion; task: ProdTask; changed_tasks: unknown[]; affected_orders: unknown[] } {
  const preview = insertPreview(item, strategy) as { changed_tasks: Array<{ task_id: number; delay_hours: number }>; affected_orders: unknown[] }
  const result = manualSave([item], false, `插单确认(${strategy})`)
  for (const c of preview.changed_tasks) {
    const t = db.tasks.find((x) => x.id === c.task_id)
    if (t && t.plan_end) {
      t.plan_end = dayjs(t.plan_end).add(c.delay_hours, 'hour').format('YYYY-MM-DD HH:mm:ss')
      t.deviation_pct = Math.max(t.deviation_pct, 10)
      const s = db.schedules.find((x) => x.task_id === t.id)
      if (s) s.plan_end = t.plan_end
    }
  }
  refreshMockSuggestions(Array.from(new Set([item.task_id, ...preview.changed_tasks.map((item) => item.task_id)])))
  const v = result.version
  v.type = 'insert'
  v.affected_orders = preview.affected_orders.length > 0 ? (preview.affected_orders as never) : []
  const task = db.tasks.find((x) => x.id === item.task_id)!
  return { version: v, task: clone(task), changed_tasks: preview.changed_tasks, affected_orders: preview.affected_orders }
}

/** APS顺延建议-采纳（演示语义）：受影响任务未完工排程计划对齐预计 + 留痕 */
export function acceptSuggestion(id: number): { task_id: number; version_id: number; aligned: number } {
  const s = db.suggestions.find((x) => x.id === id)
  if (!s) throw new MockApiError(404, 404, null, '建议不存在')
  if (s.status !== 'pending') throw new MockApiError(422, 422, null, '建议已处理，不能重复采纳')
  let aligned = 0
  for (const row of db.schedules.filter((x) => x.task_id === s.task_id && x.status !== 'done' && x.status !== 'paused' && !x.is_interrupted)) {
    if (row.forecast_start) row.plan_start = row.forecast_start
    if (row.forecast_end) row.plan_end = row.forecast_end
    aligned++
  }
  const task = db.tasks.find((t) => t.id === s.task_id)
  if (task) {
    const rows = db.schedules.filter((x) => x.task_id === task.id && !x.is_interrupted)
    if (rows.length) {
      task.plan_start = [...rows.map((r) => r.plan_start)].sort()[0] ?? task.plan_start
      task.plan_end = [...rows.map((r) => r.plan_end)].sort().reverse()[0] ?? task.plan_end
    }
  }
  s.status = 'accepted'
  s.processed_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
  pushAudit('scheduling', 'suggestion_accept', 'schedule_suggestion', id, { task_id: s.task_id })
  persistMockScheduleState()
  return { task_id: s.task_id ?? 0, version_id: db.versions.length + 100, aligned }
}

/** APS顺延建议-驳回（留痕，不改排程） */
export function dismissSuggestion(id: number): { id: number } {
  const s = db.suggestions.find((x) => x.id === id)
  if (!s) throw new MockApiError(404, 404, null, '建议不存在')
  if (s.status !== 'pending') throw new MockApiError(422, 422, null, '建议已处理')
  s.status = 'dismissed'
  s.processed_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
  pushAudit('scheduling', 'suggestion_dismiss', 'schedule_suggestion', id, { task_id: s.task_id })
  return { id }
}

/** 自动排产（演示：将任务池和现有冲突任务一起做资源避让顺排） */
export function autoSolve(taskIds: number[]): { job_id: string | null; task_count: number; machine_count: number; message: string; exceptions: AutoScheduleException[]; status?: string } {
  // 右上角是整版自动排：处理任务池中的待排任务，也处理已经排过但尚未开工、
  // 且不在未来三天冻结窗口内的任务。这样产能重算缩短工期后，后续任务可以重新压缩空挡。
  const now = dayjs()
  const freezeUntil = now.add(3, 'day')
  const autoCandidates = db.tasks.filter((task) => {
    if (task.status !== 'unscheduled' && task.status !== 'scheduled') return false
    const rows = db.schedules.filter((schedule) => schedule.task_id === task.id && !schedule.is_interrupted)
    const hasActual = rows.some((row) => row.status === 'running' || row.status === 'done' || row.actual_start || row.actual_end)
      || db.reports.some((report) => report.task_id === task.id)
    if (hasActual) return false
    if (task.status === 'unscheduled') return !Boolean(task.is_locked)
    const frozen = rows.some((row) => dayjs(row.plan_start).isAfter(now) && dayjs(row.plan_start).isBefore(freezeUntil))
    return !Boolean(task.is_locked) || !frozen
  })
  const requestedIds = taskIds.length > 0 ? Array.from(new Set([...taskIds, ...autoCandidates.map((task) => task.id)])) : autoCandidates.map((task) => task.id)
  const ids = requestedIds.filter((id) => autoCandidates.some((task) => task.id === id))
  const machines = db.machines.filter((m) => (m.type === 'injection' || m.type === 'blow') && m.status === 'running')
  const assignments: Array<{ task_id: number; machine_id: number; plan_start: string; duration_minutes: number }> = []
  const exceptions: AutoScheduleException[] = []
  const scope = new Set(ids)
  const machineBusy = new Map<number, dayjs.Dayjs>()
  const moldBusy = new Map<number, dayjs.Dayjs>()
  const base = dayjs().add(1, 'day').hour(8).minute(0).second(0)
  for (const schedule of db.schedules) {
    if (scope.has(schedule.task_id) || schedule.status === 'done' || schedule.status === 'paused' || !schedule.machine_id) continue
    const end = dayjs(schedule.plan_end)
    const machineEnd = machineBusy.get(schedule.machine_id)
    if (!machineEnd || end.isAfter(machineEnd)) machineBusy.set(schedule.machine_id, end.add(TASK_SWITCH_GAP_MIN, 'minute'))
    if (schedule.mold_id) {
      const moldEnd = moldBusy.get(schedule.mold_id)
      if (!moldEnd || end.isAfter(moldEnd)) moldBusy.set(schedule.mold_id, end)
    }
  }
  const sortedTasks = ids
    .map((id) => db.tasks.find((task) => task.id === id))
    .filter((task): task is ProdTask => Boolean(task))
    .sort((a, b) => a.priority - b.priority || a.task_no.localeCompare(b.task_no))
  const validTasks = sortedTasks.filter((task) => {
    const mold = db.molds.find((item) => item.id === task.mold_id)
    if (mold?.status === 'repair' || mold?.status === 'scrapped') {
      const statusLabel = mold.status === 'repair' ? '维修中' : '已报废'
      exceptions.push({
        task_id: task.id,
        task_no: task.task_no,
        code: 'mold_unavailable',
        reason: `任务 ${task.task_no} 使用的模具 ${mold.code} 当前为${statusLabel}，不可提交算法排产`,
      })
      return false
    }
    return true
  })
  for (const task of validTasks) {
    const oldSchedule = db.schedules.find((schedule) => schedule.task_id === task.id && (schedule.op_type === 'molding_injection' || schedule.op_type === 'molding_blow'))
    const mold = db.molds.find((item) => item.id === task.mold_id)
    if (oldSchedule && !mold) {
      exceptions.push({
        task_id: task.id,
        task_no: task.task_no,
        code: 'mold_missing',
        reason: `任务 ${task.task_no} 未找到成型工序使用的模具，无法提交算法排产`,
      })
      continue
    }
    const requiredType = oldSchedule ? db.machines.find((machine) => machine.id === oldSchedule.machine_id)?.type : mold?.type ?? 'injection'
    const compatibleMachineIds = new Set(mold?.machines?.map((machine) => machine.id) ?? [])
    const candidates = machines.filter((machine) => machine.type === requiredType && (compatibleMachineIds.size === 0 || compatibleMachineIds.has(machine.id)))
    if (candidates.length === 0) {
      exceptions.push({
        task_id: task.id,
        task_no: task.task_no,
        code: 'no_eligible_machine',
        reason: `任务 ${task.task_no} 没有可用的匹配机台，已按资源类型和模具适配关系校验`,
      })
      continue
    }
    let best: { machine: (typeof machines)[number]; start: dayjs.Dayjs; end: dayjs.Dayjs } | null = null
    for (const machine of candidates) {
      const start = [base, machineBusy.get(machine.id) ?? base, task.mold_id ? (moldBusy.get(task.mold_id) ?? base) : base]
        .reduce((latest, value) => value.isAfter(latest) ? value : latest, base)
      const end = dayjs(calcEnd(start.format('YYYY-MM-DD HH:mm:ss'), machine.capacity_per_hour, task.qty, machine.mold_change_min, machine.shift_mode))
      if (!best || start.isBefore(best.start)) best = { machine, start, end }
    }
    if (!best) continue
    // 同一资源切换到下一个任务时固定预留 30 分钟，避免任务首尾紧贴。
    machineBusy.set(best.machine.id, best.end.add(TASK_SWITCH_GAP_MIN, 'minute'))
    if (task.mold_id) moldBusy.set(task.mold_id, best.end)
    assignments.push({ task_id: task.id, machine_id: best.machine.id, plan_start: best.start.format('YYYY-MM-DD HH:mm:ss'), duration_minutes: best.end.diff(best.start, 'minute') })
  }
  const meta = { algorithm: 'CP-SAT(mock)-资源避让', makespan_hours: assignments.length ? Math.ceil(Math.max(...assignments.map((item) => item.duration_minutes / 60))) : 0, total_tardiness_hours: 0 }
  if (assignments.length === 0) {
    return {
      job_id: null,
      task_count: 0,
      machine_count: machines.length,
      message: '没有可提交算法排产的正常任务，异常任务已列入清单',
      exceptions,
      status: 'no_valid_tasks',
    }
  }
  const jobId = `auto-${dayjs().format('YYYYMMDDHHmmss')}`
  autoJobs.set(jobId, { job_id: jobId, status: 'done', solution: { assignments, meta }, meta, error: null })
  return { job_id: jobId, task_count: assignments.length, machine_count: machines.length, message: `演示模式：已处理任务池及现有重叠任务${exceptions.length ? `；异常任务 ${exceptions.length} 个未纳入求解` : ''}`, exceptions }
}

const autoJobs = new Map<string, { job_id: string; status: string; solution: { assignments: unknown[]; meta: Record<string, unknown> } | null; meta: Record<string, unknown> | null; error: string | null }>()

export function autoPoll(jobId: string): { job_id: string; status: string; solution: { assignments: unknown[]; meta: Record<string, unknown> } | null; meta: Record<string, unknown> | null; error: string | null } {
  return autoJobs.get(jobId) ?? { job_id: jobId, status: 'failed', solution: null, meta: null, error: '任务不存在' }
}

export function autoAccept(mode: 'full' | 'partial', assignments: Array<{ task_id: number; machine_id: number; plan_start: string }>): { version: ScheduleVersion; mode: string; job_id: string | null; conflicts: unknown[] } {
  const items = assignments.map((a) => ({ task_id: a.task_id, machine_id: a.machine_id, plan_start: a.plan_start }))
  // 自动排产已完成资源避让；force 仅表示跳过旧数据冲突校验，不应把所有计划染成冲突红色。
  const result = manualSave(items, true, `自动排产采纳(${mode})`, false)
  result.version.type = 'ai'
  normalizeLineScheduleGaps()
  refreshMockSuggestions(assignments.map((item) => item.task_id))
  persistMockScheduleState()
  return { version: result.version, mode, job_id: null, conflicts: [] }
}

// ==================== 报工 / 入库 ====================

function finalScheduleForTask(taskId: number): TaskSchedule | null {
  return db.schedules
    .filter((schedule) => schedule.task_id === taskId && !schedule.is_interrupted)
    .sort((a, b) => b.seq - a.seq)[0] ?? null
}

function syncRuntimeTask(task: ProdTask): void {
  const rows = db.schedules.filter((schedule) => schedule.task_id === task.id && !schedule.is_interrupted)
  const taskWasPaused = task.status === 'paused'
  for (const schedule of rows) {
    const net = db.reports.filter((report) => report.schedule_id === schedule.id).reduce((sum, report) => sum + report.qty, 0)
    schedule.done_qty = Math.max(0, net)
    const scheduleWasPaused = schedule.status === 'paused'
    schedule.status = schedule.done_qty >= schedule.qty
      ? 'done'
      : scheduleWasPaused
        ? 'paused'
        : schedule.done_qty > 0
          ? 'running'
          : 'pending'
  }
  const lastId = finalScheduleForTask(task.id)?.id
  const finalReported = db.reports
    .filter((report) => report.task_id === task.id && (report.schedule_id === null || report.schedule_id === lastId))
    .reduce((sum, report) => sum + report.qty, 0)
  task.done_qty = Math.max(0, finalReported)
  task.stocked_qty = db.stockIns.filter((stockIn) => stockIn.task_id === task.id).reduce((sum, stockIn) => sum + stockIn.qty, 0)
  task.status = task.done_qty >= task.qty
    ? 'done'
    : taskWasPaused
      ? 'paused'
      : db.reports.some((report) => report.task_id === task.id)
        ? 'in_progress'
        : rows.length > 0
          ? 'scheduled'
          : 'unscheduled'
}

function reportTimelineKey(report: WorkReport): string {
  return `${report.start_time ?? report.report_time}|${String(report.id).padStart(12, '0')}`
}

function correctionMetadata(reports: WorkReport[]): { remaining: Map<number, number>; targetByNegative: Map<number, number> } {
  const remaining = new Map<number, number>()
  const targetByNegative = new Map<number, number>()
  const groups = new Map<string, WorkReport[]>()
  for (const report of reports) {
    const key = `${report.task_id}|${report.schedule_id ?? 'task'}|${report.machine_id ?? 'none'}`
    groups.set(key, [...(groups.get(key) ?? []), report])
  }
  for (const group of groups.values()) {
    const positives = group.filter((report) => report.qty > 0).sort((a, b) => reportTimelineKey(a).localeCompare(reportTimelineKey(b)))
    const positiveIds = positives.map((report) => report.id)
    positives.forEach((report) => remaining.set(report.id, report.qty))
    const negatives = group.filter((report) => report.qty < 0).sort((a, b) => reportTimelineKey(a).localeCompare(reportTimelineKey(b)))
    for (const negative of negatives) {
      let amount = Math.abs(negative.qty)
      const linkedTargetId = negative.correction_of_report_id ?? null
      if (linkedTargetId !== null && (remaining.get(linkedTargetId) ?? 0) > 0) {
        const deduct = Math.min(remaining.get(linkedTargetId) ?? 0, amount)
        remaining.set(linkedTargetId, (remaining.get(linkedTargetId) ?? 0) - deduct)
        amount -= deduct
        targetByNegative.set(negative.id, linkedTargetId)
      }
      for (let index = positiveIds.length - 1; index >= 0 && amount > 0; index -= 1) {
        const targetId = positiveIds[index]!
        const available = remaining.get(targetId) ?? 0
        if (available <= 0) continue
        const deduct = Math.min(available, amount)
        remaining.set(targetId, available - deduct)
        amount -= deduct
        if (!targetByNegative.has(negative.id)) targetByNegative.set(negative.id, targetId)
      }
    }
  }
  return { remaining, targetByNegative }
}

/** 报工流水展示：历史冲减沿用原报工作业字段，正单返回剩余可冲减量。 */
export function decorateReportLedger(items: WorkReport[]): WorkReport[] {
  const metadata = correctionMetadata(db.reports)
  return items.map((item) => {
    const next: WorkReport = { ...item, correctable_qty: metadata.remaining.get(item.id) ?? 0 }
    if (item.qty >= 0) return next
    const targetId = metadata.targetByNegative.get(item.id) ?? item.correction_of_report_id ?? null
    const target = targetId ? db.reports.find((report) => report.id === targetId) : null
    if (!target) return next
    return {
      ...next,
      schedule_id: target.schedule_id,
      machine_id: target.machine_id,
      schedule: target.schedule,
      machine: target.machine,
      report_time: target.report_time,
      start_time: target.start_time,
      end_time: target.end_time,
      work_date: target.work_date,
      shift_code: target.shift_code,
      source: target.source,
      source_label: target.source_label,
      correction_of_report_id: target.id,
      correction_target_id: target.id,
    }
  })
}

export function submitReport(payload: { task_id: number; qty: number; defect_qty?: number; correction_of_report_id?: number | null; worker_id?: number | null; schedule_id?: number | null; machine_id?: number | null; report_time?: string; start_time?: string; end_time?: string; work_date?: string; shift_code?: 'day' | 'night'; client_request_id?: string; source?: string; offline_flag?: boolean; remark?: string }): { report: unknown; task: unknown; is_correction: boolean } {
  const task = db.tasks.find((t) => t.id === payload.task_id)
  if (!task) throw new MockApiError(404, 404, null, '任务不存在')
  if (payload.worker_id != null) throw new MockApiError(422, 422, { error_code: 'worker_attribution_disabled' }, '当前报工暂不区分个人，请不要填写 worker_id')
  const isCorrection = payload.qty < 0
  const user = db.users.find((u) => u.job_type === 'statistician') ?? db.users.find((u) => u.job_type === 'admin') ?? db.users[0]!
  const correctionTarget = isCorrection && payload.correction_of_report_id
    ? db.reports.find((report) => report.id === payload.correction_of_report_id && report.qty > 0 && report.task_id === task.id)
    : null
  if (isCorrection && payload.correction_of_report_id && !correctionTarget) {
    throw new MockApiError(422, 422, { error_code: 'correction_source_not_found' }, '原报工记录不存在或不是正报工，无法冲减')
  }
  if (correctionTarget) {
    const available = correctionMetadata(db.reports).remaining.get(correctionTarget.id) ?? 0
    if (Math.abs(payload.qty) > available) {
      throw new MockApiError(422, 422, { error_code: 'correction_exceeds_balance', available_qty: available }, `本条正报工剩余可冲减 ${available} 件，不能超过剩余数量`)
    }
  } else if (isCorrection) {
    const available = db.reports
      .filter((report) => report.task_id === task.id && (report.schedule_id ?? null) === (payload.schedule_id ?? null))
      .reduce((sum, report) => sum + report.qty, 0)
    if (Math.abs(payload.qty) > Math.max(0, available)) {
      throw new MockApiError(422, 422, { error_code: 'correction_exceeds_balance', available_qty: Math.max(0, available) }, `当前工序剩余可冲减 ${Math.max(0, available)} 件，不能超过剩余数量`)
    }
  }
  let schedule = payload.schedule_id ? db.schedules.find((item) => item.id === payload.schedule_id) : null
  const scheduleMachineId = schedule?.machine_id
  let machine = (payload.machine_id ? db.machines.find((item) => item.id === payload.machine_id) : null) ?? (scheduleMachineId ? db.machines.find((item) => item.id === scheduleMachineId) : null)
  let reportTime = payload.report_time ?? payload.end_time ?? dayjs().format('YYYY-MM-DD HH:mm:ss')
  let startTime = payload.start_time ?? reportTime
  let endTime = payload.end_time ?? reportTime
  let workDate = payload.work_date ?? dayjs().format('YYYY-MM-DD')
  let shiftCode = payload.shift_code ?? 'day'
  let source = payload.source ?? 'click'
  if (correctionTarget) {
    schedule = correctionTarget.schedule_id ? db.schedules.find((item) => item.id === correctionTarget.schedule_id) ?? null : null
    machine = correctionTarget.machine_id ? db.machines.find((item) => item.id === correctionTarget.machine_id) ?? null : null
    reportTime = correctionTarget.report_time
    startTime = correctionTarget.start_time ?? reportTime
    endTime = correctionTarget.end_time ?? reportTime
    workDate = correctionTarget.work_date ?? workDate
    shiftCode = correctionTarget.shift_code ?? shiftCode
    source = correctionTarget.source
  }
  if (payload.schedule_id && (!schedule || schedule.task_id !== task.id)) throw new MockApiError(422, 422, { error_code: 'schedule_task_mismatch' }, '报工工序不属于该任务')
  if (schedule && machine && machine.id !== schedule.machine_id) throw new MockApiError(422, 422, { error_code: 'schedule_machine_mismatch' }, '报工机台与排程机台不一致')
  if (schedule && !isCorrection) {
    const previous = db.schedules.filter((item) => item.task_id === task.id && item.seq < schedule.seq && !item.is_interrupted).sort((a, b) => b.seq - a.seq)[0]
    if (previous) {
      const previousDone = db.reports.filter((report) => report.schedule_id === previous.id).reduce((sum, report) => sum + report.qty, 0)
      const currentDone = db.reports.filter((report) => report.schedule_id === schedule.id).reduce((sum, report) => sum + report.qty, 0)
      if (currentDone + payload.qty > previousDone) throw new MockApiError(422, 422, { error_code: 'process_chain_exceed', previous_done: previousDone }, `下道工序完工量不能超过上道工序完工量（${previousDone}）`)
    }
  }
  if (dayjs(endTime).isBefore(dayjs(startTime))) {
    throw new MockApiError(422, 422, { error_code: 'invalid_report_time_range' }, '结束时间不能早于开始时间')
  }
  reportSeq += 1
  db.reports.unshift({
    id: reportSeq,
    task_id: task.id,
    task_no: task.task_no,
    product_id: task.product_id,
    product: task.product,
    machine_id: machine?.id ?? null,
    machine: machine ? { id: machine.id, code: machine.code, name: machine.name } : null,
    schedule_id: schedule?.id ?? null,
    qty: payload.qty,
    defect_qty: payload.defect_qty ?? 0,
    sign: isCorrection ? -1 : 1,
    report_by: user.id,
    operator: { id: user.id, name: user.name, employee_no: user.employee_no },
    worker_id: null,
    worker: null,
    report_time: reportTime,
    start_time: startTime,
    end_time: endTime,
    work_date: workDate,
    shift_code: shiftCode,
    client_request_id: payload.client_request_id ?? null,
    source,
    correction_of_report_id: correctionTarget?.id ?? null,
    source_label: isCorrection ? '冲减更正' : payload.source === 'leader_batch' ? '班组长/统计员日结' : payload.source === 'offline' ? '离线补传' : '报工',
    offline_flag: payload.offline_flag ? 1 : 0,
    remark: payload.remark ?? (isCorrection ? '演示冲减' : null),
  })
  syncRuntimeTask(task)
  // 入库联动（需求 #3）：最后一个工序报工（工单全部合格完工）自动生成入库单，
  // 与后端「报工/入库联动」规则一致；冲减（负数量）不触发，已入库量不重复生成。
  if (!isCorrection && task.status === 'done') {
    const stockedQty = db.stockIns.filter((s) => s.task_id === task.id).reduce((sum, s) => sum + s.qty, 0)
    const pendingStock = task.qty - stockedQty
    if (pendingStock > 0) {
      stockInSeq += 1
      db.stockIns.unshift({
        id: stockInSeq,
        ins_no: `RK-${dayjs().format('YYYYMMDD')}-${String(stockInSeq).padStart(2, '0')}`,
        task_id: task.id,
        task_no: task.task_no,
        product_id: task.product_id,
        product: task.product,
        qty: pendingStock,
        ins_status: 'confirmed',
        k3_push_status: 'queued',
        k3_bill_no: null,
        k3_result: null,
        created_by: 1,
        creator: { id: 1, name: '系统管理员' },
        remark: '末工序报工自动入库（演示）',
        created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
      })
    }
  }
  task.deviation_pct = task.qty > 0 ? Math.max(0, Math.round(((task.done_qty - task.qty * 0.6) / task.qty) * 100)) / 100 : 0
  pushAudit('production', 'report_submit', 'task', task.id, { qty: payload.qty, source: payload.source ?? 'click', is_correction: isCorrection })
  return {
    report: clone(db.reports[0]!),
    task: {
      id: task.id,
      task_no: task.task_no,
      product_code: task.product?.code,
      product_name: task.product?.name,
      qty: task.qty,
      done_qty: task.done_qty,
      stocked_qty: db.stockIns.filter((s) => s.task_id === task.id).reduce((sum, s) => sum + s.qty, 0),
      status: task.status,
      deviation_pct: task.deviation_pct,
    },
    is_correction: isCorrection,
  }
}

/** PC 当日报工表数据：与真实后端一样，一行对应一条已接受工序排程。 */
export function dailyReportContext(workDate: string, filters: { keyword?: string; workshop_id?: number; machine_id?: number; operation?: string } = {}): {
  work_date: string
  rows: unknown[]
  summary: { row_count: number; planned_qty: number; reported_qty: number; remaining_qty: number }
} {
  const day = dayjs(workDate).startOf('day')
  const end = day.endOf('day')
  const keyword = String(filters.keyword ?? '').trim().toLowerCase()
  const orderForTask = (task: ProdTask) => {
    for (const [orderId, lines] of Object.entries(db.orderLines)) {
      if (lines.some((line) => line.id === task.order_line_id)) return db.orders.find((order) => order.id === Number(orderId)) ?? null
    }
    return null
  }
  const rows = db.schedules
    .filter((schedule) => !schedule.is_interrupted && schedule.machine_id && dayjs(schedule.plan_start).isBefore(end) && dayjs(schedule.plan_end).isAfter(day))
    .map((schedule) => {
      const task = db.tasks.find((item) => item.id === schedule.task_id)
      const machine = db.machines.find((item) => item.id === schedule.machine_id)
      const order = task ? orderForTask(task) : null
      const opName = schedule.op_name ?? schedule.op_type
      const text = `${task?.task_no ?? ''} ${order?.order_no ?? ''} ${task?.product?.code ?? ''} ${task?.product?.name ?? ''} ${machine?.code ?? ''}`.toLowerCase()
      const reported = db.reports.filter((report) => report.schedule_id === schedule.id).reduce((sum, report) => sum + report.qty, 0)
      return {
        schedule_id: schedule.id,
        task_id: schedule.task_id,
        task_no: task?.task_no ?? schedule.task_no ?? '-',
        order_no: order?.order_no ?? schedule.order_no ?? null,
        product: task?.product ?? null,
        operation: { seq: schedule.seq, name: opName },
        machine: machine ? { id: machine.id, code: machine.code, name: machine.name, workshop_id: machine.workshop_id, workshop_name: machine.workshop_name, shift_mode: machine.shift_mode } : null,
        plan_start: schedule.plan_start,
        plan_end: schedule.plan_end,
        planned_qty: schedule.qty,
        reported_qty: Math.max(0, reported),
        defect_qty: db.reports.filter((report) => report.schedule_id === schedule.id).reduce((sum, report) => sum + (report.defect_qty ?? 0), 0),
        remaining_qty: Math.max(0, schedule.qty - Math.max(0, reported)),
        schedule_status: schedule.status,
        task_status: task?.status ?? 'scheduled',
        delivery_date: task?.delivery_date ?? null,
        work_date: workDate,
        _match: text.includes(keyword),
        _operation: opName,
      }
    })
    .filter((row) => row._match && (!filters.workshop_id || row.machine?.workshop_id === filters.workshop_id) && (!filters.machine_id || row.machine?.id === filters.machine_id) && (!filters.operation || row._operation === filters.operation))
    .sort((a, b) => (a.machine?.id ?? 0) - (b.machine?.id ?? 0) || a.plan_start.localeCompare(b.plan_start) || a.operation.seq - b.operation.seq)
    .map(({ _match, _operation, ...row }) => row)
  const lastScheduleByTask = new Map<number, number>()
  for (const schedule of db.schedules.filter((item) => !item.is_interrupted)) {
    const currentId = lastScheduleByTask.get(schedule.task_id)
    const current = currentId ? db.schedules.find((item) => item.id === currentId) : null
    if (!current || schedule.seq > current.seq || (schedule.seq === current.seq && schedule.id > current.id)) {
      lastScheduleByTask.set(schedule.task_id, schedule.id)
    }
  }
  const rowsWithOperations = rows.map((row) => {
    const operationOptions = db.schedules
      .filter((schedule) => schedule.task_id === row.task_id && !schedule.is_interrupted)
      .sort((a, b) => a.seq - b.seq || a.id - b.id)
      .map((schedule) => {
        const machine = schedule.machine_id ? db.machines.find((item) => item.id === schedule.machine_id) : null
        const directReported = db.reports.filter((report) => report.schedule_id === schedule.id).reduce((sum, report) => sum + report.qty, 0)
        const taskReported = lastScheduleByTask.get(row.task_id) === schedule.id
          ? db.reports.filter((report) => report.task_id === row.task_id && report.schedule_id === null).reduce((sum, report) => sum + report.qty, 0)
          : 0
        const reportedQty = Math.max(0, directReported + taskReported)
        return {
          schedule_id: schedule.id,
          operation: { seq: schedule.seq, name: schedule.op_name ?? schedule.op_type },
          machine: machine ? { id: machine.id, code: machine.code, name: machine.name, workshop_id: machine.workshop_id, workshop_name: machine.workshop_name, shift_mode: machine.shift_mode } : null,
          plan_start: schedule.plan_start,
          plan_end: schedule.plan_end,
          planned_qty: schedule.qty,
          reported_qty: reportedQty,
          remaining_qty: Math.max(0, schedule.qty - reportedQty),
          schedule_status: schedule.status,
        }
      })
    return { ...row, operation_options: operationOptions }
  })
  return {
    work_date: workDate,
    rows: rowsWithOperations,
    summary: {
      row_count: rowsWithOperations.length,
      planned_qty: rowsWithOperations.reduce((sum, row) => sum + row.planned_qty, 0),
      reported_qty: rowsWithOperations.reduce((sum, row) => sum + row.reported_qty, 0),
      remaining_qty: rowsWithOperations.reduce((sum, row) => sum + row.remaining_qty, 0),
    },
  }
}

export function availableQty(taskId: number): { task_id: number; task_no: string; reported_qty: number; stocked_qty: number; available_qty: number } {
  const task = db.tasks.find((t) => t.id === taskId)!
  const lastId = finalScheduleForTask(taskId)?.id
  const reported = db.reports.filter((r) => r.task_id === taskId && (r.schedule_id === null || r.schedule_id === lastId)).reduce((s, r) => s + r.qty, 0)
  const stocked = db.stockIns.filter((s) => s.task_id === taskId).reduce((s, i) => s + i.qty, 0)
  return { task_id: taskId, task_no: task.task_no, reported_qty: reported, stocked_qty: stocked, available_qty: Math.max(0, reported - stocked) }
}

export function createStockIn(payload: { task_id: number; qty: number; remark?: string }): { stock_in: unknown; available: { task_id: number; task_no: string; reported_qty: number; stocked_qty: number; available_qty: number } } {
  const available = availableQty(payload.task_id)
  if (payload.qty > available.available_qty) {
    throw new MockApiError(422, 422, { available_qty: available.available_qty }, `入库数量超过可入库量（${available.available_qty}）`)
  }
  const task = db.tasks.find((t) => t.id === payload.task_id)!
  stockInSeq += 1
  const isRed = payload.qty < 0
  db.stockIns.unshift({
    id: stockInSeq,
    ins_no: `RK-${dayjs().format('YYYYMMDD')}-${String(stockInSeq).padStart(2, '0')}`,
    task_id: task.id,
    task_no: task.task_no,
    product_id: task.product_id,
    product: task.product,
    qty: payload.qty,
    ins_status: 'confirmed',
    k3_push_status: isRed ? 'success' : 'queued',
    k3_bill_no: null,
    k3_result: null,
    created_by: 1,
    creator: { id: 1, name: '系统管理员' },
    remark: payload.remark ?? (isRed ? '红字回冲' : null),
    created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
  })
  pushAudit('warehouse', 'stock_in_create', 'task', task.id, { qty: payload.qty, remark: payload.remark })
  return { stock_in: clone(db.stockIns[0]!), available: availableQty(payload.task_id) }
}

export function pushStockIn(id: number): { pushed: boolean; status: string; bill_no: string | null; message: string } {
  const ins = db.stockIns.find((s) => s.id === id)
  if (!ins) throw new MockApiError(404, 404, null, '入库单不存在')
  if (ins.k3_push_status === 'success' && ins.k3_bill_no) {
    return { pushed: true, status: 'success', bill_no: ins.k3_bill_no, message: '已推送（幂等跳过）' }
  }
  const billNo = `K3-IN-${dayjs().format('YYYYMMDD')}-MOCK${String(id).padStart(4, '0')}`
  ins.k3_push_status = 'success'
  ins.k3_bill_no = billNo
  ins.k3_result = { Id: 900000 + id, Number: billNo }
  ins.ins_status = 'pushed'
  return { pushed: true, status: 'success', bill_no: billNo, message: '演示推送成功（mock 回执）' }
}

// ==================== 红灯 ====================

export function closeRedLight(id: number, reason: string, byName: string): RedLight {
  const rl = db.redLights.find((r) => r.id === id)
  if (!rl) throw new MockApiError(404, 404, null, '红灯记录不存在')
  if (rl.status === 'closed') return clone(rl)
  rl.status = 'closed'
  rl.closed_by = byName
  rl.close_reason = reason
  rl.closed_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
  pushAudit('dashboard', 'red_light_close', 'red_light', rl.id, { source: rl.source, reason })
  return clone(rl)
}

/** 重新计算红灯（演示：基于当前任务状态重算；简化合并源数据） */
export function refreshRedLights(): number {
  return db.redLights.filter((r) => r.status === 'open').length
}

// ==================== 生产异常 ====================

/** 处理异常（演示：更新状态/处理人/结果，写审计） */
export function handleException(id: number, data: { status: 'open' | 'processing' | 'closed'; handler?: string; result?: string }): ProductionException {
  const ex = db.exceptions.find((e) => e.id === id)
  if (!ex) throw new MockApiError(404, 404, null, '异常记录不存在')
  ex.status = data.status
  ex.status_label = data.status === 'open' ? '待处理' : data.status === 'processing' ? '处理中' : '已关闭'
  if (data.handler) ex.handler = data.handler
  if (data.status === 'closed' || data.status === 'processing') {
    ex.handle_time = dayjs().format('YYYY-MM-DD HH:mm:ss')
  }
  if (data.result) ex.handle_result = data.result
  pushAudit('production', 'exception_handle', 'exception', ex.id, { type: ex.type, status: ex.status, result: data.result ?? null })
  return clone(ex)
}

/** 上报异常（演示：新增一条） */
export function createException(data: Partial<ProductionException> & { desc: string; type: ProductionException['type'] }): ProductionException {
  const typeLabels: Record<ProductionException['type'], string> = {
    stop_machine: '停机', mold_change: '换模', defect: '次品', material_short: '缺料',
  }
  exceptionSeq += 1
  const ex: ProductionException = {
    id: exceptionSeq,
    type: data.type,
    type_label: typeLabels[data.type] ?? data.type,
    task_id: data.task_id ?? null,
    task_no: data.task_no ?? null,
    machine_id: data.machine_id ?? null,
    machine_code: data.machine_code ?? null,
    product_code: data.product_code ?? null,
    product_name: data.product_name ?? null,
    level: data.level ?? 'medium',
    desc: data.desc,
    report_by: data.report_by ?? '系统管理员',
    report_time: dayjs().format('YYYY-MM-DD HH:mm:ss'),
    status: data.status ?? 'open',
    status_label: data.status === 'processing' ? '处理中' : data.status === 'closed' ? '已关闭' : '待处理',
    handler: data.handler ?? null,
    handle_time: data.handle_time ?? null,
    handle_result: data.handle_result ?? null,
  }
  db.exceptions.unshift(ex)
  pushAudit('production', 'exception_report', 'exception', ex.id, { type: ex.type, desc: ex.desc })
  return clone(ex)
}

// ==================== 新品开发流程 ====================

const DEVELOP_ORDER: DevelopStageKey[] = ['inquiry', 'project', 'design', 'mold_making', 'trial', 'small_batch', 'mass']
const DEVELOP_LABELS: Record<DevelopStageKey, string> = {
  inquiry: '询价', project: '立项', design: '设计', mold_making: '开模', trial: '试模', small_batch: '小批', mass: '量产',
}

/** 推进开发任务阶段（演示：当前阶段完成 → 下一阶段 doing；量产则关闭） */
export function advanceDevelop(id: number, operatorName = '系统管理员'): DevelopTask {
  const dev = db.developTasks.find((d) => d.id === id)
  if (!dev) throw new MockApiError(404, 404, null, '开发任务不存在')
  const idx = DEVELOP_ORDER.indexOf(dev.stage)
  if (idx < 0 || idx >= DEVELOP_ORDER.length - 1) {
    throw new MockApiError(422, 422, null, '已处于最终阶段（量产），无需推进')
  }
  const next = DEVELOP_ORDER[idx + 1]
  dev.stage = next
  dev.stage_label = DEVELOP_LABELS[next]
  dev.stage_status = 'doing'
  dev.owner_name = operatorName
  dev.logs = dev.logs ?? []
  dev.logs.push({ stage: next, stage_label: DEVELOP_LABELS[next], status: 'doing', at: dayjs().format('YYYY-MM-DD HH:mm:ss') })
  pushAudit('plm', 'develop_stage_advance', 'develop', dev.id, { from: DEVELOP_ORDER[idx], to: next })
  return clone(dev)
}

/** 新建开发任务（演示） */
export function createDevelop(data: Partial<DevelopTask> & { product_name: string; customer_name: string }): DevelopTask {
  const dev: DevelopTask = {
    id: db.developTasks.length + 101,
    dev_no: data.dev_no ?? `DEV-${dayjs().format('YYYY')}-${String(db.developTasks.length + 101).padStart(3, '0')}`,
    product_code: data.product_code ?? 'HY-9XXX',
    product_name: data.product_name,
    spec: data.spec ?? '',
    customer_name: data.customer_name,
    stage: data.stage ?? 'inquiry',
    stage_label: DEVELOP_LABELS[data.stage ?? 'inquiry'] ?? '询价',
    stage_status: data.stage_status ?? 'doing',
    priority: data.priority ?? 3,
    plan_finish: data.plan_finish ?? dayjs().add(21, 'day').format('YYYY-MM-DD'),
    owner_name: data.owner_name ?? '孙业务',
    overdue: data.overdue ?? false,
    remark: data.remark ?? null,
    logs: data.logs ?? [],
  }
  db.developTasks.push(dev)
  pushAudit('plm', 'develop_create', 'develop', dev.id, { dev_no: dev.dev_no, product: dev.product_name })
  return clone(dev)
}

// ==================== 报价（五期） ====================

/** AI 估价（演示：前端 PriceCalc 同构计算） */
export function mockEstimate(productId: number | null, qty: number, margin = 20): QuoteEstimate {
  const product = productId ? db.products.find((p) => p.id === productId) ?? null : null
  const calc = calcEstimate({
    qty,
    margin,
    materials: productMaterials(product),
    processes: productProcesses(product),
    moldCost: productMoldCost(product),
    lossRate: 2,
  })
  return {
    product_id: product?.id ?? null,
    product_code: product?.code ?? null,
    product_name: product?.name ?? null,
    spec_text: product?.spec ?? '规格待确认',
    qty,
    material_cost: calc.material_cost,
    process_cost: calc.process_cost,
    mold_amort: calc.mold_amort,
    loss_cost: calc.loss_cost,
    total_cost: calc.total_cost,
    loss_rate: 2,
    margin,
    suggest_price: calc.suggest_price,
    delivery_days: calc.delivery_days,
    anomaly_flag: false,
    anomaly_message: '',
    ai_assist: 'not_enabled',
    llm_remark: 'AI 辅助未启用（演示模式），以上为规则引擎确定性估算',
    material_detail: (productMaterials(product) ?? []).map((m) => ({ item_code: m.code, item_name: m.name, qty: m.qty, price: m.price, cost: m.qty * m.price })),
    process_detail: (productProcesses(product) ?? []).map((p) => ({ op_type: p.name, op_name: p.name, hours_per_unit: p.hours_per_unit, rate: p.rate, cost: p.hours_per_unit * p.rate })),
    mold: product ? { mold_code: 'M-001', mold_name: '直口杯 500ml 注塑模', purchase_cost: 88000, amort_per_unit: calc.mold_amort } : null,
  }
}

/** 估价 → 报价单 */
export function createQuoteFromEstimate(customerName: string, estimate: QuoteEstimate): Quote {
  quoteSeq += 1
  const line: QuoteLine = {
    id: quoteSeq * 10 + 1,
    quote_id: quoteSeq,
    spec_text: estimate.spec_text,
    qty: estimate.qty,
    ref_product_id: estimate.product_id,
    product_id: estimate.product_id,
    product: estimate.product_id ? (db.products.find((p) => p.id === estimate.product_id) ?? null) : null,
    material_cost: estimate.material_cost,
    process_cost: estimate.process_cost,
    mold_amort: estimate.mold_amort,
    loss_cost: estimate.loss_cost,
    total_cost: estimate.total_cost,
    margin: estimate.margin,
    suggest_price: estimate.suggest_price,
    delivery_days: estimate.delivery_days,
    llm_remark: estimate.llm_remark,
    ai_assist: estimate.ai_assist,
    anomaly_flag: estimate.anomaly_flag,
  }
  const quote: Quote = {
    id: quoteSeq,
    quote_no: `QT${dayjs().format('YYYYMMDD')}-${String(quoteSeq).padStart(3, '0')}`,
    customer_name: customerName,
    source: 'sales',
    status: 'draft',
    status_label: '草稿',
    target_margin: estimate.margin,
    suggest_delivery: dayjs().add(estimate.delivery_days, 'day').format('YYYY-MM-DD'),
    final_price: null,
    created_by: 1,
    remark: '由 AI 估价生成（演示）',
    lines: [line],
    created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
  }
  db.quotes.unshift(quote)
  pushAudit('quote', 'quote_create', 'quote', quote.id, { source: 'estimate', customer: customerName })
  return clone(quote)
}

/** 调价（留审计） */
export function adjustQuotePrice(id: number, finalPrice: number, remark?: string): Quote {
  const q = db.quotes.find((x) => x.id === id)
  if (!q) throw new MockApiError(404, 404, null, '报价单不存在')
  const old = q.final_price
  q.final_price = finalPrice
  if (remark) q.remark = remark
  pushAudit('quote', 'quote_price_change', 'quote', q.id, { from: old, to: finalPrice })
  return clone(q)
}

/** 状态流转 */
export function transitionQuoteStatus(id: number, status: Quote['status'], finalPrice?: number): Quote {
  const q = db.quotes.find((x) => x.id === id)
  if (!q) throw new MockApiError(404, 404, null, '报价单不存在')
  q.status = status
  q.status_label = { draft: '草稿', sent: '已发', won: '成交', lost: '未成交' }[status] ?? status
  if (finalPrice !== undefined) q.final_price = finalPrice
  pushAudit('quote', 'quote_status', 'quote', q.id, { to: status })
  return clone(q)
}

/** 材料价 upsert（生效日期版本化） */
export function upsertMaterialPrice(data: Partial<MaterialPrice>): MaterialPrice {
  const row: MaterialPrice = {
    id: db.materialPrices.length + 1,
    material_code: data.material_code ?? 'RAW-XXX',
    material_name: data.material_name ?? '',
    price_per_unit: data.price_per_unit ?? 0,
    unit: data.unit ?? null,
    effective_date: data.effective_date ?? dayjs().format('YYYY-MM-DD'),
    source: data.source ?? 'manual',
  }
  db.materialPrices.push(row)
  return clone(row)
}

/** 工序费率 upsert */
export function upsertProcessRate(data: Partial<ProcessRate>): ProcessRate {
  const row: ProcessRate = {
    id: db.processRates.length + 1,
    rate_key: data.rate_key ?? 'molding',
    op_type: data.op_type ?? null,
    rate_type: data.rate_type ?? 'machine',
    rate: data.rate ?? 0,
    effective_date: data.effective_date ?? dayjs().format('YYYY-MM-DD'),
  }
  db.processRates.push(row)
  return clone(row)
}

/** 报价规则 upsert */
export function upsertQuoteRule(data: { rule_key: string; rule_value: number; remark?: string }): QuoteRuleItem {
  const found = db.quoteRules.find((r) => r.rule_key === data.rule_key)
  if (found) {
    found.rule_value = data.rule_value
    if (data.remark !== undefined) found.remark = data.remark
    return clone(found)
  }
  const row: QuoteRuleItem = { id: db.quoteRules.length + 1, rule_key: data.rule_key, rule_value: data.rule_value, remark: data.remark ?? null }
  db.quoteRules.push(row)
  return clone(row)
}

// ==================== 订单 ====================

export function createOrder(data: { customer_name: string; delivery_date?: string | null; priority?: number; remark?: string; lines: Array<{ product_code: string; qty: number; delivery_date?: string | null; priority?: number; mold_id?: number | null }> }): unknown {
  const id = db.orders.length + 1
  const orderNo = genOrderNoSeq()
  const priority = data.priority ?? 3
  const delivery = data.delivery_date ?? dayjs().add(10, 'day').format('YYYY-MM-DD HH:mm:ss')
  const lines = data.lines.map((l, i) => {
    const product = db.products.find((p) => p.code === l.product_code) ?? db.products[0]!
    const lineId = id * 100 + i + 1
    const taskId = 800 + id * 10 + i
    const task: ProdTask = {
      id: taskId,
      task_no: `T-${dayjs().format('YYYYMMDD')}-${String(800 + taskId).padStart(3, '0')}`,
      order_line_id: lineId,
      product_id: product.id,
      product: { id: product.id, code: product.code, name: product.name },
      route_id: product.default_route_id,
      mold_id: l.mold_id ?? (product.default_mold_id ?? null),
      mold: product.default_mold ? { id: product.default_mold.id, code: product.default_mold.code, name: product.default_mold.name } : null,
      qty: l.qty,
      done_qty: 0,
      status: 'unscheduled',
      priority: l.priority ?? priority,
      delivery_date: l.delivery_date ?? delivery,
      deviation_pct: 0,
      plan_start: null,
      plan_end: null,
      is_locked: 0,
      source: 'manual',
    }
    db.tasks.push(task)
    const routeOps = db.routeOps[product.default_route_id ?? -1] ?? []
    const firstOp = routeOps.slice().sort((a, b) => a.seq - b.seq)[0]
    if (firstOp) db.pool.push({
      id: task.id,
      task_no: task.task_no,
      product_id: product.id,
      product_code: product.code,
      product_name: product.name,
      qty: l.qty,
      priority: task.priority,
      delivery_date: task.delivery_date,
      is_overdue: false,
      mold_id: task.mold_id ?? null,
      mold_code: task.mold?.code ?? '',
      default_mold_code: product.default_mold?.code ?? '',
      default_route_id: product.default_route_id,
      route_published: true,
      route_ops_count: routeOps.length,
      ...poolResourceForOp(firstOp),
      estimated_hours: Math.round((l.qty / 400) * 10) / 10,
      estimate_ok: true,
      order_no: orderNo,
      customer_name: data.customer_name,
    })
    return {
      id: lineId,
      order_id: id,
      product_id: product.id,
      product,
      qty: l.qty,
      delivery_date: l.delivery_date ?? delivery,
      priority: l.priority ?? priority,
      status: 'pending' as const,
      done_qty: 0,
      line_no: i + 1,
      tasks: undefined,
    } as OrderLine
  })
  const order = {
    id,
    order_no: orderNo,
    src: 'manual' as const,
    k3_bill_no: null,
    customer_name: data.customer_name,
    status: 'route_confirm' as const,
    priority,
    delivery_date: delivery,
    remark: data.remark ?? null,
    lines_count: lines.length,
    total_qty: lines.reduce((s, l) => s + l.qty, 0),
    created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
  } as Order
  db.orders.unshift(order)
  db.orderLines[id] = lines
  pushAudit('order', 'order_create', 'order', id, { order_no: order.order_no })
  return clone(order)
}

/** 生产拆单：演示模式与真实接口保持相同的父容器/子任务语义。 */
export function splitTask(taskId: number, data: { parts: Array<{ qty: number }> }): unknown {
  const parent = db.tasks.find((task) => task.id === taskId)
  if (!parent) throw new MockApiError(404, 404, null, '任务不存在')
  const orderLine = Object.values(db.orderLines).flatMap((lines) => lines).find((line) => line.id === parent.order_line_id)
  const order = orderLine ? db.orders.find((item) => item.id === orderLine.order_id) : null
  if (!order) throw new MockApiError(404, 404, null, '任务所属订单不存在')
  if (!['route_confirm', 'pending'].includes(order.status)) throw new MockApiError(409, 409, null, '只有工艺确认中或待排产工单可以拆单')
  if (parent.is_split_parent || parent.parent_task_id) throw new MockApiError(409, 409, null, '该任务已经拆单，不可重复拆分')
  if (parent.status !== 'unscheduled' || parent.is_locked) throw new MockApiError(409, 409, null, '只有未排产且未锁定的任务可以拆单')
  if (data.parts.length < 2 || data.parts.length > 20) throw new MockApiError(422, 422, null, '拆单至少需要 2 个子任务，最多 20 个')
  const total = data.parts.reduce((sum, part) => sum + Number(part.qty || 0), 0)
  if (total !== parent.qty) throw new MockApiError(409, 409, null, `子任务数量合计必须等于原任务数量（${parent.qty}）`)

  const childIds: number[] = []
  const splitTotal = data.parts.length
  const parentPool = db.pool.find((item) => item.id === parent.id)
  parent.is_split_parent = true
  parent.status = 'split'
  db.pool = db.pool.filter((item) => item.id !== parent.id)
  data.parts.forEach((part, index) => {
    const id = Math.max(0, ...db.tasks.map((item) => item.id)) + 1
    const suffix = `-S${String(index + 1).padStart(2, '0')}`
    const child: ProdTask = {
      ...clone(parent),
      id,
      task_no: `${parent.task_no}${suffix}`.slice(0, 32),
      parent_task_id: parent.id,
      split_index: index + 1,
      split_total: splitTotal,
      is_split_parent: false,
      mold_id: null,
      mold: null,
      qty: Number(part.qty),
      done_qty: 0,
      status: 'unscheduled',
      is_locked: 0,
      plan_start: null,
      plan_end: null,
    }
    db.tasks.push(child)
    childIds.push(id)
    const routeOps = db.routeOps[child.route_id ?? -1] ?? []
    const firstOp = routeOps.slice().sort((a, b) => a.seq - b.seq)[0]
    if (firstOp && parentPool) {
      db.pool.push({
        ...clone(parentPool),
        id,
        task_no: child.task_no,
        qty: child.qty,
        mold_id: null,
        mold_code: '',
        default_mold_code: '',
        order_no: order.order_no,
        customer_name: order.customer_name,
        product_id: child.product_id,
        product_code: child.product?.code ?? '',
        product_name: child.product?.name ?? '',
        priority: child.priority,
        delivery_date: child.delivery_date,
        route_ops_count: routeOps.length,
      })
    }
  })
  pushAudit('order', 'task_split', 'order', order.id, { parent_task_id: parent.id, child_task_ids: childIds, parts: data.parts })
  return {
    parent_task_id: parent.id,
    parent_task_no: parent.task_no,
    task_ids: childIds,
    tasks: clone(db.tasks.filter((task) => childIds.includes(task.id))),
    remaining_qty: 0,
    split_total: splitTotal,
  }
}

/** 配置/调整任务工艺路线（工艺确认阶段，demo 模拟） */
export function updateOrderRoutes(id: number, data: { tasks: Array<{ task_id: number; route_id: number }> }): unknown {
  const order = db.orders.find((o) => o.id === id)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  for (const item of data.tasks) {
    const task = db.tasks.find((t) => t.id === item.task_id)
    if (task) task.route_id = item.route_id
  }
  pushAudit('order', 'order_route_edit', 'order', id, { tasks: data.tasks })
  return clone(order)
}

/** 确认工艺（route_confirm → pending，demo 模拟） */
export function confirmOrderRoute(id: number): { id: number; status: string } {
  const order = db.orders.find((o) => o.id === id)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  order.status = 'pending'
  pushAudit('order', 'order_route_confirm', 'order', id, { from: 'route_confirm', to: 'pending' })
  return { id: order.id, status: order.status }
}

/** 开始生产/恢复生产：演示模式同步恢复订单排程状态。 */
export function startOrder(id: number): { id: number; status: string } {
  const order = db.orders.find((o) => o.id === id)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  if (!['pending', 'scheduled', 'paused'].includes(order.status)) throw new MockApiError(409, 409, null, '仅待排产/已排产/已停工工单可开始生产')

  const from = order.status
  if (from === 'paused') {
    const lineIds = new Set((db.orderLines[id] ?? []).map((line) => line.id))
    const pausedTaskIds = new Set(db.tasks
      .filter((task) => lineIds.has(task.order_line_id) && task.status === 'paused')
      .map((task) => task.id))

    for (const task of db.tasks) {
      if (pausedTaskIds.has(task.id)) task.status = 'in_progress'
    }
    for (const schedule of db.schedules) {
      if (!pausedTaskIds.has(schedule.task_id) || schedule.is_interrupted || schedule.status !== 'paused') continue
      schedule.status = Number(schedule.done_qty ?? 0) > 0 ? 'running' : 'pending'
    }
  }

  order.status = 'in_progress'
  pushAudit('order', 'order_start', 'order', id, { from, to: 'in_progress' })
  persistMockScheduleState()
  return { id: order.id, status: order.status }
}

/** 停工：in_progress → paused（生产中任务暂停） */
export function pauseOrder(id: number): { id: number; status: string } {
  const order = db.orders.find((o) => o.id === id)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  if (order.status !== 'in_progress') throw new MockApiError(409, 409, null, '仅生产中工单可停工')
  const taskIds = (db.orderLines[id] ?? []).map((l) => l.id)
  const activeTaskIds = new Set<number>()
  db.tasks.forEach((t) => {
    if (taskIds.includes(t.order_line_id) && t.status === 'in_progress') {
      t.status = 'paused'
      activeTaskIds.add(t.id)
    }
  })
  db.schedules.forEach((schedule) => {
    if (activeTaskIds.has(schedule.task_id) && !schedule.is_interrupted && schedule.status !== 'done') {
      schedule.status = 'paused'
    }
  })
  order.status = 'paused'
  pushAudit('order', 'order_pause', 'order', id, { from: 'in_progress', to: 'paused' })
  persistMockScheduleState()
  return { id: order.id, status: order.status }
}

/** 中止：in_progress/paused → aborted */
export function abortOrder(id: number): { id: number; status: string } {
  const order = db.orders.find((o) => o.id === id)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  if (!['in_progress', 'paused'].includes(order.status)) throw new MockApiError(409, 409, null, '仅生产中/已停工工单可中止')
  const from = order.status
  const taskIds = (db.orderLines[id] ?? []).map((l) => l.id)
  db.tasks.forEach((t) => {
    if (taskIds.includes(t.order_line_id) && ['in_progress', 'paused'].includes(t.status)) t.status = 'paused'
  })
  order.status = 'aborted'
  pushAudit('order', 'order_abort', 'order', id, { from, to: 'aborted' })
  return { id: order.id, status: order.status }
}

/** 关闭：任意非终态 → closed */
export function closeOrder(id: number): { id: number; status: string } {
  const order = db.orders.find((o) => o.id === id)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  if (['produced', 'aborted', 'closed'].includes(order.status)) throw new MockApiError(409, 409, null, '已入库/已中止/已关闭工单不可重复关闭')
  const from = order.status
  order.status = 'closed'
  pushAudit('order', 'order_close', 'order', id, { from, to: 'closed' })
  return { id: order.id, status: order.status }
}

export function deleteOrder(id: number): void {
  const idx = db.orders.findIndex((o) => o.id === id)
  if (idx >= 0) {
    db.orders.splice(idx, 1)
    delete db.orderLines[id]
    db.tasks = db.tasks.filter((t) => !String(t.order_line_id).startsWith(String(id)))
    db.pool = db.pool.filter((p) => db.tasks.some((t) => t.id === p.id))
  }
}

// ==================== 订单编辑（工艺路线定制 / 领料清单） ====================

/** 任务工艺定制 override（key = task_id；内存态，刷新页面后清空可接受） */
const routeOpsOverride = new Map<number, OrderRouteOpDraft[]>()

/** 工序类型 → 工作中心机器类型（成型=机台 injection/blow；人工线按喷漆/效果/包装产线区分） */
const OP_MACHINE_TYPE: Record<string, string | null> = {
  molding_injection: 'injection',
  molding_blow: 'blow',
  paint: 'paint_line',
  effect: 'effect_line',
  pack: 'pack_line',
}

/** 工序类型 → 车间 id（与后端 RouteOp::workshopForOpType 推导一致） */
const OP_WORKSHOP_ID: Record<string, number | null> = {
  molding_injection: 1,
  molding_blow: 2,
  paint: 3,
  effect: 4,
  pack: 5,
}

/**
 * 领料需求量换算：round(unit_qty * qty * (1 + loss_rate/100), 4)
 * unitQty / lossRate 允许 string（后端 BOM 行可能以字符串返回），内部 Number() 转换。
 */
export function calcRequiredQty(unitQty: number | string, qty: number, lossRate: number | string): number {
  const u = Number(unitQty)
  const l = Number(lossRate)
  return Math.round(u * qty * (1 + l / 100) * 10000) / 10000
}

/** 路线库工序 → 任务工序行视图（带工作中心/车间/负责人展示辅助字段；无默认设备时按车间候选首台补齐） */
function routeOpToView(op: RouteOp, taskQty: number): OrderRouteOpView {
  const machineType = OP_MACHINE_TYPE[op.op_type] ?? null
  const isMolding = machineType === 'injection' || machineType === 'blow'
  const candidates = machineType ? db.machines.filter((m) => m.type === machineType) : []
  const defMachine = isMolding
    ? (op.default_machine_id ? db.machines.find((m) => m.id === op.default_machine_id) ?? null : candidates.find((m) => m.status === 'running' && m.is_active === 1) ?? null)
    : null
  const defLine = isMolding
    ? null
    : (op.default_line_id ? db.machines.find((m) => m.id === op.default_line_id) ?? null : candidates[0] ?? null)
  const device = isMolding ? defMachine : defLine
  const workshop = db.workshops.find((w) => w.id === op.workshop_id)
  return {
    seq: op.seq,
    op_type: op.op_type,
    op_name: op.op_name,
    workshop_id: op.workshop_id ?? OP_WORKSHOP_ID[op.op_type] ?? null,
    machine_id: isMolding ? (defMachine?.id ?? null) : null,
    line_id: isMolding ? null : (defLine?.id ?? null),
    std_hours: null,
    owner_id: null,
    remark: null,
    qty: taskQty,
    workshop_name: workshop?.name ?? (device?.workshop_name ?? null),
    machine_name: isMolding ? (device?.name ?? null) : null,
    line_name: isMolding ? null : (device?.name ?? null),
    owner_name: null,
  }
}

/** 定制工序行 → 展示视图（补车间/设备/负责人展示辅助字段） */
function draftToView(op: OrderRouteOpDraft): OrderRouteOpView {
  const device = op.machine_id
    ? db.machines.find((m) => m.id === op.machine_id) ?? null
    : (op.line_id ? db.machines.find((m) => m.id === op.line_id) ?? null : null)
  const workshop = db.workshops.find((w) => w.id === op.workshop_id)
  return {
    ...op,
    workshop_name: workshop?.name ?? (device?.workshop_name ?? null),
    machine_name: op.machine_id ? (device?.name ?? null) : null,
    line_name: op.line_id ? (device?.name ?? null) : null,
    owner_name: op.owner_id ? (db.users.find((u) => u.id === op.owner_id)?.name ?? null) : null,
  }
}

/** 任务工艺明细（override 命中则用它，否则按任务 route_id 从路线库展开；均附带排产/完工只读信息） */
function orderRouteOpsOf(task: ProdTask): OrderRouteOpView[] {
  const overridden = routeOpsOverride.get(task.id)
  if (overridden) {
    return overridden.map((op) => ({
      ...draftToView(op),
      ...schedSummaryForOp(task.id, Number(op.seq ?? 0), Number(op.qty ?? task.qty ?? 1)),
    }))
  }
  const ops = (task.route_id ? db.routeOps[task.route_id] : undefined) ?? []
  return ops.map((op) => ({
    ...routeOpToView(op, Number(task.qty ?? 1)),
    ...schedSummaryForOp(task.id, Number(op.seq ?? 0), Number(task.qty ?? 1)),
  }))
}

/** 工序排产/完工汇总（只读，来自排程与报工；未排产返回空态） */
function schedSummaryForOp(taskId: number, seq: number, opQty: number): {
  plan_start: string | null
  plan_end: string | null
  machines: string[] | null
  done_qty: number
  percent: number | null
} {
  const segs = db.schedules.filter((s) => s.task_id === taskId && s.seq === seq)
  if (segs.length === 0) {
    return { plan_start: null, plan_end: null, machines: null, done_qty: 0, percent: null }
  }
  const starts = segs.map((s) => s.plan_start).filter(Boolean).sort()
  const ends = segs.map((s) => s.plan_end).filter(Boolean).sort()
  const machines = [
    ...new Set(segs.map((s) => {
      const m = db.machines.find((x) => x.id === s.machine_id)
      return m ? `${m.code} ${m.name}` : ''
    }).filter(Boolean)),
  ]
  const done = segs.reduce((a, s) => a + Number(s.done_qty ?? 0), 0)
  return {
    plan_start: starts[0] ?? null,
    plan_end: ends[ends.length - 1] ?? null,
    machines: machines.length ? machines : null,
    done_qty: done,
    percent: opQty > 0 ? Math.min(100, Math.round((done / opQty) * 1000) / 10) : 0,
  }
}

/** GET /orders/:id/route-ops */
export function getOrderRouteOps(orderId: number): OrderRouteOpsPayload {
  const order = db.orders.find((o) => o.id === orderId)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  const lineIds = new Set((db.orderLines[orderId] ?? []).map((l) => l.id))
  const tasks = db.tasks.filter((t) => lineIds.has(t.order_line_id))
  return {
    order_id: order.id,
    order_no: order.order_no,
    status: order.status,
    editable: true,
    tasks: tasks.map((task) => {
      const route = task.route_id ? db.routes.find((r) => r.id === task.route_id) : null
      return {
        task_id: task.id,
        task_no: task.task_no,
        product_id: task.product_id,
        product_name: task.product?.name ?? null,
        qty: task.qty,
        route_id: task.route_id ?? null,
        route_name: route?.name ?? null,
        route_version: route?.version ?? null,
        overridden: routeOpsOverride.has(task.id),
        ops: orderRouteOpsOf(task),
      }
    }),
  }
}

/** PUT /orders/:id/route-ops（ops 空数组 = 恢复标准路线，清空该任务 override） */
export function saveOrderRouteOps(orderId: number, data: { tasks: Array<{ task_id: number; route_id?: number | null; ops: OrderRouteOpDraft[] }> }): OrderRouteOpsPayload {
  const order = db.orders.find((o) => o.id === orderId)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  const lineIds = new Set((db.orderLines[orderId] ?? []).map((l) => l.id))
  for (const item of data.tasks) {
    const task = db.tasks.find((t) => t.id === item.task_id)
    if (!task || !lineIds.has(task.order_line_id)) continue
    if (item.route_id !== undefined && item.route_id !== null) {
      task.route_id = item.route_id
    }
    if (item.ops.length > 0) {
      routeOpsOverride.set(task.id, clone(item.ops))
    } else {
      routeOpsOverride.delete(task.id)
    }
  }
  pushAudit('order', 'order_route_ops_edit', 'order', orderId, {
    tasks: data.tasks.map((t) => ({ task_id: t.task_id, route_id: t.route_id ?? null, ops_count: t.ops.length })),
  })
  return getOrderRouteOps(orderId)
}

/** GET /orders/:id/pick-list（按订单行 + 已发布 BOM 计算需求量；仓库批次/库存未接入时置 null） */
export function getOrderPickList(orderId: number): OrderPickListPayload {
  const order = db.orders.find((o) => o.id === orderId)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  const lines = (db.orderLines[orderId] ?? []).map((line) => {
    const bom = db.boms.find((b) => b.product_id === line.product_id && b.status === 'published') ?? null
    const bomLines = bom ? (db.bomLines[bom.id] ?? []) : []
    return {
      line_no: line.line_no,
      product_id: line.product_id,
      product_code: line.product?.code ?? null,
      product_name: line.product?.name ?? null,
      qty: line.qty,
      bom_id: bom?.id ?? null,
      bom_version: bom?.version ?? null,
      bom_status: bom?.status ?? null,
      items: bomLines.map((bl, idx) => {
        const required = calcRequiredQty(bl.qty, line.qty, bl.loss_rate)
        return {
          seq: idx + 1,
          item_code: bl.item_code,
          item_name: bl.item_name,
          spec: null,
          unit: bl.unit,
          unit_qty: Number(bl.qty),
          loss_rate: Number(bl.loss_rate),
          required_qty: required,
          issued_qty: 0,
          remain_qty: required,
          batch_no: null,
          available_qty: null,
          is_key: bl.is_key,
        }
      }),
    }
  })
  return {
    order_id: order.id,
    order_no: order.order_no,
    lines,
    summary: { item_count: lines.reduce((s, l) => s + l.items.length, 0) },
  }
}

// ==================== BOM / 模具 ====================

export function publishBom(id: number): { id: number; status: string } {
  const bom = db.boms.find((b) => b.id === id)
  if (!bom) throw new MockApiError(404, 404, null, 'BOM 不存在')
  const conflict = db.boms.find(
    (b) => b.id !== id && b.product_id === bom.product_id && (b.status === 'published' || b.status === 'disabled'),
  )
  if (conflict) {
    throw new MockApiError(422, 422, null, `该产品已有已发布版本 ${conflict.version}，请先退回该版本后再发布`)
  }
  bom.status = 'published'
  bom.k3_push_status = 'success'
  bom.published_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
  pushAudit('plm', 'publish_bom', 'bom', id, { version: bom.version, k3_push: 'success' })
  return { id, status: 'published' }
}

export function revokeBom(id: number): { id: number; status: string } {
  const bom = db.boms.find((b) => b.id === id)
  if (!bom) throw new MockApiError(404, 404, null, 'BOM 不存在')
  bom.status = 'draft'
  bom.published_at = null
  bom.revoked_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
  pushAudit('plm', 'revoke_bom', 'bom', id, { version: bom.version })
  return { id, status: 'draft' }
}

export function disableBom(id: number): { id: number; status: string } {
  const bom = db.boms.find((b) => b.id === id)
  if (!bom) throw new MockApiError(404, 404, null, 'BOM 不存在')
  bom.status = 'disabled'
  bom.disabled_at = dayjs().format('YYYY-MM-DD HH:mm:ss')
  pushAudit('plm', 'disable_bom', 'bom', id, { version: bom.version })
  return { id, status: 'disabled' }
}

export function enableBom(id: number): { id: number; status: string } {
  const bom = db.boms.find((b) => b.id === id)
  if (!bom) throw new MockApiError(404, 404, null, 'BOM 不存在')
  bom.status = 'published'
  bom.disabled_at = null
  pushAudit('plm', 'enable_bom', 'bom', id, { version: bom.version })
  return { id, status: 'published' }
}

export function deleteBom(id: number): null {
  const idx = db.boms.findIndex((b) => b.id === id)
  if (idx < 0) throw new MockApiError(404, 404, null, 'BOM 不存在')
  db.boms.splice(idx, 1)
  delete db.bomLines[id]
  pushAudit('plm', 'delete_bom', 'bom', id, {})
  return null
}

export function maintainMold(id: number, data: { type: 'maintain' | 'repair'; at?: string; cost?: number; vendor?: string; remark?: string }): unknown {
  const mold = db.molds.find((m) => m.id === id)
  if (!mold) throw new MockApiError(404, 404, null, '模具不存在')
  mold.last_maintain_at = data.at ?? dayjs().format('YYYY-MM-DD HH:mm:ss')
  mold.next_maintain_at = dayjs(mold.last_maintain_at).add(mold.maintain_cycle_days ?? 30, 'day').format('YYYY-MM-DD HH:mm:ss')
  mold.maintain_due = false
  mold.status = data.type === 'repair' ? 'repair' : mold.status
  pushAudit('plm', 'mold_maintain', 'mold', id, { type: data.type, remark: data.remark ?? null })
  return { id, next_maintain_at: mold.next_maintain_at }
}

/** 查找适用模具（产品/机台三匹配演示） */
export function compatibleMolds(machineId: number): Mold[] {
  return db.molds.filter((m) => m.machines?.some((mm) => mm.id === machineId) ?? false)
}

/**
 * 订单工序进度（与后端 OrderProgressController::show 同构）
 *
 * 演示 mock 简化：
 * - 数据来源：db.tasks（任务） + db.schedules（排程，按 task_id 关联）+ db.reports（报工）
 * - 排程按 op_type 分段聚合：plan_qty = 该 op_type 所有 schedule.qty 之和
 *   done_qty = 该 op_type schedule.done_qty 之和（演示口径，与真实报工聚合存在差异）
 * - 总进度 percent = sum(done) / sum(plan)
 * - 待排产订单（无 schedule）返回空 stages，前端组件不渲染
 */
export function getOrderProgress(orderId: number): { stages: ProgressStage[]; percent: number } {
  const order = db.orders.find((o) => o.id === orderId)
  if (!order) throw new MockApiError(404, 404, null, '订单不存在')
  const lines = db.orderLines[orderId] ?? []
  const lineIds = new Set(lines.map((l) => l.id))
  const taskIds = new Set(db.tasks.filter((t) => lineIds.has(t.order_line_id)).map((t) => t.id))
  // 按 op_type 聚合 stage（直接走 db.schedules，不依赖 task.schedules 字段填充）
  const stageMap = new Map<string, ProgressStage>()
  for (const sc of db.schedules) {
    if (!taskIds.has(sc.task_id)) continue
    const key = sc.op_type
    const plan = Number(sc.qty ?? 0)
    const done = Number(sc.done_qty ?? 0)
    const ex = stageMap.get(key)
    if (ex) {
      ex.plan_qty += plan
      ex.done_qty += done
      ex.percent = ex.plan_qty > 0 ? Math.min(100, Math.round((ex.done_qty / ex.plan_qty) * 100)) : 0
      ex.seq = Math.min(ex.seq ?? sc.seq, sc.seq)
    } else {
      stageMap.set(key, {
        op_type: key,
        op_label: sc.op_name ?? sc.op_type,
        plan_qty: plan,
        done_qty: done,
        percent: plan > 0 ? Math.min(100, Math.round((done / plan) * 100)) : 0,
        seq: sc.seq,
      })
    }
  }
  const stages = Array.from(stageMap.values()).sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0))
  const totalPlan = stages.reduce((s, x) => s + x.plan_qty, 0)
  const totalDone = stages.reduce((s, x) => s + x.done_qty, 0)
  const percent = totalPlan > 0 ? Math.min(100, Math.round((totalDone / totalPlan) * 100)) : 0
  return { stages, percent }
}

/**
 * 单任务（工单）工序进度（demo 模式订单详情产品行用）
 *
 * 与 getOrderProgress 同构，但只聚合指定 task_id 的排程（detail 接口 lines.tasks 附加 progress）。
 */
export function getTaskProgress(taskId: number): { stages: ProgressStage[]; percent: number } {
  const stageMap = new Map<string, ProgressStage>()
  for (const sc of db.schedules) {
    if (sc.task_id !== taskId) continue
    const key = sc.op_type
    const plan = Number(sc.qty ?? 0)
    const done = Number(sc.done_qty ?? 0)
    const ex = stageMap.get(key)
    if (ex) {
      ex.plan_qty += plan
      ex.done_qty += done
      ex.percent = ex.plan_qty > 0 ? Math.min(100, Math.round((ex.done_qty / ex.plan_qty) * 100)) : 0
      ex.seq = Math.min(ex.seq ?? sc.seq, sc.seq)
    } else {
      stageMap.set(key, {
        op_type: key,
        op_label: sc.op_name ?? sc.op_type,
        plan_qty: plan,
        done_qty: done,
        percent: plan > 0 ? Math.min(100, Math.round((done / plan) * 100)) : 0,
        seq: sc.seq,
      })
    }
  }
  const stages = Array.from(stageMap.values()).sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0))
  const totalPlan = stages.reduce((s, x) => s + x.plan_qty, 0)
  const totalDone = stages.reduce((s, x) => s + x.done_qty, 0)
  const percent = totalPlan > 0 ? Math.min(100, Math.round((totalDone / totalPlan) * 100)) : 0
  return { stages, percent }
}
