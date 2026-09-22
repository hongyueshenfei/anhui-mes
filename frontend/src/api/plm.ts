import { request } from './axios'
import type { Bom, BomLine, BomListRow, Machine, Mold, OpTemplate, OpType, PageResult, PlmRoute, PlmWorkshop, Product, RouteOp } from '@/types'
import type { DevelopTask } from '@/types/develop'
import { mockBomLines, mockBoms, mockMachines, mockMolds, mockOpTypes, mockProducts, mockRoutes, mockRouteOps, mockWorkshops } from '@/mock/mockData'

/**
 * PLM API（对应后端 app/Modules/Plm）
 * 所有读取接口均带 mock 兜底：后端不可用时页面仍可演示。
 */
/**
 * mock 兜底（BOM 生命周期）：直接改 mockBoms 可变数组的状态，让 UI 立即反映
 */
function mockLifecycleResult(action: 'revoke' | 'disable' | 'enable', id: number) {
  const bom = mockBoms.find((b) => b.id === id)
  if (!bom) return { id, status: 'draft' }
  if (action === 'revoke') bom.status = 'draft'
  else if (action === 'disable') bom.status = 'disabled'
  else if (action === 'enable') bom.status = 'published'
  return { id: bom.id, status: bom.status }
}

/** mock 兜底（工艺路线生命周期）：直接改 mockRoutes 可变数组的状态，让 UI 立即反映 */
function mockRouteLifecycleResult(action: 'revoke' | 'disable' | 'enable', id: number) {
  const route = mockRoutes.find((r) => r.id === id)
  if (!route) return { id, status: 'draft' }
  if (action === 'revoke') route.status = 'draft'
  else if (action === 'disable') route.status = 'disabled'
  else if (action === 'enable') route.status = 'published'
  return { id: route.id, status: route.status }
}

/** mock 兜底：由 mockBoms/mockBomLines 展平为混合行（demo 模式，后端不可用时演示用） */
function buildMockBomListRows(): BomListRow[] {
  const productNames: Record<number, string> = { 1: '直口杯 500ml', 2: '圆瓶 1L', 3: '方盒 800ml' }
  const rows: BomListRow[] = []
  for (const bom of mockBoms) {
    rows.push({
      row_type: 'bom',
      bom_id: bom.id,
      product_id: bom.product_id,
      product_code: `P-000${bom.product_id}`,
      product_name: productNames[bom.product_id] ?? '',
      version: bom.version,
      status: bom.status,
      bom_short_name: null,
      lines_count: bom.lines_count ?? mockBomLines.length,
      published_at: bom.published_at,
      remark: bom.remark,
      k3_push_status: bom.k3_push_status,
    })
    mockBomLines.forEach((line, index) => {
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
        is_semi_finished: false,
        semi_finished_product_id: null,
      })
    })
  }
  return rows
}

