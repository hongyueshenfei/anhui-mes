import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * 路由 + 权限守卫（角色 → 菜单）
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/system/LoginView.vue'),
    meta: { title: '登录' },
  },
  {
    path: '/',
    component: () => import('@/layouts/MainLayout.vue'),
    redirect: '/plm/machines',
    children: [
      {
        path: 'plm/machines',
        name: 'Machines',
        component: () => import('@/views/plm/MachinesView.vue'),
        meta: { title: '机台产线', perm: 'plm.machine.view' },
      },
      {
        path: 'plm/products',
        name: 'Products',
        component: () => import('@/views/plm/ProductsView.vue'),
        meta: { title: '产品档案', perm: 'plm.product.view' },
      },
      {
        path: 'plm/boms',
        name: 'Boms',
        component: () => import('@/views/plm/BomsView.vue'),
        meta: { title: 'BOM 管理', perm: 'plm.bom.view' },
      },
      {
        path: 'plm/routes',
        name: 'Routes',
        component: () => import('@/views/plm/RoutesView.vue'),
        meta: { title: '工艺路线', perm: 'plm.route.view' },
      },
      {
        path: 'plm/op-types',
        name: 'OpTypes',
        component: () => import('@/views/plm/OpTypesView.vue'),
        meta: { title: '工序类型', perm: 'plm.route.view' },
      },
      {
        path: 'plm/molds',
        name: 'Molds',
        component: () => import('@/views/plm/MoldsView.vue'),
        meta: { title: '模具台账', perm: 'plm.mold.view' },
      },
      {
        path: 'plm/exceptions',
        name: 'Exceptions',
        component: () => import('@/views/plm/ExceptionsView.vue'),
        meta: { title: '异常管理', perm: 'report.view' },
      },
      {
        path: 'plm/develop',
        name: 'Develop',
        component: () => import('@/views/plm/DevelopView.vue'),
        meta: { title: '新品开发流程', perm: 'plm.product.view' },
      },
      // ===== 二期：订单中心 =====
      {
        path: 'order/orders',
        name: 'Orders',
        component: () => import('@/views/order/OrdersView.vue'),
        meta: { title: '订单中心', perm: 'order.view' },
      },
      {
        path: 'order/detail/:id',
        name: 'OrderDetail',
        component: () => import('@/views/order/OrderDetailView.vue'),
        meta: { title: '订单详情', perm: 'order.view' },
      },
      // ===== 二期：排产 =====
      {
        path: 'schedule/gantt',
        name: 'Gantt',
        component: () => import('@/views/schedule/GanttView.vue'),
        meta: { title: '排产甘特', perm: 'schedule.view' },
      },
      {
        path: 'schedule/detail',
        name: 'ScheduleDetail',
        component: () => import('@/views/schedule/ScheduleDetailView.vue'),
        meta: { title: '排产结果明细', perm: 'schedule.view' },
      },
      {
        path: 'schedule/suggestions',
        name: 'ScheduleSuggestions',
        component: () => import('@/views/schedule/SuggestionsView.vue'),
        meta: { title: '顺延建议', perm: 'schedule.view' },
      },
      // ===== 三期：报工台账 / 入库管理 =====
      {
        path: 'production/reports',
        name: 'Reports',
        component: () => import('@/views/production/ReportsView.vue'),
        meta: { title: '报工台账', perm: 'report.view' },
      },
      {
        path: 'warehouse/stock-ins',
        name: 'StockIns',
        component: () => import('@/views/warehouse/StockInsView.vue'),
        meta: { title: '入库管理', perm: 'warehouse.view' },
      },
      // ===== 五期：报价中心 =====
      {
        path: 'quote/estimate',
        name: 'AiEstimate',
        component: () => import('@/views/quote/AiEstimateView.vue'),
        meta: { title: 'AI 快速估价', perm: 'quote.view' },
      },
      {
        path: 'quote/quotes',
        name: 'Quotes',
        component: () => import('@/views/quote/QuotesView.vue'),
        meta: { title: '报价单管理', perm: 'quote.view' },
      },
      {
        path: 'quote/params',
        name: 'QuoteParams',
        component: () => import('@/views/quote/QuoteParamsView.vue'),
        meta: { title: '报价参数配置', perm: 'quote.view' },
      },
      // ===== 四期：看板 =====
      {
        path: 'dashboard/workbench',
        name: 'Workbench',
        component: () => import('@/views/dashboard/WorkbenchView.vue'),
        meta: { title: '工作台', perm: 'dashboard.view' },
      },
      {
        path: 'dashboard/order-overview',
        name: 'OrderOverview',
        component: () => import('@/views/dashboard/OrderOverviewView.vue'),
        meta: { title: '订单进度总览', perm: 'dashboard.view' },
      },
      {
        path: 'dashboard/load-heatmap',
        name: 'LoadHeatmap',
        component: () => import('@/views/dashboard/LoadHeatmapView.vue'),
        meta: { title: '产能负荷热力', perm: 'dashboard.view' },
      },
      {
        path: 'dashboard/red-lights',
        name: 'RedLights',
        component: () => import('@/views/dashboard/RedLightView.vue'),
        meta: { title: '红灯管理', perm: 'dashboard.view' },
      },
      {
        path: 'dashboard/reports',
        name: 'DashboardReports',
        component: () => import('@/views/dashboard/ReportsView.vue'),
        meta: { title: '报表中心', perm: 'dashboard.view' },
      },
      {
        path: 'system/users',
        name: 'Users',
        component: () => import('@/views/system/UsersView.vue'),
        meta: { title: '用户管理', perm: 'system.user.manage' },
      },
      {
        path: 'system/roles',
        name: 'Roles',
        component: () => import('@/views/system/RolesView.vue'),
        meta: { title: '角色权限', perm: 'system.role.manage' },
      },
      {
        path: 'system/audit-logs',
        name: 'AuditLogs',
        component: () => import('@/views/system/AuditLogsView.vue'),
        meta: { title: '操作审计', perm: 'audit.view' },
      },
      {
        path: 'system/integration-config',
        name: 'IntegrationConfig',
        component: () => import('@/views/system/IntegrationConfigView.vue'),
        meta: { title: '集成配置', perm: 'integration.view' },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// 全局守卫：未登录跳登录页；无权限跳首页
router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.name === 'Login') {
    return auth.isLoggedIn ? { path: '/' } : true
  }

  if (!auth.isLoggedIn) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  const needPerm = to.meta.perm as string | undefined
  if (needPerm && !auth.can(needPerm)) {
    return { path: '/' }
  }

  document.title = `${to.meta.title ?? ''} · 虹越智造平台`
  return true
})

export default router
