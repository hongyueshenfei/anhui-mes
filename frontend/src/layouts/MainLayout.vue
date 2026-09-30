<template>
  <el-container class="main-layout">
    <!-- 侧边栏 -->
    <el-aside width="220px" class="sidebar">
      <div class="logo">
        <span class="logo-mark">虹</span>
        <div class="logo-text">
          <strong>虹越智造平台</strong>
          <small>PLM · MES · AI 报价 · 看板</small>
        </div>
      </div>
      <el-menu
        :default-active="route.path"
        router
        background-color="#1d2939"
        text-color="#cbd5e1"
        active-text-color="#ffffff"
      >
        <el-sub-menu v-for="group in visibleGroups" :key="group.key" :index="group.key">
          <template #title>
            <el-icon><component :is="group.icon" /></el-icon>
            <span>{{ group.title }}</span>
          </template>
          <el-menu-item v-for="item in group.children" :key="item.path" :index="item.path">
            <el-icon><component :is="item.icon" /></el-icon>
            <span>{{ item.title }}</span>
          </el-menu-item>
        </el-sub-menu>
      </el-menu>
    </el-aside>

    <el-container>
      <!-- 顶栏 -->
      <el-header class="header">
        <div class="header-title">{{ route.meta.title }}</div>
        <div class="header-right">
          <el-tag v-if="isMockMode" type="warning" size="small" effect="dark">演示模式 · 模拟数据（未连接后端）</el-tag>
          <el-dropdown @command="onCommand">
            <span class="user-chip">
              <el-avatar :size="28" class="avatar">{{ nameChar }}</el-avatar>
              <span class="user-name">{{ auth.user?.name ?? '未登录' }}</span>
              <el-icon><ArrowDown /></el-icon>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item disabled>
                  {{ auth.user?.employee_no }} · {{ roleLabel }}
                </el-dropdown-item>
                <el-dropdown-item divided command="logout">退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <!-- 内容区 -->
      <el-main class="content">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { ArrowDown, Setting, Tools, Box, Document, Monitor, User, Key, List, ShoppingCart, Calendar, DataLine, Memo, Bell, TrendCharts, Warning, Files, Connection, MagicStick, Ticket, PriceTag, Operation } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { STATUS_LABELS } from '@/utils/capacity'
import { isDemoMode } from '@/config/demo'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

/** 是否演示模式（数据来自本地 mock 层） */
const isMockMode = ref(isDemoMode())

interface MenuItem {
  path: string
  title: string
  icon: unknown
  perm: string
}

interface MenuGroup {
  key: string
  title: string
  icon: unknown
  children: MenuItem[]
}

