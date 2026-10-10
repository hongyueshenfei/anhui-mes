export interface MobileTask {
  id: number
  task_no: string
  product?: { code: string; name: string } | null
  qty: number
  done_qty: number
  status: string
  plan_start: string | null
  plan_end: string | null
  schedules?: Array<{
    op_name?: string
    machine_code?: string
    machine?: { code?: string }
  }>
}
