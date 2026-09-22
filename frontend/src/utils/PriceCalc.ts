/**
 * 报价前端即时预览计算（五期 E2/E4）
 *
 * 与后端 QuoteRuleEngine 同构：材料 + 加工 + 模具摊销 + 损耗 + 毛利 → 建议价。
 * 演示模式前端改数量即时重算；真实环境以后端 /api/quotes/ai-estimate 为准。
 */
import type { Product } from '@/types'

export interface CalcInput {
  qty: number
  margin?: number
  /** 材料用量表：[{code, name, qty(用量/件), price}] */
  materials?: Array<{ code: string; name: string; qty: number; price: number }>
  /** 工序表：[{name, hours_per_unit, rate}] */
  processes?: Array<{ name: string; hours_per_unit: number; rate: number }>
  /** 模具造价 */
  moldCost?: number
  lossRate?: number
}

export interface CalcResult {
  qty: number
  material_cost: number
  process_cost: number
  mold_amort: number
  loss_cost: number
  total_cost: number
  margin: number
  suggest_price: number
  delivery_days: number
}

/** 产品 → 演示材料用量（对齐种子 BOM：PP 原料 + 彩盒） */
export function productMaterials(product: Product | null): CalcInput['materials'] {
  if (!product) return []
  return [
    { code: 'RAW-PP-001', name: 'PP 原料 聚丙烯', qty: 1.02, price: 8.5 },
    { code: 'PKG-BOX-001', name: '中号彩盒', qty: 1.0, price: 0.8 },
  ]
}

/** 产品 → 演示工序（成型→喷漆→效果→包装） */
export function productProcesses(product: Product | null): CalcInput['processes'] {
  if (!product) return []
  const moldRate = product.code?.startsWith('B-') ? 45 : 55
  return [
    { name: '注塑成型', hours_per_unit: 1 / 380, rate: moldRate },
    { name: '喷漆', hours_per_unit: 1 / 500, rate: 30 },
    { name: '效果处理', hours_per_unit: 1 / 600, rate: 28 },
    { name: '包装', hours_per_unit: 1 / 800, rate: 20 },
  ]
}

/** 模具造价（演示：按产品 id 映射） */
export function productMoldCost(product: Product | null): number {
  if (!product) return 0
  return 88000 // 直口杯 M-001 造价
}

/** 确定性估价（前后端同构） */
export function calcEstimate(input: CalcInput): CalcResult {
  const qty = Math.max(1, input.qty || 0)
  const margin = Math.max(0, Math.min(90, input.margin ?? 20))
  const lossRate = input.lossRate ?? 2

  const materialCost = (input.materials ?? []).reduce((s, m) => s + m.qty * m.price, 0)
  const processCost = (input.processes ?? []).reduce((s, p) => s + p.hours_per_unit * p.rate, 0)
  const moldAmort = (input.moldCost ?? 0) / qty
  const lossCost = (materialCost + processCost) * (lossRate / 100)
  const totalCost = materialCost + processCost + moldAmort + lossCost
  const suggestPrice = totalCost / (1 - margin / 100)

  // 交期：工时×数量 ÷ 每日可用 16h + 负荷缓冲
  const totalHours = (input.processes ?? []).reduce((s, p) => s + p.hours_per_unit * qty, 0)
  const deliveryDays = Math.max(1, Math.ceil(totalHours / 16) + 0)

  return {
    qty,
    material_cost: round2(materialCost),
    process_cost: round2(processCost),
    mold_amort: round2(moldAmort),
    loss_cost: round2(lossCost),
    total_cost: round2(totalCost),
    margin,
    suggest_price: round2(suggestPrice),
    delivery_days: deliveryDays,
  }
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}