const menuGroups: MenuGroup[] = [
  {
    key: 'dashboard',
    title: '管理看板',
    icon: DataLine,
    children: [
      { path: '/dashboard/workbench', title: '工作台', icon: DataLine, perm: 'dashboard.view' },
      { path: '/dashboard/order-overview', title: '订单履约统计', icon: ShoppingCart, perm: 'dashboard.view' },
      { path: '/dashboard/load-heatmap', title: '产能负荷热力', icon: Monitor, perm: 'dashboard.view' },
      { path: '/dashboard/red-lights', title: '红灯管理', icon: Bell, perm: 'dashboard.view' },
      { path: '/dashboard/reports', title: '报表中心', icon: TrendCharts, perm: 'dashboard.view' },
    ],
  },
  {
    key: 'planning',
    title: '计划排产',
    icon: Calendar,
    children: [
      { path: '/schedule/gantt', title: '排产甘特', icon: Calendar, perm: 'schedule.view' },
      { path: '/schedule/detail', title: '排产明细', icon: DataLine, perm: 'schedule.view' },
      { path: '/schedule/suggestions', title: '顺延建议', icon: MagicStick, perm: 'schedule.view' },
    ],
  },
  {
    key: 'production',
    title: '生产执行',
    icon: ShoppingCart,
    children: [
      { path: '/order/orders', title: '工单中心', icon: ShoppingCart, perm: 'order.view' },
      { path: '/production/reports', title: '报工台账', icon: Memo, perm: 'report.view' },
      { path: '/production/report-management', title: '报表管理', icon: TrendCharts, perm: 'report.view' },
      { path: '/warehouse/stock-ins', title: '入库管理', icon: Box, perm: 'warehouse.view' },
    ],
  },
  {
    key: 'plm',
    title: '工艺 PLM',
    icon: Tools,
    children: [
      { path: '/plm/machines', title: '机台产线', icon: Monitor, perm: 'plm.machine.view' },
      { path: '/plm/products', title: '产品档案', icon: Box, perm: 'plm.product.view' },
      { path: '/plm/boms', title: 'BOM 管理', icon: Document, perm: 'plm.bom.view' },
      { path: '/plm/routes', title: '工艺路线', icon: Tools, perm: 'plm.route.view' },
      { path: '/plm/op-types', title: '工序类型', icon: Operation, perm: 'plm.route.view' },
      { path: '/plm/molds', title: '模具台账', icon: Setting, perm: 'plm.mold.view' },
      { path: '/plm/exceptions', title: '异常管理', icon: Warning, perm: 'report.view' },
      { path: '/plm/develop', title: '新品开发流程', icon: Files, perm: 'plm.product.view' },
    ],
  },
  {
    key: 'quote',
    title: '报价中心',
    icon: MagicStick,
    children: [
      { path: '/quote/estimate', title: 'AI 快速估价', icon: MagicStick, perm: 'quote.view' },
      { path: '/quote/quotes', title: '报价单管理', icon: Ticket, perm: 'quote.view' },
      { path: '/quote/params', title: '报价参数配置', icon: PriceTag, perm: 'quote.view' },
    ],
  },
  {
    key: 'system',
    title: '系统管理',
    icon: Setting,
    children: [
      { path: '/system/users', title: '用户管理', icon: User, perm: 'system.user.manage' },
      { path: '/system/roles', title: '角色权限', icon: Key, perm: 'system.role.manage' },
      { path: '/system/audit-logs', title: '操作审计', icon: List, perm: 'audit.view' },
      { path: '/system/integration-config', title: '集成配置', icon: Connection, perm: 'integration.view' },
    ],
  },
]

/** 按权限过滤菜单：仅展示有权限的子项，整组无可见项则隐藏 */
const visibleGroups = computed(() =>
  menuGroups
    .map((g) => ({ ...g, children: g.children.filter((c) => auth.can(c.perm)) }))
    .filter((g) => g.children.length > 0),
)

const nameChar = computed(() => (auth.user?.name ?? '?').charAt(0))
const roleLabel = computed(() => {
  const roleName = auth.user?.roles?.[0]
  return roleName ? (STATUS_LABELS[roleName] ?? roleName) : ''
})

onMounted(async () => {
  // 只按显式开关显示演示标识；后端失败不能改变数据源。
  await auth.refreshMe()
  isMockMode.value = isDemoMode()
})

async function onCommand(command: string): Promise<void> {
  if (command === 'logout') {
    await ElMessageBox.confirm('确定退出登录吗？', '提示', { type: 'warning' })
    await auth.logout()
    router.push('/login')
  }
}
</script>

<style scoped>
.main-layout {
  height: 100vh;
}

.sidebar {
  background: #1d2939;
  display: flex;
  flex-direction: column;
}

.logo {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 18px 16px;
  color: #fff;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.logo-mark {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  background: linear-gradient(135deg, #1677ff, #06b6d4);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: 700;
}

.logo-text {
  display: flex;
  flex-direction: column;
  line-height: 1.3;

  small {
    color: #94a3b8;
    font-size: 12px;
  }
}

.sidebar :deep(.el-menu) {
  border-right: none;
  flex: 1;
}

.header {
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e5e7eb;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.header-title {
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.user-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  outline: none;
}

.avatar {
  background: #1677ff;
  color: #fff;
  font-size: 14px;
}

.user-name {
  font-size: 14px;
  color: #1f2937;
}

.content {
  background: #f5f7fa;
  padding: 20px;
  overflow-y: auto;
}
</style>
