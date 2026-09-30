/**
 * 演示模式 · Mock 路由分发器
 *
 * 与 axios 层约定：config.method + config.url（去掉 /api 前缀）→ 返回业务 data；
 * 业务失败抛 MockApiError（axios 层将其包装为与真实 axios 错误一致的
 * {response:{status,data:{code,message,data}}}，保证页面 409 冲突等分支可复用）。
 */
import type { AxiosRequestConfig } from 'axios'
import dayjs from 'dayjs'
import {
  autoAccept,
  autoPoll,
  autoSolve,
  calcEnd,
  closeRedLight,
  compatibleMolds,
  createOrder,
  splitTask,
  confirmOrderRoute,
  updateOrderRoutes,
  createStockIn,
  advanceDevelop,
  createDevelop,
  createException,
  db,
  deleteOrder,
  getOrderProgress,
  getTaskProgress,
  getOrderRouteOps,
  saveOrderRouteOps,
  getOrderPickList,
  handleException,
  insertConfirm,
  insertPreview,
  startOrder,
  pauseOrder,
  abortOrder,
  closeOrder,
  maintainMold,
  manualSave,
  operationAdjust,
  MockApiError,
  paginate,
  publishBom,
  revokeBom,
  disableBom,
  enableBom,
  deleteBom,
  pushStockIn,
  refreshRedLights,
  recalculateAllSchedules,
  recalculateSchedulesForMachine,
  refreshAllMockSuggestions,
  acceptSuggestion,
  dismissSuggestion,
  submitReport,
  decorateReportLedger,
  dailyReportContext,
  availableQty,
  mockEstimate,
  createQuoteFromEstimate,
  adjustQuotePrice,
  transitionQuoteStatus,
  upsertMaterialPrice,
  upsertProcessRate,
  upsertQuoteRule,
} from './store'
import { clone } from './store'
import type { TaskSchedule, Order, ProgressStage } from '@/types/schedule'
import type { WorkReport } from '@/types/production'
import type { MaterialPrice, ProcessRate, Quote, QuoteEstimate } from '@/types/quote'
import { day as d, dayHour, dayTime, INS_NO, splitWorkingSegments } from './data'

/** 从 config.url 提取路径（去掉 /api 前缀） */
function pathOf(config: AxiosRequestConfig): string {
  const url = config.url ?? ''
  return url.startsWith('/api') ? url.slice(4) : url
}

/** 从 Query 参数中取值（调用方传入 pageParams(config) 结果） */
function param(params: Record<string, unknown>, key: string): unknown {
  return params?.[key] ?? ''
}

function numIds(idText: string[]): number[] {
  return idText.map(Number).filter((n) => Number.isFinite(n))
}

/** 模糊筛选 */
function matchKeyword(text: string | null | undefined, kw: string): boolean {
  if (!kw) return true
  return (text ?? '').toLowerCase().includes(kw.toLowerCase())
}

/** 甘特图只展示冲减后的有效报工段；负记录自身的录入时间不作为作业时间。 */
function effectivePositiveReports(reports: WorkReport[]): WorkReport[] {
  const positiveReports = reports
    .filter((report) => report.qty > 0)
    .sort((a, b) => String(a.start_time ?? a.report_time).localeCompare(String(b.start_time ?? b.report_time)))
    .map((report) => clone(report))
  let correctionQty = Math.abs(reports.filter((report) => report.qty < 0).reduce((sum, report) => sum + report.qty, 0))

  for (let index = positiveReports.length - 1; index >= 0 && correctionQty > 0; index -= 1) {
    const report = positiveReports[index]
    const deduct = Math.min(report.qty, correctionQty)
    report.qty -= deduct
    correctionQty -= deduct
    if (report.qty <= 0) positiveReports.splice(index, 1)
  }

  return positiveReports
}

/** 报工统计汇总：沿用报工流水的实际作业日期和工序/机台关联。 */
function buildReportSummary(filters: { dimension?: string; date_from?: string; date_to?: string; keyword?: string; machine_keyword?: string } = {}) {
  const from = (filters.date_from ?? '').trim()
  const to = (filters.date_to ?? '').trim()
  const keyword = (filters.keyword ?? '').trim().toLowerCase()
  const machineKeyword = (filters.machine_keyword ?? '').trim().toLowerCase()
  const dimension = filters.dimension ?? 'detail'
  // 与真实接口一致：历史冲减记录沿用原正报工的作业日期、班次和资源，
  // 避免同一笔正负流水被汇总到两个时间分组。
  const rows = decorateReportLedger(db.reports)
    .map((report) => {
      const task = db.tasks.find((item) => item.id === report.task_id)
      const schedule = report.schedule_id ? db.schedules.find((item) => item.id === report.schedule_id) : null
      const machine = (report.machine_id ? db.machines.find((item) => item.id === report.machine_id) : null)
        ?? (schedule?.machine_id ? db.machines.find((item) => item.id === schedule.machine_id) : null)
      const order = Object.entries(db.orderLines).find(([, lines]) => lines.some((line) => line.id === task?.order_line_id))
      const orderData = order ? db.orders.find((item) => item.id === Number(order[0])) : null
      const workDate = report.work_date ?? report.report_time?.slice(0, 10) ?? null
      return { report, task, schedule, machine, order: orderData, workDate }
    })
    .filter(({ task, machine, order, workDate }) => {
      if (!task || !workDate) return false
      if (from && workDate < from) return false
      if (to && workDate > to) return false
      if (machineKeyword && !matchKeyword(`${machine?.code ?? ''} ${machine?.name ?? ''}`, machineKeyword)) return false
      return !keyword || matchKeyword(`${task.task_no} ${task.product?.code ?? ''} ${task.product?.name ?? ''} ${order?.order_no ?? ''}`, keyword)
    })
  const groups = new Map<string, typeof rows>()
  for (const row of rows) {
    const key = `${row.workDate}|${row.report.task_id}|${row.report.schedule_id ?? 'task'}|${row.machine?.id ?? 'none'}`
    const group = groups.get(key) ?? []
    group.push(row)
    groups.set(key, group)
  }
  const lastSeqByTask = new Map<number, number>()
  for (const schedule of db.schedules) {
    const current = lastSeqByTask.get(schedule.task_id) ?? 0
    lastSeqByTask.set(schedule.task_id, Math.max(current, schedule.seq))
  }
  const isFinishedReport = (row: (typeof rows)[number]) => row.report.schedule_id == null
    || row.schedule?.seq === lastSeqByTask.get(row.report.task_id)
  const items = [...groups.values()].map((group) => {
    const first = group[0]
    const qualifiedQty = group.reduce((sum, row) => sum + row.report.qty, 0)
    const defectQty = group.reduce((sum, row) => sum + (row.report.defect_qty ?? 0), 0)
    const plannedQty = first.schedule?.qty ?? first.task?.qty ?? 0
    const lastReportTime = [...group].sort((a, b) => String(b.report.report_time).localeCompare(String(a.report.report_time)))[0]?.report.report_time ?? null
    return {
      work_date: first.workDate,
      task_id: first.report.task_id,
      task_no: first.task?.task_no ?? null,
      order_no: first.order?.order_no ?? null,
      product: first.task?.product ? { id: first.task.product.id, code: first.task.product.code, name: first.task.product.name } : null,
      operation: first.schedule ? { seq: first.schedule.seq, name: first.schedule.op_name ?? first.schedule.op_type } : null,
      machine: first.machine ? { id: first.machine.id, code: first.machine.code, name: first.machine.name } : null,
      planned_qty: plannedQty,
      qualified_qty: qualifiedQty,
      defect_qty: defectQty,
      total_qty: qualifiedQty + defectQty,
      completion_pct: plannedQty > 0 ? Math.min(100, Math.max(0, Math.round(qualifiedQty / plannedQty * 1000) / 10)) : 0,
      report_count: group.length,
      normal_count: group.filter((row) => row.report.sign === 1).length,
      correction_count: group.filter((row) => row.report.sign === -1).length,
      last_report_time: lastReportTime,
    }
  }).sort((a, b) => `${b.work_date}|${b.last_report_time}|${b.task_no}`.localeCompare(`${a.work_date}|${a.last_report_time}|${a.task_no}`))

  const resourceOf = (row: (typeof rows)[number]) => row.report.machine_id
    ? db.machines.find((machine) => machine.id === row.report.machine_id) ?? null
    : row.machine ? db.machines.find((machine) => machine.id === row.machine?.id) ?? null : null
  const operationOf = (row: (typeof rows)[number]) => row.schedule
    ? { seq: row.schedule.seq, name: row.schedule.op_name ?? row.schedule.op_type }
    : null
  const workshopOf = (row: (typeof rows)[number]) => {
    const machine = resourceOf(row)
    return machine ? { id: machine.workshop_id ?? 0, code: machine.workshop, name: machine.workshop_name ?? machine.workshop } : null
  }
  const lastReportOf = (group: (typeof rows)) => [...group].sort((a, b) => String(b.report.report_time).localeCompare(String(a.report.report_time)))[0]?.report.report_time ?? null
  const commonAggregate = (group: (typeof rows)) => {
    const qualifiedQty = group.reduce((sum, row) => sum + row.report.qty, 0)
    const defectQty = group.reduce((sum, row) => sum + (row.report.defect_qty ?? 0), 0)
    const correctionRows = group.filter((row) => row.report.sign === -1)
    return {
      work_date: group[0]?.workDate ?? null,
      shift_code: group[0]?.report.shift_code ?? 'unknown',
      operation: operationOf(group[0]),
      machine: group[0]?.machine ?? null,
      workshop: workshopOf(group[0]),
      qualified_qty: qualifiedQty,
      defect_qty: defectQty,
      total_qty: qualifiedQty + defectQty,
      report_count: group.length,
      correction_count: correctionRows.length,
      correction_qty: Math.abs(correctionRows.reduce((sum, row) => sum + row.report.qty + (row.report.defect_qty ?? 0), 0)),
      task_count: new Set(group.map((row) => row.report.task_id)).size,
      last_report_time: lastReportOf(group),
    }
  }
  const groupsBy = (keyOf: (row: (typeof rows)[number]) => string) => {
    const grouped = new Map<string, (typeof rows)>()
    for (const row of rows) {
      const key = keyOf(row)
      grouped.set(key, [...(grouped.get(key) ?? []), row])
    }
    return [...grouped.values()]
  }
  let dimensionItems: unknown[] = items
  if (dimension === 'daily') {
    dimensionItems = groupsBy((row) => `${row.workDate}|${row.report.shift_code ?? 'unknown'}|${workshopOf(row)?.id ?? 'none'}|${row.schedule?.seq ?? 'task'}|${resourceOf(row)?.id ?? 'none'}`)
      .map((group) => ({
        ...commonAggregate(group),
        valid_time_count: group.filter((row) => row.report.start_time && row.report.end_time && row.report.start_time !== row.report.end_time).length,
        invalid_time_count: group.filter((row) => !row.report.start_time || !row.report.end_time || row.report.start_time === row.report.end_time).length,
      }))
  } else if (dimension === 'progress') {
    dimensionItems = groupsBy((row) => `${row.task?.product_id ?? 'none'}|${row.schedule?.seq ?? 'task'}`).map((group) => {
      const aggregate = commonAggregate(group)
      const taskPlan = new Map<string, number>()
      for (const row of group) taskPlan.set(`${row.report.task_id}|${row.report.schedule_id ?? 'task'}`, row.schedule?.qty ?? row.task?.qty ?? 0)
      const plannedQty = [...taskPlan.values()].reduce((sum, value) => sum + value, 0)
      return {
        ...aggregate,
        product: group[0]?.task?.product ? { id: group[0].task.product.id, code: group[0].task.product.code, name: group[0].task.product.name } : null,
        planned_qty: plannedQty,
        remaining_qty: Math.max(0, plannedQty - aggregate.qualified_qty),
        over_qty: Math.max(0, aggregate.qualified_qty - plannedQty),
        completion_pct: plannedQty > 0 ? Math.min(100, Math.max(0, Math.round(aggregate.qualified_qty / plannedQty * 1000) / 10)) : 0,
        order_count: new Set(group.map((row) => row.order?.id).filter(Boolean)).size,
      }
    })
  } else if (dimension === 'delivery') {
    dimensionItems = groupsBy((row) => `${row.task?.delivery_date ?? row.order?.delivery_date ?? '未设置交期'}|${row.order?.customer_name ?? '未设置客户'}`).map((group) => {
      const aggregate = commonAggregate(group)
      const taskRows = new Map<number, (typeof rows)[number]>()
      for (const row of group) taskRows.set(row.report.task_id, row)
      const orderQty = [...taskRows.values()].reduce((sum, row) => sum + (row.task?.qty ?? 0), 0)
      const finishedQty = group.filter(isFinishedReport).reduce((sum, row) => sum + row.report.qty, 0)
      const remainingQty = Math.max(0, orderQty - finishedQty)
      const due = group[0]?.task?.delivery_date ?? group[0]?.order?.delivery_date ?? null
      const riskStatus = !due ? '未设置交期' : remainingQty === 0 ? '已完成' : due < dayjs().format('YYYY-MM-DD') ? '已逾期' : dayjs(due).diff(dayjs(), 'day') <= 3 ? '临近交期' : '正常'
      return {
        ...aggregate,
        delivery_date: due,
        customer_name: group[0]?.order?.customer_name ?? null,
        order_count: new Set(group.map((row) => row.order?.id).filter(Boolean)).size,
        product_count: new Set(group.map((row) => row.task?.product_id).filter(Boolean)).size,
        order_qty: orderQty,
        finished_qualified_qty: finishedQty,
        remaining_qty: remainingQty,
        risk_status: riskStatus,
      }
    })
  } else if (dimension === 'resource') {
    dimensionItems = groupsBy((row) => `${workshopOf(row)?.id ?? 'none'}|${resourceOf(row)?.id ?? 'none'}|${row.schedule?.seq ?? 'task'}|${row.report.shift_code ?? 'unknown'}`).map((group) => {
      const aggregate = commonAggregate(group)
      const validRows = group.filter((row) => row.report.start_time && row.report.end_time && row.report.start_time !== row.report.end_time)
      const actualHours = validRows.reduce((sum, row) => sum + Math.max(0, dayjs(row.report.end_time).diff(dayjs(row.report.start_time), 'minute') / 60) * (row.report.sign === -1 ? -1 : 1), 0)
      const capacity = resourceOf(group[0])?.capacity_per_hour ?? 0
      const capacityQty = Math.max(0, actualHours) * capacity
      return {
        ...aggregate,
        actual_hours: Math.round(actualHours * 100) / 100,
        capacity_qty: Math.round(capacityQty * 10) / 10,
        utilization_pct: capacityQty > 0 ? Math.round(Math.max(0, aggregate.qualified_qty) / capacityQty * 1000) / 10 : null,
        valid_time_count: validRows.length,
        invalid_time_count: group.length - validRows.length,
        time_quality: validRows.length === group.length ? '有效时间完整' : '含单点/缺失时间',
      }
    })
  } else if (dimension === 'quality') {
    dimensionItems = groupsBy((row) => `${row.workDate}|${workshopOf(row)?.id ?? 'none'}|${row.schedule?.seq ?? 'task'}`).map((group) => {
      const aggregate = commonAggregate(group)
      return { ...aggregate, defect_rate: aggregate.total_qty > 0 ? Math.round(aggregate.defect_qty / aggregate.total_qty * 1000) / 10 : 0 }
    })
  }

  return {
    dimension,
    totals: {
      row_count: dimensionItems.length,
      task_count: new Set(rows.map((row) => row.report.task_id)).size,
      report_count: rows.length,
      qualified_qty: rows.reduce((sum, row) => sum + row.report.qty, 0),
      defect_qty: rows.reduce((sum, row) => sum + (row.report.defect_qty ?? 0), 0),
      total_qty: rows.reduce((sum, row) => sum + row.report.qty + (row.report.defect_qty ?? 0), 0),
      finished_qualified_qty: rows.filter(isFinishedReport).reduce((sum, row) => sum + row.report.qty, 0),
      finished_defect_qty: rows.filter(isFinishedReport).reduce((sum, row) => sum + (row.report.defect_qty ?? 0), 0),
      correction_count: rows.filter((row) => row.report.sign === -1).length,
    },
    items: dimensionItems,
  }
}

