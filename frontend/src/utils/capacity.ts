/**
 * 产能换算工具（前端即时预览：验收 A9"输入 1000 件即时显示需 X 小时"）
 * 与后端 CapacityCalcService 口径一致：成型换模固定 30 分钟，合计时长预留 5%
 */
export function estimateHours(qty: number, capacityPerHour: number, moldChangeMin = 0): number {
  if (qty <= 0 || capacityPerHour <= 0) {
    throw new Error('数量与产能必须大于 0')
  }
  const changeoverHours = moldChangeMin > 0 ? 0.5 : 0
  return (changeoverHours + qty / capacityPerHour) * 1.05
}

/** 格式化时长："3.0 小时" / "2 天 3 小时" */
export function formatHours(hours: number): string {
  if (hours < 24) {
    return `${hours.toFixed(1)} 小时`
  }
  const days = Math.floor(Math.round(hours) / 24)
  const remain = Math.round((hours - days * 24) * 10) / 10
  return remain > 0 ? `${days} 天 ${remain} 小时` : `${days} 天`
}

/** 状态中文名映射（机台/产品/BOM/工艺/模具通用） */
export const STATUS_LABELS: Record<string, string> = {
  // 机台
  running: '运行中',
  stopped: '停机',
  maintenance: '维修中',
  // 产品
  developing: '开发中',
  trial: '试产',
  mass: '量产',
  obsolete: '停产',
  // BOM / 工艺
  draft: '草稿',
  published: '已发布',
  disabled: '已禁用',
  // 模具
  in_use: '使用中',
  idle: '闲置',
  repair: '维修中',
  scrapped: '已报废',
  // 车间
  injection: '注塑',
  blow: '吹塑',
  paint: '喷漆',
  effect: '效果',
  pack: '包装',
  // 班制
  two_shift: '两班制',
  day: '白班制',
  // 岗位
  admin: '管理员',
  engineer: '工程师',
  planner: '计划员',
  leader: '班组长',
  statistician: '统计员',
  worker: '工人',
  sales: '业务员',
  boss: '老板',
}
