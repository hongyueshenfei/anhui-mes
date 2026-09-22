/**
 * 虹越智造平台 · 前端类型定义（与后端 API 契约对齐）
 */

/** 统一响应结构（后端约定 {code, message, data}） */
export interface ApiResponse<T = unknown> {
  code: number
  message: string
  data: T
}

/** 分页结构 */
export interface PageResult<T> {
  current_page: number
  data: T[]
  first_page_url: string | null
  from: number | null
  last_page: number
  last_page_url: string | null
  links: unknown[]
  next_page_url: string | null
  path: string
  per_page: number
  prev_page_url: string | null
  to: number | null
  total: number
}

/** 登录用户 */
export interface AuthUser {
  id: number
  employee_no: string
  name: string
  phone: string | null
  job_type: string
  workshop: { id: number; name: string } | null
  wecom_bound: boolean
  wx_bound: boolean
  roles: string[]
  permissions: string[]
}

/** 登录响应 */
export interface LoginResult {
  token: string
  token_type: string
  expires_in: number
  user: {
    id: number
    employee_no: string
    name: string
    job_type: string
    roles: string[]
    permissions: string[]
  }
}

/** 机台 / 产线资源（喷漆/效果/包装产线绑定车间作为资源归属） */
export interface Machine {
  id: number
  code: string
  name: string
  type: 'injection' | 'blow' | 'paint_line' | 'effect_line' | 'pack_line'
  workshop: string
  workshop_id?: number | null
  workshop_name?: string | null
  type_label?: string
  capacity_per_hour: number
  mold_change_min: number
  shift_mode: 'two_shift' | 'day'
  status: 'running' | 'stopped' | 'maintenance'
  is_active: 0 | 1
  created_at?: string
}

/** 车间（后端 /plm/workshops，机台/产线按车间分组归属） */
export interface PlmWorkshop {
  id: number
  code: string
  name: string
  sort: number
  is_active: 0 | 1
  remark: string | null
  /** 车间下机台/产线数量（删除保护：>0 拒删 409） */
  machines_count?: number
}

/** 产品 */
export interface Product {
  id: number
  code: string
  name: string
  spec: string | null
  unit: string
  status: 'developing' | 'trial' | 'mass' | 'obsolete'
  default_mold_id: number | null
  default_route_id: number | null
  weight: number | null
  default_mold?: { id: number; code: string; name: string } | null
  default_route?: { id: number; name: string; version: string } | null
  /** 已生成数量 = Σ 该产品所有生产任务 done_qty（报工合格累计，金蝶同步基础信息外的生产统计） */
  produced_qty?: number
}

/** BOM */
export interface Bom {
  id: number
  product_id: number
  version: string
  status: 'draft' | 'published' | 'disabled'
  k3_push_status: string
  published_at?: string | null
  published_by?: number | null
  disabled_at?: string | null
  disabled_by?: number | null
  revoked_at?: string | null
  revoked_by?: number | null
  remark: string | null
  lines_count?: number
  lines?: BomLine[]
}

/** BOM 明细 */
export interface BomLine {
  id?: number
  seq: number
  item_code: string
  item_name: string
  qty: number
  unit: string
  loss_rate: number
  is_key: 0 | 1
}

/**
 * BOM 列表混合行（金蝶物料清单样式：BOM 头行 + 明细行交错）
 * row_type=bom 为头行；row_type=line 为紧随其后的明细行。
 */
export interface BomListRow {
  row_type: 'bom' | 'line'
  // BOM 头行
  bom_id?: number
  product_id?: number
  product_code?: string
  product_name?: string
  version?: string
  status?: 'draft' | 'published' | 'disabled'
  bom_short_name?: string | null
  lines_count?: number
  published_at?: string | null
  remark?: string | null
  k3_push_status?: string | null
  // 明细行
  line_id?: number
  seq?: number
  item_code?: string
  item_name?: string
  spec?: string
  qty?: string
  unit?: string
  loss_rate?: string
  is_key?: 0 | 1
  is_semi_finished?: boolean
  semi_finished_product_id?: number | null
}

/** 工艺路线 */
export interface PlmRoute {
  id: number
  /** 路线名称（独立路线库，不归属产品） */
  name: string
  version: string
  status: 'draft' | 'published' | 'disabled'
  remark: string | null
  ops_count?: number
  ops?: RouteOp[]
}

