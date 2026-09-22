<template>
  <section class="daily-report-grid">
    <div class="grid-toolbar">
      <div class="filters">
        <el-date-picker
          v-model="workDate"
          type="date"
          value-format="YYYY-MM-DD"
          :disabled-date="disableFutureDate"
          :clearable="false"
          style="width: 150px"
          @change="load"
        />
        <el-select v-model="workshopFilter" placeholder="全部车间" clearable filterable style="width: 140px">
          <el-option v-for="item in workshopOptions" :key="item.id" :label="item.name" :value="item.id" />
        </el-select>
        <el-select v-model="machineFilter" placeholder="全部机台/产线" clearable filterable style="width: 170px">
          <el-option v-for="item in machineOptions" :key="item.id" :label="`${item.code} · ${item.name}`" :value="item.id" />
        </el-select>
        <el-select v-model="operationFilter" placeholder="全部工序" clearable style="width: 130px">
          <el-option v-for="item in operationOptions" :key="item" :label="item" :value="item" />
        </el-select>
        <el-input v-model="keyword" placeholder="工单号/订单号/产品" clearable style="width: 210px" @keyup.enter="load" />
        <el-button type="primary" plain :loading="loading" @click="load">查询</el-button>
      </div>
      <div class="toolbar-actions">
        <el-tag type="info" effect="plain">报工日期：{{ workDate }}</el-tag>
        <el-button :loading="loading" @click="load">刷新</el-button>
        <el-button type="primary" :loading="saving" :disabled="!pendingRows.length" @click="saveBatch">
          保存本批（{{ pendingRows.length }} 行）
        </el-button>
      </div>
    </div>

    <div class="summary-row">
      <div class="summary-item"><span>排程行</span><strong>{{ filteredRows.length }}</strong></div>
      <div class="summary-item"><span>计划数量</span><strong>{{ formatNumber(filteredSummary.planned_qty) }}</strong></div>
      <div class="summary-item"><span>累计已报</span><strong class="success-text">{{ formatNumber(filteredSummary.reported_qty) }}</strong></div>
      <div class="summary-item"><span>剩余数量</span><strong class="warning-text">{{ formatNumber(filteredSummary.remaining_qty) }}</strong></div>
      <div class="summary-tip">填写本次数量及实际作业起止时间；同一工序的分段报工会按时间段展示。</div>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon class="grid-alert" />

    <el-table
      v-loading="loading"
      :data="filteredRows"
      row-key="schedule_id"
      border
      stripe
      height="calc(100vh - 365px)"
      class="report-table"
      empty-text="当天没有已接受的排程任务"
    >
      <el-table-column type="index" label="#" width="52" fixed="left" align="center" />
      <el-table-column label="机台/产线" width="150" fixed="left">
        <template #default="{ row }">
          <div class="primary-cell">{{ row.machine?.code ?? '-' }}</div>
          <div class="secondary-cell">{{ row.machine?.name ?? '未分配资源' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="工单号" width="145" fixed="left">
        <template #default="{ row }">
          <div class="primary-cell">{{ row.task_no }}</div>
          <div class="secondary-cell">{{ row.order_no ?? '无订单号' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="产品" min-width="190" fixed="left">
        <template #default="{ row }">
          <div class="primary-cell">{{ row.product?.name ?? '-' }}</div>
          <div class="secondary-cell">{{ row.product?.code ?? '-' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="工序" width="115">
        <template #default="{ row }"><el-tag size="small" effect="plain">{{ row.operation.seq }} · {{ row.operation.name }}</el-tag></template>
      </el-table-column>
      <el-table-column label="计划时段" width="180">
        <template #default="{ row }">
          <div>{{ formatTime(row.plan_start) }}</div>
          <div class="secondary-cell">至 {{ formatTime(row.plan_end) }}</div>
        </template>
      </el-table-column>
      <el-table-column prop="planned_qty" label="计划数量" width="92" align="right">
        <template #default="{ row }">{{ formatNumber(row.planned_qty) }}</template>
      </el-table-column>
      <el-table-column prop="reported_qty" label="累计已报" width="92" align="right">
        <template #default="{ row }">{{ formatNumber(row.reported_qty) }}</template>
      </el-table-column>
      <el-table-column prop="remaining_qty" label="剩余" width="92" align="right">
        <template #default="{ row }"><span :class="{ 'warning-text': row.remaining_qty > 0 }">{{ formatNumber(row.remaining_qty) }}</span></template>
      </el-table-column>
      <el-table-column label="本次合格数" width="145" align="center">
        <template #default="{ row, $index }">
          <div class="input-with-action">
            <el-input-number
              v-model="row.input_qty"
              class="cell-input"
              :min="0"
              :max="10000000"
              :controls="false"
              placeholder="留空不报"
              @keyup.enter="focusNextInput($index)"
            />
            <el-button v-if="row.remaining_qty > 0" link type="primary" @click="fillRemaining(row)">余量</el-button>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="次品数" width="108" align="center">
        <template #default="{ row }">
          <el-input-number v-model="row.input_defect_qty" class="cell-input" :min="0" :max="10000000" :controls="false" placeholder="0" />
        </template>
      </el-table-column>
      <el-table-column label="实际作业时间" width="365" align="center">
        <template #default="{ row }">
          <div class="time-range-cell">
            <el-date-picker
              v-model="row.input_start_time"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              format="MM-DD HH:mm"
              placeholder="开始"
              size="small"
              :clearable="true"
            />
            <span>至</span>
            <el-date-picker
              v-model="row.input_end_time"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              format="MM-DD HH:mm"
              placeholder="结束"
              size="small"
              :clearable="true"
            />
          </div>
        </template>
      </el-table-column>
      <el-table-column label="班次" width="105">
        <template #default="{ row }">
          <el-select v-model="row.shift_code" class="cell-select">
            <el-option label="白班" value="day" />
            <el-option label="夜班" value="night" />
          </el-select>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="100" align="center">
        <template #default="{ row }"><el-tag size="small" :type="statusType(row)">{{ statusLabel(row) }}</el-tag></template>
      </el-table-column>
      <el-table-column label="备注" min-width="150">
        <template #default="{ row }"><el-input v-model="row.remark" class="cell-input" placeholder="可选" maxlength="120" /></template>
      </el-table-column>
    </el-table>

    <div class="grid-footer">
      <span>空白行不会生成报工流水；数量超过剩余量时会阻止提交，不会自动截断；填报人只记录操作账号。</span>
      <span>操作人：{{ auth.user?.name ?? '-' }}</span>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import dayjs from 'dayjs'
import { ElMessage } from 'element-plus'
import { productionApi } from '@/api/production'
import type { DailyReportContext, DailyReportRow } from '@/types/production'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const emit = defineEmits<{ (event: 'saved'): void }>()
const workDate = ref(dayjs().format('YYYY-MM-DD'))
const keyword = ref('')
const workshopFilter = ref<number | undefined>()
const machineFilter = ref<number | undefined>()
const operationFilter = ref('')
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const context = ref<DailyReportContext | null>(null)
const rows = ref<DailyReportRow[]>([])

const pendingRows = computed(() => rows.value.filter((row) => row.input_qty !== null || (row.input_defect_qty ?? 0) > 0))
const workshopOptions = computed(() => uniqueBy(rows.value.map((row) => ({ id: row.machine?.workshop_id, name: row.machine?.workshop_name })), 'id'))
const machineOptions = computed(() => uniqueBy(rows.value.map((row) => row.machine ? { id: row.machine.id, code: row.machine.code, name: row.machine.name } : null), 'id'))
const operationOptions = computed(() => [...new Set(rows.value.map((row) => row.operation.name))])
const filteredRows = computed(() => rows.value.filter((row) => {
  if (workshopFilter.value && row.machine?.workshop_id !== workshopFilter.value) return false
  if (machineFilter.value && row.machine?.id !== machineFilter.value) return false
  if (operationFilter.value && row.operation.name !== operationFilter.value) return false
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) return true
  return `${row.task_no} ${row.order_no ?? ''} ${row.product?.code ?? ''} ${row.product?.name ?? ''} ${row.machine?.code ?? ''}`.toLowerCase().includes(kw)
}))
const filteredSummary = computed(() => filteredRows.value.reduce((summary, row) => {
  summary.planned_qty += row.planned_qty
  summary.reported_qty += row.reported_qty
  summary.remaining_qty += row.remaining_qty
  return summary
}, { planned_qty: 0, reported_qty: 0, remaining_qty: 0 }))

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const result = await productionApi.dailyReportContext({ work_date: workDate.value })
    context.value = result
    rows.value = result.rows.map((row) => ({
      ...row,
      input_qty: null,
      input_defect_qty: null,
      input_start_time: null,
      input_end_time: null,
      shift_code: defaultShift(row),
      remark: '',
    }))
  } catch (error) {
    context.value = null
    rows.value = []
    loadError.value = error instanceof Error ? error.message : '当日报工数据加载失败，请检查后端连接'
  } finally {
    loading.value = false
  }
}

async function saveBatch(): Promise<void> {
  const invalid = pendingRows.value.find((row) => {
    const qty = row.input_qty ?? 0
    return qty <= 0 || qty > row.remaining_qty || !row.input_start_time || !row.input_end_time || dayjs(row.input_end_time).isBefore(dayjs(row.input_start_time))
  })
  if (invalid) {
    const qty = invalid.input_qty ?? 0
    if (qty <= 0) {
      ElMessage.warning(`工单 ${invalid.task_no} 本次合格数必须大于 0`)
      return
    }
    if (!invalid.input_start_time || !invalid.input_end_time) {
      ElMessage.warning(`工单 ${invalid.task_no} 请填写本次实际作业开始和结束时间`)
      return
    }
    if (dayjs(invalid.input_end_time).isBefore(dayjs(invalid.input_start_time))) {
      ElMessage.warning(`工单 ${invalid.task_no} 的结束时间不能早于开始时间`)
      return
    }
    ElMessage.warning(`工单 ${invalid.task_no} 本次数量 ${qty} 超过剩余量 ${invalid.remaining_qty}`)
    return
  }

  saving.value = true
  let saved = 0
  try {
    for (const row of pendingRows.value) {
      await productionApi.submitReport({
        task_id: row.task_id,
        schedule_id: row.schedule_id,
        machine_id: row.machine?.id ?? null,
        qty: row.input_qty ?? 0,
        defect_qty: row.input_defect_qty ?? 0,
        report_time: row.input_end_time ?? undefined,
        start_time: row.input_start_time ?? undefined,
        end_time: row.input_end_time ?? undefined,
        work_date: workDate.value,
        shift_code: row.shift_code,
        client_request_id: `pc-${row.schedule_id}-${workDate.value}-${Date.now()}-${saved}`,
        source: 'leader_batch',
        remark: row.remark.trim() || undefined,
      })
      saved += 1
    }
    ElMessage.success(`已保存 ${saved} 行报工，原有报工流水保持不变`)
    await load()
    emit('saved')
  } catch {
    ElMessage.warning(`已保存 ${saved} 行，剩余行未提交；请刷新核对后继续`)
    await load()
  } finally {
    saving.value = false
  }
}

function defaultShift(row: DailyReportContext['rows'][number]): 'day' | 'night' {
  const hour = dayjs(row.plan_start).hour()
  return hour >= 20 || hour < 8 ? 'night' : 'day'
}

function fillRemaining(row: DailyReportRow): void {
  row.input_qty = row.remaining_qty
}

function statusLabel(row: DailyReportRow): string {
  if (row.input_qty !== null || (row.input_defect_qty ?? 0) > 0) return '待保存'
  if (row.remaining_qty <= 0) return '已完成'
  if (row.reported_qty > 0) return '部分完成'
  return '待报工'
}

function statusType(row: DailyReportRow): 'success' | 'warning' | 'info' | 'primary' {
  if (row.input_qty !== null || (row.input_defect_qty ?? 0) > 0) return 'primary'
  if (row.remaining_qty <= 0) return 'success'
  if (row.reported_qty > 0) return 'warning'
  return 'info'
}

function formatNumber(value: number): string { return Number(value ?? 0).toLocaleString('zh-CN') }
function formatTime(value: string): string { return value ? dayjs(value).format('MM-DD HH:mm') : '-' }
function disableFutureDate(date: Date): boolean { return dayjs(date).isAfter(dayjs().endOf('day').toDate()) }
function focusNextInput(index: number): void {
  const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('.report-table .cell-input input'))
  inputs[index + 1]?.focus()
}

function uniqueBy<T extends { id?: number | null }>(items: Array<T | null>, key: 'id'): T[] {
  const seen = new Set<number>()
  return items.filter((item): item is T => {
    if (!item?.[key] || seen.has(item[key] as number)) return false
    seen.add(item[key] as number)
    return true
  })
}
</script>

<style scoped>
.daily-report-grid { display: flex; flex-direction: column; gap: 12px; }
.grid-toolbar, .filters, .toolbar-actions, .summary-row, .grid-footer { display: flex; align-items: center; }
.grid-toolbar { justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.filters, .toolbar-actions { gap: 8px; flex-wrap: wrap; }
.summary-row { gap: 0; border: 1px solid #e5eaf2; border-radius: 6px; background: #f8fafc; min-height: 58px; }
.summary-item { min-width: 112px; padding: 8px 16px; border-right: 1px solid #e5eaf2; }
.summary-item span { display: block; color: #64748b; font-size: 12px; }
.summary-item strong { display: block; margin-top: 3px; color: #1e293b; font-size: 17px; }
.summary-tip { padding: 0 16px; color: #64748b; font-size: 12px; }
.primary-cell { color: #1e293b; font-weight: 600; line-height: 20px; }
.secondary-cell { color: #94a3b8; font-size: 12px; line-height: 18px; }
.success-text { color: #15803d !important; }
.warning-text { color: #b45309 !important; }
.input-with-action { display: flex; align-items: center; gap: 4px; }
.cell-input { width: 100px; }
.cell-select { width: 100%; }
.time-range-cell { display: flex; align-items: center; gap: 4px; }
.time-range-cell > span { color: #94a3b8; flex: 0 0 auto; }
.time-range-cell :deep(.el-date-editor) { width: 160px; }
.grid-alert { margin: 0; }
.grid-footer { justify-content: space-between; color: #94a3b8; font-size: 12px; }
:deep(.report-table .el-table__cell) { padding: 7px 0; }
:deep(.report-table .cell) { line-height: 20px; }
</style>
