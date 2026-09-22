/**
 * 时间显示格式化（订单中心/详情等通用）
 *
 * 后端 JSON 序列化 DATETIME 时输出 ISO 8601（如 2026-09-19T16:00:00.000000Z），
 * 但数据库实际按 Asia/Shanghai 存储，Z 仅为序列化产物：
 * 直接截取字符串中的「日期/时间」数字展示，避免 dayjs 按 UTC 解析产生时区偏移。
 */
export function formatDateTime(v: string | null | undefined): string {
  if (!v) return '-'
  const m = String(v).match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/)
  if (m) return `${m[1]} ${m[2]}`
  return String(v)
}

export function formatDate(v: string | null | undefined): string {
  if (!v) return '-'
  const m = String(v).match(/^(\d{4}-\d{2}-\d{2})/)
  if (m) return m[1]
  return String(v)
}