/** 工序 */
export interface RouteOp {
  id?: number
  /** 路线内连续序号（1/2/3/...） */
  seq: number
  op_type: string
  op_name: string
  /** 生产车间（车间实体化后显式选择；NULL=按工序类型字典推导） */
  workshop_id?: number | null
  mold_change_min: number | null
  shift_mode: 'two_shift' | 'day' | null
  machine_type: string | null
  rate_key: string | null
  /** 成型工序默认机台（后端外键，指向 machines.id） */
  default_machine_id?: number
  /** 人工线工序默认产线（后端外键，指向 machines.id 的 line 行） */
  default_line_id?: number
  /** 标准工时（小时）；工艺路线库 / 订单级明细均可保存 */
  std_hours?: number | null
  /** 工序负责人（users.id） */
  owner_id?: number | null
  /** 工序负责人姓名（后端 join users 回填） */
  owner_name?: string | null
  /** 工序备注 */
  remark?: string | null
  /** 后端不返回；前端镜像推导用（由 op_type 经 OP_TYPE_WORKSHOP 推导） */
  workshop?: string
}

/** 车间（与后端 RouteOp::workshopForOpType 的映射一致） */
export type Workshop = 'injection' | 'blow' | 'paint' | 'effect' | 'pack'

/** 车间中文标签 */
export const WORKSHOP_LABEL: Record<Workshop, string> = {
  injection: '注塑车间',
  blow: '吹塑车间',
  paint: '喷漆车间',
  effect: '效果车间',
  pack: '包装车间',
}

/** 工序类型 → 适用车间（前端镜像后端 RouteOp::workshopForOpType） */
export const OP_TYPE_WORKSHOP: Record<string, Workshop | undefined> = {
  molding_injection: 'injection',
  molding_blow: 'blow',
  paint: 'paint',
  effect: 'effect',
  pack: 'pack',
}

/** 工序类型字典（后端 op_types 表，可维护化；替代此前硬编码 5 项） */
export interface OpType {
  id: number
  /** 工序类型键（route_ops.op_type 引用） */
  op_type: string
  op_name: string
  /** 是否成型工序（1=需机台/换模） */
  is_molding: boolean
  /** 成型工序机台类型（injection/blow），非成型为 null */
  machine_type: string | null
  /** 适用车间（决定机台/产线候选） */
  workshop: Workshop
  /** 展示排序（小在前） */
  sort: number
  /** 1=启用 0=停用（停用后不再出现在下拉，历史 route_ops 不受影响） */
  is_active: boolean
  remark?: string | null
  /** 引用次数（route_ops 引用该类型的数量，删除保护用） */
  used_count?: number
}

/** 工序模板（后端 opTemplates 返回：op_types 启用项 + 兜底映射合并） */
export interface OpTemplate {
  op_type: string
  op_name: string
  is_molding: boolean
  machine_type: string | null
  workshop: Workshop
  /** 工序类型字典排序（工序序号口径：路线内连续 1/2/3/...） */
  sort?: number
}

/** 模具 */
export interface Mold {
  id: number
  code: string
  name: string
  type: 'injection' | 'blow'
  status: 'in_use' | 'idle' | 'repair' | 'scrapped'
  location: string | null
  cavity_count: number
  lifetime_openings: number
  opened_count: number
  purchase_cost: number | null
  purchase_date: string | null
  vendor: string | null
  maintain_cycle_days: number | null
  last_maintain_at: string | null
  next_maintain_at: string | null
  remaining_openings?: number
  maintain_due?: boolean
  life_used_pct?: number
  products?: { id: number; code: string; name: string }[]
  machines?: { id: number; code: string; name: string }[]
}

/** 用户 */
export interface SysUser {
  id: number
  employee_no: string
  name: string
  phone: string | null
  job_type: string
  status: 0 | 1
  workshop_id: number | null
  workshop_name?: string | null
  wecom_userid: string | null
  wx_openid: string | null
  wecom_bound: boolean
  wx_bound: boolean
  roles: string[]
}

/** 角色 */
export interface SysRole {
  id: number
  name: string
  guard_name: string
  permissions: string[]
}

/** 审计日志 */
export interface AuditLog {
  id: number
  user_id: number | null
  user?: { id: number; employee_no: string; name: string } | null
  module: string
  action: string
  target_type: string | null
  target_id: number | null
  detail: Record<string, unknown> | null
  ip: string | null
  created_at: string
}
