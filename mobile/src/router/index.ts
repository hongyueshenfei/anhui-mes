import { createRouter, createWebHistory } from 'vue-router'

/**
 * 车间移动端路由（一期登录/绑定；三期任务/报工）
 */
const routes = [
  {
    path: '/login',
    name: 'WxLogin',
    component: () => import('@/views/login/WxLoginView.vue'),
  },
  {
    path: '/bind',
    name: 'BindEmployee',
    component: () => import('@/views/login/BindEmployeeView.vue'),
  },
  // ===== 三期：今日任务/扫码报工/我的报工 =====
  {
    path: '/tasks',
    name: 'TodayTasks',
    component: () => import('@/views/tasks/TodayTasksView.vue'),
  },
  {
    path: '/task/:id',
    name: 'TaskDetail',
    component: () => import('@/views/task-detail/TaskDetailView.vue'),
  },
  {
    path: '/report/qr',
    name: 'ScanReport',
    component: () => import('@/views/task-detail/ScanReportView.vue'),
  },
  {
    path: '/report/task',
    name: 'ReportForm',
    component: () => import('@/views/report/ReportFormView.vue'),
  },
  {
    path: '/reports/mine',
    name: 'MyReports',
    component: () => import('@/views/report/MyReportsView.vue'),
  },
  { path: '/', redirect: '/tasks' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

function canOperateReports(): boolean {
  try {
    const user = JSON.parse(localStorage.getItem('hy_mobile_user') ?? 'null') as { job_type?: string; roles?: string[] } | null
    const allowed = ['admin', 'leader', 'statistician']
    return allowed.includes(user?.job_type ?? '') || (user?.roles ?? []).some((role) => allowed.includes(role))
  } catch {
    return false
  }
}

// 登录守卫（扫码/免密 Token）
router.beforeEach((to) => {
  if (to.name === 'WxLogin' || to.name === 'BindEmployee') {
    return true
  }
  const token = localStorage.getItem('hy_mobile_token')
  if (!token) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  if (['ScanReport', 'ReportForm'].includes(String(to.name)) && !canOperateReports()) {
    return { path: '/tasks' }
  }
  return true
})

export default router
