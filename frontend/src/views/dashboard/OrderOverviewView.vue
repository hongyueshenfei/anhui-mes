<template>
  <div class="order-overview">
    <div class="page-intro">
      <div>
        <div class="page-title">订单履约统计</div>
        <div class="page-subtitle">按订单汇总生产完成、入库完成、交期风险和计划偏差；拆单子任务统一归并到原订单。</div>
      </div>
      <el-tag type="info" effect="plain">生产完成 ≠ 入库完成</el-tag>
    </div>

    <div class="metric-grid">
      <el-card v-for="card in summaryCards" :key="card.key" shadow="never" class="metric-card" :class="`metric-card--${card.tone}`">
        <div class="metric-value">{{ card.value }}</div>
        <div class="metric-label">{{ card.label }}</div>
        <div class="metric-sub">{{ card.sub }}</div>
      </el-card>
    </div>

    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <div>
            <div class="panel-title">订单履约台账</div>
            <div class="panel-subtitle">优先展示已延期、预计延期和高优先级订单</div>
          </div>
          <div class="filters">
            <el-input v-model="keyword" placeholder="订单号/客户/金蝶单号" clearable size="small" class="keyword-input" @keyup.enter="load" />
            <el-select v-model="fulfillmentStatus" size="small" class="status-select" @change="load">
              <el-option label="全部履约状态" value="" />
              <el-option v-for="option in fulfillmentOptions" :key="option.value" :label="option.label" :value="option.value" />
            </el-select>
            <el-button size="small" type="primary" @click="load">查询</el-button>
          </div>
        </div>
      </template>

      <div class="coverage-strip">
        <span>生产完成量 <strong>{{ formatQty(summary.done_qty) }}</strong> / {{ formatQty(summary.total_qty) }}</span>
        <span>入库完成量 <strong>{{ formatQty(summary.stocked_qty) }}</strong> / {{ formatQty(summary.total_qty) }}</span>
        <span>生产完成率 <strong>{{ summary.production_completion_pct }}%</strong></span>
        <span>入库完成率 <strong>{{ summary.stock_completion_pct }}%</strong></span>
        <span>暂停 <strong class="text-warning">{{ summary.paused }}</strong> 单</span>
        <span>未开工 <strong class="text-muted">{{ summary.not_started }}</strong> 单</span>
      </div>

      <el-table v-loading="loading" :data="items" size="small" stripe border empty-text="暂无订单履约数据" class="fulfillment-table">
        <el-table-column label="订单号" width="180" fixed="left">
          <template #default="{ row }">
            <el-link type="primary" @click="openOrder(row.order_id)">{{ row.order_no }}</el-link>
            <div v-if="row.k3_bill_no" class="muted">{{ row.k3_bill_no }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="customer_name" label="客户" min-width="140" show-overflow-tooltip />
        <el-table-column label="优先级" width="72" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="priorityType(row.priority)" :effect="row.priority === 0 ? 'dark' : 'light'">P{{ row.priority }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="交期" width="145">
          <template #default="{ row }">
            <span :class="{ 'text-danger': row.due_flag === 'overdue', 'text-warning': row.due_flag === 'due_soon' }">{{ formatDate(row.delivery_date) }}</span>
            <div v-if="row.due_flag === 'overdue'" class="risk-hint">逾期 {{ row.delay_days }} 天</div>
            <div v-else-if="row.due_flag === 'due_soon'" class="risk-hint text-warning">临近交期</div>
          </template>
        </el-table-column>
        <el-table-column label="订单量" width="100" align="right">
          <template #default="{ row }">{{ formatQty(row.total_qty) }}</template>
        </el-table-column>
        <el-table-column label="生产完成" min-width="190">
          <template #default="{ row }">
            <el-progress :percentage="row.progress_pct" :stroke-width="8" :color="progressColor(row.fulfillment_status)" />
            <div class="progress-caption">{{ formatQty(row.done_qty) }} / {{ formatQty(row.total_qty) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="入库完成" width="145" align="right">
          <template #default="{ row }">
            <span>{{ formatQty(row.stocked_qty ?? 0) }}</span>
            <span class="muted">（{{ row.stock_progress_pct ?? 0 }}%）</span>
          </template>
        </el-table-column>
        <el-table-column label="计划完工" width="155">
          <template #default="{ row }">{{ formatDateTime(row.plan_end) }}</template>
        </el-table-column>
        <el-table-column label="预计完工" width="155">
          <template #default="{ row }">{{ formatDateTime(row.forecast_end) }}</template>
        </el-table-column>
        <el-table-column label="偏差" width="90" align="center">
          <template #default="{ row }">
            <span v-if="row.delay_days > 0" class="text-danger">+{{ row.delay_days }}天</span>
            <span v-else-if="row.fulfillment_status === 'completed_on_time'" class="text-success">按期</span>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="履约状态" width="110" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="fulfillmentTagType(row.fulfillment_status)">{{ row.fulfillment_status_label ?? '-' }}</el-tag>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { dashboardApi } from '@/api/dashboard'
import type { OrderOverviewData } from '@/types/dashboard'

const router = useRouter()
const loading = ref(false)
const keyword = ref('')
const fulfillmentStatus = ref('')
const data = ref<OrderOverviewData | null>(null)

const emptySummary: NonNullable<OrderOverviewData['summary']> = {
  total_orders: 0,
  production_completed: 0,
  on_time_completed: 0,
  late_completed: 0,
  in_progress: 0,
  forecast_late: 0,
  overdue: 0,
  not_started: 0,
  paused: 0,
  total_qty: 0,
  done_qty: 0,
  process_plan_qty: 0,
  process_done_qty: 0,
  process_progress_pct: 0,
  stocked_qty: 0,
  production_completion_pct: 0,
  stock_completion_pct: 0,
  on_time_rate: null,
}

const fulfillmentOptions = [
  { value: 'completed_on_time', label: '按期完成' },
  { value: 'completed_late', label: '延期完成' },
  { value: 'in_progress', label: '生产中' },
  { value: 'scheduled', label: '已排产' },
  { value: 'forecast_late', label: '预计延期' },
  { value: 'overdue', label: '已延期' },
  { value: 'paused', label: '已暂停' },
  { value: 'not_started', label: '未开工' },
]

const summary = computed(() => data.value?.summary ?? emptySummary)
const items = computed(() => data.value?.items ?? [])
const summaryCards = computed(() => [
  { key: 'total', label: '订单总数', value: `${summary.value.total_orders} 单`, sub: `订单量 ${formatQty(summary.value.total_qty)} 件`, tone: 'neutral' },
  { key: 'completed', label: '生产完成', value: `${summary.value.production_completed} 单`, sub: `完成量 ${formatQty(summary.value.done_qty)} 件`, tone: 'success' },
  { key: 'on-time', label: '按期完成率', value: summary.value.on_time_rate === null ? '-' : `${summary.value.on_time_rate}%`, sub: `按期 ${summary.value.on_time_completed} 单 · 延期完成 ${summary.value.late_completed} 单`, tone: 'primary' },
  { key: 'in-progress', label: '生产中', value: `${summary.value.in_progress} 单`, sub: '当前生产中的订单', tone: 'primary' },
  { key: 'forecast-late', label: '预计延期', value: `${summary.value.forecast_late} 单`, sub: '按当前预计完工时间判断', tone: 'warning' },
  { key: 'overdue', label: '已延期', value: `${summary.value.overdue} 单`, sub: `暂停 ${summary.value.paused} · 未开工 ${summary.value.not_started}`, tone: 'danger' },
])

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await dashboardApi.orderOverview({
      keyword: keyword.value.trim() || undefined,
      fulfillment_status: fulfillmentStatus.value || undefined,
    })
  } catch {
    data.value = null
  } finally {
    loading.value = false
  }
}

function formatQty(value: number | null | undefined): string {
  return Number(value ?? 0).toLocaleString('zh-CN')
}

function formatDate(value: string | null | undefined): string {
  return value ? value.slice(0, 10) : '-'
}

function formatDateTime(value: string | null | undefined): string {
  return value ? value.slice(0, 16) : '-'
}

function priorityType(priority: number): 'danger' | 'warning' | 'info' {
  return priority === 0 ? 'danger' : priority <= 1 ? 'warning' : 'info'
}

function fulfillmentTagType(status: string | undefined): 'success' | 'warning' | 'danger' | 'info' | 'primary' {
  if (status === 'completed_on_time') return 'success'
  if (status === 'completed_late' || status === 'forecast_late') return 'warning'
  if (status === 'overdue' || status === 'aborted') return 'danger'
  if (status === 'in_progress') return 'primary'
  return 'info'
}

function progressColor(status: string | undefined): string {
  if (status === 'overdue' || status === 'completed_late') return '#f56c6c'
  if (status === 'forecast_late') return '#e6a23c'
  if (status === 'completed_on_time') return '#67c23a'
  return '#409eff'
}

function openOrder(orderId: number): void {
  router.push(`/order/detail/${orderId}`)
}

onMounted(load)
</script>

<style scoped>
.order-overview {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.page-intro,
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.page-title {
  color: #1f2937;
  font-size: 18px;
  font-weight: 700;
}

.page-subtitle,
.panel-subtitle,
.muted {
  color: #94a3b8;
  font-size: 12px;
}

.page-subtitle {
  margin-top: 4px;
}

.metric-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
}

.metric-card {
  min-height: 84px;
  border-top: 3px solid #dbeafe;
  text-align: center;
}

.metric-card--success {
  border-top-color: #67c23a;
}

.metric-card--primary {
  border-top-color: #409eff;
}

.metric-card--warning {
  border-top-color: #e6a23c;
}

.metric-card--danger {
  border-top-color: #f56c6c;
}

.metric-value {
  color: #1f2937;
  font-size: 22px;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  line-height: 1.3;
}

.metric-label {
  color: #475569;
  font-size: 13px;
}

.metric-sub {
  margin-top: 3px;
  color: #94a3b8;
  font-size: 12px;
  white-space: nowrap;
}

.panel-title {
  color: #1f2937;
  font-size: 15px;
  font-weight: 600;
}

.filters {
  display: flex;
  align-items: center;
  gap: 8px;
}

.keyword-input {
  width: 205px;
}

.status-select {
  width: 145px;
}

.coverage-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
  margin-bottom: 10px;
  padding: 8px 12px;
  border-left: 3px solid #409eff;
  background: #f8fafc;
  color: #64748b;
  font-size: 12px;
}

.coverage-strip strong {
  color: #1f2937;
  font-variant-numeric: tabular-nums;
}

.text-danger {
  color: #f56c6c;
  font-weight: 600;
}

.text-warning {
  color: #e6a23c;
}

.text-success {
  color: #67c23a;
  font-weight: 600;
}

.text-muted {
  color: #64748b !important;
}

.risk-hint,
.progress-caption {
  margin-top: 3px;
  color: #94a3b8;
  font-size: 12px;
}

.progress-caption {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

:deep(.el-card__body) {
  padding: 14px;
}

:deep(.metric-card .el-card__body) {
  padding: 10px 12px;
}

:deep(.fulfillment-table) {
  color: #334155;
  font-variant-numeric: tabular-nums;
}

:deep(.fulfillment-table th.el-table__cell) {
  color: #475569;
  font-weight: 600;
}

@media (max-width: 1280px) {
  .metric-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 760px) {
  .page-intro,
  .panel-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .filters {
    width: 100%;
    flex-wrap: wrap;
  }

  .keyword-input,
  .status-select {
    flex: 1;
    min-width: 160px;
    width: auto;
  }

  .metric-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .metric-sub {
    white-space: normal;
  }
}
</style>
