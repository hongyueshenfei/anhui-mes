/**
 * 五期类型定义（AI 报价 + 发货收口，与后端 API 契约对齐）
 */

// ==================== AI 估价 ====================

export interface EstimateMaterialDetail {
  item_code: string
  item_name: string
  qty: number
  price: number
  cost: number
}

export interface EstimateProcessDetail {
  op_type: string
  op_name: string
  hours_per_unit: number
  rate: number
  cost: number
}

export interface QuoteEstimate {
  product_id: number | null
  product_code: string | null
  product_name: string | null
  spec_text: string
  qty: number
  material_cost: number
  process_cost: number
  mold_amort: number
  loss_cost: number
  total_cost: number
  loss_rate: number
  margin: number
  suggest_price: number
  delivery_days: number
  anomaly_flag: boolean
  anomaly_message: string
  ai_assist: 'enabled' | 'not_enabled'
  llm_remark: string | null
  material_detail: EstimateMaterialDetail[]
  process_detail: EstimateProcessDetail[]
  mold: { mold_code: string; mold_name: string; purchase_cost: number; amort_per_unit: number } | null
}

// ==================== 报价单 ====================

export interface QuoteLine {
  id: number
  quote_id: number
  spec_text: string
  qty: number
  ref_product_id: number | null
  product_id: number | null
  product?: { id: number; code: string; name: string } | null
  material_cost: number
  process_cost: number
  mold_amort: number
  loss_cost: number
  total_cost: number
  margin: number
  suggest_price: number
  delivery_days: number
  llm_remark: string | null
  ai_assist: string
  anomaly_flag: boolean
}

export interface Quote {
  id: number
  quote_no: string
  customer_name: string
  source: 'sales' | 'mini_program'
  status: 'draft' | 'sent' | 'won' | 'lost'
  status_label: string
  target_margin: number
  suggest_delivery: string | null
  final_price: number | null
  created_by: number | null
  creator?: { id: number; name: string; employee_no: string } | null
  remark: string | null
  lines: QuoteLine[]
  lines_count?: number
  total_qty?: number
  created_at?: string
}

// ==================== 报价参数 ====================

export interface MaterialPrice {
  id: number
  material_code: string
  material_name: string
  price_per_unit: number
  unit: string | null
  effective_date: string
  source: string
}

export interface ProcessRate {
  id: number
  rate_key: string
  op_type: string | null
  rate_type: string
  rate: number
  effective_date: string
}

export interface QuoteRuleItem {
  id: number
  rule_key: string
  rule_value: number
  remark: string | null
}