/** 保存产品后回填默认工艺/默认模具引用（演示模式内存库展示用，与后端返回口径一致） */
function enrichProductRefs(product: import('@/types').Product): import('@/types').Product {
  const p = { ...product }
  if (p.default_route_id) {
    const r = db.routes.find((rr) => rr.id === p.default_route_id)
    if (r) {
      p.default_route = { id: r.id, name: r.name, version: r.version }
    }
  } else {
    p.default_route = null
  }
  if (p.default_mold_id) {
    const m = db.molds.find((mm) => mm.id === p.default_mold_id)
    if (m) p.default_mold = { id: m.id, code: m.code, name: m.name }
  } else {
    p.default_mold = null
  }
  return p
}

function pageParams(config: AxiosRequestConfig): Record<string, unknown> {
  return ((config.params ?? {}) as Record<string, unknown>)
}

/** 甘特时间线数据（按 start/end 过滤排程，按机台分组） */
function buildTimeline(start: string, end: string) {
  const dayLabels: string[] = []
  const s = dayjs(start)
  const e = dayjs(end)
  for (let cur = s; !cur.isAfter(e); cur = cur.add(1, 'day')) {
    dayLabels.push(cur.format('YYYY-MM-DD'))
  }
  const inWindow = (sch: TaskSchedule): boolean => {
    const ps = dayjs(sch.plan_start)
    const pe = dayjs(sch.plan_end)
    return ps.isBefore(e.add(1, 'day')) && pe.isAfter(s.subtract(6, 'hour'))
  }
  const machines = db.machines.map((m) => ({
    id: m.id,
    code: m.code,
    name: m.name,
    type: m.type,
    workshop: m.workshop,
    capacity_per_hour: m.capacity_per_hour,
    shift_mode: m.shift_mode,
    status: m.status,
    status_label: m.is_active === 0 && m.status === 'running'
      ? '已停用'
      : m.status === 'maintenance' ? '维修中' : m.status === 'stopped' ? '已停用' : '运行中',
    is_active: m.is_active === 1,
    schedules: db.schedules
      .filter((sch) => sch.machine_id === m.id && inWindow(sch))
      .map((sch) => {
        const lastScheduleId = db.schedules
          .filter((item) => item.task_id === sch.task_id)
          .sort((a, b) => b.seq - a.seq)[0]?.id
        const reportRows = db.reports.filter((report) =>
          report.schedule_id === sch.id || (report.schedule_id == null && sch.id === lastScheduleId),
        )
        const effectiveReports = effectivePositiveReports(reportRows)
        const scheduleSnapshot = clone(sch)
        const snapshotDoneQty = scheduleSnapshot.done_qty ?? 0
        scheduleSnapshot.has_report_history = db.reports.some((report) => report.task_id === sch.task_id)
        scheduleSnapshot.actual_start = effectiveReports[0]?.start_time ?? effectiveReports[0]?.report_time
          ?? (reportRows.length ? null : scheduleSnapshot.actual_start)
        scheduleSnapshot.actual_end = effectiveReports.length && snapshotDoneQty >= scheduleSnapshot.qty
          ? effectiveReports[effectiveReports.length - 1]?.end_time ?? effectiveReports[effectiveReports.length - 1]?.report_time ?? null
          : null
        return {
          ...scheduleSnapshot,
          actual_segments: effectiveReports.flatMap((report) => splitWorkingSegments(
            report.start_time ?? report.report_time,
            report.end_time ?? report.report_time,
            db.machines.find((item) => item.id === (report.machine_id ?? sch.machine_id))?.shift_mode ?? 'day',
          ).map((piece, index) => ({
            ...piece,
            qty: index === 0 ? report.qty : 0,
          }))),
        }
      }),
  }))
  return {
    start,
    end,
    shift: {
      two_shift: { shift1: { name: '白班', start: '08:00', end: '20:00' }, shift2: { name: '夜班', start: '20:00', end: '08:00' } },
      day: { start: '08:00', end: '17:00' },
    },
    machines,
    day_labels: dayLabels,
  }
}

/** 排产结果明细（按 task_id） */
function buildDetail(taskId: number) {
  const task = db.tasks.find((t) => t.id === taskId)
  if (!task) throw new MockApiError(404, 404, null, '任务不存在')
  const scheds = db.schedules.filter((s) => s.task_id === taskId)
  const line = Object.values(db.orderLines).flat().find((item) => item.id === task.order_line_id)
  const order = line ? db.orders.find((item) => item.id === line.order_id) : null
  const statusLabels: Record<string, string> = { unscheduled: '未排产', scheduled: '已排产', in_progress: '进行中', paused: '已暂停', done: '已完成' }
  return {
    task: {
      id: task.id,
      task_no: task.task_no,
      product_code: task.product?.code,
      product_name: task.product?.name,
      qty: task.qty,
      done_qty: task.done_qty,
      status: task.status,
      status_label: statusLabels[task.status] ?? task.status,
      priority: task.priority,
      delivery_date: task.delivery_date,
      plan_start: task.plan_start,
      plan_end: task.plan_end,
      deviation_pct: task.deviation_pct,
      is_locked: task.is_locked,
      mold_code: task.mold?.code ?? null,
      order_no: order?.order_no ?? '',
    },
    schedules: scheds.map((s) => {
      const machine = db.machines.find((m) => m.id === s.machine_id)
      const mold = s.mold_id ? db.molds.find((m) => m.id === s.mold_id) : null
      const reportedQty = s.seq === 1
        ? Math.max(0, db.reports.filter((report) => report.task_id === task.id).reduce((sum, report) => sum + report.qty, 0))
        : Math.max(0, s.done_qty ?? 0)
      // 有实际结束时间即代表该工序已完成；实际开始但未结束时才按报工数量展示进度。
      const completedQty = Math.min(s.qty, s.actual_end ? s.qty : reportedQty)
      const lastScheduleId = scheds.slice().sort((a, b) => b.seq - a.seq)[0]?.id
      const scheduleReports = db.reports
        .filter((report) => {
          return report.schedule_id === s.id || (report.schedule_id == null && s.id === lastScheduleId)
        })
        .sort((a, b) => String(a.start_time ?? a.report_time).localeCompare(String(b.start_time ?? b.report_time)))
      const actualSegments = effectivePositiveReports(scheduleReports)
        .flatMap((report) => splitWorkingSegments(
          report.start_time ?? report.report_time,
          report.end_time ?? report.report_time,
          machine?.shift_mode ?? 'day',
        ).map((piece, index) => ({
          ...piece,
          qty: index === 0 ? report.qty : 0,
        })))
      const reportLogs = db.reports
        .filter((report) => report.schedule_id === s.id || (report.schedule_id == null && s.id === lastScheduleId))
        .sort((a, b) => String(a.start_time ?? a.report_time).localeCompare(String(b.start_time ?? b.report_time)))
        .map((report) => ({
          id: report.id,
          qty: report.qty,
          defect_qty: report.defect_qty,
          sign: report.sign,
          report_time: report.report_time,
          start_time: report.start_time ?? report.report_time,
          end_time: report.end_time ?? report.report_time,
          work_segments: splitWorkingSegments(
            report.start_time ?? report.report_time,
            report.end_time ?? report.report_time,
            machine?.shift_mode ?? 'day',
          ),
          work_date: report.work_date ?? null,
          shift_code: report.shift_code ?? null,
          source: report.source,
          source_label: report.source_label ?? null,
          operator_name: report.operator?.name ?? null,
          worker_name: report.worker?.name ?? null,
          machine_code: report.machine?.code ?? machine?.code ?? null,
          remark: report.remark ?? null,
        }))
      return {
        id: s.id,
        seq: s.seq,
        op_type: s.op_type,
        op_name: s.op_name ?? (machine?.type === 'blow' ? '吹塑成型' : '注塑成型'),
        machine_id: s.machine_id,
        machine_code: machine?.code ?? null,
        machine_name: machine?.name ?? null,
        workshop_name: machine?.workshop ?? null,
        mold_id: s.mold_id,
        plan_start: s.plan_start,
        plan_end: s.plan_end,
        forecast_start: s.forecast_start ?? null,
        forecast_end: s.forecast_end ?? null,
        actual_start: s.actual_start ?? null,
        actual_end: s.actual_end ?? null,
        actual_segments: actualSegments,
        report_logs: reportLogs,
        is_frozen: s.is_frozen ?? 0,
        changeover_minutes: s.changeover_minutes ?? 0,
        qty: s.qty,
        reported_qty: completedQty,
        progress_pct: s.qty > 0 ? Math.round((completedQty / s.qty) * 100) : 0,
        status: s.status,
        source: s.source,
        is_interrupted: s.is_interrupted ?? 0,
        done_qty: s.done_qty ?? task.done_qty,
        is_overlap: s.is_overlap ?? 0,
        note: s.note ?? null,
        mold_code: mold?.code ?? null,
      }
    }),
  }
}

