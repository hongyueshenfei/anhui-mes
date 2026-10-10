<template>
  <div class="order-overview">
    <div class="page-intro">
      <div>
        <div class="page-subtitle">拆单任务归并到原订单；工序推进、成品生产完成和入库完成分别统计。</div>
      </div>
      <el-tag type="info" effect="plain">工序推进 ≠ 成品完成 ≠ 入库完成</el-tag>
    </div>

    <div class="metric-grid">
      <el-card v-for="card in summaryCards" :key="card.key" shadow="never" class="metric-card" :class="`metric-card--${card.tone}`">
        <div class="metric-value">{{ hasData ? card.value : '—' }}</div>
        <div class="metric-label">{{ card.label }}</div>
        <div class="metric-sub">{{ hasData ? card.sub : loadError ? '数据暂不可用' : '正在获取最新数据' }}</div>
      </el-card>
    </div>

    <el-card shadow="never" class="panel">
      <div v-if="loadError" class="load-error" role="alert">
        <span>{{ loadError }}</span>
        <el-button size="small" type="primary" plain @click="load">重新加载</el-button>
      </div>
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

      <div v-if="hasData" class="coverage-strip">
        <span>工序推进 <strong>{{ summary.process_progress_pct }}%</strong>（{{ formatQty(summary.process_done_qty) }} / {{ formatQty(summary.process_plan_qty) }} 件）</span>
        <span>生产完成量 <strong>{{ formatQty(summary.done_qty) }}</strong> / {{ formatQty(summary.total_qty) }}</span>
        <span>入库完成量 <strong>{{ formatQty(summary.stocked_qty) }}</strong> / {{ formatQty(summary.total_qty) }}</span>
        <span>生产完成率 <strong>{{ summary.production_completion_pct }}%</strong></span>
        <span>入库完成率 <strong>{{ summary.stock_completion_pct }}%</strong></span>
        <span>暂停 <strong class="text-warning">{{ summary.paused }}</strong> 单</span>
        <span>未开工 <strong class="text-muted">{{ summary.not_started }}</strong> 单</span>
      </div>
      <div v-else class="coverage-placeholder">
        {{ loadError ? '汇总数据未能获取。' : '正在加载履约汇总…' }}
      </div>

      <div v-if="isCompact" class="table-scroll-hint">表格可横向滚动；工序推进量按订单各工序报工汇总，不代表最终成品数</div>
      <div class="table-scroll">
      <el-table v-loading="loading" :data="items" :style="{ minWidth: isCompact ? '1600px' : '100%' }" size="small" stripe border :empty-text="tableEmptyText" class="fulfillment-table">
        <el-table-column label="订单号" width="180">
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
        <el-table-column label="工序推进" min-width="190">
          <template #default="{ row }">
            <el-progress :percentage="row.process_progress_pct ?? 0" :stroke-width="7" color="var(--hy-blue)" :format="(percentage: number) => `${percentage.toFixed(1)}%`" />
            <div class="progress-caption">{{ formatQty(row.process_done_qty) }} / {{ formatQty(row.process_plan_qty) }} 件</div>
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
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { dashboardApi } from '@/api/dashboard'
import type { OrderOverviewData } from '@/types/dashboard'

const router = useRouter()
const loading = ref(true)
const loadError = ref('')
const keyword = ref('')
const fulfillmentStatus = ref('')
const data = ref<OrderOverviewData | null>(null)
const hasData = computed(() => data.value !== null)
const isCompact = ref(false)
let compactMediaQuery: MediaQueryList | undefined
const tableEmptyText = computed(() => {
  if (loading.value) return '正在加载订单履约数据…'
  if (loadError.value && !hasData.value) return '数据加载失败，请重新加载'
  return '暂无符合条件的订单履约数据'
})

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
  loadError.value = ''
  try {
    data.value = await dashboardApi.orderOverview({
      keyword: keyword.value.trim() || undefined,
      fulfillment_status: fulfillmentStatus.value || undefined,
    })
  } catch {
    loadError.value = data.value
      ? '刷新失败，当前仍显示上次成功查询的数据。'
      : '订单履约数据加载失败，请检查连接后重试。'
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
  if (status === 'overdue' || status === 'completed_late') return 'var(--hy-red)'
  if (status === 'forecast_late') return 'var(--hy-amber)'
  if (status === 'completed_on_time') return 'var(--hy-green)'
  return 'var(--hy-blue)'
}

function openOrder(orderId: number): void {
  router.push(`/order/detail/${orderId}`)
}

function syncCompactLayout(event: MediaQueryListEvent): void {
  isCompact.value = event.matches
}

onMounted(() => {
  compactMediaQuery = window.matchMedia('(max-width: 1600px)')
  isCompact.value = compactMediaQuery.matches
  compactMediaQuery.addEventListener('change', syncCompactLayout)
  load()
})

onUnmounted(() => compactMediaQuery?.removeEventListener('change', syncCompactLayout))
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
  min-height: 88px;
  border-left: 3px solid #dbe4ea;
  text-align: left;
}

.metric-card--success {
  border-left-color: var(--hy-green);
}

.metric-card--primary {
  border-left-color: var(--hy-blue);
}

.metric-card--warning {
  border-left-color: var(--hy-amber);
}

.metric-card--danger {
  border-left-color: var(--hy-red);
}

.metric-value {
  color: var(--hy-ink);
  font-size: 22px;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  line-height: 1.3;
}

.metric-label {
  color: #536776;
  font-size: 13px;
}

.metric-sub {
  margin-top: 3px;
  color: var(--hy-muted);
  font-size: 12px;
  line-height: 1.4;
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
  border-left: 3px solid var(--hy-blue);
  background: #f5f8fa;
  color: #536776;
  font-size: 12px;
}

.coverage-strip strong {
  color: var(--hy-ink);
  font-variant-numeric: tabular-nums;
}

.text-danger {
  color: var(--hy-red);
  font-weight: 600;
}

.text-warning {
  color: var(--hy-amber);
}

.text-success {
  color: var(--hy-green);
  font-weight: 600;
}

.text-muted {
  color: var(--hy-muted) !important;
}

.coverage-placeholder {
  margin-bottom: 10px;
  padding: 10px 12px;
  border-left: 3px solid var(--hy-line);
  background: #f8fafb;
  color: var(--hy-muted);
  font-size: 12px;
}

.coverage-placeholder span {
  margin-left: 8px;
}

.load-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  padding: 9px 12px;
  border: 1px solid #ecc8c5;
  border-radius: 6px;
  color: var(--hy-red);
  background: #fff7f6;
}

.table-scroll {
  width: 100%;
  overflow-x: auto;
}

.table-scroll-hint {
  margin: -4px 0 7px;
  color: var(--hy-muted);
  font-size: 12px;
  text-align: right;
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

:deep(.fulfillment-table .el-table__inner-wrapper) {
  min-width: 100%;
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

@media (max-width: 1100px) {
  .metric-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 760px) {
  .page-intro,
  .panel-header,
  .load-error {
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
