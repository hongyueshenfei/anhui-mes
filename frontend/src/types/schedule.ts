/**
 * 虹越智造平台 · 二期（订单/排产）类型定义（与后端 API 契约对齐）
 */

// ==================== 订单中心 ====================

export interface Order {
  id: number
  order_no: string
  src: 'manual' | 'excel' | 'mini_program' | 'k3'
  k3_bill_no: string | null
  customer_name: string
  status: 'route_confirm' | 'pending' | 'scheduled' | 'in_progress' | 'paused' | 'produced' | 'aborted' | 'closed'
  priority: number
  delivery_date: string | null
  remark?: string | null
  created_by?: number | null
  creator?: { id: number; name: string; employee_no: string } | null
  lines_count?: number
  total_qty?: number
  /**
   * 工单中心列表（工单粒度）：订单按产品拆解，一行=一个工单
   * - task_no 工单号（唯一）
   * - order_id 所属订单 id（跳详情/操作用）
   * - product_name/product_code 产品信息
   * - task_status 工单自身状态（unscheduled/scheduled/in_progress/paused/done）
   */
  task_no?: string
  order_id?: number
  product_name?: string
  product_code?: string
  product_id?: number
  mold_id?: number | null
  qty?: number
  done_qty?: number
  task_status?: string
  is_locked?: boolean
  parent_task_id?: number | null
  parent_task_no?: string | null
  split_index?: number | null
  split_total?: number | null
  is_split_child?: boolean
  started_at?: string | null
  completed_at?: string | null
  progress?: { stages: ProgressStage[]; percent: number }
  created_at?: string
  lines?: OrderLine[]
}

/** 工序进度分段（按 op_type） */
export interface ProgressStage {
  op_type: string
  op_label: string
  plan_qty: number
  done_qty: number
  percent: number
  /** 工序在工艺路线中的序号，用于前端按工艺顺序排列节点 */
  seq?: number
}

/** 工单操作日志 */
export interface WorkOrderLog {
  id: number
  order_id: number
  action: 'edit' | 'start' | 'complete' | 'revoke' | 'split'
  from_status: string | null
  to_status: string | null
  operator_id: number | null
  operator_name: string | null
  remark: string | null
  meta?: Record<string, unknown> | null
  created_at: string
  operator?: { id: number; name: string } | null
}

export interface OrderLine {
  id: number
  order_id: number
  product_id: number
  product?: { id: number; code: string; name: string; spec?: string | null; unit?: string }
  qty: number
  delivery_date: string | null
  priority: number
  status: 'pending' | 'scheduled' | 'in_progress' | 'done'
  done_qty: number
  mold_id?: number | null
  line_no: number
  tasks?: ProdTask[]
}

export interface ProdTask {
  id: number
  task_no: string
  order_line_id: number
  product_id: number
  product?: { id: number; code: string; name: string }
  route_id?: number | null
  mold_id?: number | null
  mold?: { id: number; code: string; name: string } | null
  qty: number
  done_qty: number
  /** 已入库量（= Σ入库单，任务级冗余，可对账） */
  stocked_qty?: number
  status: 'unscheduled' | 'scheduled' | 'in_progress' | 'paused' | 'done' | 'split'
  priority: number
  delivery_date: string | null
  deviation_pct: number
  plan_start: string | null
  plan_end: string | null
  /** 实际开工（首条正报工时间，订单详情接口聚合带出） */
  actual_start?: string | null
  /** 实际完工（末条正报工时间，近似完工时点） */
  actual_end?: string | null
  /** 累计工时（Σ排程计划时长，小时；实际工时二期接入报工时长后替换） */
  total_hours?: number | null
  /** 工序级进度（stages，订单详情接口按任务聚合带出，展示分工序节点） */
  progress?: { stages: ProgressStage[]; percent: number }
  is_locked: number | boolean
  parent_task_id?: number | null
  split_index?: number | null
  split_total?: number | null
  is_split_parent?: boolean
  source?: string
  schedules?: TaskSchedule[]
  /** 所属订单行（任务列表接口带出，报工选择按工单分组） */
  line?: { order?: { id?: number; order_no?: string; customer_name?: string } }
}

