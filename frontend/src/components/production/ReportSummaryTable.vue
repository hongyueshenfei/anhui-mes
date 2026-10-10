<template>
  <div class="summary-page">
    <div class="summary-switcher">
      <div>
        <div class="summary-title">生产执行报表</div>
        <div class="summary-subtitle">汇总订单履约、日班次执行、产品工序、交期、资源效率和质量数据</div>
      </div>
      <el-radio-group v-model="dimension" size="small" class="dimension-switcher" @change="load">
        <el-radio-button v-for="item in dimensionOptions" :key="item.value" :value="item.value">
          {{ item.label }}
        </el-radio-button>
      </el-radio-group>
    </div>

    <div class="summary-toolbar" :class="{ 'summary-toolbar--orders': dimension === 'order_progress' }">
      <el-date-picker
        v-if="dimension !== 'order_progress'"
        v-model="dateRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        range-separator="~"
        start-placeholder="作业开始日期"
        end-placeholder="作业结束日期"
        clearable
        class="filter-date"
      />
      <el-input
        v-model="keyword"
        clearable
        :placeholder="dimension === 'order_progress' ? '订单号/客户/金蝶单号' : '任务号/订单号/产品编码'"
        class="filter-keyword"
        @keyup.enter="load"
      />
      <el-select
        v-if="dimension === 'order_progress'"
        v-model="fulfillmentStatus"
        clearable
        placeholder="全部履约状态"
        class="filter-status"
        @change="load"
      >
        <el-option v-for="option in fulfillmentOptions" :key="option.value" :label="option.label" :value="option.value" />
      </el-select>
      <el-input
        v-if="dimension !== 'order_progress'"
        v-model="machineKeyword"
        clearable
        placeholder="机台/产线编码"
        class="filter-machine"
        @keyup.enter="load"
      />
      <el-button type="primary" @click="load">查询</el-button>
    </div>

    <el-alert
      v-if="errorMessage"
      type="error"
      :closable="false"
      show-icon
      class="summary-error"
      :title="errorMessage"
    />

    <div class="summary-note">{{ activeOption.note }}</div>

    <div v-loading="loading" class="metric-grid">
      <div
        v-for="metric in metrics"
        :key="metric.label"
        class="metric-card"
        :class="`metric-card--${metric.tone || 'neutral'}`"
        >
        <div class="metric-value" :class="metric.tone">{{ metric.value }}</div>
        <div class="metric-label">{{ metric.label }}</div>
        <div v-if="metric.sub" class="metric-sub">{{ metric.sub }}</div>
      </div>
    </div>

    <el-table v-if="dimension === 'order_progress'" v-loading="loading" :data="orderItems" stripe border empty-text="暂无订单进度数据">
      <el-table-column label="订单号" min-width="190" fixed="left">
        <template #default="{ row }">
          <div>{{ row.order_no }}</div>
          <div v-if="row.k3_bill_no" class="sub">{{ row.k3_bill_no }}</div>
        </template>
      </el-table-column>
      <el-table-column prop="customer_name" label="客户" min-width="140" show-overflow-tooltip />
      <el-table-column label="优先级" width="82" align="center">
        <template #default="{ row }"><el-tag size="small" :type="priorityTag(row.priority)">P{{ row.priority }}</el-tag></template>
      </el-table-column>
      <el-table-column label="交期" width="125">
        <template #default="{ row }">
          <span :class="{ 'defect': row.due_flag === 'overdue', 'remaining': row.due_flag === 'due_soon' }">{{ row.delivery_date ?? '-' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="订单量" width="105" align="right"><template #default="{ row }">{{ formatQty(row.total_qty) }}</template></el-table-column>
      <el-table-column label="工序推进" min-width="175">
        <template #default="{ row }">
          <el-progress :percentage="row.process_progress_pct" :stroke-width="8" :color="orderProgressColor(row.fulfillment_status)" />
          <div class="progress-caption">{{ formatQty(row.process_done_qty) }} / {{ formatQty(row.process_plan_qty) }} 件次</div>
        </template>
      </el-table-column>
      <el-table-column label="成品完成" width="125" align="right">
        <template #default="{ row }">{{ formatQty(row.done_qty) }} <span class="sub">（{{ row.progress_pct }}%）</span></template>
      </el-table-column>
      <el-table-column label="入库完成" width="145" align="right">
        <template #default="{ row }">{{ formatQty(row.stocked_qty) }} <span class="sub">（{{ row.stock_progress_pct ?? 0 }}%）</span></template>
      </el-table-column>
      <el-table-column prop="plan_end" label="计划完工" width="150" />
      <el-table-column prop="forecast_end" label="预计完工" width="150" />
      <el-table-column label="偏差" width="85" align="center">
        <template #default="{ row }">
          <span v-if="row.delay_days > 0" class="defect">+{{ row.delay_days }}天</span>
          <span v-else-if="row.fulfillment_status === 'completed_on_time'" class="finished">按期</span>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="订单状态" width="115" align="center">
        <template #default="{ row }"><el-tag size="small" :type="fulfillmentTag(row.fulfillment_status)">{{ row.fulfillment_status_label ?? '-' }}</el-tag></template>
      </el-table-column>
    </el-table>

    <el-table v-else-if="dimension === 'detail'" v-loading="loading" :data="items" stripe border empty-text="暂无报表数据">
      <el-table-column prop="work_date" label="实际作业日期" width="120" />
      <el-table-column prop="task_no" label="任务号" min-width="190" />
      <el-table-column prop="order_no" label="订单号" min-width="150" />
      <el-table-column label="产品" min-width="180">
        <template #default="{ row }">
          {{ row.product?.name ?? '-' }}<span v-if="row.product?.code" class="sub">（{{ row.product.code }}）</span>
        </template>
      </el-table-column>
      <el-table-column label="工序" width="120"><template #default="{ row }">{{ operationLabel(row) }}</template></el-table-column>
      <el-table-column label="机台/产线" width="140"><template #default="{ row }">{{ machineLabel(row) }}</template></el-table-column>
      <el-table-column label="计划量" width="95" align="right"><template #default="{ row }">{{ formatQty(row.planned_qty) }}</template></el-table-column>
      <el-table-column label="净合格" width="95" align="right"><template #default="{ row }">{{ formatQty(row.qualified_qty) }}</template></el-table-column>
      <el-table-column label="次品数" width="95" align="right"><template #default="{ row }"><span class="defect">{{ formatQty(row.defect_qty) }}</span></template></el-table-column>
      <el-table-column label="完成率" width="110" align="center"><template #default="{ row }"><el-progress :percentage="row.completion_pct ?? 0" :stroke-width="8" /></template></el-table-column>
      <el-table-column label="报工笔数" width="90" align="center"><template #default="{ row }">{{ row.report_count }}<span v-if="row.correction_count" class="sub">（冲{{ row.correction_count }}）</span></template></el-table-column>
      <el-table-column prop="last_report_time" label="最后报工时间" width="165" />
    </el-table>

    <el-table v-else-if="dimension === 'daily'" v-loading="loading" :data="items" stripe border empty-text="暂无日班次报工数据">
      <el-table-column prop="work_date" label="作业日期" width="120" />
      <el-table-column label="班次" width="90"><template #default="{ row }">{{ shiftLabel(row.shift_code) }}</template></el-table-column>
      <el-table-column label="车间" min-width="130"><template #default="{ row }">{{ workshopLabel(row) }}</template></el-table-column>
      <el-table-column label="工序" min-width="120"><template #default="{ row }">{{ operationLabel(row) }}</template></el-table-column>
      <el-table-column label="机台/产线" min-width="140"><template #default="{ row }">{{ machineLabel(row) }}</template></el-table-column>
      <el-table-column prop="task_count" label="任务数" width="85" align="right" />
      <el-table-column label="净合格" width="105" align="right"><template #default="{ row }">{{ formatQty(row.qualified_qty) }}</template></el-table-column>
      <el-table-column label="次品" width="90" align="right"><template #default="{ row }"><span class="defect">{{ formatQty(row.defect_qty) }}</span></template></el-table-column>
      <el-table-column label="冲减" width="90" align="right"><template #default="{ row }"><span class="correction">{{ formatQty(row.correction_qty) }}</span></template></el-table-column>
      <el-table-column prop="report_count" label="报工笔数" width="95" align="right" />
      <el-table-column label="时间数据" width="110"><template #default="{ row }">{{ row.invalid_time_count ? `含${row.invalid_time_count}条单点` : '完整' }}</template></el-table-column>
    </el-table>

    <el-table v-else-if="dimension === 'progress'" v-loading="loading" :data="items" stripe border empty-text="暂无产品工序进度数据">
      <el-table-column label="产品" min-width="190"><template #default="{ row }">{{ row.product?.name ?? '-' }}<span class="sub">{{ row.product?.code }}</span></template></el-table-column>
      <el-table-column label="工序" min-width="130"><template #default="{ row }">{{ operationLabel(row) }}</template></el-table-column>
      <el-table-column prop="order_count" label="订单数" width="85" align="right" />
      <el-table-column prop="task_count" label="任务数" width="85" align="right" />
      <el-table-column label="关联计划量" width="115" align="right"><template #default="{ row }">{{ formatQty(row.planned_qty) }}</template></el-table-column>
      <el-table-column label="净合格" width="105" align="right"><template #default="{ row }">{{ formatQty(row.qualified_qty) }}</template></el-table-column>
      <el-table-column label="剩余量" width="105" align="right"><template #default="{ row }"><span class="remaining">{{ formatQty(row.remaining_qty) }}</span></template></el-table-column>
      <el-table-column label="完成率" width="135"><template #default="{ row }"><el-progress :percentage="row.completion_pct ?? 0" :stroke-width="8" /></template></el-table-column>
      <el-table-column label="超报量" width="95" align="right"><template #default="{ row }"><span v-if="row.over_qty" class="defect">{{ formatQty(row.over_qty) }}</span><span v-else>-</span></template></el-table-column>
    </el-table>

    <el-table v-else-if="dimension === 'delivery'" v-loading="loading" :data="items" stripe border empty-text="暂无交期汇总数据">
      <el-table-column label="交期" width="125"><template #default="{ row }">{{ row.delivery_date ?? '未设置' }}</template></el-table-column>
      <el-table-column prop="customer_name" label="客户" min-width="170"><template #default="{ row }">{{ row.customer_name ?? '未设置客户' }}</template></el-table-column>
      <el-table-column prop="order_count" label="订单数" width="85" align="right" />
      <el-table-column prop="product_count" label="产品数" width="85" align="right" />
      <el-table-column label="订单量" width="105" align="right"><template #default="{ row }">{{ formatQty(row.order_qty) }}</template></el-table-column>
      <el-table-column label="成品合格" width="105" align="right"><template #default="{ row }">{{ formatQty(row.finished_qualified_qty) }}</template></el-table-column>
      <el-table-column label="未完成" width="105" align="right"><template #default="{ row }"><span class="remaining">{{ formatQty(row.remaining_qty) }}</span></template></el-table-column>
      <el-table-column label="交期状态" width="115"><template #default="{ row }"><el-tag size="small" :type="riskTag(row.risk_status)">{{ row.risk_status }}</el-tag></template></el-table-column>
    </el-table>

    <el-table v-else-if="dimension === 'resource'" v-loading="loading" :data="items" stripe border empty-text="暂无资源效率数据">
      <el-table-column label="车间" min-width="130"><template #default="{ row }">{{ workshopLabel(row) }}</template></el-table-column>
      <el-table-column label="机台/产线" min-width="150"><template #default="{ row }">{{ machineLabel(row) }}</template></el-table-column>
      <el-table-column label="工序" min-width="120"><template #default="{ row }">{{ operationLabel(row) }}</template></el-table-column>
      <el-table-column label="班次" width="90"><template #default="{ row }">{{ shiftLabel(row.shift_code) }}</template></el-table-column>
      <el-table-column label="净合格" width="105" align="right"><template #default="{ row }">{{ formatQty(row.qualified_qty) }}</template></el-table-column>
      <el-table-column label="次品" width="90" align="right"><template #default="{ row }"><span class="defect">{{ formatQty(row.defect_qty) }}</span></template></el-table-column>
      <el-table-column label="有效工时(h)" width="115" align="right"><template #default="{ row }">{{ row.actual_hours ?? '-' }}</template></el-table-column>
      <el-table-column label="理论产出" width="105" align="right"><template #default="{ row }">{{ row.capacity_qty ? formatQty(row.capacity_qty) : '-' }}</template></el-table-column>
      <el-table-column label="产能利用率" width="125"><template #default="{ row }">{{ row.utilization_pct === null || row.utilization_pct === undefined ? '-' : `${row.utilization_pct}%` }}</template></el-table-column>
      <el-table-column label="时间质量" min-width="135"><template #default="{ row }"><el-tag size="small" :type="row.invalid_time_count ? 'warning' : 'success'">{{ row.time_quality }}</el-tag></template></el-table-column>
    </el-table>

    <el-table v-else v-loading="loading" :data="items" stripe border empty-text="暂无质量汇总数据">
      <el-table-column prop="work_date" label="作业日期" width="120" />
      <el-table-column label="车间" min-width="130"><template #default="{ row }">{{ workshopLabel(row) }}</template></el-table-column>
      <el-table-column label="工序" min-width="130"><template #default="{ row }">{{ operationLabel(row) }}</template></el-table-column>
      <el-table-column label="净合格" width="105" align="right"><template #default="{ row }">{{ formatQty(row.qualified_qty) }}</template></el-table-column>
      <el-table-column label="次品" width="95" align="right"><template #default="{ row }"><span class="defect">{{ formatQty(row.defect_qty) }}</span></template></el-table-column>
      <el-table-column label="次品率" width="100"><template #default="{ row }"><span class="defect">{{ row.defect_rate ?? 0 }}%</span></template></el-table-column>
      <el-table-column label="冲减数量" width="110" align="right"><template #default="{ row }"><span class="correction">{{ formatQty(row.correction_qty) }}</span></template></el-table-column>
      <el-table-column prop="correction_count" label="冲减笔数" width="95" align="right" />
      <el-table-column prop="report_count" label="报工笔数" width="95" align="right" />
      <el-table-column prop="task_count" label="任务数" width="85" align="right" />
    </el-table>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { productionApi } from '@/api/production'
import { dashboardApi } from '@/api/dashboard'
import { getApiErrorMessage } from '@/api/axios'
import type { ReportSummary, ReportSummaryDimension, ReportSummaryItem } from '@/types/production'
import type { OrderOverviewData, OrderProgress } from '@/types/dashboard'

type ReportManagementDimension = ReportSummaryDimension | 'order_progress'
type SummaryMetric = { label: string; value: string; tone: string; sub?: string }

const emptySummary: ReportSummary = {
  dimension: 'detail',
  totals: {
    row_count: 0,
    task_count: 0,
    report_count: 0,
    qualified_qty: 0,
    defect_qty: 0,
    total_qty: 0,
    finished_qualified_qty: 0,
    finished_defect_qty: 0,
    correction_count: 0,
  },
  items: [],
}

const emptyOrderSummary: NonNullable<OrderOverviewData['summary']> = {
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

const dimension = ref<ReportManagementDimension>('detail')
const dimensionOptions: Array<{ value: ReportManagementDimension; label: string; note: string }> = [
  { value: 'detail', label: '明细汇总', note: '按实际作业日期、任务、工序和资源汇总；数量为净值，冲减记录会抵扣原报工。' },
  { value: 'daily', label: '日/班次执行', note: '看每天、每班、每个车间和资源做了多少；时间数据不完整的报工会单独提示。' },
  { value: 'order_progress', label: '订单进度', note: '工序推进按各道工序报工工作量加权；成品完成仍按末工序/任务级报工，入库完成单独统计。' },
  { value: 'progress', label: '产品工序进度', note: '按产品和工序看计划量、净合格、剩余量及超报量；计划量按任务/工序去重。' },
  { value: 'delivery', label: '交期/客户', note: '按交期和客户看订单负荷、成品完成量、剩余量及交期风险。' },
  { value: 'resource', label: '资源效率', note: '仅用有明确开始和结束时间的报工计算有效工时；单点或缺失时间会标记为数据质量问题。' },
  { value: 'quality', label: '质量/冲减', note: '按日期、车间和工序查看次品率、冲减数量及冲减笔数，便于追溯更正。' },
]
const fulfillmentOptions = [
  { value: 'completed_on_time', label: '按期完成' },
  { value: 'completed_late', label: '延期完成' },
  { value: 'in_progress', label: '生产中' },
  { value: 'scheduled', label: '已排产' },
  { value: 'forecast_late', label: '预计延期' },
  { value: 'overdue', label: '已延期' },
  { value: 'paused', label: '已暂停' },
  { value: 'not_started', label: '未开工' },
  { value: 'aborted', label: '已中止' },
]

const dateRange = ref<[string, string] | null>(null)
const keyword = ref('')
const machineKeyword = ref('')
const fulfillmentStatus = ref('')
const loading = ref(false)
const errorMessage = ref('')
const totals = ref(emptySummary.totals)
const items = ref<ReportSummaryItem[]>([])
const orderOverview = ref<OrderOverviewData | null>(null)

const activeOption = computed(() => dimensionOptions.find((item) => item.value === dimension.value) ?? dimensionOptions[0])
const orderItems = computed<OrderProgress[]>(() => orderOverview.value?.items ?? [])
const orderSummary = computed(() => orderOverview.value?.summary ?? emptyOrderSummary)
const metrics = computed<SummaryMetric[]>(() => dimension.value === 'order_progress'
  ? [
      { label: '订单总数', value: `${orderSummary.value.total_orders} 单`, tone: '' },
      { label: '生产完成', value: `${orderSummary.value.production_completed} 单`, tone: 'finished', sub: `成品 ${formatQty(orderSummary.value.done_qty)} / ${formatQty(orderSummary.value.total_qty)} 件` },
      { label: '按期完成率', value: orderSummary.value.on_time_rate === null ? '-' : `${orderSummary.value.on_time_rate}%`, tone: '', sub: `按期 ${orderSummary.value.on_time_completed} 单 · 延期完成 ${orderSummary.value.late_completed} 单` },
      { label: '工序推进率', value: `${orderSummary.value.process_progress_pct}%`, tone: 'finished', sub: `${formatQty(orderSummary.value.process_done_qty)} / ${formatQty(orderSummary.value.process_plan_qty)} 件次` },
      { label: '入库完成率', value: `${orderSummary.value.stock_completion_pct}%`, tone: '', sub: `${formatQty(orderSummary.value.stocked_qty)} / ${formatQty(orderSummary.value.total_qty)} 件` },
      { label: '延期风险', value: `${orderSummary.value.overdue} 已延期`, tone: orderSummary.value.overdue ? 'defect' : '', sub: `预计延期 ${orderSummary.value.forecast_late} 单` },
    ]
  : [
      { label: '涉及任务', value: formatQty(totals.value.task_count), tone: '' },
      { label: '汇总行数', value: formatQty(totals.value.row_count), tone: '' },
      { label: '净合格数', value: formatQty(totals.value.qualified_qty), tone: '' },
      { label: '成品合格', value: formatQty(totals.value.finished_qualified_qty), tone: 'finished' },
      { label: '次品数', value: formatQty(totals.value.defect_qty), tone: 'defect' },
      { label: '冲减笔数', value: formatQty(totals.value.correction_count), tone: 'correction' },
    ])

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    if (dimension.value === 'order_progress') {
      orderOverview.value = await dashboardApi.orderOverview({
        keyword: keyword.value.trim() || undefined,
        fulfillment_status: fulfillmentStatus.value || undefined,
      })
      return
    }
    orderOverview.value = null
    const result = await productionApi.reportSummary({
      dimension: dimension.value,
      date_from: dateRange.value?.[0],
      date_to: dateRange.value?.[1],
      keyword: keyword.value.trim() || undefined,
      machine_keyword: machineKeyword.value.trim() || undefined,
    })
    totals.value = result.totals
    items.value = result.items
  } catch (error) {
    totals.value = emptySummary.totals
    items.value = []
    orderOverview.value = null
    errorMessage.value = getApiErrorMessage(error, '生产执行报表加载失败')
  } finally {
    loading.value = false
  }
}

function formatQty(value: number | null | undefined): string {
  return Number(value ?? 0).toLocaleString('zh-CN')
}

function operationLabel(row: ReportSummaryItem): string {
  return row.operation ? `${row.operation.seq} · ${row.operation.name}` : '任务级'
}

function machineLabel(row: ReportSummaryItem): string {
  return row.machine?.code ?? row.machine?.name ?? '-'
}

function workshopLabel(row: ReportSummaryItem): string {
  return row.workshop?.name ?? '-'
}

function shiftLabel(value: string | null | undefined): string {
  return value === 'day' ? '白班' : value === 'night' ? '晚班' : '未设置'
}

function riskTag(value: string | null | undefined): 'success' | 'warning' | 'danger' | 'info' {
  if (value === '已完成') return 'success'
  if (value === '已逾期') return 'danger'
  if (value === '临近交期') return 'warning'
  return 'info'
}

function priorityTag(priority: number): 'danger' | 'warning' | 'info' {
  return priority === 0 ? 'danger' : priority === 1 ? 'warning' : 'info'
}

function fulfillmentTag(status: string | undefined): 'success' | 'warning' | 'danger' | 'info' | 'primary' {
  if (status === 'completed_on_time') return 'success'
  if (status === 'completed_late' || status === 'forecast_late') return 'warning'
  if (status === 'overdue' || status === 'aborted') return 'danger'
  if (status === 'in_progress') return 'primary'
  return 'info'
}

function orderProgressColor(status: string | undefined): string {
  if (status === 'overdue' || status === 'completed_late') return '#f56c6c'
  if (status === 'forecast_late') return '#e6a23c'
  if (status === 'completed_on_time') return '#67c23a'
  return '#409eff'
}

onMounted(load)
</script>

<style scoped>
.summary-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.summary-switcher {
  display: grid;
  grid-template-columns: minmax(240px, 1fr) auto;
  align-items: center;
  gap: 16px;
  padding-bottom: 10px;
  border-bottom: 1px solid #ebeef5;
}

.summary-title {
  color: #1f2937;
  font-size: 16px;
  font-weight: 700;
}

.summary-subtitle,
.summary-note {
  color: #64748b;
  font-size: 12px;
}

.summary-toolbar {
  display: grid;
  grid-template-columns: minmax(250px, 1.35fr) minmax(190px, 1fr) minmax(150px, 0.8fr) auto;
  align-items: center;
  gap: 8px;
}

.summary-toolbar :deep(.el-input),
.summary-toolbar :deep(.el-date-editor) {
  width: 100%;
}

.summary-toolbar :deep(.el-button) {
  min-width: 72px;
}

.summary-toolbar--orders {
  grid-template-columns: minmax(220px, 1.4fr) minmax(180px, 0.8fr) auto;
}

.summary-error {
  margin-bottom: 0;
}

.summary-note {
  padding-left: 10px;
  border-left: 3px solid #409eff;
  line-height: 18px;
}

.metric-grid {
  display: grid;
  width: 100%;
  min-width: 0;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
}

.metric-card {
  display: flex;
  min-width: 0;
  min-height: 70px;
  flex-direction: column;
  justify-content: center;
  padding: 10px 12px;
  border: 1px solid #e5e7eb;
  border-top: 3px solid #dbeafe;
  border-radius: 6px;
  background: #f8fafc;
  text-align: center;
}

.metric-card--finished {
  border-top-color: #67c23a;
  background: #f0f9eb;
}

.metric-card--defect,
.metric-card--correction {
  border-top-color: #f56c6c;
  background: #fef0f0;
}

.metric-value {
  color: #1f2937;
  font-size: 20px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1.35;
}

.metric-label {
  color: #64748b;
  font-size: 12px;
}

.metric-sub {
  margin-top: 2px;
  color: #94a3b8;
  font-size: 11px;
}

.defect,
.correction {
  color: #dc2626;
}

.finished {
  color: #16a34a;
}

.remaining {
  color: #d97706;
}

.sub {
  display: inline-block;
  margin-left: 4px;
  color: #94a3b8;
  font-size: 12px;
}

.progress-caption {
  margin-top: 2px;
  color: #64748b;
  font-size: 11px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

:deep(.el-table) {
  --el-table-header-bg-color: #f8fafc;
  --el-table-row-hover-bg-color: #f5f9ff;
  color: #334155;
  font-variant-numeric: tabular-nums;
}

:deep(.el-table th.el-table__cell) {
  color: #475569;
  font-weight: 600;
}

:deep(.el-table td.el-table__cell),
:deep(.el-table th.el-table__cell) {
  padding: 9px 0;
}

:deep(.el-progress__text) {
  font-variant-numeric: tabular-nums;
}

@media (max-width: 1200px) {
  .metric-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .summary-toolbar {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .summary-toolbar :deep(.el-button) {
    width: 100%;
  }
}

@media (max-width: 720px) {
  .summary-switcher {
    grid-template-columns: 1fr;
    align-items: start;
  }

  .dimension-switcher {
    display: flex;
    width: 100%;
    overflow-x: auto;
  }

  .metric-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .summary-toolbar {
    grid-template-columns: 1fr;
  }
}
</style>
