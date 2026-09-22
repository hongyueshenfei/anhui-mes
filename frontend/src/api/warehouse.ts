import { request } from './axios'
import type { PageResult } from '@/types'
import type { AvailableQty, IntegrationLog, StockIn } from '@/types/production'

/**
 * 入库/集成 API（对应后端 app/Modules/Warehouse、Integration）
 */
export const warehouseApi = {
  /** 可入库量（报工完成 − 已入库） */
  availableQty(taskId: number): Promise<AvailableQty> {
    return request({ url: '/warehouse/available-qty', method: 'get', params: { task_id: taskId } })
  },

  /** 分批入库 */
  createStockIn(data: { task_id: number; qty: number; remark?: string }): Promise<{
    stock_in: StockIn
    available: AvailableQty
  }> {
    return request({ url: '/warehouse/ins', method: 'post', data })
  },

  /** 入库单列表（推送状态过滤） */
  listStockIns(params?: Record<string, unknown>): Promise<PageResult<StockIn>> {
    return request({ url: '/warehouse/ins', method: 'get', params })
  },

  /** 手动重推金蝶 */
  pushStockIn(id: number): Promise<{ pushed: boolean; status: string; bill_no: string | null; message: string }> {
    return request({ url: `/warehouse/ins/${id}/push`, method: 'post' })
  },
}

/** 集成日志 API */
export const integrationApi = {
  listLogs(params?: Record<string, unknown>): Promise<PageResult<IntegrationLog>> {
    return request({ url: '/system/integration-logs', method: 'get', params })
  },
}