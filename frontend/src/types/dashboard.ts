/**
 * 虹越智造平台 · 四期（看板/集成）类型定义（与后端 API 契约对齐）
 */

// ==================== 工作台 ====================

export interface WorkbenchStats {
  /** 订单统计 */
  orders: { total: number; pending: number; in_progress: number; overdue: number; done: number }
  /** 任务统计 */
  tasks: { total: number; unscheduled: number; running: number; overdue: number; behind: number }
  /** 今日报工/入库 */
  today: { report_qty: number; stock_in_qty: number; report_count: number }
  /** 红灯概览（按来源计数） */
  red_lights: { total: number; by_source: Array<{ source: string; source_label: string; count: number }> }
  /** 机台运行概况 */
  machines: { total: number; running: number; maintenance: number; utilization_avg: number }
}

export interface WorkbenchData {
  stats: WorkbenchStats
  /** 红灯速览（前 5 条未处理） */
  top_red_lights: RedLight[]
  /** 临期 3 天订单 */
  soon_orders: Array<{ order_id: number; order_no: string; customer_name: string; delivery_date: string; total_qty: number; progress_pct: number }>
  /** 今日派工任务 */
  today_tasks: Array<{ task_id: number; task_no: string; product_code: string; product_name: string; machine_code: string | null; plan_start: string; plan_end: string; status: string; progress_pct: number }>
}

// ==================== 订单履约统计 ====================

export interface OrderProgress {
  order_id: number
  order_no: string
  k3_bill_no: string | null
  customer_name: string
  priority: number
  delivery_date: string | null
  status: 'route_confirm' | 'pending' | 'scheduled' | 'in_progress' | 'paused' | 'produced' | 'aborted' | 'closed' | 'delivered'
  status_label: string
  total_qty: number
  done_qty: number
  /** 工序报工工作量，不等同于最终成品数量；按所有已排工序加权汇总 */
  process_plan_qty: number
  process_done_qty: number
  process_progress_pct: number
  progress_pct: number
  /** 逾期/临期标记：overdue 红 / due_soon 黄 / normal 正常 */
  due_flag: 'overdue' | 'due_soon' | 'normal'
  delay_days: number
  tasks_count: number
  task_status: 'unscheduled' | 'scheduled' | 'in_progress' | 'done'
  /** 订单履约状态：按期/延期完成、生产中、预计延期、已延期、未开工、暂停 */
  fulfillment_status?: 'completed_on_time' | 'completed_late' | 'overdue' | 'forecast_late' | 'paused' | 'not_started' | 'scheduled' | 'in_progress' | 'aborted' | string
  fulfillment_status_label?: string
  stocked_qty?: number
  stock_progress_pct?: number
  completed_tasks?: number
  paused_tasks?: number
  plan_start?: string | null
  plan_end?: string | null
  forecast_end?: string | null
  actual_end?: string | null
}

export interface OrderOverviewData {
  summary?: {
    total_orders: number
    production_completed: number
    on_time_completed: number
    late_completed: number
    in_progress: number
    forecast_late: number
    overdue: number
    not_started: number
    paused: number
    total_qty: number
    done_qty: number
    process_plan_qty: number
    process_done_qty: number
    process_progress_pct: number
    stocked_qty: number
    production_completion_pct: number
    stock_completion_pct: number
    on_time_rate: number | null
  }
  /** 分区统计：待排/在产/逾期/完成 */
  groups: {
    pending: { count: number; qty: number }
    in_progress: { count: number; qty: number }
    overdue: { count: number; qty: number }
    done: { count: number; qty: number }
  }
  items: OrderProgress[]
}

// ==================== 产能负荷热力 ====================

export interface HeatCell {
  date: string
  load_pct: number | null
  /** 是否预警（>85%） */
  alert: boolean
  /** 本行对应机台 */
  machine_id: number
}

export interface HeatmapRow {
  machine_id: number
  machine_code: string
  machine_name: string
  workshop: string
  /** 7 天负荷 */
  cells: HeatCell[]
  /** 平均负荷 */
  avg_load: number
}

export interface LoadHeatmapData {
  start: string
  end: string
  days: string[]
  rows: HeatmapRow[]
  /** 预警阈值 */
  alert_threshold: number
  /** 汇总：高负荷机台数/平均负荷 */
  summary: { alert_count: number; avg_load: number; peak_load: number }
}

// ==================== 红灯管理 ====================

export interface RedLight {
  id: number
  /** unscheduled 未排产 / no_report 未报工 / overdue 逾期 / behind 落后 */
  source: 'unscheduled' | 'no_report' | 'overdue' | 'behind'
  source_label: string
  level: 'high' | 'medium' | 'low'
  task_id: number
  task_no: string
  product_code: string
  product_name: string
  order_no: string
  qty: number
  done_qty: number
  progress_pct: number
  reason: string
  plan_start: string | null
  plan_end: string | null
  delivery_date: string | null
  /** 责任人（班组长/计划员） */
  owner_id: number | null
  owner_name: string | null
  owner_workshop: string | null
  status: 'open' | 'closed'
  closed_by: string | null
  close_reason: string | null
  created_at: string
  closed_at: string | null
}

export interface RedLightGroup {
  owner_id: number | null
  owner_name: string
  owner_workshop: string | null
  total: number
  by_source: Array<{ source: string; source_label: string; count: number }>
  items: RedLight[]
}

export interface RedLightListData {
  total: number
  open_count: number
  closed_count: number
  by_source: Array<{ source: string; source_label: string; count: number }>
  groups: RedLightGroup[]
  items: RedLight[]
}

// ==================== 报表中心 ====================

export interface ReportMetric {
  key: string
  label: string
  value: number
  unit: string
  trend: 'up' | 'down' | 'flat'
  compare_text: string
}

export interface ReportSeries {
  label: string
  /** 无业务样本时为 null，前端应展示暂无数据而不是伪造数值 */
  data: Array<{ date: string; value: number | null }>
}

export interface ReportsData {
  metrics: ReportMetric[]
  /** 交期达成率 近 7 天 */
  delivery_series: ReportSeries
  /** 机台利用率 7 天（按机台） */
  utilization_series: ReportSeries
  /** 报工及时率 近 30 天按周 */
  report_timely_series: ReportSeries
  /** 次品率 */
  defect_series: ReportSeries
  /** 模具寿命 TOP */
  mold_life: Array<{ mold_id: number; mold_code: string; mold_name: string; life_used_pct: number; maintain_due: boolean }>
}

// ==================== 小程序回写（演示/契约） ====================

export interface MpProgressWriteback {
  order_id: number
  order_no: string
  product_id: number
  product_code: string
  done_qty: number
  expected_finish: string | null
  delayed: boolean
  shipable_qty: number
  progress_pct: number
  updated_at: string
}
