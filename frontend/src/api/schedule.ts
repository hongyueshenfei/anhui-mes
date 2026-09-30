import { request } from './axios'
import type {
  AutoPollResult,
  AutoSubmitResult,
  InsertPreview,
  PoolTask,
  ScheduleDetail,
  ScheduleDetailList,
  ScheduleSuggestion,
  ScheduleVersion,
  TimelineData,
} from '@/types/schedule'

/**
 * 排产 API（对应后端 app/Modules/Scheduling）
 */
export const scheduleApi = {
  // ============ 数据 ============

  /** 待排任务池 */
  taskPool(params?: Record<string, unknown>): Promise<{ total: number; items: PoolTask[] }> {
    return request({ url: '/schedule/task-pool', method: 'get', params })
  },

  /** 甘特时间线（机台/班组 × 时间窗） */
  timeline(start: string, end: string): Promise<TimelineData> {
    return request({ url: '/schedule/timeline', method: 'get', params: { start, end } })
  },

  /** 已排产任务明细列表（默认全部，支持按任务号筛选） */
  details(params?: {
    date?: string
    keyword?: string
    order_no?: string
    product?: string
    machine?: string
    limit?: number
  }): Promise<ScheduleDetailList> {
    return request({ url: '/schedule/details', method: 'get', params })
  },

  /** 插单受影响订单预览（纯计算） */
  ordersAffected(params: {
    task_id: number
    machine_id: number
    plan_start: string
    strategy?: 'wait' | 'interrupt'
    mold_id?: number | null
  }): Promise<InsertPreview> {
    return request({ url: '/schedule/orders-affected', method: 'get', params })
  },

  /** 任务排程明细（甘特+明细双视图） */
  detail(taskId: number): Promise<ScheduleDetail> {
    return request({ url: '/schedule/detail', method: 'get', params: { task_id: taskId } })
  },

  // ============ 操作 ============

  /** 手工排程保存（保存即锁定；mode=backward 交期倒排，不可行自动降级顺排） */
  manualSave(data: {
    items: Array<{ task_id: number; machine_id: number; plan_start: string; mold_id?: number | null; deadline?: string }>
    force?: boolean
    note?: string
    mode?: 'forward' | 'backward'
  }): Promise<{ version: ScheduleVersion; tasks: unknown[]; conflicts: unknown[]; backward_downgraded: string[] }> {
    return request({ url: '/schedule/manual-save', method: 'post', data })
  },

  /** 按当前机台/产线最新产能重算已排但未开工工序，不生成算法建议 */
  recalculate(): Promise<{ task_count: number; changed_count: number }> {
    return request({ url: '/schedule/recalculate', method: 'post' })
  },

  /** 单道工序调整（只改当前排程行，其他工序保持原计划） */
  operationAdjust(data: {
    schedule_id: number
    machine_id: number
    plan_start: string
    plan_end?: string
    edge?: 'start' | 'end'
    note?: string
  }): Promise<{ version: ScheduleVersion; task: unknown; schedule: unknown; conflicts: unknown[] }> {
    return request({ url: '/schedule/operation-adjust', method: 'post', data })
  },

  /** 插单预览（不落库） */
  insertPreview(data: {
    task_id: number
    machine_id: number
    plan_start: string
    strategy?: 'wait' | 'interrupt'
    mold_id?: number | null
    force_interrupt?: boolean
  }): Promise<InsertPreview> {
    return request({ url: '/schedule/insert', method: 'post', data })
  },

  /** 插单确认 */
  insertConfirm(data: {
    task_id: number
    machine_id: number
    plan_start: string
    strategy?: 'wait' | 'interrupt'
    mold_id?: number | null
    force_interrupt?: boolean
  }): Promise<{
    version: ScheduleVersion
    task: unknown
    affected_orders: InsertPreview['affected_orders']
    changed_tasks: InsertPreview['changed_tasks']
  }> {
    return request({ url: '/schedule/insert/confirm', method: 'post', data })
  },

  // ============ 算法排产 ============

  /** 提交算法排产（返回 job_id，异步求解） */
  autoSubmit(taskIds?: number[]): Promise<AutoSubmitResult> {
    return request({ url: '/schedule/auto', method: 'post', data: { task_ids: taskIds ?? [] } })
  },

  /** 轮询求解结果 */
  autoPoll(jobId: string): Promise<AutoPollResult> {
    return request({ url: `/schedule/auto/${jobId}`, method: 'get' })
  },

  /** 接受求解结果（整版接受/局部采纳） */
  autoAccept(data: {
    job_id?: string
    mode: 'full' | 'partial'
    assignments: Array<{ task_id: number; machine_id: number; plan_start: string; mold_id?: number | null }>
  }): Promise<{
    version: ScheduleVersion
    mode: string
    job_id?: string | null
    conflicts?: unknown[]
    adjusted_assignments?: Array<{
      task_id: number
      task_no?: string
      original_machine_code?: string | null
      adjusted_machine_code?: string | null
      reason?: string
    }>
  }> {
    return request({ url: '/schedule/auto/accept', method: 'post', data })
  },

  // ============ 锁定 ============

  lock(taskIds: number[]): Promise<{ locked_count: number }> {
    return request({ url: '/schedule/lock', method: 'post', data: { task_ids: taskIds } })
  },

  unlock(taskIds: number[], reason: string, exceptionUnlock = false): Promise<{ unlocked_count: number }> {
    return request({
      url: '/schedule/unlock',
      method: 'post',
      data: { task_ids: taskIds, reason, exception_unlock: exceptionUnlock },
    })
  },

  // ============ APS 顺延建议（建议层） ============

  /** 建议清单（默认 pending，status=all 查全部） */
  suggestions(status: string = 'pending'): Promise<ScheduleSuggestion[]> {
    return request({ url: '/schedule/suggestions', method: 'get', params: { status } })
  },

  /** 全量刷新建议层，不修改正式排程 */
  refreshSuggestions(): Promise<{ task_count: number }> {
    return request({ url: '/schedule/suggestions/refresh', method: 'post' })
  },

  /** 采纳建议：受影响任务未完工工序计划对齐预计，采纳后仍可手动调整 */
  acceptSuggestion(id: number): Promise<{ task_id: number; version_id: number; aligned: number }> {
    return request({ url: `/schedule/suggestions/${id}/accept`, method: 'post' })
  },

  /** 驳回建议（留痕，不改排程） */
  dismissSuggestion(id: number): Promise<{ id: number }> {
    return request({ url: `/schedule/suggestions/${id}/dismiss`, method: 'post' })
  },
}