/** Excel 导入结果 */
export interface ImportResult {
  success: boolean
  imported_count: number
  skipped_count: number
  error_rows: Array<{ row: number; product_code: string; error: string }>
  order?: Order | null
}

// ==================== 排产 ====================

export interface TaskSchedule {
  id: number
  task_id: number
  task_no?: string
  seq: number
  route_op_id: number
  op_type: string
  op_name?: string
  machine_id: number | null
  workshop_id?: number | null
  workshop_name?: string | null
  mold_id: number | null
  plan_start: string
  plan_end: string
  qty: number
  done_qty?: number
  /** 该工序报工累计完工量（任务详情接口带出，手动报工展示/校验） */
  reported_qty?: number
  /** 按工序计划量计算的累计完工百分比 */
  progress_pct?: number
  status: 'pending' | 'running' | 'paused' | 'done'
  source: string
  is_locked?: number
  is_overlap?: number
  is_interrupted?: number
  is_conflict?: number
  /** APS一期：预计起止（保存时=计划；报工驱动滚动，计划永不自动改） */
  forecast_start?: string | null
  forecast_end?: string | null
  /** APS一期：实际起止（报工推断：首笔正报工/累计合格达工序量） */
  actual_start?: string | null
  actual_end?: string | null
  /** 每条正报工的实际时间段；间隔表示中间没有报工/生产事实 */
  actual_segments?: Array<{ start: string; end: string; qty: number }>
  /** APS一期：冻结区标记（当前起未来 N 天不可直接手工重排） */
  is_frozen?: number
  /** APS一期：排入时实际采用的换模分钟数（矩阵>工序>机台） */
  changeover_minutes?: number
  note?: string | null
  /** 甘特展示辅助 */
  product_code?: string
  product_name?: string
  order_no?: string
  mold_code?: string | null
  machine?: { id: number; code: string; name?: string } | null
}

/** 任务池条目 */
export interface PoolTask {
  id: number
  task_no: string
  product_id: number
  product_code: string
  product_name: string
  qty: number
  priority: number
  delivery_date: string | null
  is_overdue: boolean
  mold_id: number | null
  mold_code: string
  /** 模具当前状态；维修中/已报废时任务仍展示在池中但不可拖拽排产 */
  mold_status?: 'in_use' | 'idle' | 'repair' | 'scrapped' | null
  mold_status_label?: string | null
  mold_unavailable?: boolean
  default_mold_code: string
  default_route_id: number | null
  route_published: boolean
  /** 任务池首道生效工序，决定甘特图可拖入的资源类型 */
  route_ops_count: number
  required_op_type: string | null
  required_op_name: string | null
  required_resource_type: 'injection' | 'blow' | 'paint_line' | 'effect_line' | 'pack_line' | null
  required_resource_label: string
  estimated_hours: number | null
  estimate_ok: boolean
  order_no: string
  customer_name: string
}

/** 甘特时间线 */
export interface TimelineData {
  start: string
  end: string
  shift: {
    two_shift: { shift1: { name: string; start: string; end: string }; shift2: { name: string; start: string; end: string } }
    day: { start: string; end: string }
  }
  machines: Array<{
    id: number
    code: string
    name: string
    type: string
    workshop: string
    capacity_per_hour: number
    shift_mode: string
    schedules: TaskSchedule[]
  }>
  day_labels: string[]
}

export interface InsertPreview {
  task: {
    id: number
    task_no: string
    product_code: string
    product_name: string
    qty: number
    priority: number
  }
  strategy: 'wait' | 'interrupt'
  insert_chain: TaskSchedule[]
  interrupted: null | { task_id: number; task_no: string; done_qty: number; remaining_qty: number }
  changed_tasks: Array<{
    task_id: number
    task_no: string
    old_end: string | null
    new_end: string | null
    delay_hours: number
    reason: string
  }>
  affected_orders: Array<{
    order_id: number
    order_no: string
    customer_name: string
    delay_hours: number
    task_count: number
    tasks: unknown[]
  }>
}

