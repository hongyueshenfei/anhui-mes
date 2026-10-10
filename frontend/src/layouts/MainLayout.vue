<template>
  <el-container class="main-layout">
    <el-aside width="248px" class="desktop-sidebar">
      <PrimaryNavigation :groups="visibleGroups" :active-path="route.path" />
    </el-aside>

    <el-container class="workspace-column">
      <el-header class="app-header">
        <div class="header-leading">
          <el-button
            class="mobile-nav-trigger"
            text
            :icon="Expand"
            aria-label="打开主导航"
            @click="mobileNavOpen = true"
          />
          <div class="header-context">
            <span class="header-title">{{ route.meta.title }}</span>
            <span class="header-caption">虹越生产协同平台</span>
          </div>
        </div>

        <div class="header-right">
          <el-tag v-if="isMockMode" type="warning" size="small" effect="plain">
            演示数据
          </el-tag>
          <el-dropdown @command="onCommand">
            <button class="user-chip" type="button" aria-label="打开用户菜单">
              <el-avatar :size="30" class="avatar">{{ nameChar }}</el-avatar>
              <span class="user-name">{{ auth.user?.name ?? '未登录' }}</span>
              <el-icon><ArrowDown /></el-icon>
            </button>
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

      <el-main class="content">
        <router-view />
      </el-main>
    </el-container>

    <el-drawer
      v-model="mobileNavOpen"
      class="mobile-navigation-drawer"
      direction="ltr"
      size="280px"
      :with-header="false"
      :lock-scroll="true"
      aria-label="主导航"
    >
      <PrimaryNavigation
        :groups="visibleGroups"
        :active-path="route.path"
        @select="mobileNavOpen = false"
      />
    </el-drawer>
  </el-container>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import {
  ArrowDown,
  Bell,
  Box,
  Calendar,
  Connection,
  DataLine,
  Document,
  Expand,
  Files,
  Key,
  List,
  MagicStick,
  Memo,
  Monitor,
  Operation,
  PriceTag,
  Setting,
  ShoppingCart,
  Ticket,
  Tools,
  TrendCharts,
  User,
  Warning,
} from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { STATUS_LABELS } from '@/utils/capacity'
import { isDemoMode } from '@/config/demo'
import PrimaryNavigation from '@/components/layout/PrimaryNavigation.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isMockMode = ref(isDemoMode())
const mobileNavOpen = ref(false)

interface MenuItem {
  path: string
  title: string
  icon: typeof DataLine
  perm: string
}

interface MenuGroup {
  key: string
  title: string
  icon: typeof DataLine
  children: MenuItem[]
}

const menuGroups: MenuGroup[] = [
  {
    key: 'dashboard', title: '管理看板', icon: DataLine,
    children: [
      { path: '/dashboard/workbench', title: '工作台', icon: DataLine, perm: 'dashboard.view' },
      { path: '/dashboard/order-overview', title: '订单履约统计', icon: ShoppingCart, perm: 'dashboard.view' },
      { path: '/dashboard/load-heatmap', title: '产能负荷热力', icon: Monitor, perm: 'dashboard.view' },
      { path: '/dashboard/red-lights', title: '红灯管理', icon: Bell, perm: 'dashboard.view' },
      { path: '/dashboard/reports', title: '报表中心', icon: TrendCharts, perm: 'dashboard.view' },
    ],
  },
  {
    key: 'planning', title: '计划排产', icon: Calendar,
    children: [
      { path: '/schedule/gantt', title: '排产甘特', icon: Calendar, perm: 'schedule.view' },
      { path: '/schedule/detail', title: '排产明细', icon: DataLine, perm: 'schedule.view' },
      { path: '/schedule/suggestions', title: '顺延建议', icon: MagicStick, perm: 'schedule.view' },
    ],
  },
  {
    key: 'production', title: '生产执行', icon: ShoppingCart,
    children: [
      { path: '/order/orders', title: '工单中心', icon: ShoppingCart, perm: 'order.view' },
      { path: '/production/reports', title: '报工台账', icon: Memo, perm: 'report.view' },
      { path: '/production/report-management', title: '报表管理', icon: TrendCharts, perm: 'report.view' },
      { path: '/warehouse/stock-ins', title: '入库管理', icon: Box, perm: 'warehouse.view' },
    ],
  },
  {
    key: 'plm', title: '工艺 PLM', icon: Tools,
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
    key: 'quote', title: '报价中心', icon: MagicStick,
    children: [
      { path: '/quote/estimate', title: 'AI 快速估价', icon: MagicStick, perm: 'quote.view' },
      { path: '/quote/quotes', title: '报价单管理', icon: Ticket, perm: 'quote.view' },
      { path: '/quote/params', title: '报价参数配置', icon: PriceTag, perm: 'quote.view' },
    ],
  },
  {
    key: 'system', title: '系统管理', icon: Setting,
    children: [
      { path: '/system/users', title: '用户管理', icon: User, perm: 'system.user.manage' },
      { path: '/system/roles', title: '角色权限', icon: Key, perm: 'system.role.manage' },
      { path: '/system/audit-logs', title: '操作审计', icon: List, perm: 'audit.view' },
      { path: '/system/integration-config', title: '集成配置', icon: Connection, perm: 'integration.view' },
    ],
  },
]

