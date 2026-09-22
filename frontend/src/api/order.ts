import { request } from './axios'
import type { PageResult } from '@/types'
import type { ImportResult, Order, OrderLine, WorkOrderLog, ProgressStage } from '@/types/schedule'
import type { OrderPickListPayload, OrderRouteOpsPayload, OrderRouteOpsSubmitTask } from '@/types/orderEdit'

/**
 * 订单中心 API（对应后端 app/Modules/Order）
 */
export const orderApi = {
  /** 订单列表 */
  listOrders(params?: Record<string, unknown>): Promise<PageResult<Order>> {
    return request({ url: '/orders', method: 'get', params })
  },

  /** 订单详情（含明细与任务） */
  getOrder(id: number): Promise<Order> {
    return request({ url: `/orders/${id}`, method: 'get' })
  },

  /** 订单明细 */
  listOrderLines(id: number): Promise<OrderLine[]> {
    return request({ url: `/orders/${id}/lines`, method: 'get' })
  },

  /** 手工建单（多产品明细） */
  createOrder(data: {
    customer_name: string
    delivery_date?: string | null
    priority?: number
    remark?: string
    lines: Array<{ product_code: string; qty: number; delivery_date?: string | null; priority?: number; mold_id?: number | null }>
  }): Promise<Order> {
    return request({ url: '/orders', method: 'post', data })
  },

  /** 生产拆单：只拆分数量，模具/机台在甘特图排产时选择并校验 */
  splitTask(id: number, data: { parts: Array<{ qty: number }> }): Promise<{
    parent_task_id: number
    parent_task_no: string
    task_ids: number[]
    tasks: unknown[]
    remaining_qty: number
    split_total: number
  }> {
    return request({ url: `/orders/tasks/${id}/split`, method: 'post', data })
  },

  /** Excel/CSV 导入 */
  importExcel(file: File): Promise<ImportResult> {
    const form = new FormData()
    form.append('file', file)
    return request({ url: '/orders/import-excel', method: 'post', data: form, headers: { 'Content-Type': 'multipart/form-data' } })
  },

  /** 导入模板下载地址 */
  templateUrl(): string {
    return '/api/orders/import-template'
  },

  /** 删除订单 */
  deleteOrder(id: number): Promise<null> {
    return request({ url: `/orders/${id}`, method: 'delete' })
  },

  /** 编辑工单头信息 */
  updateOrder(id: number, data: {
    customer_name?: string
    priority?: number
    delivery_date?: string | null
    remark?: string | null
  }): Promise<Order> {
    return request({ url: `/orders/${id}`, method: 'patch', data })
  },

  /** 开始生产 */
  startOrder(id: number): Promise<Order> {
    return request({ url: `/orders/${id}/start`, method: 'post' })
  },

  /** 完结入库 */
  completeOrder(id: number): Promise<Order> {
    return request({ url: `/orders/${id}/complete`, method: 'post' })
  },

  /** 撤销（回到待排产） */
  revokeOrder(id: number): Promise<Order> {
    return request({ url: `/orders/${id}/revoke`, method: 'post' })
  },

  /** 停工（生产中 → 已停工，任务暂停；再次开工恢复） */
  pauseOrder(id: number): Promise<Order> {
    return request({ url: `/orders/${id}/pause`, method: 'post' })
  },

  /** 中止（生产中/已停工 → 已中止） */
  abortOrder(id: number): Promise<Order> {
    return request({ url: `/orders/${id}/abort`, method: 'post' })
  },

  /** 关闭（任意非终态 → 已关闭） */
  closeOrder(id: number): Promise<Order> {
    return request({ url: `/orders/${id}/close`, method: 'post' })
  },

  /** 配置/调整任务工艺路线（工艺确认阶段） */
  updateOrderRoutes(id: number, data: { tasks: Array<{ task_id: number; route_id: number }> }): Promise<Order> {
    return request({ url: `/orders/${id}/routes`, method: 'put', data })
  },

  /** 确认工艺（route_confirm → pending，校验全部任务工艺已配置） */
  confirmOrderRoute(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/orders/${id}/route-confirm`, method: 'post' })
  },

  /** 工单操作日志 */
  listOrderLogs(id: number, params?: Record<string, unknown>): Promise<PageResult<WorkOrderLog>> {
    return request({ url: `/orders/${id}/logs`, method: 'get', params })
  },

  /** 工序进度（按 op_type 分段） */
  getOrderProgress(id: number): Promise<{ stages: ProgressStage[]; percent: number }> {
    return request({ url: `/orders/${id}/progress`, method: 'get' })
  },

  /** 订单·工艺路线明细（各任务工序行 + 定制状态；订单编辑弹窗数据源） */
  getOrderRouteOps(id: number): Promise<OrderRouteOpsPayload> {
    return request({ url: `/orders/${id}/route-ops`, method: 'get' })
  },

  /** 订单·保存工艺路线定制（restore_standard=true 时后端清除该任务定制回退标准路线，ops 不校验） */
  updateOrderRouteOps(id: number, data: { tasks: OrderRouteOpsSubmitTask[] }): Promise<OrderRouteOpsPayload> {
    return request({ url: `/orders/${id}/route-ops`, method: 'put', data })
  },

  /** 订单·领料清单（按订单行 + 已发布 BOM 计算需求量） */
  getOrderPickList(id: number): Promise<OrderPickListPayload> {
    return request({ url: `/orders/${id}/pick-list`, method: 'get' })
  },
}