/** 已排产任务明细列表（默认全部，支持按任务号筛选） */
function buildDetails(filters: { date?: string; keyword?: string; order_no?: string; product?: string; machine?: string } = {}) {
  const normalized = (filters.keyword ?? '').trim().toLowerCase()
  const orderKeyword = (filters.order_no ?? '').trim().toLowerCase()
  const productKeyword = (filters.product ?? '').trim().toLowerCase()
  const machineKeyword = (filters.machine ?? '').trim().toLowerCase()
  const date = (filters.date ?? '').trim()
  const items = db.tasks
    .filter((task) => {
      const schedules = db.schedules.filter((schedule) => schedule.task_id === task.id)
      const line = Object.values(db.orderLines).flat().find((item) => item.id === task.order_line_id)
      const order = line ? db.orders.find((item) => item.id === line.order_id) : null
      const machineMatches = schedules.some((schedule) => {
        const machine = db.machines.find((item) => item.id === schedule.machine_id)
        const text = `${machine?.code ?? ''} ${machine?.name ?? ''}`.toLowerCase()
        return !machineKeyword || text.includes(machineKeyword)
      })
      const dateMatches = schedules.some((schedule) => {
        const start = String(schedule.plan_start ?? '').slice(0, 10)
        const end = String(schedule.plan_end ?? '').slice(0, 10)
        return !date || (start <= date && end >= date)
      })
      const orderMatches = !orderKeyword || String(order?.order_no ?? '').toLowerCase().includes(orderKeyword)
      const productText = `${task.product?.code ?? ''} ${task.product?.name ?? ''}`.toLowerCase()
      const productMatches = !productKeyword || productText.includes(productKeyword)
      return schedules.length > 0 && dateMatches && orderMatches && productMatches && machineMatches
    })
    .filter((task) => !normalized || task.task_no.toLowerCase().includes(normalized))
    .sort((a, b) => String(a.plan_start ?? '').localeCompare(String(b.plan_start ?? '')) || a.id - b.id)
    .map((task) => {
      const detail = buildDetail(task.id)
      if (date) {
        detail.schedules = detail.schedules.filter((schedule) =>
          schedule.plan_start <= `${date} 23:59:59` && schedule.plan_end >= `${date} 00:00:00`)
      }
      return detail
    })

  return { total: items.length, items }
}

/** 演示用户（映射角色权限） */
function demoUser(employeeNo: string): { user: { id: number; employee_no: string; name: string; phone: string | null; job_type: string; roles: string[]; permissions: string[] } } | null {
  const u = db.users.find((x) => x.employee_no === employeeNo) ?? (employeeNo === 'admin' ? db.users[0] : null)
  if (!u) return null
  const roles = db.roles.filter((r) => u.roles.includes(r.name))
  const permissions = Array.from(new Set(roles.flatMap((r) => r.permissions)))
  const allRoles = db.roles.map((r) => r.name)
  const isAdmin = u.roles.includes('admin')
  return {
    user: {
      id: u.id,
      employee_no: u.employee_no,
      name: u.name,
      phone: u.phone,
      job_type: u.job_type,
      roles: isAdmin ? allRoles : u.roles,
      permissions: isAdmin ? Array.from(new Set(db.roles.flatMap((r) => r.permissions))) : permissions,
    },
  }
}

