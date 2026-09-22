import { request } from './axios'
import type { LoadHeatmapData, OrderOverviewData, RedLight, RedLightListData, ReportsData, WorkbenchData } from '@/types/dashboard'

/**
 * 四期看板 API（对应后端 app/Modules/Dashboard）
 * 演示模式下由本地 mock 路由响应。
 */
export const dashboardApi = {
  /** 工作台（KPI + 红灯速览 + 临期订单 + 今日派工） */
  workbench(): Promise<WorkbenchData> {
    return request({ url: '/dashboard/workbench', method: 'get' })
  },

  /** 订单进度总览 */
  orderOverview(params?: Record<string, unknown>): Promise<OrderOverviewData> {
    return request({ url: '/dashboard/order-overview', method: 'get', params })
  },

  /** 产能负荷热力（7 天 × 机台） */
  loadHeatmap(): Promise<LoadHeatmapData> {
    return request({ url: '/dashboard/load-heatmap', method: 'get' })
  },

  /** 红灯列表（按责任人聚合） */
  redLights(params?: Record<string, unknown>): Promise<RedLightListData> {
    return request({ url: '/dashboard/red-lights', method: 'get', params })
  },

  /** 关闭红灯（留审计） */
  closeRedLight(id: number, data: { reason: string; closed_by?: string }): Promise<RedLight> {
    return request({ url: `/dashboard/red-lights/${id}/close`, method: 'post', data })
  },

  /** 手动重算红灯（金蝶拉单/排产后刷新） */
  refreshRedLights(): Promise<{ refreshed: boolean; open_count: number }> {
    return request({ url: '/dashboard/red-lights/refresh', method: 'post' })
  },

  /** 报表中心指标 */
  reports(): Promise<ReportsData> {
    return request({ url: '/dashboard/reports', method: 'get' })
  },

  /** 任务执行看板 */
  taskBoard(): Promise<{ overview: Record<string, number>; items: unknown[] }> {
    return request({ url: '/dashboard/task-board', method: 'get' })
  },
}

/** 集成配置/拉单 API（四期演示） */
export const integrationApiV4 = {
  /** 手动拉取金蝶生产订单（增量游标） */
  pullProdOrders(): Promise<{ pulled: number; skipped: number; cursor: number; message: string }> {
    return request({ url: '/integration/pull-prod-orders', method: 'post' })
  },
  /** K3 连接测试 */
  k3Health(): Promise<{ ok: boolean; mock: boolean; message: string }> {
    return request({ url: '/integration/k3-health', method: 'get' })
  },
  /** 物料同步（每日 + 手动） */
  syncMaterials(): Promise<{ synced: number; unmatched: number; message: string }> {
    return request({ url: '/integration/sync-materials', method: 'post' })
  },
  /** 未匹配物料提示清单 */
  unmatchedMaterials(): Promise<{ total: number; items: Array<{ id: number; code: string; name: string; matched_product_id: number | null; reason: string }>; message: string }> {
    return request({ url: '/integration/materials/unmatched', method: 'get' })
  },
}