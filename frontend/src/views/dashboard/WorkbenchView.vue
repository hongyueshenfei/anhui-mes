<template>
  <div class="workbench">
    <!-- KPI 卡片 -->
    <el-row :gutter="12" class="kpi-row">
      <el-col :span="6" v-for="card in kpiCards" :key="card.label">
        <el-card shadow="never" class="kpi-card" :class="card.tone">
          <div class="kpi-value">{{ card.value }}</div>
          <div class="kpi-label">{{ card.label }}</div>
          <div class="kpi-sub">{{ card.sub }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="12" class="main-row">
      <!-- 红灯速览 -->
      <el-col :span="10">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>红灯速览</span>
              <el-button size="small" type="danger" plain @click="goRedLights">
                红灯管理（{{ stats?.red_lights.total ?? 0 }}）<el-icon><ArrowRight /></el-icon>
              </el-button>
            </div>
          </template>
          <div v-if="redItems.length === 0" class="empty">当前无红灯 🎉</div>
          <div v-for="rl in redItems" :key="rl.id" class="rl-item" :class="`rl-${rl.source}`" @click="goRedLights">
            <el-tag :type="rlTagType(rl.source)" size="small">{{ rl.source_label }}</el-tag>
            <span class="rl-task">{{ rl.task_no }} · {{ rl.product_name }}</span>
            <span class="rl-reason">{{ rl.reason }}</span>
          </div>
        </el-card>
      </el-col>

      <!-- 临期 3 天订单 -->
      <el-col :span="14">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>临期订单（3 天内交付）</span>
              <el-button size="small" @click="goOrders">订单总览<el-icon><ArrowRight /></el-icon></el-button>
            </div>
          </template>
          <el-table :data="soonOrders" size="small" stripe>
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
      <el-table :data="todayTasks" size="small" stripe>
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

onMounted(async () => {
  try {
    data.value = await dashboardApi.workbench()
  } catch {
    data.value = null
  }
})
</script>

<style scoped>
.workbench {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.kpi-row {
  margin-bottom: 0;
}
.kpi-card {
  border-radius: 10px;
  border-left: 4px solid #1677ff;
}
.kpi-card.danger {
  border-left-color: #f56c6c;
}
.kpi-card.green {
  border-left-color: #67c23a;
}
.kpi-card.cyan {
  border-left-color: #06b6d4;
}
.kpi-value {
  font-size: 26px;
  font-weight: 700;
  color: #1f2937;
}
.kpi-label {
  font-size: 13px;
  color: #6b7280;
  margin-top: 2px;
}
.kpi-sub {
  font-size: 12px;
  color: #94a3b8;
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
  font-weight: 600;
}
.rl-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 4px;
  border-bottom: 1px dashed #e5e7eb;
  cursor: pointer;
  font-size: 13px;
}
.rl-item:hover {
  background: #f8fafc;
}
.rl-task {
  font-weight: 600;
  color: #1f2937;
  white-space: nowrap;
}
.rl-reason {
  color: #6b7280;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.empty {
  color: #94a3b8;
  font-size: 13px;
  padding: 20px 0;
  text-align: center;
}
</style>