/** 主分发入口：返回业务 data；抛 MockApiError 表示业务失败 */
export function dispatch(config: AxiosRequestConfig): unknown {
  const method = (config.method ?? 'get').toLowerCase()
  const path = pathOf(config)
  const params = pageParams(config)

  // ==================== 健康检查 ====================
  if (method === 'get' && path === '/health') {
    return { app: '虹越智造平台（演示模式）', version: 'demo-1.0', phase: 'P4-DEMO', time: dayjs().format('YYYY-MM-DD HH:mm:ss') }
  }

  // ==================== AUTH ====================
  if (method === 'post' && path === '/auth/login') {
    const body = (config.data ?? {}) as { employee_no?: string; password?: string }
    const emp = body.employee_no ?? 'admin'
    const demo = demoUser(emp)
    if (!demo) {
      throw new MockApiError(401, 401, null, '工号不存在（演示账号：admin / E001 / P001 / L001 / W001 / S001）')
    }
    if (body.password !== '123456') {
      throw new MockApiError(401, 401, null, '密码错误（演示密码统一 123456）')
    }
    return { token: `demo-token-${emp}-${Date.now()}`, token_type: 'Bearer', expires_in: 86400, ...demo }
  }
  if (method === 'get' && path === '/auth/me') {
    const stored = localStorage.getItem('hy_user')
    if (stored) {
      try {
        return JSON.parse(stored) as unknown
      } catch {
        // fallthrough
      }
    }
    return demoUser('admin')?.user
  }
  if (method === 'post' && path === '/auth/logout') {
    return null
  }
  if (method === 'post' && path === '/auth/bind-employee') {
    const body = (config.data ?? {}) as { employee_no?: string }
    return { token: `demo-token-${body.employee_no ?? 'wx'}`, token_type: 'Bearer', expires_in: 86400, ...demoUser(body.employee_no ?? 'W001') }
  }
  if (method === 'post' && path === '/auth/wecom-login') {
    // 演示：扫码直接成功（绑定 W001）
    return { code: 0, message: '演示模式：企微扫码直接成功', data: { need_bind: false, token: 'demo-token-wecom', ...demoUser('W001') } }
  }

  // ==================== PLM 机台 ====================
  if (method === 'get' && path === '/plm/machines') {
    let items = clone(db.machines)
    const kw = String(param(params, 'keyword') ?? '').trim()
    const status = String(param(params, 'status') ?? '').trim()
    const type = String(param(params, 'type') ?? '').trim()
    const workshop = String(param(params, 'workshop') ?? '').trim()
    const workshopId = String(param(params, 'workshop_id') ?? '').trim()
    if (kw) items = items.filter((m) => matchKeyword(`${m.code} ${m.name}`, kw))
    if (status) items = items.filter((m) => m.status === status)
    if (type) items = items.filter((m) => m.type === type)
    if (workshop) items = items.filter((m) => m.workshop === workshop)
    if (workshopId) items = items.filter((m) => m.workshop_id === Number(workshopId))
    const all = param(params, 'all')
    if (all === true || all === '1') return items
    return paginate(items, params)
  }
  if (method === 'post' && path === '/plm/machines') {
    const body = (config.data ?? {}) as Partial<import('@/types').Machine>
    const machine = { ...body, id: db.machines.length + 1, created_at: dayjs().format('YYYY-MM-DD HH:mm:ss') } as import('@/types').Machine
    db.machines.push(machine)
    return clone(machine)
  }
  const machineById = path.match(/^\/plm\/machines\/(\d+)$/)
  if (machineById) {
    const id = Number(machineById[1])
    const idx = db.machines.findIndex((m) => m.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '机台不存在')
    if (method === 'get') return clone(db.machines[idx])
    if (method === 'put') {
      const previous = db.machines[idx]
      db.machines[idx] = { ...previous, ...((config.data ?? {}) as object) } as import('@/types').Machine
      return clone(db.machines[idx])
    }
    if (method === 'delete') {
      db.machines.splice(idx, 1)
      return null
    }
  }
  const machineRecalculate = path.match(/^\/plm\/machines\/(\d+)\/recalculate-schedules$/)
  if (machineRecalculate && method === 'post') {
    const id = Number(machineRecalculate[1])
    const result = recalculateSchedulesForMachine(id)
    return result
  }

  // ==================== PLM 车间 ====================
  if (method === 'get' && path === '/plm/workshops') {
    let items = clone(db.workshops)
    const activeOnly = param(params, 'active_only')
    const kw = String(param(params, 'keyword') ?? '').trim()
    if (activeOnly === 1 || activeOnly === '1') items = items.filter((w) => w.is_active === 1)
    if (kw) items = items.filter((w) => matchKeyword(`${w.code} ${w.name}`, kw))
    const all = param(params, 'all')
    if (all === true || all === '1') return items
    return paginate(items, params)
  }
  if (method === 'post' && path === '/plm/workshops') {
    const body = (config.data ?? {}) as Partial<import('@/types').PlmWorkshop>
    const workshop = { ...body, id: db.workshops.length + 1, machines_count: 0 } as import('@/types').PlmWorkshop
    db.workshops.push(workshop)
    return clone(workshop)
  }
  const workshopById = path.match(/^\/plm\/workshops\/(\d+)$/)
  if (workshopById) {
    const id = Number(workshopById[1])
    const idx = db.workshops.findIndex((w) => w.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '车间不存在')
    if (method === 'put') {
      db.workshops[idx] = { ...db.workshops[idx], ...((config.data ?? {}) as object) } as import('@/types').PlmWorkshop
      return clone(db.workshops[idx])
    }
    if (method === 'delete') {
      const ws = db.workshops[idx]
      const used = db.machines.filter((m) => m.workshop_id === ws.id).length
      if (used > 0) throw new MockApiError(409, 409, null, '车间下仍有机台/产线，无法删除')
      db.workshops.splice(idx, 1)
      return null
    }
  }

  // ==================== PLM 产品 ====================
  if (method === 'get' && path === '/plm/products') {
    let items = clone(db.products)
    const kw = String(param(params, 'keyword') ?? '').trim()
    const status = String(param(params, 'status') ?? '').trim()
    if (kw) items = items.filter((p) => matchKeyword(`${p.code} ${p.name} ${p.spec ?? ''}`, kw))
    if (status) items = items.filter((p) => p.status === status)
    return paginate(items, params)
  }
  if (method === 'post' && path === '/plm/products') {
    const body = (config.data ?? {}) as Partial<import('@/types').Product>
    const product = { ...body, id: db.products.length + 1 } as import('@/types').Product
    db.products.push(enrichProductRefs(product))
    return clone(product)
  }
  const productById = path.match(/^\/plm\/products\/(\d+)$/)
  if (productById) {
    const id = Number(productById[1])
    const idx = db.products.findIndex((p) => p.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '产品不存在')
    if (method === 'get') return clone(db.products[idx])
    if (method === 'put') {
      db.products[idx] = enrichProductRefs(
        { ...db.products[idx], ...((config.data ?? {}) as object) } as import('@/types').Product
      )
      return clone(db.products[idx])
    }
    if (method === 'delete') {
      db.products.splice(idx, 1)
      return null
    }
  }
  const productStatus = path.match(/^\/plm\/products\/(\d+)\/status$/)
  if (productStatus && method === 'post') {
    const id = Number(productStatus[1])
    const product = db.products.find((p) => p.id === id)
    if (!product) throw new MockApiError(404, 404, null, '产品不存在')
    product.status = ((config.data ?? {}) as { status?: import('@/types').Product['status'] }).status ?? product.status
    return { id, status: product.status }
  }

  // ==================== PLM BOM ====================
  const bomsOf = path.match(/^\/plm\/products\/(\d+)\/boms$/)
  if (bomsOf && method === 'get') {
    const pid = Number(bomsOf[1])
    return clone(db.boms.filter((b) => b.product_id === pid))
  }
  if (bomsOf && method === 'post') {
    const pid = Number(bomsOf[1])
    const body = (config.data ?? {}) as { version?: string; remark?: string; lines?: import('@/types').BomLine[] }
    const bom = {
      id: db.boms.length + 1,
      product_id: pid,
      version: body.version ?? 'V1.0',
      status: 'draft' as const,
      k3_push_status: 'none',
      published_at: null,
      remark: body.remark ?? null,
      lines_count: body.lines?.length ?? 0,
    }
    db.boms.push(bom)
    if (body.lines) db.bomLines[bom.id] = clone(body.lines)
    return clone(bom)
  }
  const bomById = path.match(/^\/plm\/boms\/(\d+)$/)
  if (bomById) {
    const id = Number(bomById[1])
    const idx = db.boms.findIndex((b) => b.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, 'BOM 不存在')
    if (method === 'get') {
      return { ...clone(db.boms[idx]), lines: clone(db.bomLines[id] ?? []) }
    }
    if (method === 'put') {
      const body = (config.data ?? {}) as { lines?: import('@/types').BomLine[]; remark?: string }
      if (body.lines) db.bomLines[id] = clone(body.lines)
      if (body.remark !== undefined) db.boms[idx].remark = body.remark
      return { ...clone(db.boms[idx]), lines: clone(db.bomLines[id] ?? []) }
    }
    if (method === 'delete') {
      deleteBom(id)
      return null
    }
  }
  const bomPublish = path.match(/^\/plm\/boms\/(\d+)\/publish$/)
  if (bomPublish && method === 'post') {
    return publishBom(Number(bomPublish[1]))
  }
  const bomRevoke = path.match(/^\/plm\/boms\/(\d+)\/revoke$/)
  if (bomRevoke && method === 'post') {
    return revokeBom(Number(bomRevoke[1]))
  }
  const bomDisable = path.match(/^\/plm\/boms\/(\d+)\/disable$/)
  if (bomDisable && method === 'post') {
    return disableBom(Number(bomDisable[1]))
  }
  const bomEnable = path.match(/^\/plm\/boms\/(\d+)\/enable$/)
  if (bomEnable && method === 'post') {
    return enableBom(Number(bomEnable[1]))
  }

  // 全量混合列表（金蝶物料清单样式：BOM 头行 + 明细行交错，与后端 listAll 同形）
  // 注意：不返回 bom_short_name 字段（后端为 null，前端模板用 ?? '—' 兜底）
  if (method === 'get' && path === '/plm/boms') {
    const productCode = String(param(params, 'product_code') ?? '').trim()
    const status = String(param(params, 'status') ?? '').trim()
    const search = String(param(params, 'search') ?? '').trim()

    // 半成品判定缓存：line.item_code ∈ db.products.code → is_semi_finished=true（内存 whereIn 一次取齐，避免 N+1）
    const semiMap = new Map<string, number>()
    for (const p of db.products) {
      semiMap.set(p.code, p.id)
    }

    const rows: import('@/types').BomListRow[] = []
    const sortedBoms = clone(db.boms).sort((a, b) => (a.product_id - b.product_id) || (a.id - b.id))

    for (const bom of sortedBoms) {
      const product = db.products.find((p) => p.id === bom.product_id)
      const productCodeText = product?.code ?? `P-${String(bom.product_id).padStart(4, '0')}`
      const productName = product?.name ?? ''

      if (productCode && !matchKeyword(productCodeText, productCode)) continue
      if (status && bom.status !== status) continue
      if (search && !matchKeyword(`${bom.remark ?? ''} ${productName} ${productCodeText}`, search)) continue

      rows.push({
        row_type: 'bom',
        bom_id: bom.id,
        product_id: bom.product_id,
        product_code: productCodeText,
        product_name: productName,
        version: bom.version,
        status: bom.status,
        lines_count: bom.lines_count ?? (db.bomLines[bom.id]?.length ?? 0),
        published_at: bom.published_at ?? null,
        remark: bom.remark ?? null,
        k3_push_status: bom.k3_push_status ?? 'none',
      })

      const lines = db.bomLines[bom.id] ?? []
      lines.forEach((line, index) => {
        const semiId = semiMap.get(line.item_code)
        rows.push({
          row_type: 'line',
          bom_id: bom.id,
          line_id: index + 1,
          seq: line.seq,
          item_code: line.item_code,
          item_name: line.item_name,
          spec: '',
          qty: String(line.qty),
          unit: line.unit,
          loss_rate: String(line.loss_rate),
          is_key: line.is_key,
          is_semi_finished: semiId !== undefined,
          semi_finished_product_id: semiId ?? null,
        })
      })
    }

    return rows
  }

  // 按产品编码查该产品全部 BOM 版本（半成品跳转入口）
  const bomsByProductCode = path.match(/^\/plm\/products\/by-code\/([^/]+)\/boms$/)
  if (bomsByProductCode && method === 'get') {
    const code = decodeURIComponent(bomsByProductCode[1])
    const product = db.products.find((p) => p.code === code)
    if (!product) {
      throw new MockApiError(404, 404, null, `产品编码 ${code} 未找到`)
    }
    return clone(db.boms)
      .filter((b) => b.product_id === product.id)
      .map((b) => ({ ...b, lines_count: b.lines_count ?? (db.bomLines[b.id]?.length ?? 0) }))
  }

  // ==================== PLM 工艺路线 ====================
  if (method === 'get' && path === '/plm/routes') {
    let items = clone(db.routes)
    const kw = String(param(params, 'keyword') ?? '').trim()
    const status = String(param(params, 'status') ?? '').trim()
    if (kw) items = items.filter((r) => matchKeyword(`${r.name} ${r.version}`, kw))
    if (status) items = items.filter((r) => r.status === status)
    return items.map((r) => ({ ...r, ops_count: r.ops_count ?? (db.routeOps[r.id]?.length ?? 0) }))
  }
  if (method === 'post' && path === '/plm/routes') {
    const body = (config.data ?? {}) as { name?: string; version?: string; remark?: string; ops?: import('@/types').RouteOp[] }
    const route = {
      id: db.routes.length + 1,
      name: body.name ?? '新工艺路线',
      version: body.version ?? 'R1',
      status: 'draft' as const,
      remark: body.remark ?? null,
      ops_count: body.ops?.length ?? 0,
      ops: body.ops ?? [],
    }
    db.routes.push(route)
    db.routeOps[route.id] = clone(body.ops ?? [])
    return clone(route)
  }
  // 全部已发布工艺路线（跨产品，产品档案「默认工艺」下拉）
  if (method === 'get' && path === '/plm/routes/published-all') {
    return clone(db.routes)
      .filter((r) => r.status === 'published')
      .map((r) => ({ ...r, ops_count: r.ops_count ?? (db.routeOps[r.id]?.length ?? 0) }))
  }
  const routeById = path.match(/^\/plm\/routes\/(\d+)$/)
  if (routeById) {
    const id = Number(routeById[1])
    const idx = db.routes.findIndex((r) => r.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '工艺路线不存在')
    if (method === 'get') return { ...clone(db.routes[idx]), ops: clone(db.routeOps[id] ?? []) }
    if (method === 'put') {
      const body = (config.data ?? {}) as { name?: string; version?: string; remark?: string; ops?: import('@/types').RouteOp[] }
      const route = db.routes[idx]
      // 名称+版本唯一校验（排除自身）
      const nextName = body.name ?? route.name
      const nextVersion = body.version ?? route.version
      const dup = db.routes.find((r) => r.id !== id && r.name === nextName && r.version === nextVersion)
      if (dup) {
        throw new MockApiError(422, 422, null, `工艺路线「${nextName}」版本 ${nextVersion} 已存在`)
      }
      if (body.name !== undefined) route.name = body.name
      if (body.version !== undefined) route.version = body.version
      if (body.remark !== undefined) route.remark = body.remark
      if (body.ops) db.routeOps[id] = clone(body.ops)
      return { ...clone(db.routes[idx]), ops: clone(db.routeOps[id] ?? []) }
    }
  }
  const routePublish = path.match(/^\/plm\/routes\/(\d+)\/publish$/)
  if (routePublish && method === 'post') {
    const id = Number(routePublish[1])
    const route = db.routes.find((r) => r.id === id)
    if (!route) throw new MockApiError(404, 404, null, '工艺路线不存在')
    route.status = 'published'
    return { id, status: 'published' }
  }
  // 工艺生命周期：下架（退回草稿）/ 禁用 / 启用
  const routeRevoke = path.match(/^\/plm\/routes\/(\d+)\/revoke$/)
  if (routeRevoke && method === 'post') {
    const id = Number(routeRevoke[1])
    const route = db.routes.find((r) => r.id === id)
    if (!route) throw new MockApiError(404, 404, null, '工艺路线不存在')
    route.status = 'draft'
    return { id, status: 'draft' }
  }
  const routeDisable = path.match(/^\/plm\/routes\/(\d+)\/disable$/)
  if (routeDisable && method === 'post') {
    const id = Number(routeDisable[1])
    const route = db.routes.find((r) => r.id === id)
    if (!route) throw new MockApiError(404, 404, null, '工艺路线不存在')
    route.status = 'disabled'
    return { id, status: 'disabled' }
  }
  const routeEnable = path.match(/^\/plm\/routes\/(\d+)\/enable$/)
  if (routeEnable && method === 'post') {
    const id = Number(routeEnable[1])
    const route = db.routes.find((r) => r.id === id)
    if (!route) throw new MockApiError(404, 404, null, '工艺路线不存在')
    route.status = 'published'
    return { id, status: 'published' }
  }
  // 删除工艺（任意状态软删：从 routes/routeOps 移除）
  const routeDelete = path.match(/^\/plm\/routes\/(\d+)$/)
  if (routeDelete && method === 'delete') {
    const id = Number(routeDelete[1])
    const idx = db.routes.findIndex((r) => r.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '工艺路线不存在')
    db.routes.splice(idx, 1)
    delete db.routeOps[id]
    return null
  }
  const routeCopy = path.match(/^\/plm\/routes\/(\d+)\/copy$/)
  if (routeCopy && method === 'post') {
    const id = Number(routeCopy[1])
    const src = db.routes.find((r) => r.id === id)
    if (!src) throw new MockApiError(404, 404, null, '源工艺路线不存在')
    const body = (config.data ?? {}) as { name?: string; version?: string; remark?: string }
    const copied = {
      ...clone(src),
      id: db.routes.length + 1,
      name: body.name ?? `${src.name} 副本`,
      version: body.version ?? 'R1',
      status: 'draft' as const,
      remark: body.remark ?? `复制自「${src.name}」${src.version}`,
      ops: clone(db.routeOps[id] ?? []),
    }
    db.routes.push(copied)
    db.routeOps[copied.id] = clone(db.routeOps[id] ?? [])
    return clone(copied)
  }
  if (method === 'get' && path === '/plm/op-templates') {
    // 从工序类型字典生成（启用项，按 sort），带 workshop
    return db.opTypes
      .filter((t) => t.is_active)
      .sort((a, b) => a.sort - b.sort)
      .map((t) => ({ op_type: t.op_type, op_name: t.op_name, is_molding: t.is_molding, machine_type: t.machine_type, workshop: t.workshop, sort: t.sort }))
  }

  // ==================== PLM 工序类型字典（可维护化） ====================
  if (method === 'get' && path === '/plm/op-types') {
    // 引用次数 = 该 op_type 在所有工艺路线工序中的出现次数
    const usedCount = new Map<string, number>()
    Object.values(db.routeOps).forEach((ops) => {
      ops.forEach((op) => usedCount.set(op.op_type, (usedCount.get(op.op_type) ?? 0) + 1))
    })
    const kw = String(param(params, 'keyword') ?? '').trim()
    return clone(
      db.opTypes
        .filter((t) => !kw || t.op_name.includes(kw) || t.op_type.includes(kw))
        .sort((a, b) => a.sort - b.sort)
        .map((t) => ({ ...t, used_count: usedCount.get(t.op_type) ?? 0 })),
    )
  }
  if (method === 'post' && path === '/plm/op-types') {
    const body = (config.data ?? {}) as import('@/types').OpType
    if (db.opTypes.some((t) => t.op_type === body.op_type)) {
      throw new MockApiError(422, 422, null, `工序类型键 ${body.op_type} 已存在`)
    }
    const maxSort = Math.max(0, ...db.opTypes.map((t) => t.sort))
    const item: import('@/types').OpType = {
      id: Math.max(0, ...db.opTypes.map((t) => t.id)) + 1,
      op_type: body.op_type,
      op_name: body.op_name,
      is_molding: !!body.is_molding,
      machine_type: body.is_molding ? body.machine_type ?? null : null,
      workshop: body.workshop,
      sort: body.sort ?? maxSort + 1,
      is_active: body.is_active ?? true,
      remark: body.remark ?? null,
    }
    db.opTypes.push(item)
    return clone(item)
  }
  const opTypeMatch = path.match(/^\/plm\/op-types\/(\d+)$/)
  if (opTypeMatch && method === 'put') {
    const id = Number(opTypeMatch[1])
    const item = db.opTypes.find((t) => t.id === id)
    if (!item) throw new MockApiError(404, 404, null, '工序类型不存在')
    const body = (config.data ?? {}) as Partial<import('@/types').OpType>
    // op_type 键不可改（防止历史 route_ops 失联）；其余字段可改
    if (body.op_type && body.op_type !== item.op_type) {
      throw new MockApiError(422, 422, null, '工序类型键不可修改')
    }
    if (body.op_name !== undefined) item.op_name = body.op_name
    if (body.is_molding !== undefined) item.is_molding = !!body.is_molding
    if (body.is_molding) item.machine_type = body.machine_type ?? item.machine_type
    else item.machine_type = null
    if (body.workshop !== undefined) item.workshop = body.workshop
    if (body.sort !== undefined) item.sort = body.sort
    if (body.is_active !== undefined) item.is_active = !!body.is_active
    if (body.remark !== undefined) item.remark = body.remark
    return clone(item)
  }
  if (opTypeMatch && method === 'delete') {
    const id = Number(opTypeMatch[1])
    const idx = db.opTypes.findIndex((t) => t.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '工序类型不存在')
    const used = Object.values(db.routeOps).some((ops) => ops.some((op) => op.op_type === db.opTypes[idx].op_type))
    if (used) throw new MockApiError(409, 409, null, '该工序类型已被工艺路线引用，无法删除（可停用）')
    db.opTypes.splice(idx, 1)
    return null
  }
  if (method === 'get' && path === '/plm/capacity/preview') {
    const qty = Number(param(params, 'qty') ?? 0)
    const cap = Number(param(params, 'capacity_per_hour') ?? 400)
    const change = Number(param(params, 'mold_change_min') ?? 30)
    if (qty <= 0) return { ok: false, message: '数量必须大于 0', hours: null }
    const hours = Math.round(((qty / cap + (change > 0 ? 0.5 : 0)) * 1.05) * 100) / 100
    return { ok: true, message: `约需 ${hours} 小时`, hours }
  }

  // ==================== PLM 模具 ====================
  if (method === 'get' && path === '/plm/molds') {
    let items = clone(db.molds)
    const kw = String(param(params, 'keyword') ?? '').trim()
    const status = String(param(params, 'status') ?? '').trim()
    const productId = Number(param(params, 'product_id') ?? 0)
    if (kw) items = items.filter((m) => matchKeyword(`${m.code} ${m.name}`, kw))
    if (status) items = items.filter((m) => m.status === status)
    if (productId > 0) items = items.filter((m) => m.id === db.products.find((p) => p.id === productId)?.default_mold_id || m.products?.some((p) => p.id === productId))
    return paginate(items, params)
  }
  if (method === 'post' && path === '/plm/molds') {
    const body = (config.data ?? {}) as Partial<import('@/types').Mold>
    const mold = {
      ...body,
      id: db.molds.length + 1,
      opened_count: 0,
      machine_ids: undefined,
      product_ids: undefined,
    } as import('@/types').Mold & { machine_ids?: number[]; product_ids?: number[] }
    db.molds.push(mold)
    return clone(mold)
  }
  const moldById = path.match(/^\/plm\/molds\/(\d+)$/)
  if (moldById) {
    const id = Number(moldById[1])
    const idx = db.molds.findIndex((m) => m.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '模具不存在')
    if (method === 'get') return clone(db.molds[idx])
    if (method === 'put') {
      db.molds[idx] = { ...db.molds[idx], ...((config.data ?? {}) as object) } as import('@/types').Mold
      return clone(db.molds[idx])
    }
    if (method === 'delete') {
      db.molds.splice(idx, 1)
      return null
    }
  }
  const moldMaintain = path.match(/^\/plm\/molds\/(\d+)\/maintain$/)
  if (moldMaintain && method === 'post') {
    return maintainMold(Number(moldMaintain[1]), (config.data ?? {}) as { type: 'maintain' | 'repair'; at?: string; cost?: number; vendor?: string; remark?: string })
  }
  const moldLife = path.match(/^\/plm\/molds\/(\d+)\/life$/)
  if (moldLife && method === 'get') {
    const mold = db.molds.find((m) => m.id === Number(moldLife[1]))
    if (!mold) throw new MockApiError(404, 404, null, '模具不存在')
    return {
      mold: { id: mold.id, code: mold.code, opened_count: mold.opened_count },
      logs: [
        { opening_count: Math.max(0, mold.opened_count - 1200), created_at: dayTime(-30, 8, 0), order_id: db.orders[0]?.id ?? 1, order_no: db.orders[0]?.order_no ?? 'SO-DEMO-001' },
        { opening_count: Math.max(0, mold.opened_count - 400), created_at: dayTime(-10, 8, 0), order_id: db.orders[1]?.id ?? 2, order_no: db.orders[1]?.order_no ?? 'SO-DEMO-002' },
        { opening_count: mold.opened_count, created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'), order_id: db.orders[2]?.id ?? 3, order_no: db.orders[2]?.order_no ?? 'SO-DEMO-003' },
      ],
    }
  }

  // ==================== 订单中心（工单粒度） ====================
  // 一个订单按产品行拆成多个工单（ProdTask），列表一行=一个工单，task_no 全局唯一。
  // 行 id=工单 id；order_id=所属订单 id（详情跳转 / 订单级状态机操作）；
  // 行 status=订单 8 态（驱动状态机操作与状态标签）；task_status=工单自身状态；
  // progress=按工序（op_type）分阶段的完工进度（与黑湖小工单一致）。
  if (method === 'get' && path === '/orders') {
    const kw = String(param(params, 'keyword') ?? '').trim()
    const status = String(param(params, 'status') ?? '').trim()
    const src = String(param(params, 'src') ?? '').trim()

    // 预建 订单行 id → 订单 索引，避免内层反复遍历
    const lineToOrder = new Map<number, (typeof db.orders)[number]>()
    for (const [orderId, ls] of Object.entries(db.orderLines)) {
      const order = db.orders.find((o) => o.id === Number(orderId))
      if (order) for (const l of ls) lineToOrder.set(l.id, order)
    }

    let items: Order[] = db.tasks.filter((t) => !t.is_split_parent).map((t) => {
      const order = lineToOrder.get(t.order_line_id)
      const row: Order = {
        id: t.id,
        order_no: order?.order_no ?? '',
        src: order?.src ?? 'manual',
        k3_bill_no: order?.k3_bill_no ?? null,
        customer_name: order?.customer_name ?? '',
        status: (order?.status ?? 'pending') as Order['status'], // 订单 8 态，驱动状态机操作
        priority: order?.priority ?? t.priority,
        delivery_date: t.delivery_date ?? order?.delivery_date ?? null,
        task_no: t.task_no,
        order_id: order?.id,
        product_name: t.product?.name ?? '',
        product_code: t.product?.code ?? '',
        product_id: t.product_id,
        mold_id: t.mold_id ?? null,
        task_status: t.status,
        is_locked: !!t.is_locked,
      }
      // 完工进度：按工序分阶段（注塑/吹塑成型→喷漆→效果→包装），工单中心必带
      row.progress = getTaskProgress(t.id)
      return row
    })

    // 六路模糊搜索合并进同一 OR 闭包（工单号/产品名/产品编码/订单号/客户/金蝶单号）
    if (kw) {
      items = items.filter((o) =>
        matchKeyword(`${o.task_no} ${o.product_name} ${o.product_code} ${o.order_no} ${o.customer_name} ${o.k3_bill_no ?? ''}`, kw),
      )
    }
    if (status) items = items.filter((o) => o.status === status)
    if (src) items = items.filter((o) => o.src === src)
    return paginate(items, params)
  }
  if (method === 'post' && path === '/orders') {
    return createOrder((config.data ?? {}) as Parameters<typeof createOrder>[0])
  }
  const orderTaskSplit = path.match(/^\/orders\/tasks\/(\d+)\/split$/)
  if (orderTaskSplit && method === 'post') {
    return splitTask(Number(orderTaskSplit[1]), (config.data ?? {}) as Parameters<typeof splitTask>[1])
  }
  // 工艺确认流程：配置任务工艺路线 + 确认（demo 同步真实后端行为）
  const orderRouteConfirm = path.match(/^\/orders\/(\d+)\/route-confirm$/)
  if (orderRouteConfirm && method === 'post') {
    return confirmOrderRoute(Number(orderRouteConfirm[1]))
  }
  const orderStart = path.match(/^\/orders\/(\d+)\/start$/)
  if (orderStart && method === 'post') {
    return startOrder(Number(orderStart[1]))
  }
  const orderPause = path.match(/^\/orders\/(\d+)\/pause$/)
  if (orderPause && method === 'post') {
    return pauseOrder(Number(orderPause[1]))
  }
  const orderAbort = path.match(/^\/orders\/(\d+)\/abort$/)
  if (orderAbort && method === 'post') {
    return abortOrder(Number(orderAbort[1]))
  }
  const orderClose = path.match(/^\/orders\/(\d+)\/close$/)
  if (orderClose && method === 'post') {
    return closeOrder(Number(orderClose[1]))
  }
  const orderRoutes = path.match(/^\/orders\/(\d+)\/routes$/)
  if (orderRoutes && method === 'put') {
    return updateOrderRoutes(Number(orderRoutes[1]), (config.data ?? {}) as { tasks: Array<{ task_id: number; route_id: number }> })
  }
  // 订单编辑：工艺路线明细（查询 / 保存定制 ops；ops 空数组 = 恢复标准路线）
  const orderRouteOps = path.match(/^\/orders\/(\d+)\/route-ops$/)
  if (orderRouteOps && method === 'get') {
    return getOrderRouteOps(Number(orderRouteOps[1]))
  }
  if (orderRouteOps && method === 'put') {
    return saveOrderRouteOps(Number(orderRouteOps[1]), (config.data ?? {}) as { tasks: Array<{ task_id: number; route_id?: number | null; ops: import('@/types/orderEdit').OrderRouteOpDraft[] }> })
  }
  // 订单编辑：领料清单（按订单行 + 已发布 BOM 计算需求量）
  const orderPickList = path.match(/^\/orders\/(\d+)\/pick-list$/)
  if (orderPickList && method === 'get') {
    return getOrderPickList(Number(orderPickList[1]))
  }
  if (method === 'post' && path === '/orders/import-excel') {
    return {
      success: true,
      imported_count: 3,
      skipped_count: 1,
      error_rows: [{ row: 4, product_code: 'HY-9999', error: '产品编码未匹配' }],
      order: { id: 99, order_no: `SO${dayjs().format('YYYYMMDD')}-IMP`, customer_name: 'Excel 导入（演示）' },
    }
  }
  const orderById = path.match(/^\/orders\/(\d+)$/)
  if (orderById) {
    const id = Number(orderById[1])
    const order = db.orders.find((o) => o.id === id)
    if (!order) throw new MockApiError(404, 404, null, '订单不存在')
    if (method === 'get') {
      const lines = (db.orderLines[id] ?? []).map((l) => {
        const tasks = db.tasks.filter((t) => t.order_line_id === l.id && !t.is_split_parent).map((t) => ({
          ...clone(t),
          progress: getTaskProgress(t.id), // 工单级工序进度（与后端 attachTaskExecutionMeta 一致）
        }))
        return { ...clone(l), tasks }
      })
      return { ...clone(order), lines }
    }
    if (method === 'delete') {
      deleteOrder(id)
      return null
    }
  }
  const orderLines = path.match(/^\/orders\/(\d+)\/lines$/)
  if (orderLines && method === 'get') {
    const id = Number(orderLines[1])
    return clone(db.orderLines[id] ?? [])
  }
  // 订单工序进度（按 op_type 分段；与后端 OrderProgressController::show 同构）
  const orderProgress = path.match(/^\/orders\/(\d+)\/progress$/)
  if (orderProgress && method === 'get') {
    return getOrderProgress(Number(orderProgress[1]))
  }

  // ==================== 排产 ====================
  if (method === 'get' && path === '/schedule/task-pool') {
    return { total: db.pool.length, items: clone(db.pool) }
  }
  if (method === 'get' && path === '/schedule/timeline') {
    const start = String(param(params, 'start') ?? d(0))
    const end = String(param(params, 'end') ?? d(7))
    return buildTimeline(start, end)
  }
  if (method === 'get' && path === '/schedule/details') {
    return buildDetails({
      date: String(param(params, 'date') ?? ''),
      keyword: String(param(params, 'keyword') ?? ''),
      order_no: String(param(params, 'order_no') ?? ''),
      product: String(param(params, 'product') ?? ''),
      machine: String(param(params, 'machine') ?? ''),
    })
  }
  if (method === 'get' && path === '/schedule/detail') {
    return buildDetail(Number(param(params, 'task_id') ?? 0))
  }
  if (method === 'get' && path === '/schedule/orders-affected') {
    return insertPreview({
      task_id: Number(param(params, 'task_id') ?? 0),
      machine_id: Number(param(params, 'machine_id') ?? 0),
      plan_start: String(param(params, 'plan_start') ?? dayjs().format('YYYY-MM-DD HH:mm:ss')),
      mold_id: param(params, 'mold_id') ? Number(param(params, 'mold_id')) : null,
    }, String(param(params, 'strategy') ?? 'wait') as 'wait' | 'interrupt')
  }
  if (method === 'post' && path === '/schedule/manual-save') {
    const body = (config.data ?? {}) as { items: Array<{ task_id: number; machine_id: number; plan_start: string; mold_id?: number | null }>; force?: boolean; note?: string }
    return manualSave(body.items ?? [], body.force === true, body.note)
  }
  if (method === 'post' && path === '/schedule/recalculate') {
    return recalculateAllSchedules()
  }
  if (method === 'post' && path === '/schedule/operation-adjust') {
    const body = (config.data ?? {}) as {
      schedule_id: number
      machine_id: number
      plan_start: string
      plan_end?: string
      edge?: 'start' | 'end'
      note?: string
    }
    return operationAdjust(body)
  }
  if (method === 'post' && path === '/schedule/insert') {
    const body = (config.data ?? {}) as { task_id: number; machine_id: number; plan_start: string; strategy?: 'wait' | 'interrupt'; mold_id?: number | null }
    return insertPreview(body, body.strategy ?? 'wait')
  }
  if (method === 'post' && path === '/schedule/insert/confirm') {
    const body = (config.data ?? {}) as { task_id: number; machine_id: number; plan_start: string; strategy?: 'wait' | 'interrupt'; mold_id?: number | null }
    return insertConfirm(body, body.strategy ?? 'wait')
  }
  if (method === 'post' && path === '/schedule/auto') {
    const body = (config.data ?? {}) as { task_ids?: number[] }
    return autoSolve(body.task_ids ?? [])
  }
  if (method === 'get' && path === '/schedule/auto/accept') {
    throw new MockApiError(404, 404, null, 'not found')
  }
  if (method === 'post' && path === '/schedule/auto/accept') {
    const body = (config.data ?? {}) as { mode?: 'full' | 'partial'; assignments?: Array<{ task_id: number; machine_id: number; plan_start: string }> }
    return autoAccept(body.mode ?? 'full', body.assignments ?? [])
  }
  const autoJob = path.match(/^\/schedule\/auto\/([\w-]+)$/)
  if (autoJob && method === 'get') {
    return autoPoll(autoJob[1])
  }
  if (method === 'post' && path === '/schedule/lock') {
    const body = (config.data ?? {}) as { task_ids?: number[] }
    const ids = numIds(String(body.task_ids ?? []).split(',')) ?? []
    return { locked_count: ids.length }
  }
  if (method === 'post' && path === '/schedule/unlock') {
    const body = (config.data ?? {}) as { task_ids?: number[] }
    const ids = numIds(String(body.task_ids ?? []).split(',')) ?? []
    return { unlocked_count: ids.length }
  }
  // ---- APS 顺延建议（建议层） ----
  if (method === 'get' && path === '/schedule/suggestions') {
    const status = String(param(params, 'status') ?? 'pending')
    const items = status === 'all' ? db.suggestions : db.suggestions.filter((s) => s.status === status)
    return clone([...items].sort((a, b) => b.id - a.id))
  }
  if (method === 'post' && path === '/schedule/suggestions/refresh') {
    return refreshAllMockSuggestions()
  }
  const suggestionAccept = path.match(/^\/schedule\/suggestions\/(\d+)\/accept$/)
  if (suggestionAccept && method === 'post') {
    return acceptSuggestion(Number(suggestionAccept[1]))
  }
  const suggestionDismiss = path.match(/^\/schedule\/suggestions\/(\d+)\/dismiss$/)
  if (suggestionDismiss && method === 'post') {
    return dismissSuggestion(Number(suggestionDismiss[1]))
  }

  // ==================== 任务 / 报工 ====================
  if (method === 'get' && path === '/tasks') {
    let items = clone(db.tasks)
    const kw = String(param(params, 'keyword') ?? '').trim()
    const status = String(param(params, 'status') ?? '').trim()
    if (kw) items = items.filter((t) => matchKeyword(`${t.task_no} ${t.product?.code} ${t.product?.name}`, kw))
    if (status) items = items.filter((t) => t.status === status)
    // 附加订单信息（手动报工按工单分组）
    items = items.map((t) => {
      let order: (typeof db.orders)[number] | null = null
      for (const [orderId, ls] of Object.entries(db.orderLines)) {
        if (ls.some((l) => l.id === t.order_line_id)) {
          order = db.orders.find((o) => o.id === Number(orderId)) ?? null
          break
        }
      }
      return {
        ...t,
        line: order ? { order: { id: order.id, order_no: order.order_no, customer_name: order.customer_name } } : t.line,
      }
    })
    if (param(params, 'all')) {
      return { total: items.length, data: items }
    }
    return paginate(items, params)
  }
  const taskById = path.match(/^\/tasks\/(\d+)$/)
  if (taskById) {
    const id = Number(taskById[1])
    const task = db.tasks.find((t) => t.id === id)
    if (!task) throw new MockApiError(404, 404, null, '任务不存在')
    if (method === 'get') {
      const stocked = db.stockIns.filter((s) => s.task_id === id).reduce((sum, s) => sum + s.qty, 0)
      const lastScheduleId = db.schedules.filter((s) => s.task_id === id && !s.is_interrupted).sort((a, b) => b.seq - a.seq)[0]?.id
      const reported = db.reports.filter((r) => r.task_id === id && (r.schedule_id === null || r.schedule_id === lastScheduleId)).reduce((sum, r) => sum + r.qty, 0)
      return { ...clone(task), stocked_qty: stocked, reported_qty: reported }
    }
  }
  const taskReports = path.match(/^\/tasks\/(\d+)\/report-history$/)
  if (taskReports && method === 'get') {
    const id = Number(taskReports[1])
    return clone(db.reports.filter((r) => r.task_id === id))
  }
  if (method === 'get' && path === '/reports') {
    let items = clone(db.reports)
    const kw = String(param(params, 'keyword') ?? '').trim()
    const taskId = Number(param(params, 'task_id') ?? 0)
    const sign = String(param(params, 'sign') ?? '').trim()
    const source = String(param(params, 'source') ?? '').trim()
    if (kw) items = items.filter((r) => matchKeyword(`${r.task_no ?? ''} ${r.product?.name ?? ''}`, kw))
    if (taskId) items = items.filter((r) => r.task_id === taskId)
    if (sign) items = items.filter((r) => String(r.sign) === sign)
    if (source) items = items.filter((r) => r.source === source)
    // 补齐工序与机台/产线，必须沿报工流水的 schedule_id 精准关联。
    items = items.map((r) => {
      const s = r.schedule_id ? db.schedules.find((x) => x.id === r.schedule_id) : null
      const m = s?.machine_id ? db.machines.find((x) => x.id === s.machine_id) : null
      return {
        ...r,
        schedule: s ? { id: s.id, task_id: s.task_id, seq: s.seq, op_name: s.op_name ?? s.op_type } : null,
        machine: m ? { id: m.id, code: m.code, name: m.name } : (r.machine ?? null),
      }
    })
    return paginate(decorateReportLedger(items), params)
  }
  if (method === 'get' && path === '/reports/daily-context') {
    return dailyReportContext(String(param(params, 'work_date') || dayjs().format('YYYY-MM-DD')), {
      keyword: String(param(params, 'keyword') || ''),
      workshop_id: Number(param(params, 'workshop_id') || 0) || undefined,
      machine_id: Number(param(params, 'machine_id') || 0) || undefined,
      operation: String(param(params, 'operation') || '') || undefined,
    })
  }
  if (method === 'get' && path === '/reports/summary') {
    return buildReportSummary({
      dimension: String(param(params, 'dimension') || 'detail'),
      date_from: String(param(params, 'date_from') || ''),
      date_to: String(param(params, 'date_to') || ''),
      keyword: String(param(params, 'keyword') || ''),
      machine_keyword: String(param(params, 'machine_keyword') || ''),
    })
  }
  if (method === 'post' && path === '/reports') {
    return submitReport((config.data ?? {}) as Parameters<typeof submitReport>[0])
  }
  if (method === 'post' && path === '/reports/verify') {
    const body = (config.data ?? {}) as { task_id?: number; qty?: number }
    const task = db.tasks.find((t) => t.id === Number(body.task_id ?? 0))
    if (!task) throw new MockApiError(404, 404, null, '任务不存在')
    const reported = db.reports.filter((r) => r.task_id === task.id && r.sign === 1).reduce((sum, r) => sum + r.qty, 0)
    const duplicated = reported + Number(body.qty ?? 0) > task.qty
    return {
      duplicated,
      message: duplicated ? `累计报工将超过任务量（已完成 ${reported}/${task.qty}）` : '校验通过',
      latest_at: db.reports.filter((r) => r.task_id === task.id).map((r) => r.report_time).sort().pop() ?? null,
    }
  }

  // ==================== 仓库 ====================
  if (method === 'get' && path === '/warehouse/available-qty') {
    return availableQty(Number(param(params, 'task_id') ?? 0))
  }
  if (method === 'get' && path === '/warehouse/ins') {
    let items = clone(db.stockIns)
    const status = String(param(params, 'k3_push_status') ?? '').trim()
    if (status) items = items.filter((s) => s.k3_push_status === status)
    // 入库单与任务、产品保持与真实 StockInResource 一致，避免列表只显示产品而丢失工单链路。
    items = items.map((stockIn) => {
      const task = db.tasks.find((item) => item.id === stockIn.task_id)
      return {
        ...stockIn,
        task: task ? { id: task.id, task_no: task.task_no, product_id: task.product_id, qty: task.qty, status: task.status } : undefined,
      }
    })
    return paginate(items, params)
  }
  if (method === 'post' && path === '/warehouse/ins') {
    return createStockIn((config.data ?? {}) as { task_id: number; qty: number; remark?: string })
  }
  const stockInPush = path.match(/^\/warehouse\/ins\/(\d+)\/push$/)
  if (stockInPush && method === 'post') {
    return pushStockIn(Number(stockInPush[1]))
  }

  // ==================== 系统 ====================
  if (method === 'get' && path === '/system/users') {
    let items = clone(db.users)
    const kw = String(param(params, 'keyword') ?? '').trim()
    if (kw) items = items.filter((u) => matchKeyword(`${u.employee_no} ${u.name}`, kw))
    return paginate(items, params)
  }
  // 负责人候选（订单编辑弹窗下拉；可选按车间过滤，最多 5 条演示）
  if (method === 'get' && path === '/system/users/options') {
    const workshopId = Number(param(params, 'workshop_id') ?? 0) || null
    return db.users
      .filter((u) => u.id > 1 && (!workshopId || u.workshop_id === workshopId))
      .slice(0, 5)
      .map((u) => ({ id: u.id, name: u.name, employee_no: u.employee_no, workshop_id: u.workshop_id ?? null, workshop_name: u.workshop_name ?? null }))
  }
  if (method === 'post' && path === '/system/users') {
    const body = (config.data ?? {}) as Partial<import('@/types').SysUser>
    const user = { ...body, id: db.users.length + 1, roles: [], workshop_id: body.workshop_id ?? null } as import('@/types').SysUser
    db.users.push(user)
    return clone(user)
  }
  const userById = path.match(/^\/system\/users\/(\d+)$/)
  if (userById) {
    const id = Number(userById[1])
    const idx = db.users.findIndex((u) => u.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '用户不存在')
    if (method === 'put') {
      db.users[idx] = { ...db.users[idx], ...((config.data ?? {}) as object) } as import('@/types').SysUser
      return clone(db.users[idx])
    }
    if (method === 'delete') {
      db.users.splice(idx, 1)
      return null
    }
  }
  const userRoles = path.match(/^\/system\/users\/(\d+)\/roles$/)
  if (userRoles && method === 'post') {
    const id = Number(userRoles[1])
    const user = db.users.find((u) => u.id === id)
    if (!user) throw new MockApiError(404, 404, null, '用户不存在')
    user.roles = ((config.data ?? {}) as { roles?: string[] }).roles ?? []
    return { roles: user.roles }
  }
  const userReset = path.match(/^\/system\/users\/(\d+)\/reset-password$/)
  if (userReset && method === 'post') {
    return null
  }
  if (method === 'get' && path === '/system/roles') {
    return clone(db.roles)
  }
  if (method === 'post' && path === '/system/roles') {
    const body = (config.data ?? {}) as { name?: string; permissions?: string[] }
    const role = { id: db.roles.length + 1, name: body.name ?? 'role', guard_name: 'web', permissions: body.permissions ?? [] }
    db.roles.push(role)
    return { id: role.id, name: role.name }
  }
  const roleById = path.match(/^\/system\/roles\/(\d+)$/)
  if (roleById) {
    const id = Number(roleById[1])
    const idx = db.roles.findIndex((r) => r.id === id)
    if (idx < 0) throw new MockApiError(404, 404, null, '角色不存在')
    if (method === 'put') {
      const body = (config.data ?? {}) as { name?: string; permissions?: string[] }
      if (body.name) db.roles[idx].name = body.name
      if (body.permissions) db.roles[idx].permissions = body.permissions
      return null
    }
    if (method === 'delete') {
      db.roles.splice(idx, 1)
      return null
    }
  }
  if (method === 'get' && path === '/system/audit-logs') {
    let items = clone(db.auditLogs)
    const kw = String(param(params, 'keyword') ?? '').trim()
    if (kw) items = items.filter((l) => matchKeyword(`${l.module} ${l.action}`, kw))
    return paginate(items, params)
  }
  if (method === 'get' && path === '/system/integration-logs') {
    let items = clone(db.integrationLogs)
    const status = String(param(params, 'status') ?? '').trim()
    if (status) items = items.filter((l) => l.status === status)
    return paginate(items, params)
  }
  const integrationLogById = path.match(/^\/system\/integration-logs\/(\d+)$/)
  if (integrationLogById && method === 'get') {
    const log = db.integrationLogs.find((l) => l.id === Number(integrationLogById[1]))
    if (!log) throw new MockApiError(404, 404, null, '日志不存在')
    return clone(log)
  }

  // ==================== 四期 · 看板 ====================
  if (method === 'get' && path === '/dashboard/workbench') {
    return clone(db.workbench)
  }
  if (method === 'get' && path === '/dashboard/order-overview') {
    const model = clone(db.orderOverview)
    const kw = String(param(params, 'keyword') ?? '').trim()
    const due = String(param(params, 'due_flag') ?? '').trim()
    const fulfillmentStatus = String(param(params, 'fulfillment_status') ?? '').trim()
    let items = model.items
    if (kw) items = items.filter((i) => matchKeyword(`${i.order_no} ${i.customer_name} ${i.k3_bill_no ?? ''}`, kw))
    if (due) items = items.filter((i) => i.due_flag === due)
    if (fulfillmentStatus) items = items.filter((i) => i.fulfillment_status === fulfillmentStatus)
    const totalQty = items.reduce((sum, item) => sum + item.total_qty, 0)
    const doneQty = items.reduce((sum, item) => sum + item.done_qty, 0)
    const processPlanQty = items.reduce((sum, item) => sum + item.process_plan_qty, 0)
    const processDoneQty = items.reduce((sum, item) => sum + item.process_done_qty, 0)
    const stockedQty = items.reduce((sum, item) => sum + (item.stocked_qty ?? 0), 0)
    const completed = items.filter((item) => item.fulfillment_status?.startsWith('completed'))
    const completedWithDelivery = completed.filter((item) => item.delivery_date)
    const isInProgress = (item: typeof items[number]) => item.fulfillment_status === 'scheduled' || item.fulfillment_status === 'in_progress'
    const sumBy = (predicate: (item: typeof items[number]) => boolean) => items.filter(predicate).reduce((sum, item) => sum + item.total_qty, 0)
    return {
      ...model,
      summary: {
        total_orders: items.length,
        production_completed: completed.length,
        on_time_completed: items.filter((item) => item.fulfillment_status === 'completed_on_time').length,
        late_completed: items.filter((item) => item.fulfillment_status === 'completed_late').length,
        in_progress: items.filter(isInProgress).length,
        forecast_late: items.filter((item) => item.fulfillment_status === 'forecast_late').length,
        overdue: items.filter((item) => item.fulfillment_status === 'overdue').length,
        not_started: items.filter((item) => item.fulfillment_status === 'not_started').length,
        paused: items.filter((item) => item.fulfillment_status === 'paused').length,
        total_qty: totalQty,
        done_qty: doneQty,
        process_plan_qty: processPlanQty,
        process_done_qty: processDoneQty,
        process_progress_pct: processPlanQty > 0 ? Math.min(100, Math.round(processDoneQty / processPlanQty * 1000) / 10) : 0,
        stocked_qty: stockedQty,
        production_completion_pct: totalQty > 0 ? Math.round(doneQty / totalQty * 1000) / 10 : 0,
        stock_completion_pct: totalQty > 0 ? Math.round(stockedQty / totalQty * 1000) / 10 : 0,
        on_time_rate: completedWithDelivery.length > 0
          ? Math.round(completedWithDelivery.filter((item) => item.fulfillment_status === 'completed_on_time').length / completedWithDelivery.length * 1000) / 10
          : null,
      },
      groups: {
        pending: { count: items.filter((item) => item.fulfillment_status === 'not_started').length, qty: sumBy((item) => item.fulfillment_status === 'not_started') },
        in_progress: { count: items.filter(isInProgress).length, qty: sumBy(isInProgress) },
        overdue: { count: items.filter((item) => item.due_flag === 'overdue').length, qty: sumBy((item) => item.due_flag === 'overdue') },
        done: { count: completed.length, qty: sumBy((item) => item.fulfillment_status?.startsWith('completed') === true) },
      },
      items,
    }
  }
  if (method === 'get' && path === '/dashboard/load-heatmap') {
    return clone(db.heatmap)
  }
  if (method === 'get' && path === '/dashboard/red-lights') {
    const status = String(param(params, 'status') ?? '').trim()
    const source = String(param(params, 'source') ?? '').trim()
    let items = clone(db.redLights)
    if (status) items = items.filter((r) => r.status === status)
    if (source) items = items.filter((r) => r.source === source)
    const open = items.filter((r) => r.status === 'open')
    const bySourceMap = new Map<string, { source: string; source_label: string; count: number }>()
    for (const r of open) {
      const key = r.source
      const found = bySourceMap.get(key)
      if (found) found.count += 1
      else bySourceMap.set(key, { source: r.source, source_label: r.source_label, count: 1 })
    }
    const groups = new Map<number | null, { owner_id: number | null; owner_name: string; owner_workshop: string | null; total: number; by_source: Array<{ source: string; source_label: string; count: number }>; items: typeof items }>()
    for (const r of items) {
      const key = r.owner_id
      const g = groups.get(key) ?? { owner_id: key, owner_name: r.owner_name ?? '未指派', owner_workshop: r.owner_workshop, total: 0, by_source: [], items: [] }
      g.total += 1
      g.items.push(r)
      groups.set(key, g)
    }
    const groupList = Array.from(groups.values()).map((g) => {
      const bySrc = new Map<string, { source: string; source_label: string; count: number }>()
      for (const r of g.items) {
        const f = bySrc.get(r.source)
        if (f) f.count += 1
        else bySrc.set(r.source, { source: r.source, source_label: r.source_label, count: 1 })
      }
      return { ...g, by_source: Array.from(bySrc.values()) }
    })
    return {
      total: items.length,
      open_count: items.filter((r) => r.status === 'open').length,
      closed_count: items.filter((r) => r.status === 'closed').length,
      by_source: Array.from(bySourceMap.values()),
      groups: groupList,
      items,
    }
  }
  if (method === 'post' && path === '/dashboard/red-lights/refresh') {
    const openCount = refreshRedLights()
    return { refreshed: true, open_count: openCount }
  }
  const redLightClose = path.match(/^\/dashboard\/red-lights\/(\d+)\/close$/)
  if (redLightClose && method === 'post') {
    const body = (config.data ?? {}) as { reason?: string; closed_by?: string }
    return closeRedLight(Number(redLightClose[1]), body.reason ?? '现场处理完成', body.closed_by ?? '系统管理员')
  }
  if (method === 'get' && path === '/dashboard/reports') {
    return clone(db.reportsData)
  }
  if (method === 'get' && path === '/dashboard/task-board') {
    return {
      overview: {
        total: db.tasks.length,
        unscheduled: db.tasks.filter((t) => t.status === 'unscheduled').length,
        scheduled: db.tasks.filter((t) => t.status === 'scheduled').length,
        in_progress: db.tasks.filter((t) => t.status === 'in_progress').length,
        done: db.tasks.filter((t) => t.status === 'done').length,
      },
      items: clone(db.tasks).map((t) => ({
        id: t.id,
        task_no: t.task_no,
        product_code: t.product?.code,
        product_name: t.product?.name,
        machine_code: db.schedules.find((s) => s.task_id === t.id)?.machine_id ? db.machines.find((m) => m.id === db.schedules.find((s) => s.task_id === t.id)!.machine_id)?.code : null,
        status: t.status,
        qty: t.qty,
        done_qty: t.done_qty,
        progress_pct: t.qty ? Math.round((t.done_qty / t.qty) * 100) : 0,
        priority: t.priority,
        delivery_date: t.delivery_date,
      })),
    }
  }

  // ==================== 四期 · 生产异常 / 新品开发 ====================
  if (method === 'get' && path === '/production/exceptions') {
    let items = clone(db.exceptions)
    const status = String(param(params, 'status') ?? '').trim()
    const type = String(param(params, 'type') ?? '').trim()
    const kw = String(param(params, 'keyword') ?? '').trim()
    if (status) items = items.filter((e) => e.status === status)
    if (type) items = items.filter((e) => e.type === type)
    if (kw) items = items.filter((e) => matchKeyword(`${e.desc} ${e.task_no ?? ''} ${e.machine_code ?? ''}`, kw))
    return paginate(items, params)
  }
  if (method === 'post' && path === '/production/exceptions') {
    return createException((config.data ?? {}) as Parameters<typeof createException>[0])
  }
  const exceptionById = path.match(/^\/production\/exceptions\/(\d+)$/)
  if (exceptionById && method === 'get') {
    const ex = db.exceptions.find((e) => e.id === Number(exceptionById[1]))
    if (!ex) throw new MockApiError(404, 404, null, '异常不存在')
    return clone(ex)
  }
  const exceptionHandle = path.match(/^\/production\/exceptions\/(\d+)\/handle$/)
  if (exceptionHandle && method === 'post') {
    return handleException(Number(exceptionHandle[1]), (config.data ?? {}) as Parameters<typeof handleException>[1])
  }
  if (method === 'get' && path === '/plm/develop') {
    let items = clone(db.developTasks)
    const stage = String(param(params, 'stage') ?? '').trim()
    if (stage) items = items.filter((d) => d.stage === stage)
    const byStage = new Map<string, typeof items>()
    for (const it of items) {
      const arr = byStage.get(it.stage) ?? []
      arr.push(it)
      byStage.set(it.stage, arr)
    }
    return {
      stages: [
        { key: 'inquiry', label: '询价', color: '#909399' },
        { key: 'project', label: '立项', color: '#409eff' },
        { key: 'design', label: '设计', color: '#67c23a' },
        { key: 'mold_making', label: '开模', color: '#e6a23c' },
        { key: 'trial', label: '试模', color: '#f56c6c' },
        { key: 'small_batch', label: '小批', color: '#9c27b0' },
        { key: 'mass', label: '量产', color: '#1677ff' },
      ],
      items,
      by_stage: Object.fromEntries(byStage),
      total: items.length,
      overdue_count: items.filter((d) => d.overdue).length,
    }
  }
  if (method === 'post' && path === '/plm/develop') {
    return createDevelop((config.data ?? {}) as Parameters<typeof createDevelop>[0])
  }
  const developById = path.match(/^\/plm\/develop\/(\d+)$/)
  if (developById && method === 'get') {
    const dev = db.developTasks.find((d) => d.id === Number(developById[1]))
    if (!dev) throw new MockApiError(404, 404, null, '开发任务不存在')
    return clone(dev)
  }
  const developAdvance = path.match(/^\/plm\/develop\/(\d+)\/advance$/)
  if (developAdvance && method === 'post') {
    const body = (config.data ?? {}) as { operator_name?: string }
    return advanceDevelop(Number(developAdvance[1]), body.operator_name ?? '系统管理员')
  }

  // ==================== 五期 · AI 报价 ====================
  if (method === 'post' && path === '/quotes/ai-estimate') {
    const body = (config.data ?? {}) as { product_id?: number | null; qty?: number; margin?: number }
    return mockEstimate(body.product_id ?? null, Number(body.qty ?? 0), Number(body.margin ?? 20))
  }
  if (method === 'get' && path === '/quotes') {
    let items = clone(db.quotes)
    const status = String(param(params, 'status') ?? '').trim()
    const kw = String(param(params, 'keyword') ?? '').trim()
    if (status) items = items.filter((q) => q.status === status)
    if (kw) items = items.filter((q) => matchKeyword(`${q.quote_no} ${q.customer_name}`, kw))
    return paginate(items, params)
  }
  if (method === 'post' && path === '/quotes') {
    const body = (config.data ?? {}) as { from_estimate?: boolean; customer_name?: string; estimate?: QuoteEstimate }
    if (body.from_estimate && body.estimate) {
      return createQuoteFromEstimate(body.customer_name ?? '演示客户', body.estimate)
    }
    throw new MockApiError(422, 422, null, '请提供估价数据（from_estimate + estimate）')
  }
  const quoteById = path.match(/^\/quotes\/(\d+)$/)
  if (quoteById && method === 'get') {
    const q = db.quotes.find((x) => x.id === Number(quoteById[1]))
    if (!q) throw new MockApiError(404, 404, null, '报价单不存在')
    return clone(q)
  }
  const quotePrice = path.match(/^\/quotes\/(\d+)\/price$/)
  if (quotePrice && method === 'patch') {
    const body = (config.data ?? {}) as { final_price?: number; remark?: string }
    return adjustQuotePrice(Number(quotePrice[1]), Number(body.final_price ?? 0), body.remark)
  }
  const quoteStatus = path.match(/^\/quotes\/(\d+)\/status$/)
  if (quoteStatus && method === 'patch') {
    const body = (config.data ?? {}) as { status: Quote['status']; final_price?: number }
    return transitionQuoteStatus(Number(quoteStatus[1]), body.status, body.final_price)
  }
  // 报价参数
  if (method === 'get' && path === '/quotes/params/materials') {
    let items = clone(db.materialPrices)
    const kw = String(param(params, 'keyword') ?? '').trim()
    if (kw) items = items.filter((m) => matchKeyword(`${m.material_code} ${m.material_name}`, kw))
    return param(params, 'all') === true || param(params, 'all') === '1' ? items : paginate(items, params)
  }
  if (method === 'post' && path === '/quotes/params/materials') {
    return upsertMaterialPrice((config.data ?? {}) as Partial<MaterialPrice>)
  }
  if (method === 'get' && path === '/quotes/params/process-rates') {
    let items = clone(db.processRates)
    const kw = String(param(params, 'keyword') ?? '').trim()
    if (kw) items = items.filter((r) => matchKeyword(r.rate_key, kw))
    return param(params, 'all') === true || param(params, 'all') === '1' ? items : paginate(items, params)
  }
  if (method === 'post' && path === '/quotes/params/process-rates') {
    return upsertProcessRate((config.data ?? {}) as Partial<ProcessRate>)
  }
  if (method === 'get' && path === '/quotes/params/rules') {
    return clone(db.quoteRules)
  }
  if (method === 'put' && path === '/quotes/params/rules') {
    return upsertQuoteRule((config.data ?? {}) as { rule_key: string; rule_value: number; remark?: string })
  }

  // ==================== 集成（演示占位） ====================
  if (method === 'get' && path === '/integration/pull-prod-orders') {
    const fresh = 0
    return { pulled: fresh, skipped: 0, cursor: 100, message: '演示模式：金蝶拉单为空（无新增）' }
  }
  if (method === 'post' && path === '/integration/pull-prod-orders') {
    return { pulled: 0, skipped: 0, cursor: 100, message: '演示模式：金蝶拉单完成（无新增，幂等）' }
  }
  if (method === 'get' && path === '/integration/k3-health') {
    return { ok: true, mock: true, message: 'K3 连接配置可用（演示 mock）' }
  }
  if (method === 'get' && path === '/integration/materials/unmatched') {
    return {
      total: 2,
      items: [
        { id: 1, code: 'RAW-PP-999', name: 'PP 树脂（未备案）', matched_product_id: null, reason: '金蝶物料库中未找到对应编码' },
        { id: 2, code: 'PUMP-300-01', name: '300ml 泵头总成', matched_product_id: null, reason: '金蝶物料库中未找到对应编码' },
      ],
      message: '期初物料主数据同步后未匹配项（供人工核对）',
    }
  }
  if (method === 'post' && path === '/integration/sync-materials') {
    return { synced: 18, unmatched: 2, message: '物料主数据同步完成（演示）' }
  }

  throw new MockApiError(404, 404, null, `演示模式未实现该端点：${method.toUpperCase()} ${path}`)
}

/** 供 axios 层使用的统一包装：返回解包后的 data（兼容 request() 语义） */
export async function mockRequest<T>(config: AxiosRequestConfig): Promise<T> {
  const delay = 120 + Math.floor(Math.random() * 260)
  await new Promise((resolve) => setTimeout(resolve, delay))
  return dispatch(config) as T
}

export { calcEnd, compatibleMolds, dayHour }
export { INS_NO }
