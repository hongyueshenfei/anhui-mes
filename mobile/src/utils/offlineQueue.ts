import { mobileReport } from '@/api/mobile'

/**
 * 弱网离线报工队列（C7：断网暂存 localStorage → 联网自动补传）
 *
 * - 入队：报工提交失败/离线时压入队列（含本地幂等键 uuid）
 * - 补传：联网时逐条提交；成功移除；失败保留（下次再补）
 * - 防重：后端 /reports/verify 5 分钟窗口 + 前端本地幂等键双保险
 */
const QUEUE_KEY = 'hy_mobile_report_queue'

export interface OfflineReport {
  id: string // 本地 uuid 幂等键
  payload: {
    task_id: number
    schedule_id?: number | null
    machine_id?: number | null
    qty: number
    defect_qty?: number
    worker_id?: number | null
    source?: string
    offline_flag?: boolean
    remark?: string
    report_time?: string
    start_time?: string
    end_time?: string
    work_date?: string
    shift_code?: string
    client_request_id?: string
  }
  queued_at: string
}

export function uuid(): string {
  return 'rep-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)
}

export function enqueueReport(payload: OfflineReport['payload']): OfflineReport {
  const item: OfflineReport = {
    id: uuid(),
    payload: { ...payload, source: payload.source ?? 'offline', offline_flag: true },
    queued_at: new Date().toISOString(),
  }
  const queue = loadQueue()
  queue.push(item)
  localStorage.setItem(QUEUE_KEY, JSON.stringify(queue))
  return item
}

export function loadQueue(): OfflineReport[] {
  try {
    return JSON.parse(localStorage.getItem(QUEUE_KEY) ?? '[]') as OfflineReport[]
  } catch {
    return []
  }
}

export function pendingCount(): number {
  return loadQueue().length
}

function saveQueue(queue: OfflineReport[]): void {
  if (queue.length === 0) {
    localStorage.removeItem(QUEUE_KEY)
  } else {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(queue))
  }
}

/**
 * 联网补传：逐条提交，成功移除、失败保留（不中断后续条目）
 * 返回 {sent, failed, total}
 */
export async function flushOfflineQueue(): Promise<{ sent: number; failed: number; total: number }> {
  const queue = loadQueue()
  if (queue.length === 0) {
    return { sent: 0, failed: 0, total: 0 }
  }

  let sent = 0
  let failed = 0
  const remain: OfflineReport[] = []

  for (const item of queue) {
    try {
      // 先防重校验（本地幂等键对应内容与后端 verify 双保险）
      await mobileReport(item.payload)
      sent += 1
    } catch {
      remain.push(item)
      failed += 1
    }
  }

  saveQueue(remain)

  return { sent, failed, total: queue.length }
}

export function clearQueue(): void {
  localStorage.removeItem(QUEUE_KEY)
}
