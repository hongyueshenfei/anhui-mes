/**
 * 订单编辑 · 工艺路线明细 / 领料清单 类型定义（与后端 API 契约对齐）
 *
 * 对应接口：
 * - GET  /orders/:id/route-ops  查询订单各任务工艺路线明细（含定制状态）
 * - PUT  /orders/:id/route-ops  保存任务工艺路线定制 ops（空数组 = 恢复标准路线）
 * - GET  /orders/:id/pick-list  领料清单（按订单行 + 已发布 BOM 计算需求量）
 * - GET  /system/users/options  负责人候选（下拉数据源）
 */

/** 工序行草稿（提交 / 行内编辑的最小契约字段） */
export interface OrderRouteOpDraft {
  seq: number
  op_type: string
  op_name: string
  workshop_id: number | null
  /** 成型工序工作中心（molding_* 写入此字段） */
  machine_id: number | null
  /** 人工线工序工作中心（paint/effect/pack 写入此字段） */
  line_id: number | null
  std_hours: number | null
  owner_id: number | null
  remark: string | null
  /** 本工序计划数量（默认 = 产品/任务数量；作为该工序排产与报工的数量依据） */
  qty: number | null
}

/** 工序行展示视图（草稿 + 展示辅助字段） */
export type OrderRouteOpView = OrderRouteOpDraft & {
  workshop_name?: string | null
  machine_name?: string | null
  line_name?: string | null
  owner_name?: string | null
  /**
   * 以下为排产/完工只读信息（由排程与报工自动带出，确认工艺阶段不可修改）
   */
  /** 排产开始时间（该工序最早排程段起点） */
  plan_start?: string | null
  /** 排产结束时间（该工序最晚排程段终点） */
  plan_end?: string | null
  /** 排产机台/产线（同工序多段多台 → 数组，多选展示） */
  machines?: string[] | null
  /** 完工数量 = 该工序各排程段报工净额之和 */
  done_qty?: number | null
  /** 完工进度（done_qty / 工序数量 × 100，0-100） */
  percent?: number | null
}

/** 订单下单个生产任务的工艺明细 */
export interface OrderTaskRouteOps {
  task_id: number
  task_no: string
  product_id: number | null
  product_name: string | null
  qty: number
  route_id: number | null
  route_name: string | null
  route_version: string | null
  /** true=已定制工序明细；false=沿用标准路线 */
  overridden: boolean
  ops: OrderRouteOpView[]
}

/** GET/PUT /orders/:id/route-ops 响应 */
export interface OrderRouteOpsPayload {
  order_id: number
  order_no: string
  status: string
  editable: boolean
  tasks: OrderTaskRouteOps[]
}

/** 领料清单 · 物料行 */
export interface OrderPickItem {
  seq: number
  item_code: string
  item_name: string
  spec: string | null
  unit: string
  unit_qty: number
  loss_rate: number
  /** 订单需求量 = round(unit_qty * 订单数量 * (1 + loss_rate/100), 4) */
  required_qty: number
  issued_qty: number
  remain_qty: number
  /** 仓库批次（未接入时 null） */
  batch_no: string | null
  /** 库存可用量（未接入时 null） */
  available_qty: number | null
  is_key: 0 | 1
}

/** 领料清单 · 订单行分组 */
export interface OrderPickLine {
  line_no: number
  product_id: number | null
  product_code: string | null
  product_name: string | null
  qty: number
  /** 无已发布 BOM 时为空（行头显示「无已发布 BOM」） */
  bom_id: number | null
  bom_version: string | null
  bom_status: string | null
  items: OrderPickItem[]
}

/** GET /orders/:id/pick-list 响应 */
export interface OrderPickListPayload {
  order_id: number
  order_no: string
  lines: OrderPickLine[]
  summary: { item_count: number }
}

/** 负责人候选（/system/users/options） */
export interface UserOption {
  id: number
  name: string
  employee_no: string
  workshop_id: number | null
  workshop_name: string | null
}

/** 订单工艺明细提交项（PUT /orders/:id/route-ops） */
export interface OrderRouteOpsSubmitTask {
  task_id: number
  route_id?: number | null
  /**
   * true = 清除该任务的订单级定制，回退到工艺路线库标准工序。
   * 此时后端不再校验 ops，ops 传空数组即可。
   */
  restore_standard?: boolean
  ops?: OrderRouteOpDraft[]
}