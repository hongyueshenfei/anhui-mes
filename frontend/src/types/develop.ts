/**
 * 新品开发流程（四期演示/真实 API 契约）
 *
 * 阶段看板：询价(inquiry) → 立项(project) → 设计(design) → 开模(mold_making)
 *            → 试模(trial) → 小批(small_batch) → 量产(mass)
 */
export interface DevelopTask {
  id: number
  /** 开发单号 */
  dev_no: string
  product_code: string
  product_name: string
  spec: string
  customer_name: string
  /** 当前阶段（见 developStages） */
  stage: DevelopStageKey
  stage_label: string
  /** 阶段状态：todo 未开始 / doing 进行中 / done 已完成 */
  stage_status: 'todo' | 'doing' | 'done'
  priority: number
  /** 计划完成日期 */
  plan_finish: string
  /** 负责人 */
  owner_name: string
  /** 是否逾期 */
  overdue: boolean
  /** 备注 */
  remark: string | null
  /** 阶段推进记录（演示时间轴） */
  logs?: Array<{ stage: string; stage_label: string; status: string; at: string }>
}

export type DevelopStageKey =
  | 'inquiry'
  | 'project'
  | 'design'
  | 'mold_making'
  | 'trial'
  | 'small_batch'
  | 'mass'

export interface DevelopStage {
  key: DevelopStageKey
  label: string
  color: string
}

export const developStages: DevelopStage[] = [
  { key: 'inquiry', label: '询价', color: '#909399' },
  { key: 'project', label: '立项', color: '#409eff' },
  { key: 'design', label: '设计', color: '#67c23a' },
  { key: 'mold_making', label: '开模', color: '#e6a23c' },
  { key: 'trial', label: '试模', color: '#f56c6c' },
  { key: 'small_batch', label: '小批', color: '#9c27b0' },
  { key: 'mass', label: '量产', color: '#1677ff' },
]

export interface DevelopBoardData {
  stages: DevelopStage[]
  items: DevelopTask[]
}
