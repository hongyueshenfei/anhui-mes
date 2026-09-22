<template>
  <el-card shadow="never">
    <template #header>
      <div class="detail-header">
        <span>排产结果明细（甘特 + 明细双视图）</span>
        <div v-if="isSingle" class="detail-context">当前工单 ID：{{ currentTaskId }}</div>
      </div>
    </template>

    <div v-if="!isSingle" class="filter-row">
      <el-date-picker
        v-model="filters.date"
        clearable
        type="date"
        value-format="YYYY-MM-DD"
        placeholder="计划日期（当天）"
        size="small"
        style="width: 150px"
      />
      <el-input
        v-model="keyword"
        clearable
        placeholder="任务号"
        size="small"
        style="width: 180px"
        @keyup.enter="search"
      />
      <el-input
        v-model="filters.orderNo"
        clearable
        placeholder="订单号"
        size="small"
        style="width: 170px"
        @keyup.enter="search"
      />
      <el-input
        v-model="filters.product"
        clearable
        placeholder="产品编码/名称"
        size="small"
        style="width: 180px"
        @keyup.enter="search"
      />
      <el-input
        v-model="filters.machine"
        clearable
        placeholder="机台/产线"
        size="small"
        style="width: 150px"
        @keyup.enter="search"
      />
      <el-button size="small" type="primary" @click="search">查询</el-button>
    </div>

    <el-alert
      v-if="errorMessage"
      type="error"
      :closable="false"
      show-icon
      class="mb-12"
      :title="errorMessage"
    />
    <el-skeleton v-else-if="loading" :rows="6" animated />
    <el-empty v-else-if="isSingle && !detail" description="暂无该任务的排产明细" :image-size="70">
      <template #default>
        <div class="empty-help">请从甘特图双击已完工或排产中的任务，或输入工单 ID 查询。</div>
      </template>
    </el-empty>

    <template v-else-if="isSingle && detail">
      <!-- 任务概览 -->
      <el-descriptions :column="4" border size="small" class="mb-12">
        <el-descriptions-item label="任务号">{{ detail.task.task_no }}</el-descriptions-item>
        <el-descriptions-item label="产品">{{ detail.task.product_name }}（{{ detail.task.product_code }}）</el-descriptions-item>
        <el-descriptions-item label="数量/完成">{{ detail.task.qty }} / {{ detail.task.done_qty }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag size="small" :type="statusTag(detail.task.status)">{{ detail.task.status_label }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="订单号">{{ detail.task.order_no }}</el-descriptions-item>
        <el-descriptions-item label="交期">{{ detail.task.delivery_date ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="进度偏差">{{ detail.task.deviation_pct }}%</el-descriptions-item>
        <el-descriptions-item label="锁定">
          <el-tag v-if="detail.task.is_locked" size="small" type="danger">已锁定</el-tag>
          <el-tag v-else size="small" type="info">未锁定</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="计划起止" :span="4">
          {{ detail.task.plan_start ?? '-' }} → {{ detail.task.plan_end ?? '-' }}
        </el-descriptions-item>
      </el-descriptions>

      <!-- 工序排程明细 -->
      <el-table :data="detail.schedules" size="small" border stripe>
        <el-table-column label="工序" width="120">
          <template #default="{ row }">{{ row.seq }} · {{ row.op_name }}</template>
        </el-table-column>
        <el-table-column label="机台" width="90">
          <template #default="{ row }">{{ row.machine_code ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="车间" width="110">
          <template #default="{ row }">{{ row.workshop_name ?? '-' }}</template>
        </el-table-column>
        <el-table-column prop="qty" label="数量" width="80" align="center" />
        <el-table-column label="计划开始" width="160">
          <template #default="{ row }">{{ row.plan_start }}</template>
        </el-table-column>
        <el-table-column label="计划结束" width="160">
          <template #default="{ row }">{{ row.plan_end }}</template>
        </el-table-column>
        <el-table-column label="实际开始" width="160">
          <template #default="{ row }">
            {{ row.actual_start ?? '-' }}
          </template>
        </el-table-column>
        <el-table-column label="实际结束" width="160">
          <template #default="{ row }">{{ row.actual_end ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="完工数量" width="100" align="center">
          <template #default="{ row }">{{ row.reported_qty }} / {{ row.qty }}</template>
        </el-table-column>
        <el-table-column label="完工进度" width="110" align="center">
          <template #default="{ row }">
            <el-progress :percentage="row.progress_pct" :stroke-width="8" :show-text="true" />
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="schedTag(row.status)">{{ schedLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="90" align="center">
          <template #default="{ row }">{{ sourceLabel(row.source) }}</template>
        </el-table-column>
        <el-table-column label="标记" min-width="120">
          <template #default="{ row }">
            <el-tag v-if="row.is_interrupted" size="small" type="warning">打断留档 {{ row.done_qty }}</el-tag>
            <el-tag v-if="row.is_overlap" size="small" type="danger" class="ml4">强制重叠</el-tag>
            <el-tag v-if="row.is_frozen" size="small" type="info" class="ml4">冻结区</el-tag>
            <span v-if="row.note" class="note-text">{{ row.note }}</span>
          </template>
        </el-table-column>
        <el-table-column label="报工日志" width="108" fixed="right" align="center">
          <template #default="{ row }">
            <el-button
              size="small"
              type="primary"
              link
              @click="openReportLog(row)"
            >报工日志</el-button>
          </template>
        </el-table-column>
      </el-table>
    </template>

    <template v-else>
      <div class="list-summary">共 {{ total }} 个已排产任务，当前展示 {{ listRows.length }} 条工序明细</div>
      <el-empty v-if="listRows.length === 0" description="暂无已排产任务" :image-size="70" />
      <el-table v-else :data="listRows" size="small" border stripe height="calc(100vh - 260px)">
        <el-table-column prop="task_no" label="任务号" min-width="190" fixed="left" />
        <el-table-column prop="order_no" label="订单号" min-width="150" />
        <el-table-column label="产品" min-width="180">
          <template #default="{ row }">{{ row.product_name }}（{{ row.product_code }}）</template>
        </el-table-column>
        <el-table-column label="工序" width="120">
          <template #default="{ row }">{{ row.seq }} · {{ row.op_name }}</template>
        </el-table-column>
        <el-table-column label="机台/产线" width="110">
          <template #default="{ row }">{{ row.machine_code ?? '-' }}</template>
        </el-table-column>
        <el-table-column prop="qty" label="计划量" width="90" align="center" />
        <el-table-column label="已报工" width="110" align="center">
          <template #default="{ row }">{{ row.reported_qty }} / {{ row.qty }}</template>
        </el-table-column>
        <el-table-column label="完工进度" width="120" align="center">
          <template #default="{ row }">
            <el-progress :percentage="row.progress_pct" :stroke-width="8" :show-text="true" />
          </template>
        </el-table-column>
        <el-table-column label="计划时间" min-width="300">
          <template #default="{ row }">{{ row.plan_start }} → {{ row.plan_end }}</template>
        </el-table-column>
        <el-table-column label="实际时间" min-width="300">
          <template #default="{ row }">{{ row.actual_start ?? '-' }} → {{ row.actual_end ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="schedTag(row.status)">{{ schedLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="标记" min-width="130">
          <template #default="{ row }">
            <el-tag v-if="row.is_frozen" size="small" type="info">冻结区</el-tag>
            <el-tag v-if="row.is_interrupted" size="small" type="warning" class="ml4">打断留档</el-tag>
            <el-tag v-if="row.is_overlap" size="small" type="danger" class="ml4">强制重叠</el-tag>
          </template>
        </el-table-column>
      </el-table>
    </template>

    <el-dialog
      v-model="reportLogVisible"
      :title="reportLogTitle"
      width="920px"
      append-to-body
      destroy-on-close
    >
      <template v-if="activeReportSchedule">
        <div class="report-log-context">
          <span>工序 {{ activeReportSchedule.seq }} · {{ activeReportSchedule.op_name }}</span>
          <span>计划量 {{ activeReportSchedule.qty }}</span>
          <span>累计报工 {{ reportLogQty }} / {{ activeReportSchedule.qty }}</span>
          <span>记录 {{ activeReportLogs.length }} 条</span>
        </div>
        <el-table
          v-if="activeReportLogs.length"
          :data="activeReportLogs"
          size="small"
          border
          stripe
          max-height="460"
        >
          <el-table-column label="实际作业时间" min-width="300">
            <template #default="{ row }">
              <div v-if="row.work_segments?.[0]" class="work-segments">
                {{ formatLogTime(row.work_segments[0].start) }}
                <span class="sub">至</span>
                {{ formatLogTime(row.work_segments[0].end) }}
              </div>
              <template v-else>
                {{ formatLogTime(row.start_time ?? row.report_time) }}
                <span class="sub">至</span>
                {{ formatLogTime(row.end_time ?? row.report_time) }}
              </template>
            </template>
          </el-table-column>
          <el-table-column label="报工时间" width="155">
            <template #default="{ row }">{{ formatLogTime(row.report_time) }}</template>
          </el-table-column>
          <el-table-column label="合格数" width="100" align="center">
            <template #default="{ row }">
              <el-tag :type="row.qty >= 0 ? 'success' : 'danger'" size="small">
                {{ row.qty >= 0 ? '+' : '' }}{{ row.qty }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="defect_qty" label="次品数" width="85" align="center" />
          <el-table-column label="报工人" width="100">
            <template #default="{ row }">{{ row.operator_name ?? '-' }}</template>
          </el-table-column>
          <el-table-column label="来源" width="125">
            <template #default="{ row }">
              <el-tag size="small" effect="plain">{{ reportSourceLabel(row.source, row.source_label) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注/冲减原因" min-width="150">
            <template #default="{ row }">
              <span :class="{ correction: row.qty < 0 || row.sign < 0 }">{{ row.remark ?? '-' }}</span>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-else description="该工序暂无报工记录" :image-size="60" />
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { scheduleApi } from '@/api/schedule'
import { getApiErrorMessage } from '@/api/axios'
import type { ScheduleDetail, ScheduleDetailList } from '@/types/schedule'
import { formatDateTime } from '@/utils/datetime'

const props = defineProps<{ taskId?: number | null }>()
const route = useRoute()

const detail = ref<ScheduleDetail | null>(null)
const allDetails = ref<ScheduleDetailList>({ total: 0, items: [] })
const keyword = ref('')
const filters = reactive({ date: '', orderNo: '', product: '', machine: '' })
const loading = ref(false)
const errorMessage = ref('')
const reportLogVisible = ref(false)
const activeReportSchedule = ref<ScheduleDetail['schedules'][number] | null>(null)
let loadSequence = 0

function normalizeTaskId(value: unknown): number | null {
  const numeric = Number(Array.isArray(value) ? value[0] : value)
  return Number.isInteger(numeric) && numeric > 0 ? numeric : null
}

const currentTaskId = computed(() => normalizeTaskId(props.taskId) ?? normalizeTaskId(route.query.task_id))
const isSingle = computed(() => currentTaskId.value !== null)
const total = computed(() => allDetails.value.total)
const activeReportLogs = computed(() => activeReportSchedule.value?.report_logs ?? [])
const reportLogQty = computed(() => activeReportLogs.value.reduce((sum, row) => sum + row.qty, 0))
const reportLogTitle = computed(() => {
  const taskNo = detail.value?.task.task_no ?? '任务'
  const schedule = activeReportSchedule.value
  return schedule ? '报工日志 · ' + taskNo + ' · 工序 ' + schedule.seq + ' ' + schedule.op_name : '报工日志'
})
const listRows = computed(() => allDetails.value.items.flatMap((item) => item.schedules.map((schedule) => ({
  ...schedule,
  task_no: item.task.task_no,
  order_no: item.task.order_no,
  product_code: item.task.product_code,
  product_name: item.task.product_name,
}))))

watch(currentTaskId, (v) => {
  if (v) load(v)
  else loadAll()
}, { immediate: true })

async function load(id: string | number): Promise<void> {
  const numeric = Number(id)
  if (!Number.isInteger(numeric) || numeric <= 0) {
    detail.value = null
    errorMessage.value = '请输入有效的工单 ID'
    return
  }
  const sequence = ++loadSequence
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await scheduleApi.detail(numeric)
    if (sequence !== loadSequence) return
    detail.value = result
  } catch (error) {
    if (sequence !== loadSequence) return
    detail.value = null
    errorMessage.value = `${getApiErrorMessage(error, '排产明细加载失败')}（工单 ID：${numeric}）`
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function loadAll(): Promise<void> {
  const sequence = ++loadSequence
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await scheduleApi.details({
      date: filters.date || undefined,
      keyword: keyword.value.trim() || undefined,
      order_no: filters.orderNo.trim() || undefined,
      product: filters.product.trim() || undefined,
      machine: filters.machine.trim() || undefined,
    })
    if (sequence !== loadSequence) return
    allDetails.value = result
  } catch (error) {
    if (sequence !== loadSequence) return
    allDetails.value = { total: 0, items: [] }
    errorMessage.value = getApiErrorMessage(error, '排产明细加载失败')
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

function search(): void {
  if (!isSingle.value) loadAll()
}

function openReportLog(row: ScheduleDetail['schedules'][number]): void {
  activeReportSchedule.value = row
  reportLogVisible.value = true
}

function formatLogTime(value: string | null | undefined): string {
  return value ? formatDateTime(value) : '-'
}

function reportSourceLabel(source: string, label?: string | null): string {
  if (label) return label
  return {
    scan: '扫码报工',
    click: '点选报工',
    leader_report: '班组长代报',
    leader_batch: '班组长/统计员日结',
    makeup: '补报',
    offline: '离线补传',
    machine_qr: '机台扫码',
  }[source] ?? source
}

function statusTag(s: string): 'primary' | 'success' | 'info' | 'warning' | 'danger' {
  return ({ done: 'success', in_progress: 'primary', scheduled: 'success', paused: 'warning', unscheduled: 'info' }[s] ?? 'info') as 'primary' | 'success' | 'info' | 'warning' | 'danger'
}
function schedTag(s: string): 'primary' | 'success' | 'info' | 'warning' {
  return ({ done: 'success', running: 'primary', paused: 'warning', pending: 'info' }[s] ?? 'info') as 'primary' | 'success' | 'info' | 'warning'
}
function schedLabel(s: string): string {
  return { done: '完成', running: '进行中', paused: '暂停', pending: '待执行' }[s] ?? s
}
function sourceLabel(s: string): string {
  return { manual: '手工', ai: '算法', insert: '插单', auto: '自动' }[s] ?? s
}
</script>

<style scoped>
.detail-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  font-weight: 600;
}
.detail-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 400;
}
.filter-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.detail-context {
  color: #64748b;
  font-size: 13px;
  font-weight: 400;
}
.list-summary {
  margin-bottom: 12px;
  color: #64748b;
  font-size: 13px;
}
.mb-12 {
  margin-bottom: 12px;
}
.ml4 {
  margin-left: 4px;
}
.note-text {
  font-size: 12px;
  color: #94a3b8;
  margin-left: 6px;
}
.empty-help {
  color: #64748b;
  font-size: 13px;
}
.report-log-context {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  align-items: center;
  margin-bottom: 12px;
  padding: 9px 12px;
  color: #475569;
  font-size: 13px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}
.report-log-context span + span {
  padding-left: 18px;
  border-left: 1px solid #cbd5e1;
}
.sub {
  margin: 0 4px;
  color: #94a3b8;
}
.work-segments {
  line-height: 20px;
}
.correction {
  color: #dc2626;
}
</style>
