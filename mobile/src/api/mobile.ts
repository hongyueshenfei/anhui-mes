import http from './axios'

/**
 * 车间移动端 API（统一 /mobile 前缀；baseURL 见 axios.ts，走 vite 代理 → Laravel）
 * 认证：Sanctum Bearer（扫码/免密登录后写入 localStorage）
 */

/** 今日任务（C2） */
export function mobileTasks(params: { date?: string; status?: string } = {}) {
  return http.get('/mobile/tasks', { params })
}

/** 任务详情（C3 扫码 → 详情） */
export function mobileTask(id: number) {
  return http.get(`/mobile/tasks/${id}`)
}

/** 机台扫码后读取某工作日已固定的排程任务及可代报工人 */
export function mobileMachineReportContext(code: string, date?: string) {
  return http.get(`/mobile/machines/${encodeURIComponent(code)}/report-context`, { params: date ? { date } : undefined })
}

/** 报工（提交；返回 report + task 进度） */
export function mobileReport(data: {
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
}) {
  return http.post('/mobile/reports', data)
}

/** 我的报工 */
export function mobileReportsMine() {
  return http.get('/mobile/reports/mine')
}

/** 登录/绑定（微信免密 / 企微模拟凭据 / 账号密码） */
export function mobileAuth() {
  return {
    wxLogin(openid: string) {
      return http.post('/mobile/auth/wx-login', { openid })
    },
    bindEmployee(data: { channel: 'wecom' | 'wx'; code?: string; openid?: string; employee_no: string; password?: string }) {
      return http.post('/mobile/auth/bind-employee', data)
    },
    accountLogin(employeeNo: string, password: string) {
      return http.post('/api/auth/login', { employee_no: employeeNo, password })
    },
  }
}
