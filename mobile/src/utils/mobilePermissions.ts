export function canCurrentUserOperateReports(): boolean {
  try {
    const user = JSON.parse(localStorage.getItem('hy_mobile_user') ?? 'null') as {
      job_type?: string
      roles?: string[]
    } | null
    const allowedRoles = ['admin', 'leader', 'statistician']
    return allowedRoles.includes(user?.job_type ?? '')
      || (user?.roles ?? []).some((role) => allowedRoles.includes(role))
  } catch {
    return false
  }
}
