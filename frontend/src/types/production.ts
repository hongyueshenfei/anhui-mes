/**
 * 三期类型定义（报工/入库/集成日志，与后端 API 契约对齐）
 */

export interface WorkReport {
  id: number
  task_id: number
  task?: { id: number; task_no: string; product_id: number; qty: number; status: string }
  task_no?: string
  product_id: number
  product?: { id: number; code: string; name: string }
  schedule_id?: number | null
  /** 所属工序（排程段，任务级报工为 null） */
  schedule?: { id: number; task_id: number; seq: number; op_type?: string; op_name?: string } | null
  machine_id: number | null
  machine?: { id: number; code: string; name: string } | null
  qty: number
  defect_qty: number
  sign: number
  /** 正报工尚可冲减的数量；冲完后为 0，前端隐藏冲减入口 */
  correctable_qty?: number
  /** 冲减对应的原正报工；历史负流水由后端兼容推断 */
  correction_of_report_id?: number | null
  correction_target_id?: number | null
  report_by: number
  operator?: { id: number; name: string; employee_no: string } | null
  worker_id: number | null
  worker?: { id: number; name: string; employee_no: string; workshop?: { id: number; name: string } | null } | null
  report_time: string
  /** 本次实际作业时间段；旧流水回退为 report_time 同一时刻 */
  start_time?: string | null
  end_time?: string | null
  work_date?: string | null
  shift_code?: 'day' | 'night' | null
  client_request_id?: string | null
  source: string
  source_label?: string
  offline_flag: number
  remark: string | null
}

export interface StockIn {
  id: number
  ins_no: string
  task_id: number
  task?: { id: number; task_no: string; product_id: number; qty: number; status: string }
  task_no?: string
  product_id: number
  product?: { id: number; code: string; name: string }
  qty: number
  ins_status: 'draft' | 'confirmed' | 'pushed'
  k3_push_status: 'queued' | 'pushing' | 'success' | 'failed' | 'retry'
  k3_push_status_label?: string
  k3_bill_no: string | null
  k3_result: Record<string, unknown> | null
  created_by?: number | null
  creator?: { id: number; name: string } | null
  remark: string | null
  created_at?: string
}

export interface AvailableQty {
  task_id: number
  task_no: string
  reported_qty: number
  stocked_qty: number
  available_qty: number
}

export interface IntegrationLog {
  id: number
  direction: 'in' | 'out'
  target: 'k3' | 'mini_program' | 'ai'
  api_type: string
  biz_type: string | null
  biz_id: number | null
  request_body: Record<string, unknown> | null
  response_body: Record<string, unknown> | null
  status: 'success' | 'failed' | 'retry' | 'queued'
  status_label?: string
  retry_count: number
  error_msg: string | null
  created_at: string
  finished_at: string | null
}

export interface ReportPayload {
  task_id: number
  qty: number
  defect_qty?: number
  correction_of_report_id?: number | null
  worker_id?: number | null
  schedule_id?: number | null
  machine_id?: number | null
  report_time?: string
  start_time?: string
  end_time?: string
  work_date?: string
  shift_code?: 'day' | 'night'
  client_request_id?: string
  source?: 'scan' | 'click' | 'leader_report' | 'leader_batch' | 'makeup' | 'offline' | 'machine_qr'
  offline_flag?: boolean
  remark?: string
}

export interface DailyReportRow {
  schedule_id: number
  task_id: number
  task_no: string
  order_no?: string | null
  product: { id: number; code: string; name: string } | null
  operation: { seq: number; name: string }
  /** 同一工单可切换的未打断工序排程；提交时以 selected_schedule_id 为准 */
  operation_options?: DailyReportOperationOption[]
  machine: { id: number; code: string; name: string; workshop_id?: number | null; workshop_name?: string | null; shift_mode?: 'day' | 'two_shift' | string | null } | null
  plan_start: string
  plan_end: string
  planned_qty: number
  reported_qty: number
  defect_qty: number
  remaining_qty: number
  schedule_status: string
  task_status: string
  delivery_date?: string | null
  work_date: string
  input_qty: number | null
  input_defect_qty: number | null
  input_start_time: string | null
  input_end_time: string | null
  selected_schedule_id?: number
  shift_code: 'day' | 'night'
  remark: string
}

export interface DailyReportOperationOption {
  schedule_id: number
  operation: { seq: number; name: string }
  machine: { id: number; code: string; name: string; workshop_id?: number | null; workshop_name?: string | null; shift_mode?: 'day' | 'two_shift' | string | null } | null
  plan_start: string
  plan_end: string
  planned_qty: number
  reported_qty: number
  remaining_qty: number
  schedule_status: string
}

export interface DailyReportContext {
  work_date: string
  rows: Omit<DailyReportRow, 'input_qty' | 'input_defect_qty' | 'input_start_time' | 'input_end_time' | 'selected_schedule_id' | 'shift_code' | 'remark'>[]
  summary: { row_count: number; planned_qty: number; reported_qty: number; remaining_qty: number }
}

export type ReportSummaryDimension = 'detail' | 'daily' | 'progress' | 'delivery' | 'resource' | 'quality'

export interface ReportSummaryItem {
  work_date: string | null
  shift_code?: 'day' | 'night' | 'unknown' | string | null
  task_id?: number
  task_no?: string | null
  order_no?: string | null
  customer_name?: string | null
  delivery_date?: string | null
  product?: { id: number; code: string; name: string } | null
  operation?: { seq: number; name: string } | null
  machine?: { id: number; code: string; name: string; capacity_per_hour?: number; shift_mode?: string | null } | null
  workshop?: { id: number; code: string; name: string } | null
  planned_qty?: number
  order_qty?: number
  qualified_qty: number
  finished_qualified_qty?: number
  remaining_qty?: number
  over_qty?: number
  defect_qty: number
  total_qty: number
  completion_pct?: number
  defect_rate?: number
  report_count: number
  normal_count?: number
  correction_count: number
  correction_qty?: number
  task_count?: number
  order_count?: number
  product_count?: number
  valid_time_count?: number
  invalid_time_count?: number
  actual_hours?: number
  capacity_qty?: number
  utilization_pct?: number | null
  time_quality?: string | null
  risk_status?: string | null
  last_report_time: string | null
}

export interface ReportSummary {
  dimension: ReportSummaryDimension
  totals: {
    row_count: number
    task_count: number
    report_count: number
    qualified_qty: number
    defect_qty: number
    total_qty: number
    /** 仅末工序/任务级报工合格数，成品口径 */
    finished_qualified_qty: number
    /** 仅末工序/任务级报工次品数 */
    finished_defect_qty: number
    correction_count: number
  }
  items: ReportSummaryItem[]
}

export interface ReportResult {
  report: WorkReport
  task: {
    id: number
    task_no: string
    product_code: string
    product_name: string
    qty: number
    done_qty: number
    stocked_qty: number
    status: string
    deviation_pct: number
  }
  is_correction: boolean
}
/** 生产异常（四期演示/真实 API 契约：停机/换模/次品/缺料） */
export interface ProductionException {
  id: number
  /** stop_machine 停机 / mold_change 换模 / defect 次品 / material_short 缺料 */
  type: 'stop_machine' | 'mold_change' | 'defect' | 'material_short'
  type_label: string
  task_id: number | null
  task_no: string | null
  machine_id: number | null
  machine_code: string | null
  product_code: string | null
  product_name: string | null
  level: 'high' | 'medium' | 'low'
  desc: string
  report_by: string
  report_time: string
  status: 'open' | 'processing' | 'closed'
  status_label: string
  handler: string | null
  handle_time: string | null
  handle_result: string | null
}
