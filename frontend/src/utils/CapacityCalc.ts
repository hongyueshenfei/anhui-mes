/**
 * 产能换算工具（前端即时预览）
 * 与后端 CapacityCalcService 口径一致：
 * 生产时长(小时) =（固定换模 0.5 小时 + 数量 / 标准产能）× 1.05
 */
export interface CapacityPreview {
  ok: boolean
  message: string
  hours: number | null
  pureProcessHours: number | null
}

/** 预估所需小时（含换模） */
export function estimateHours(qty: number, capacityPerHour: number, moldChangeMin = 0): number {
  if (qty <= 0 || capacityPerHour <= 0) {
    throw new Error('数量与标准产能必须大于 0')
  }
  const changeoverHours = moldChangeMin > 0 ? 0.5 : 0
  return (changeoverHours + qty / capacityPerHour) * 1.05
}

/** 格式化时长："3.5 小时" / "2 天 3 小时" */
export function formatHours(hours: number): string {
  if (hours < 24) {
    return `${hours.toFixed(1)} 小时`
  }
  const days = Math.floor(Math.round(hours) / 24)
  const remain = Math.round((hours - days * 24) * 10) / 10
  return remain > 0 ? `${days} 天 ${remain.toFixed(1)} 小时` : `${days} 天`
}

/** 产能预览（用于任务池/建单展示"需 X 小时"） */
export function capacityPreview(qty: number, capacityPerHour?: number | null, moldChangeMin?: number | null): CapacityPreview {
  if (!capacityPerHour || capacityPerHour <= 0) {
    return { ok: false, message: '未配置标准产能', hours: null, pureProcessHours: null }
  }
  const hours = estimateHours(qty, capacityPerHour, moldChangeMin ?? 0)
  return {
    ok: true,
    message: formatHours(hours),
    hours: Math.round(hours * 100) / 100,
    pureProcessHours: Math.round((qty / capacityPerHour) * 100) / 100,
  }
}

/** 班制时段常量（与后端 ShiftConfigSeeder 对齐） */
export const SHIFT = {
  twoShift: [
    { name: '白班', start: '08:00', end: '20:00' },
    { name: '夜班', start: '20:00', end: '08:00' },
  ],
  day: { start: '08:00', end: '17:00' },
}

/** 毫秒 → 分钟向下取整（甘特 30min 吸附） */
export function toMinutes(ms: number): number {
  return Math.floor(ms / 60000)
}

/** 吸附到 30 分钟网格 */
export function snapToGrid(ms: number, minutesPerCell = 30): number {
  const cells = Math.round(ms / (minutesPerCell * 60000))
  return cells * minutesPerCell * 60000
}
