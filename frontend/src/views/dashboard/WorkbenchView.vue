<template>
  <div v-loading="loading" class="workbench">
    <div v-if="loadError" class="load-error" role="alert">
      <span>{{ loadError }}</span>
      <el-button text type="primary" @click="load">重新加载</el-button>
    </div>

    <!-- KPI 卡片 -->
    <el-row :gutter="12" class="kpi-row">
      <el-col v-for="card in kpiCards" :key="card.label" :xs="12" :sm="12" :md="6">
        <el-card shadow="never" class="kpi-card" :class="card.tone">
          <div class="kpi-value">{{ card.value }}</div>
          <div class="kpi-label">{{ card.label }}</div>
          <div class="kpi-sub">{{ card.sub }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="12" class="main-row">
      <!-- 红灯速览 -->
      <el-col :xs="24" :lg="10">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>红灯速览</span>
              <el-button size="small" type="danger" plain @click="goRedLights">
                红灯管理（{{ stats?.red_lights.total ?? 0 }}）<el-icon><ArrowRight /></el-icon>
              </el-button>
            </div>
          </template>
          <div v-if="!loading && data && redItems.length === 0" class="empty">暂无待处理异常</div>
          <button
            v-for="rl in redItems"
            :key="rl.id"
            type="button"
            class="rl-item"
            :class="`rl-${rl.source}`"
            :aria-label="`查看异常任务 ${rl.task_no}，${rl.reason}`"
            @click="goRedLights"
          >
            <el-tag :type="rlTagType(rl.source)" size="small">{{ rl.source_label }}</el-tag>
            <span class="rl-task">{{ rl.task_no }} · {{ rl.product_name }}</span>
            <span class="rl-reason">{{ rl.reason }}</span>
            <span class="rl-action">查看</span>
          </button>
        </el-card>
      </el-col>

      <!-- 临期 3 天订单 -->
      <el-col :xs="24" :lg="14">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>临期订单（3 天内交付）</span>
              <el-button size="small" @click="goOrders">订单总览<el-icon><ArrowRight /></el-icon></el-button>
            </div>
          </template>
          <el-table :data="soonOrders" size="small" stripe empty-text="近 3 天暂无到期订单">
            <el-table-column prop="order_no" label="订单号" width="150" />
            <el-table-column prop="customer_name" label="客户" min-width="120" />
            <el-table-column prop="delivery_date" label="交期" width="160" />
            <el-table-column label="进度" width="160">
              <template #default="{ row }">
                <el-progress :percentage="row.progress_pct" :stroke-width="8" />
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>

    <!-- 今日派工 -->
    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header"><span>今日派工任务（排产甘特）</span></div>
      </template>
      <el-table :data="todayTasks" size="small" stripe empty-text="今天暂无排程任务">
        <el-table-column prop="task_no" label="任务号" width="150" />
        <el-table-column prop="product_code" label="产品编码" width="110" />
        <el-table-column prop="product_name" label="产品名称" min-width="140" />
        <el-table-column prop="machine_code" label="机台" width="90" />
        <el-table-column prop="plan_start" label="计划开始" width="160" />
        <el-table-column prop="plan_end" label="计划结束" width="160" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="taskStatusType(row.status)" size="small">{{ taskStatusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="进度" width="140">
          <template #default="{ row }">
            <el-progress :percentage="row.progress_pct" :stroke-width="8" />
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight } from '@element-plus/icons-vue'
import { dashboardApi } from '@/api/dashboard'
import type { RedLight, WorkbenchData } from '@/types/dashboard'

const router = useRouter()
const data = ref<WorkbenchData | null>(null)
const loading = ref(true)
const loadError = ref('')

const stats = computed(() => data.value?.stats ?? null)
const redItems = computed<RedLight[]>(() => data.value?.top_red_lights ?? [])
const soonOrders = computed(() => data.value?.soon_orders ?? [])
const todayTasks = computed(() => data.value?.today_tasks ?? [])

const kpiCards = computed(() => {
  const s = stats.value
  return [
    { label: '进行中订单', value: s ? `${s.orders.in_progress}/${s.orders.total}` : '-', sub: `待排 ${s?.orders.pending ?? 0} · 已交付 ${s?.orders.done ?? 0}`, tone: 'blue' },
    { label: '未排产任务', value: s?.tasks.unscheduled ?? '-', sub: `进行中 ${s?.tasks.running ?? 0} · 落后 ${s?.tasks.behind ?? 0}`, tone: s && s.tasks.unscheduled > 0 ? 'danger' : 'green' },
    { label: '今日报工/入库', value: s ? `${s.today.report_qty.toLocaleString()}/${s.today.stock_in_qty.toLocaleString()}` : '-', sub: `报工 ${s?.today.report_count ?? 0} 笔`, tone: 'cyan' },
    { label: '红灯未处理', value: s?.red_lights.total ?? '-', sub: s ? s.red_lights.by_source.map((b) => `${b.source_label} ${b.count}`).join(' · ') : '', tone: s && (s?.red_lights.total ?? 0) > 0 ? 'danger' : 'green' },
  ]
})

function rlTagType(source: string): 'danger' | 'warning' | 'info' | 'primary' {
  return source === 'unscheduled' ? 'warning' : source === 'no_report' ? 'info' : 'danger'
}
function taskStatusType(status: string): 'primary' | 'success' | 'warning' | 'info' {
  return status === 'running' ? 'success' : status === 'pending' ? 'info' : status === 'paused' ? 'warning' : 'primary'
}
function taskStatusLabel(status: string): string {
  return status === 'running' ? '进行中' : status === 'pending' ? '待开始' : status === 'paused' ? '已暂停' : status === 'done' ? '已完成' : status
}

function goRedLights(): void {
  router.push('/dashboard/red-lights')
}
function goOrders(): void {
  router.push('/dashboard/order-overview')
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await dashboardApi.workbench()
  } catch {
    data.value = null
    loadError.value = '工作台数据暂时无法加载，请检查连接后重试。'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.workbench {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.load-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 12px;
  border: 1px solid #ecc8c5;
  border-radius: 6px;
  color: var(--hy-red);
  background: #fff7f6;
}
.kpi-row {
  margin-bottom: 0;
}
.kpi-card {
  min-height: 120px;
  border-left: 3px solid var(--hy-blue);
}
.kpi-card.danger {
  border-left-color: var(--hy-red);
}
.kpi-card.green {
  border-left-color: var(--hy-green);
}
.kpi-card.cyan {
  border-left-color: #4b8e9c;
}
.kpi-value {
  font-size: 26px;
  font-weight: 700;
  color: var(--hy-ink);
  font-variant-numeric: tabular-nums;
}
.kpi-label {
  font-size: 13px;
  color: #536776;
  margin-top: 2px;
}
.kpi-sub {
  font-size: 12px;
  color: var(--hy-muted);
  margin-top: 4px;
}
.main-row {
  margin-bottom: 0;
}
.panel {
  border-radius: 10px;
}
.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  color: var(--hy-ink);
  font-weight: 650;
}
.rl-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 4px;
  border-bottom: 1px dashed #e5e7eb;
  cursor: pointer;
  width: 100%;
  border: 0;
  text-align: left;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 13px;
}
.rl-item:hover {
  background: #eef5f8;
}
.rl-item:focus-visible {
  outline: 3px solid rgba(23, 109, 179, 0.55);
  outline-offset: 2px;
}
.rl-task {
  font-weight: 600;
  color: var(--hy-ink);
  white-space: nowrap;
}
.rl-reason {
  color: #536776;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rl-action {
  flex: 0 0 auto;
  color: var(--hy-blue);
  font-size: 12px;
}
.empty {
  color: var(--hy-muted);
  font-size: 13px;
  padding: 20px 0;
  text-align: left;
}

@media (max-width: 760px) {
  .load-error,
  .panel-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .rl-item {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .rl-reason {
    flex: 1 1 100%;
    padding-left: 2px;
  }
}
</style>