const visibleGroups = computed(() =>
  menuGroups
    .map((group) => ({ ...group, children: group.children.filter((item) => auth.can(item.perm)) }))
    .filter((group) => group.children.length > 0),
)

const nameChar = computed(() => (auth.user?.name ?? '?').charAt(0))
const roleLabel = computed(() => {
  const roleName = auth.user?.roles?.[0]
  return roleName ? (STATUS_LABELS[roleName] ?? roleName) : ''
})

watch(() => route.path, () => {
  mobileNavOpen.value = false
})

onMounted(async () => {
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
  width: 100%;
  min-height: 100vh;
  background: var(--hy-canvas);
}

.desktop-sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  background: var(--hy-ink);
}

.workspace-column {
  min-width: 0;
}

.app-header {
  position: sticky;
  z-index: 5;
  top: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 62px;
  padding: 0 24px;
  border-bottom: 1px solid var(--hy-line);
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(8px);
}

.header-leading,
.header-context,
.header-right {
  display: flex;
  align-items: center;
}

.header-leading {
  gap: 12px;
}

.header-context {
  align-items: flex-start;
  flex-direction: column;
  gap: 1px;
}

.header-title {
  color: var(--hy-ink);
  font-size: 15px;
  font-weight: 650;
}

.header-caption {
  color: var(--hy-muted);
  font-size: 11px;
}

.header-right {
  gap: 14px;
}

.user-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 7px 4px 4px;
  border: 1px solid transparent;
  border-radius: 7px;
  color: var(--hy-text);
  background: transparent;
  cursor: pointer;
}

.user-chip:hover {
  border-color: var(--hy-line);
  background: #f7f9fa;
}

.avatar {
  color: #fff;
  background: #28516b;
  font-size: 13px;
  font-weight: 650;
}

.user-name {
  color: var(--hy-text);
  font-size: 13px;
}

.mobile-nav-trigger {
  display: none;
  min-height: 34px;
  color: var(--hy-ink);
  font-size: 18px;
}

.content {
  min-width: 0;
  padding: 20px 22px 28px;
  overflow-x: auto;
  background: var(--hy-canvas);
}

.mobile-navigation-drawer :deep(.el-drawer__body) {
  padding: 0;
  background: var(--hy-ink);
}

@media (max-width: 900px) {
  .desktop-sidebar {
    display: none;
  }

  .mobile-nav-trigger {
    display: inline-flex;
  }

  .app-header {
    padding: 0 16px;
  }

  .content {
    padding: 14px;
  }
}

@media (max-width: 560px) {
  .header-caption {
    display: none;
  }

  .header-right {
    gap: 7px;
  }

  .user-name {
    max-width: 78px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .content {
    padding: 10px;
  }
}
</style>
