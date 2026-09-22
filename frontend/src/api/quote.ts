import { request } from './axios'
import type { PageResult } from '@/types'
import type { MaterialPrice, ProcessRate, Quote, QuoteEstimate, QuoteLine, QuoteRuleItem } from '@/types/quote'

/**
 * 报价中心 API（五期，对应后端 app/Modules/Quote）
 * 演示模式下由本地 mock 路由响应。
 */
export const quoteApi = {
  /** AI 快速估价（规则引擎 + DeepSeek 辅助降级） */
  aiEstimate(data: { product_id?: number | null; spec_text?: string; qty: number; margin?: number }): Promise<QuoteEstimate> {
    return request({ url: '/quotes/ai-estimate', method: 'post', data })
  },

  /** 报价单列表 */
  listQuotes(params?: Record<string, unknown>): Promise<PageResult<Quote> | Quote[]> {
    return request({ url: '/quotes', method: 'get', params })
  },

  /** 报价单详情 */
  getQuote(id: number): Promise<Quote> {
    return request({ url: `/quotes/${id}`, method: 'get' })
  },

  /** 创建报价单（from_estimate 估价转单 / 手工） */
  createQuote(data: { from_estimate?: boolean; customer_name?: string; estimate?: QuoteEstimate; lines?: QuoteLine[]; target_margin?: number }): Promise<Quote> {
    return request({ url: '/quotes', method: 'post', data })
  },

  /** 调价（留审计） */
  adjustPrice(id: number, data: { final_price: number; remark?: string }): Promise<Quote> {
    return request({ url: `/quotes/${id}/price`, method: 'patch', data })
  },

  /** 状态流转 */
  transitionStatus(id: number, data: { status: 'draft' | 'sent' | 'won' | 'lost'; final_price?: number }): Promise<Quote> {
    return request({ url: `/quotes/${id}/status`, method: 'patch', data })
  },

  // ============ 报价参数 ============
  listMaterials(params?: Record<string, unknown>): Promise<PageResult<MaterialPrice> | MaterialPrice[]> {
    return request({ url: '/quotes/params/materials', method: 'get', params })
  },
  upsertMaterial(data: Partial<MaterialPrice>): Promise<MaterialPrice> {
    return request({ url: '/quotes/params/materials', method: 'post', data })
  },
  listProcessRates(params?: Record<string, unknown>): Promise<PageResult<ProcessRate> | ProcessRate[]> {
    return request({ url: '/quotes/params/process-rates', method: 'get', params })
  },
  upsertProcessRate(data: Partial<ProcessRate>): Promise<ProcessRate> {
    return request({ url: '/quotes/params/process-rates', method: 'post', data })
  },
  listRules(): Promise<QuoteRuleItem[]> {
    return request({ url: '/quotes/params/rules', method: 'get' })
  },
  upsertRule(data: { rule_key: string; rule_value: number; remark?: string }): Promise<QuoteRuleItem> {
    return request({ url: '/quotes/params/rules', method: 'put', data })
  },
}