export const plmApi = {
  // ============ 机台 ============
  listMachines(params?: Record<string, unknown>): Promise<PageResult<Machine> | Machine[]> {
    return request(
      { url: '/plm/machines', method: 'get', params },
      mockMachines as unknown as PageResult<Machine>,
    )
  },
  createMachine(data: Partial<Machine>): Promise<Machine> {
    return request({ url: '/plm/machines', method: 'post', data })
  },
  updateMachine(id: number, data: Partial<Machine>): Promise<Machine> {
    return request({ url: `/plm/machines/${id}`, method: 'put', data })
  },
  recalculateMachineSchedules(id: number): Promise<{ task_count: number; changed_count: number }> {
    return request({ url: `/plm/machines/${id}/recalculate-schedules`, method: 'post' })
  },
  deleteMachine(id: number): Promise<null> {
    return request({ url: `/plm/machines/${id}`, method: 'delete' })
  },

  /**
   * 产线资源（人工线：喷漆线/效果线/包装线）。
   * 后端无独立「产线」表——产线即 machines 表中对应 type 的行，
   * 因此直接复用 listMachines 透传过滤条件。
   */
  listLines(params: { workshop?: string; type?: 'paint_line' | 'effect_line' | 'pack_line'; all?: boolean }): Promise<PageResult<Machine> | Machine[]> {
    return request(
      { url: '/plm/machines', method: 'get', params },
      mockMachines as unknown as PageResult<Machine>,
    )
  },

  // ============ 车间 ============
  listWorkshops(params?: Record<string, unknown>): Promise<PageResult<PlmWorkshop> | PlmWorkshop[]> {
    return request({ url: '/plm/workshops', method: 'get', params }, mockWorkshops as unknown as PageResult<PlmWorkshop>)
  },
  createWorkshop(data: Partial<PlmWorkshop>): Promise<PlmWorkshop> {
    return request({ url: '/plm/workshops', method: 'post', data })
  },
  updateWorkshop(id: number, data: Partial<PlmWorkshop>): Promise<PlmWorkshop> {
    return request({ url: `/plm/workshops/${id}`, method: 'put', data })
  },
  deleteWorkshop(id: number): Promise<null> {
    return request({ url: `/plm/workshops/${id}`, method: 'delete' })
  },

  // ============ 产品 ============
  listProducts(params?: Record<string, unknown>): Promise<PageResult<Product> | Product[]> {
    return request({ url: '/plm/products', method: 'get', params }, mockProducts as unknown as PageResult<Product>)
  },
  getProduct(id: number): Promise<Product> {
    return request({ url: `/plm/products/${id}`, method: 'get' })
  },
  createProduct(data: Partial<Product>): Promise<Product> {
    return request({ url: '/plm/products', method: 'post', data })
  },
  updateProduct(id: number, data: Partial<Product>): Promise<Product> {
    return request({ url: `/plm/products/${id}`, method: 'put', data })
  },
  transitionStatus(id: number, status: string): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/products/${id}/status`, method: 'post', data: { status } })
  },
  deleteProduct(id: number): Promise<null> {
    return request({ url: `/plm/products/${id}`, method: 'delete' })
  },

  // ============ BOM ============
  listBoms(productId: number): Promise<Bom[]> {
    return request({ url: `/plm/products/${productId}/boms`, method: 'get' }, mockBoms)
  },
  /** 全量混合列表（金蝶物料清单样式：BOM 头行 + 明细行交错） */
  listAllBoms(params?: { product_code?: string; status?: string; search?: string }): Promise<BomListRow[]> {
    return request({ url: '/plm/boms', method: 'get', params }, buildMockBomListRows())
  },
  /** 按产品编码查该产品全部 BOM 版本（半成品跳转入口） */
  listBomsByProductCode(code: string): Promise<Bom[]> {
    return request({ url: `/plm/products/by-code/${code}/boms`, method: 'get' }, mockBoms)
  },
  getBom(id: number): Promise<Bom & { lines: BomLine[] }> {
    return request({ url: `/plm/boms/${id}`, method: 'get' }, { ...mockBoms[0] } as Bom & { lines: BomLine[] })
  },
  createBom(productId: number, data: { version: string; remark?: string; lines: BomLine[] }): Promise<Bom> {
    return request({ url: `/plm/products/${productId}/boms`, method: 'post', data })
  },
  updateBom(id: number, data: { lines: BomLine[] }): Promise<Bom> {
    return request({ url: `/plm/boms/${id}`, method: 'put', data })
  },
  publishBom(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/boms/${id}/publish`, method: 'post' })
  },
  revokeBom(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/boms/${id}/revoke`, method: 'post' }, mockLifecycleResult('revoke', id))
  },
  disableBom(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/boms/${id}/disable`, method: 'post' }, mockLifecycleResult('disable', id))
  },
  enableBom(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/boms/${id}/enable`, method: 'post' }, mockLifecycleResult('enable', id))
  },
  deleteBom(id: number): Promise<null> {
    return request({ url: `/plm/boms/${id}`, method: 'delete' }, null)
  },

  // ============ 工艺路线 ============
  /** 工艺路线列表（独立路线库，可关键字/状态过滤） */
  listRoutes(params?: { keyword?: string; status?: string }): Promise<PlmRoute[]> {
    return request({ url: '/plm/routes', method: 'get', params }, mockRoutes)
  },
  /** 全部已发布工艺路线（产品档案「默认工艺」下拉数据源） */
  listPublishedRoutesAll(): Promise<PlmRoute[]> {
    return request({ url: '/plm/routes/published-all', method: 'get' }, mockRoutes)
  },
  getRoute(id: number): Promise<PlmRoute & { ops: RouteOp[] }> {
    const route = mockRoutes.find((r) => r.id === id) ?? mockRoutes[0]
    return request({ url: `/plm/routes/${id}`, method: 'get' }, { ...route, ops: mockRouteOps.map((o) => ({ ...o })) })
  },
  createRoute(data: { name: string; version: string; remark?: string; ops: RouteOp[] }): Promise<PlmRoute> {
    return request({ url: '/plm/routes', method: 'post', data })
  },
  updateRoute(id: number, data: { name?: string; version?: string; remark?: string; ops?: RouteOp[] }): Promise<PlmRoute> {
    return request({ url: `/plm/routes/${id}`, method: 'put', data })
  },
  publishRoute(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/routes/${id}/publish`, method: 'post' })
  },
  /** 下架工艺路线（已发布 → 草稿，可编辑后重新发布） */
  revokeRoute(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/routes/${id}/revoke`, method: 'post' }, mockRouteLifecycleResult('revoke', id))
  },
  /** 禁用工艺路线（任意状态 → 已禁用） */
  disableRoute(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/routes/${id}/disable`, method: 'post' }, mockRouteLifecycleResult('disable', id))
  },
  /** 启用工艺路线（已禁用 → 已发布） */
  enableRoute(id: number): Promise<{ id: number; status: string }> {
    return request({ url: `/plm/routes/${id}/enable`, method: 'post' }, mockRouteLifecycleResult('enable', id))
  },
  /** 删除工艺路线（任意状态软删；存在引用时 409 保护） */
  deleteRoute(id: number): Promise<null> {
    const idx = mockRoutes.findIndex((r) => r.id === id)
    if (idx >= 0) mockRoutes.splice(idx, 1)
    return request({ url: `/plm/routes/${id}`, method: 'delete' }, null)
  },
  copyRoute(id: number, data: { name: string; version: string; remark?: string }): Promise<PlmRoute> {
    return request({ url: `/plm/routes/${id}/copy`, method: 'post', data })
  },
  opTemplates(): Promise<OpTemplate[]> {
    return request({ url: '/plm/op-templates', method: 'get' }, [
      { op_type: 'molding_injection', op_name: '注塑成型', is_molding: true, machine_type: 'injection', workshop: 'injection', sort: 1 },
      { op_type: 'molding_blow', op_name: '吹塑成型', is_molding: true, machine_type: 'blow', workshop: 'blow', sort: 2 },
      { op_type: 'paint', op_name: '喷漆', is_molding: false, machine_type: null, workshop: 'paint', sort: 3 },
      { op_type: 'effect', op_name: '效果处理', is_molding: false, machine_type: null, workshop: 'effect', sort: 4 },
      { op_type: 'pack', op_name: '包装', is_molding: false, machine_type: null, workshop: 'pack', sort: 5 },
    ])
  },

  // ============ 工序类型字典（可维护化） ============
  listOpTypes(params?: Record<string, unknown>): Promise<OpType[]> {
    return request({ url: '/plm/op-types', method: 'get', params }, mockOpTypes)
  },
  createOpType(data: Partial<OpType>): Promise<OpType> {
    return request({ url: '/plm/op-types', method: 'post', data })
  },
  updateOpType(id: number, data: Partial<OpType>): Promise<OpType> {
    return request({ url: `/plm/op-types/${id}`, method: 'put', data })
  },
  deleteOpType(id: number): Promise<null> {
    return request({ url: `/plm/op-types/${id}`, method: 'delete' })
  },
  /** 产能预览（验收 A9：输入 1000 件即时显示需 X 小时） */
  previewCapacity(params: { qty: number; capacity_per_hour?: number; mold_change_min?: number }): Promise<{
    ok: boolean
    message: string
    hours: number | null
  }> {
    return request({ url: '/plm/capacity/preview', method: 'get', params })
  },

  // ============ 模具 ============
  listMolds(params?: Record<string, unknown>): Promise<PageResult<Mold> | Mold[]> {
    return request({ url: '/plm/molds', method: 'get', params }, mockMolds as unknown as PageResult<Mold>)
  },
  getMold(id: number): Promise<Mold> {
    return request({ url: `/plm/molds/${id}`, method: 'get' })
  },
  createMold(data: Partial<Mold> & { product_ids?: number[]; machine_ids?: number[] }): Promise<Mold> {
    return request({ url: '/plm/molds', method: 'post', data })
  },
  updateMold(id: number, data: Partial<Mold> & { product_ids?: number[]; machine_ids?: number[] }): Promise<Mold> {
    return request({ url: `/plm/molds/${id}`, method: 'put', data })
  },
  deleteMold(id: number): Promise<null> {
    return request({ url: `/plm/molds/${id}`, method: 'delete' })
  },
  addMaintain(id: number, data: { type: 'maintain' | 'repair'; at?: string; cost?: number; vendor?: string; remark?: string }): Promise<unknown> {
    return request({ url: `/plm/molds/${id}/maintain`, method: 'post', data })
  },
  moldLife(id: number): Promise<{
    mold: { id: number; code: string; opened_count: number }
    logs: Array<{
      opening_count: number
      created_at: string
      order_no?: string
      order_id?: number
      task?: { line?: { order?: { id: number; order_no: string } } }
    }>
  }> {
    return request({
      url: `/plm/molds/${id}/life`,
      method: 'get',
    }, {
      mold: { id, code: 'M-001', opened_count: 35600 },
      logs: [{ opening_count: 100, created_at: '2025-01-05 10:00:00' }],
    })
  },
}
/**
 * 四期：新品开发流程 API（阶段看板：询价→立项→设计→开模→试模→小批→量产）
 */
export const plmApiV4 = {
  /** 看板数据（阶段定义 + 全部任务） */
  develop(): Promise<{ stages: Array<{ key: string; label: string; color: string }>; items: DevelopTask[]; by_stage?: Record<string, DevelopTask[]>; total: number; overdue_count: number }> {
    return request({ url: '/plm/develop', method: 'get' })
  },

  /** 新建开发任务 */
  createDevelop(data: Partial<DevelopTask> & { product_name: string; customer_name: string }): Promise<DevelopTask> {
    return request({ url: '/plm/develop', method: 'post', data })
  },

  /** 推进阶段（留审计） */
  advanceDevelop(id: number, operatorName?: string): Promise<DevelopTask> {
    return request({ url: `/plm/develop/${id}/advance`, method: 'post', data: { operator_name: operatorName } })
  },
}