export interface ScheduleVersion {
  id: number
  version_no: number
  type: 'manual' | 'ai' | 'insert'
  status: 'draft' | 'applied' | 'rolled_back'
  snapshot: {
    before: { schedules: TaskSchedule[]; tasks: Record<string, unknown> }
    after: { schedules: TaskSchedule[]; tasks: Record<string, unknown> }
  }
  affected_orders: InsertPreview['affected_orders'] | null
  biz_key?: string | null
  created_by?: number | null
  created_at?: string
  applied_at?: string | null
  rolled_back_at?: string | null
  creator?: { id: number; name: string; employee_no: string } | null
}

/** 排产结果明细（甘特 + 明细双视图） */
export interface ScheduleDetail {
  task: {
    id: number
    task_no: string
    product_code: string
    product_name: string
    qty: number
    done_qty: number
    status: string
    status_label: string
    priority: number
    delivery_date: string | null
    plan_start: string | null
    plan_end: string | null
    deviation_pct: number
    is_locked: number
    mold_code: string
    order_no: string
  }
  schedules: Array<{
    id: number
    seq: number
    op_type: string
    op_name: string
    machine_id: number | null
    machine_code: string | null
    machine_name: string | null
    /** 所属车间（由机台归属推导，班组下线后资源归属口径） */
    workshop_name: string | null
    mold_id: number | null
    plan_start: string
    plan_end: string
    /** APS一期：预计起止（报工滚动；晚于计划表示滞后） */
    forecast_start: string | null
    forecast_end: string | null
    /** APS一期：实际起止（报工推断） */
    actual_start: string | null
    actual_end: string | null
    /** APS一期：冻结区标记 */
    is_frozen: number
    /** APS一期：实际采用的换模分钟数 */
    changeover_minutes: number
    qty: number
    /** 该工序已报工完工数量 */
    reported_qty: number
    /** 该工序完工进度百分比 */
    progress_pct: number
    status: string
    source: string
    is_interrupted: number
    done_qty: number
    is_overlap: number
    note: string | null
    report_logs?: ScheduleReportLog[]
  }>
}

/** 排产明细中某道工序的完整报工流水（含冲减记录） */
export interface ScheduleReportLog {
  id: number
  qty: number
  defect_qty: number
  sign: number
  report_time: string | null
  start_time: string | null
  end_time: string | null
  /** 按资源班制拆分后的有效作业段；白班跨天时不包含夜间空档 */
  work_segments?: Array<{ start: string; end: string }>
  work_date: string | null
  shift_code: string | null
  source: string
  source_label?: string | null
  operator_name: string | null
  worker_name: string | null
  machine_code: string | null
  remark: string | null
}

/** 已排产任务明细列表 */
export interface ScheduleDetailList {
  total: number
  items: ScheduleDetail[]
}

/** APS一期：顺延建议（建议层——系统生成，计划员采纳/驳回，绝不自动改排程） */
export interface ScheduleSuggestion {
  id: number
  type: 'postpone'
  trigger_report_id: number | null
  task_id: number | null
  /** 受影响排程明细（工序级：原计划结束/滚动后预计） */
  affected: Array<{
    schedule_id: number
    seq: number
    op_name: string
    plan_end: string
    forecast_end: string | null
  }>
  /** 延期订单清单 */
  delayed_orders: Array<{
    order_id: number | null
    order_no: string | null
    task_no: string | null
    product_code: string | null
    product_name: string | null
    delivery_date: string | null
    forecast_end: string | null
  }>
  reason: string
  status: 'pending' | 'accepted' | 'dismissed'
  processed_by?: number | null
  processed_at?: string | null
  created_at?: string
  task?: { id: number; task_no: string; delivery_date: string | null } | null
}

/** 自动排产提交结果 */
export interface AutoScheduleException {
  task_id: number
  task_no: string
  code: string
  reason: string
}

export interface AutoSubmitResult {
  job_id: string | null
  task_count: number
  machine_count: number
  message: string
  exceptions?: AutoScheduleException[]
  status?: 'queued' | 'no_valid_tasks' | string
}

/** 自动排产轮询结果 */
export interface AutoPollResult {
  job_id: string
  status: 'queued' | 'pending' | 'running' | 'done' | 'failed' | string
  solution: { assignments: Array<{ task_id: number; machine_id: number; plan_start: string; duration_minutes?: number }>; meta?: Record<string, unknown> } | null
  meta: Record<string, unknown> | null
  error: string | null
}
