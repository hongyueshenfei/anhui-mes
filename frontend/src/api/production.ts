import { request } from './axios'
import type { PageResult } from '@/types'
import type { ProdTask } from '@/types/schedule'
import type { DailyReportContext, ProductionException, ReportPayload, ReportResult, ReportSummary, WorkReport } from '@/types/production'

/**
 * 报工/任务 API（对应后端 app/Modules/Production）
 */
export const productionApi = {
  /** 任务列表（今日任务数据源） */
  listTasks(params?: Record<string, unknown>): Promise<PageResult<ProdTask>> {
    return request({ url: '/tasks', method: 'get', params })
  },

  /** 任务详情（含报工汇总） */
  getTask(id: number): Promise<ProdTask> {
    return request({ url: `/tasks/${id}`, method: 'get' })
  },

  /** 任务报工流水 */
  taskReportHistory(id: number): Promise<WorkReport[]> {
    return request({ url: `/tasks/${id}/report-history`, method: 'get' })
  },

  /** 报工台账 */
  listReports(params?: Record<string, unknown>): Promise<PageResult<WorkReport>> {
    return request({ url: '/reports', method: 'get', params })
  },

  /** 报工统计汇总：按实际作业日期 + 任务 + 工序 + 机台/产线聚合 */
  reportSummary(params?: Record<string, unknown>): Promise<ReportSummary> {
    return request({ url: '/reports/summary', method: 'get', params })
  },

  /** PC 当日报工表：一行对应一条已接受的工序排程 */
  dailyReportContext(params: Record<string, unknown>): Promise<DailyReportContext> {
    return request({ url: '/reports/daily-context', method: 'get', params })
  },

  /** 提交报工/冲减 */
  submitReport(data: ReportPayload): Promise<ReportResult> {
    return request({ url: '/reports', method: 'post', data })
  },

  /** 防重校验 */
  verifyReport(data: { task_id: number; qty: number; worker_id?: number | null }): Promise<{
    duplicated: boolean
    message: string
    latest_at: string | null
  }> {
    return request({ url: '/reports/verify', method: 'post', data })
  },
}
/**
 * 四期：生产异常 API（演示/真实契约：停机/换模/次品/缺料）
 */
export const productionApiV4 = {
  /** 异常列表 */
  exceptions(params?: Record<string, unknown>): Promise<PageResult<ProductionException>> {
    return request({ url: '/production/exceptions', method: 'get', params })
  },

  /** 异常详情 */
  exception(id: number): Promise<ProductionException> {
    return request({ url: `/production/exceptions/${id}`, method: 'get' })
  },

  /** 上报异常 */
  reportException(data: Partial<ProductionException> & { desc: string; type: ProductionException['type'] }): Promise<ProductionException> {
    return request({ url: '/production/exceptions', method: 'post', data })
  },

  /** 处理异常（留审计） */
  handleException(id: number, data: { status: 'open' | 'processing' | 'closed'; handler?: string; result?: string }): Promise<ProductionException> {
    return request({ url: `/production/exceptions/${id}/handle`, method: 'post', data })
  },
}
