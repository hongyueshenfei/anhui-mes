<template>
  <div class="gantt-page">
    <!-- 工具条 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-radio-group v-model="rangePreset" size="small" @change="reloadTimeline">
            <el-radio-button value="p7">含过去 7 天</el-radio-button>
            <el-radio-button value="7d">未来 7 天</el-radio-button>
            <el-radio-button value="14d">未来 14 天</el-radio-button>
            <el-radio-button value="30d">未来 30 天</el-radio-button>
          </el-radio-group>
          <el-divider direction="vertical" />
          <!-- 比例尺：默认天级总览，拉动放大到小时级 -->
          <span class="zoom-label">比例尺</span>
          <el-slider v-model="pxPerDay" :min="40" :max="2160" :step="20" class="zoom-slider" />
          <el-button-group>
            <el-button size="small" :type="pxPerDay <= 200 ? 'primary' : 'default'" @click="pxPerDay = 140">天</el-button>
            <el-button size="small" :type="pxPerDay > 200 ? 'primary' : 'default'" @click="pxPerDay = 2160">时</el-button>
          </el-button-group>
          <el-divider direction="vertical" />
          <span class="legend-chip"><i class="dot dot-plan" />计划</span>
          <span class="legend-chip"><i class="dot dot-actual" />实际</span>
          <span class="legend-chip"><i class="dot dot-forecast" />预计(滞后)</span>
          <span class="legend-chip">❄️ 当前起未来三天冻结</span>
        </div>
        <div class="toolbar-right">
          <el-button size="small" @click="chartRef?.scrollToNow()">
            <el-icon><Aim /></el-icon>回到当前
          </el-button>
          <el-button size="small" :loading="poolLoading" @click="reloadAll">
            <el-icon><Refresh /></el-icon>刷新
          </el-button>
          <el-button size="small" type="warning" :loading="recalculating" @click="recalculateSchedules">
            <el-icon><RefreshRight /></el-icon>重算排程
          </el-button>
          <el-button size="small" type="success" @click="autoOpen">
            <el-icon><MagicStick /></el-icon>自动排
          </el-button>
          <el-button
            v-if="canUnlockSelected"
            size="small"
            type="warning"
            @click="unlockSelected"
          >
            <el-icon><Unlock /></el-icon>解锁
          </el-button>
          <el-button size="small" @click="detailOpen">
            <el-icon><List /></el-icon>排产明细
          </el-button>
        </div>
      </div>
      <div class="gantt-control-row">
        <div class="gantt-exception-inline" aria-label="甘特图异常摘要">
          <span class="gantt-summary-label">异常摘要</span>
          <button
            class="gantt-stat is-danger"
            :class="{ 'is-active': ganttFilters.exceptionKind === 'conflict' }"
            type="button"
            @click="toggleExceptionFilter('conflict')"
          >
            <span>冲突</span><strong>{{ ganttStats.conflict }}</strong>
          </button>
          <button
            class="gantt-stat is-warning"
            :class="{ 'is-active': ganttFilters.exceptionKind === 'overdue' }"
            type="button"
            @click="toggleExceptionFilter('overdue')"
          >
            <span>超期</span><strong>{{ ganttStats.overdue }}</strong>
          </button>
          <button
            class="gantt-stat is-info"
            :class="{ 'is-active': ganttFilters.exceptionKind === 'forecast' }"
            type="button"
            @click="toggleExceptionFilter('forecast')"
          >
            <span>预计滞后</span><strong>{{ ganttStats.forecast }}</strong>
          </button>
          <span class="gantt-stat-note">任务池 {{ poolItems.length }} 条</span>
        </div>
        <div class="gantt-filter-inline">
          <el-input
            v-model="ganttFilters.keyword"
            clearable
            size="small"
            class="gantt-filter-keyword"
            placeholder="搜索任务号 / 订单号 / 产品"
          />
          <el-select v-model="ganttFilters.workshop" clearable size="small" placeholder="全部车间" class="gantt-filter-select">
            <el-option v-for="item in workshopOptions" :key="item" :label="item" :value="item" />
          </el-select>
          <el-select v-model="ganttFilters.resourceType" clearable size="small" placeholder="全部资源类型" class="gantt-filter-select">
            <el-option v-for="item in resourceTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
          <el-select v-model="ganttFilters.status" clearable size="small" placeholder="全部状态" class="gantt-filter-status">
            <el-option label="待执行" value="pending" />
            <el-option label="进行中" value="running" />
            <el-option label="已暂停" value="paused" />
            <el-option label="已完成" value="done" />
          </el-select>
          <el-checkbox v-model="ganttFilters.exceptionOnly" label="只看异常" size="small" />
          <el-button v-if="hasGanttFilters" text size="small" @click="resetGanttFilters">清空筛选</el-button>
          <span class="gantt-filter-summary">显示 {{ rows.length }} / {{ allRows.length }} 个资源</span>
        </div>
      </div>
    </el-card>

    <div class="gantt-main">
      <!-- 任务池 -->
      <TaskPoolPanel :items="poolItems" :picked-id="pickedTask?.id ?? null" @pick="pickedTask = $event" />

      <!-- 甘特 -->
      <el-card shadow="never" class="gantt-card">
        <div class="gantt-help">操作提示：单击计划条选中并高亮该工单全部工序；当前起未来三天的锁定任务不能直接操作，需选中后点击上方“解锁”并填写原因；超过三天不显示锁定标识，可直接调整。长按任务主体移动整条工序链，拖动左侧手柄只调整开始时间，拖动右侧手柄只调整结束时间；双击进入排产明细。生产中/已完成或已有实际报工的任务仅支持查看。</div>
        <div v-if="selectedBar" class="selection-hint">
          <span>已选工单：<strong>{{ selectedBar.taskNo }}</strong></span>
          <span>订单：{{ selectedBar.orderNo || '—' }}</span>
          <span>已联动高亮该任务全部 {{ taskSegmentCount }} 段工序</span>
          <span v-if="selectedActualSummary" class="selection-metrics">{{ selectedActualSummary }}</span>
        </div>
        <GanttChart
          ref="chartRef"
          :rows="rows"
          :start-date="startDate"
          :end-date="endDate"
          :shift="timeline?.shift ?? defaultShift"
          :dragging-task="pickedTask"
          :px-per-day="pxPerDay"
          :focus-now="rangePreset !== 'p7'"
          @update:px-per-day="pxPerDay = $event"
          @drop-on="onDropOn"
          @click-empty="onClickEmpty"
          @click-bar="onClickBar"
          @dbl-click-bar="onDblClickBar"
          @move-bar="onMoveBar"
          @resize-bar="onResizeBar"
          @native-drop="onNativeDrop"
          @drop-rejected="onDropRejected"
        />
        <div v-if="rows.length === 0" class="empty-tip">
          {{ allRows.length ? '没有匹配的排程资源，请清空筛选或展开车间。' : '加载甘特数据中…（确认已登录且有排产查看权限）' }}
        </div>
      </el-card>
    </div>

    <!-- 插单确认弹窗 -->
    <InsertDialog v-model="insertVisible" :payload="insertPayload" @confirmed="reloadAll" />

    <!-- 自动排弹窗 -->
    <AutoScheduleDialog ref="autoDialogRef" :machines="machineBriefs" @accepted="reloadAll" />

    <!-- 明细抽屉 -->
    <el-drawer v-model="detailDrawer" :title="selectedBar ? `排产结果明细 · ${selectedBar.taskNo}` : '排产结果明细'" size="70%">
      <!-- 按任务 ID 重建明细组件，避免抽屉复用旧实例导致双击后不重新请求。 -->
      <ScheduleDetailView :key="detailTaskId ?? 'empty'" :task-id="detailTaskId" />
    </el-drawer>

    <!-- 冲突处理弹窗 -->
    <el-dialog v-model="conflictVisible" :title="conflictMode === 'chain' ? '整条工序链排程冲突' : '排程冲突'" width="480px">
      <el-alert type="error" :closable="false" class="mb-12">
        {{ conflictMessage }}
      </el-alert>
      <div v-for="c in conflictList" :key="c.resource + c.task_id_a + c.task_id_b + (c.seq_a ?? '')" class="conflict-item">
        <div>🔴 {{ c.message }}</div>
        <div class="conflict-detail">
          本次调整：{{ c.task_no_a }} · {{ c.op_name_a || `第${c.seq_a ?? '-'}道工序` }}
          （{{ c.start_a }} ~ {{ c.end_a }}）
        </div>
        <div class="conflict-detail">
          冲突任务：{{ c.task_no_b }} · {{ c.op_name_b || `第${c.seq_b ?? '-'}道工序` }}
          （{{ c.start_b }} ~ {{ c.end_b }}）
        </div>
      </div>
      <template #footer>
        <el-button @click="conflictVisible = false">取消</el-button>
        <el-button v-if="conflictMode === 'place'" type="warning" :loading="forceSaving" @click="forceSave">
          强制排入并标记超期（显式确认）
        </el-button>
        <el-button v-if="conflictMode === 'place' && pendingDrop" type="primary" @click="toInsertConfirm">以插单方式排入（顺延）</el-button>
        <el-button
          v-if="conflictMode === 'chain' && pendingDrop?.scheduleId"
          type="primary"
          :loading="operationSaving"
          @click="moveOnlyOperation"
        >
          仅移动本工序
        </el-button>
        <span v-if="conflictMode === 'chain'" class="conflict-footer-hint">整条工序链未移动；可选择仅移动本工序，其他工序保持原计划</span>
        <span v-if="conflictMode === 'operation'" class="conflict-footer-hint">本工序未移动；其他工序保持原计划，不会被重算</span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Aim, List, MagicStick, Refresh, RefreshRight, Unlock } from '@element-plus/icons-vue'
import { scheduleApi } from '@/api/schedule'
import type { PoolTask, TimelineData } from '@/types/schedule'
import type { GanttBar, GanttRow } from '@/utils/ScheduleAdapter'
import { machineTypeLabel, toDate, toIso, toRows } from '@/utils/ScheduleAdapter'
import GanttChart from '@/components/gantt/GanttChart.vue'
import TaskPoolPanel from '@/components/schedule/TaskPoolPanel.vue'
import InsertDialog from '@/components/schedule/InsertDialog.vue'
import AutoScheduleDialog from '@/components/schedule/AutoScheduleDialog.vue'
import ScheduleDetailView from '@/views/schedule/ScheduleDetailView.vue'

// 默认同时展示历史完工与近期计划，避免打开甘特图看不到过去几天的数据。
const rangePreset = ref<'p7' | '7d' | '14d' | '30d'>('p7')
const poolItems = ref<PoolTask[]>([])
const poolLoading = ref(false)
const timeline = ref<TimelineData | null>(null)
const pickedTask = ref<PoolTask | null>(null)
/** 比例尺（像素/天）：默认 140 天级总览，滑杆放大到 2160 小时级 */
const pxPerDay = ref(140)

const defaultShift: TimelineData['shift'] = {
  two_shift: { shift1: { name: '白班', start: '08:00', end: '20:00' }, shift2: { name: '夜班', start: '20:00', end: '08:00' } },
  day: { start: '08:00', end: '17:00' },
}

const now = computed(() => new Date())
const startDate = computed(() => {
  const d = new Date(now.value)
  d.setHours(0, 0, 0, 0)
  if (rangePreset.value === 'p7') d.setDate(d.getDate() - 7)
  return d
})
const endDate = computed(() => {
  // p7 = 过去7天 + 今天 + 未来7天；其他选项保持纯未来窗口。
  const days = rangePreset.value === 'p7' ? 14 : rangePreset.value === '7d' ? 7 : rangePreset.value === '14d' ? 14 : 30
  const d = new Date(startDate.value)
  d.setDate(d.getDate() + days)
  return d
})

const allRows = computed<GanttRow[]>(() => (timeline.value ? toRows(timeline.value) : []))
type ExceptionKind = '' | 'conflict' | 'overdue' | 'forecast'

const ganttFilters = ref({
  keyword: '',
  workshop: '',
  resourceType: '',
  status: '',
  exceptionOnly: false,
  exceptionKind: '' as ExceptionKind,
})
const workshopOptions = computed(() => [...new Set(allRows.value.map((row) => row.workshop).filter(Boolean))])
const resourceTypeOptions = computed(() => {
  const values = [...new Set(allRows.value.map((row) => row.resourceType).filter(Boolean))]
  return values.map((value) => ({ value, label: machineTypeLabel(value) }))
})
const ganttStats = computed(() => {
  const bars = allRows.value.flatMap((row) => row.bars)
  return {
    conflict: bars.filter((bar) => bar.isConflict || bar.isOverlap).length,
    overdue: bars.filter(isOverdueBar).length,
    forecast: bars.filter((bar) => Boolean(bar.forecastEnd)).length,
  }
})
const hasGanttFilters = computed(() => Boolean(
  ganttFilters.value.keyword
  || ganttFilters.value.workshop
  || ganttFilters.value.resourceType
  || ganttFilters.value.status
  || ganttFilters.value.exceptionOnly
  || ganttFilters.value.exceptionKind,
))

function isOverdueBar(bar: GanttBar): boolean {
  if (bar.status === 'done') return false
  if (bar.actualEnd) return toDate(bar.actualEnd).getTime() > toDate(bar.end).getTime()
  return toDate(bar.end).getTime() < Date.now()
}

function matchesExceptionFilter(bar: GanttBar): boolean {
  const kind = ganttFilters.value.exceptionKind
  if (kind === 'conflict') return Boolean(bar.isConflict || bar.isOverlap)
  if (kind === 'overdue') return isOverdueBar(bar)
  if (kind === 'forecast') return Boolean(bar.forecastEnd)
  if (!ganttFilters.value.exceptionOnly) return true
  return Boolean(bar.isConflict || bar.isOverlap || bar.isInterrupted || bar.isLocked || bar.forecastEnd || isOverdueBar(bar))
}

function toggleExceptionFilter(kind: Exclude<ExceptionKind, ''>): void {
  if (ganttFilters.value.exceptionKind === kind) {
    ganttFilters.value.exceptionKind = ''
    return
  }
  ganttFilters.value.exceptionOnly = false
  ganttFilters.value.exceptionKind = kind
}
const rows = computed<GanttRow[]>(() => {
  const keyword = ganttFilters.value.keyword.trim().toLowerCase()
  return allRows.value
    .map((row) => ({
      ...row,
      bars: row.bars.filter((bar) => {
        const matchesKeyword = !keyword || [bar.taskNo, bar.orderNo, bar.productCode, bar.productName, bar.opName]
          .some((value) => value.toLowerCase().includes(keyword))
        const matchesWorkshop = !ganttFilters.value.workshop || row.workshop === ganttFilters.value.workshop
        const matchesResource = !ganttFilters.value.resourceType || row.resourceType === ganttFilters.value.resourceType
        const matchesStatus = !ganttFilters.value.status || bar.status === ganttFilters.value.status
        const matchesException = matchesExceptionFilter(bar)
        return matchesKeyword && matchesWorkshop && matchesResource && matchesStatus && matchesException
      }),
    }))
    .filter((row) => row.bars.length > 0)
})
const machineBriefs = computed(() => (timeline.value?.machines ?? []).map((m) => ({ id: m.id, code: m.code, name: m.name })))

// ---- 插单 ----
const insertVisible = ref(false)
const insertPayload = ref<{ taskId: number; machineId: number; planStart: string } | null>(null)

// ---- 冲突处理 ----
const conflictVisible = ref(false)
const conflictMessage = ref('')
const conflictList = ref<Array<{
  task_id_a: number
  task_no_a: string
  seq_a?: number
  op_name_a?: string
  start_a: string
  end_a: string
  task_id_b: number
  task_no_b: string
  seq_b?: number
  op_name_b?: string
  start_b: string
  end_b: string
  message: string
  resource: string
}>>([])
const conflictMode = ref<'place' | 'chain' | 'operation'>('place')
const forceSaving = ref(false)
const operationSaving = ref(false)
const pendingDrop = ref<{
  taskId: number
  machineId: number
  planStart: string
  scheduleId?: number
  operationMachineId?: number
  operationPlanStart?: string
  opName?: string
} | null>(null)

type ScheduleError = { code?: string; message?: string; op_name?: string; resource_type?: string; line_type?: string }

const resourceConfigErrorCodes = new Set([
  'line_resource_mismatch',
  'line_resource_workshop_mismatch',
  'line_resource_not_found',
  'line_resource_unavailable',
  'resource_type_not_configured',
])

function showResourceConfigError(errors: ScheduleError[], fallback: string): boolean {
  const error = errors.find((item) => resourceConfigErrorCodes.has(item.code ?? ''))
  if (!error) return false

  pendingDrop.value = null
  const detail = [
    error.message ?? fallback,
    error.op_name ? `工序：${error.op_name}` : '',
    error.resource_type ? `要求资源：${error.resource_type}` : '',
    error.line_type ? `当前资源：${error.line_type}` : '',
  ].filter(Boolean).join('\n')
  void ElMessageBox.alert(detail, '工序资源配置异常', {
    type: 'error',
    confirmButtonText: '知道了',
  })
  return true
}

const autoDialogRef = ref<InstanceType<typeof AutoScheduleDialog> | null>(null)
const chartRef = ref<InstanceType<typeof GanttChart> | null>(null)
const detailDrawer = ref(false)
const selectedBar = ref<GanttBar | null>(null)
const detailTaskId = ref<number | null>(null)
const recalculating = ref(false)

const canUnlockSelected = computed(() => {
  const bar = selectedBar.value
  return Boolean(
    bar
      && bar.isLocked
      && bar.isFrozen
      && bar.status !== 'running'
      && bar.status !== 'done'
      && !bar.actualStart
      && !bar.actualEnd,
  )
})

const taskSegmentCount = computed(() => {
  if (!selectedBar.value) return 0
  return allRows.value.reduce((count, row) => count + row.bars.filter((bar) => bar.taskId === selectedBar.value?.taskId).length, 0)
})

function formatDuration(minutes: number): string {
  const rounded = Math.max(0, Math.round(minutes))
  if (rounded < 60) return `${rounded}分钟`
  const hours = Math.floor(rounded / 60)
  const rest = rounded % 60
  return rest ? `${hours}小时${rest}分` : `${hours}小时`
}

const selectedActualSummary = computed(() => {
  const segments = selectedBar.value?.actualSegments ?? []
  if (!segments.length) return ''
  const effectiveMinutes = segments.reduce((total, segment) => {
    return total + Math.max(0, (toDate(segment.end).getTime() - toDate(segment.start).getTime()) / 60000)
  }, 0)
  const spanStart = Math.min(...segments.map((segment) => toDate(segment.start).getTime()))
  const spanEnd = Math.max(...segments.map((segment) => toDate(segment.end).getTime()))
  return `实际有效 ${formatDuration(effectiveMinutes)} · 时间跨度 ${formatDuration((spanEnd - spanStart) / 60000)} · ${segments.length} 段`
})

function resetGanttFilters(): void {
  ganttFilters.value = { keyword: '', workshop: '', resourceType: '', status: '', exceptionOnly: false, exceptionKind: '' }
}

onMounted(reloadAll)

async function reloadAll(): Promise<void> {
  await Promise.all([loadPool(), reloadTimeline()])
}

async function loadPool(): Promise<void> {
  poolLoading.value = true
  try {
    const result = await scheduleApi.taskPool()
    poolItems.value = result.items
  } catch {
    poolItems.value = []
  } finally {
    poolLoading.value = false
  }
}

async function reloadTimeline(): Promise<void> {
  const fmt = (d: Date): string => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  try {
    timeline.value = await scheduleApi.timeline(fmt(startDate.value), fmt(endDate.value))
  } catch {
    timeline.value = null
  }
}

/** 拖拽/点击放置：先尝试手工保存；冲突 → 弹窗选择强制/插单 */
async function tryPlace(payload: { row: GanttRow; startIso: string }, task: PoolTask): Promise<void> {
  const machineId = payload.row.id

  const base = { taskId: task.id, machineId, planStart: payload.startIso }
  pendingDrop.value = base

  try {
    await scheduleApi.manualSave({
      items: [{ task_id: task.id, machine_id: machineId, plan_start: payload.startIso }],
    })
    ElMessage.success(`排程已保存：${payload.startIso} 开始（任务已锁定）`)
    pickedTask.value = null
    reloadAll()
  } catch (e) {
    const resp = e as { response?: { status?: number; data?: { message?: string; data?: { errors?: unknown[] } } } }
    const errors = resp.response?.data?.data?.errors as typeof conflictList.value | undefined
    if (resp.response?.status === 409 && errors?.length) {
      const codes = (errors as Array<{ code?: string }>).map((x) => x.code ?? '')
      const isTimeConflict = codes.includes('resource_conflict')
      if (!isTimeConflict) {
        // 三匹配 / 资源强对应等业务拒绝：明确提示（含工序名与资源清单）
        const first = errors[0] as { message?: string; op_name?: string; target_resource?: { code?: string; type_label?: string } } | undefined
        const detail = first
          ? `${first.message ?? ''}\n工序：${first.op_name ?? '-'}｜目标资源：${first.target_resource?.code ?? '-'}（${first.target_resource?.type_label ?? ''}）`
          : (resp.response.data?.message ?? '排程被拒绝')
        ElMessageBox.alert(detail, '无法排产（资源强对应）', { type: 'error' })
        return
      }
      conflictMessage.value = resp.response.data?.message ?? '存在资源冲突'
      conflictList.value = errors
      conflictMode.value = 'place'
      conflictVisible.value = true
      return
    }
    if (resp.response?.status === 409) {
      // 三匹配等业务拒绝
      ElMessageBox.alert(resp.response.data?.message ?? '排程被拒绝', '无法排产', { type: 'error' })
      return
    }
    ElMessage.error('保存失败，请重试')
  }
}

async function onDropOn(payload: { row: GanttRow; startIso: string }): Promise<void> {
  const task = pickedTask.value
  if (!task) {
    ElMessage.warning('请先从任务池选择任务（拖拽任务或点击任务）')
    return
  }
  await tryPlace(payload, task)
}

/** 浏览器原生 HTML5 拖拽接收：从 dataTransfer 拿 taskId，自动在池子里找任务并放置 */
async function onNativeDrop(payload: { taskId: number; row: GanttRow; startIso: string }): Promise<void> {
  const task = poolItems.value.find((t) => t.id === payload.taskId)
  if (!task) {
    ElMessage.warning(`任务 #${payload.taskId} 已不在任务池，可能已排过`)
    return
  }
  pickedTask.value = task
  ElMessage.success(`已选中「${task.product_name}」，开始排程…`)
  await tryPlace({ row: payload.row, startIso: payload.startIso }, task)
}

function onDropRejected(payload: { task: PoolTask; row: GanttRow }): void {
  ElMessage.warning(`任务「${payload.task.task_no}」首道工序需要${payload.task.required_resource_label}，不能排入${payload.row.label}`)
}

async function onClickEmpty(payload: { row: GanttRow; startIso: string }): Promise<void> {
  const task = pickedTask.value
  if (!task) {
    ElMessage.info('点击了空白处：先在任务池选择任务后，此处将放置/插单')
    return
  }
  await tryPlace(payload, task)
}

async function forceSave(): Promise<void> {
  if (!pendingDrop.value) return
  forceSaving.value = true
  try {
    // 强制排入需显式确认标记超期
    await ElMessageBox.confirm('强制排入将允许机台/模具时间重叠，请再次确认（标记超期）。', '强制排入确认', {
      type: 'warning',
      confirmButtonText: '确认强制排入',
    })
    await scheduleApi.manualSave({
      items: [{ task_id: pendingDrop.value.taskId, machine_id: pendingDrop.value.machineId, plan_start: pendingDrop.value.planStart }],
      force: true,
      note: '前端显式确认强制排入（标记超期）',
    })
    ElMessage.warning('已强制排入（存在重叠已标记）')
    conflictVisible.value = false
    pickedTask.value = null
    reloadAll()
  } catch {
    // 用户取消或保存失败
  } finally {
    forceSaving.value = false
  }
}

/** 冲突时转为插单（顺延受影响任务） */
async function toInsertConfirm(): Promise<void> {
  if (!pendingDrop.value) return
  conflictVisible.value = false
  insertPayload.value = pendingDrop.value
  insertVisible.value = true
}

/** 单击甘特条：仅选中并联动高亮同一工单的全部工序 */
function onClickBar(bar: GanttBar): void {
  selectedBar.value = bar
  detailTaskId.value = bar.taskId
}

/** 双击甘特条：打开明细并加载对应任务 */
function onDblClickBar(bar: GanttBar): void {
  if (!Number.isInteger(bar.taskId) || bar.taskId <= 0) {
    ElMessage.error('当前甘特任务缺少有效工单 ID，无法打开明细')
    return
  }
  selectedBar.value = bar
  detailTaskId.value = bar.taskId
  detailDrawer.value = true
}

/** 当前选中的三日锁定任务只能通过工具栏显式解锁。 */
async function unlockSelected(): Promise<void> {
  const bar = selectedBar.value
  if (!bar || !canUnlockSelected.value) return

  const prompt = await ElMessageBox.prompt(
    `任务「${bar.taskNo}」计划在未来三天内，解锁后才允许调整排程。请填写本次调整原因。`,
    '解锁排程',
    {
      inputPlaceholder: '例如：客户交期变更，需要重新排产',
      confirmButtonText: '确认解锁',
      cancelButtonText: '取消',
      inputValidator: (value: string) => value.trim() ? true : '请填写解锁原因',
    },
  ).catch(() => null)
  if (!prompt) return

  const taskId = bar.taskId
  const scheduleId = bar.id
  try {
    await scheduleApi.unlock([taskId], prompt.value.trim(), true)
    ElMessage.success(`任务「${bar.taskNo}」已解锁，现在可以调整排程`)
    await reloadAll()
    selectedBar.value = rows.value.flatMap((row) => row.bars).find((item) => item.id === scheduleId || item.taskId === taskId) ?? null
  } catch (error) {
    const resp = error as { response?: { data?: { message?: string } } }
    ElMessage.error(resp.response?.data?.message ?? '解锁失败，请刷新后重试')
  }
}

/** 已排工序调整：默认移动整条工序链；整链冲突时再允许仅移动当前工序 */
async function unlockForAdjustment(taskId: number, reason: string): Promise<void> {
  try {
    await scheduleApi.unlock([taskId], reason)
    return
  } catch (error) {
    const resp = error as { response?: { status?: number; data?: { message?: string; data?: { errors?: Array<{ code?: string; message?: string }> } } } }
    const errors = resp.response?.data?.data?.errors ?? []
    const frozen = errors.find((item) => item.code === 'frozen_schedule')
    if (resp.response?.status !== 409 || !frozen) throw error

    throw new Error(frozen.message ?? '当前任务处于未来三天锁定区，请先选中任务并点击上方“解锁”')
  }
}

async function onMoveBar(payload: { bar: GanttBar; row: GanttRow; startIso: string }): Promise<void> {
  const { bar, row } = payload
  if (bar.status === 'running' || bar.status === 'done') {
    ElMessage.warning('生产中/已完成工序不能调整计划，请通过异常或更正流程处理')
    return
  }
  if (!bar.id) {
    ElMessage.error('无法定位当前工序排程，暂不能调整')
    return
  }

  const taskBars = rows.value
    .flatMap((item) => item.bars)
    .filter((item) => item.taskId === bar.taskId)
    .sort((a, b) => a.seq - b.seq || a.start.localeCompare(b.start))
  const first = taskBars[0]
  if (!first || !first.machineId) {
    ElMessage.error('无法定位该工单的首工序机台，暂不能调整')
    return
  }

  // 拖动后续工序时保持整条链的相对位置；拖动首工序时允许换机台。
  const grabbedOffset = Math.max(0, (new Date(bar.start.replace(' ', 'T')).getTime() - new Date(first.start.replace(' ', 'T')).getTime()) / 60000)
  const targetStart = new Date(payload.startIso.replace(' ', 'T'))
  targetStart.setMinutes(targetStart.getMinutes() - grabbedOffset)
  const machineId = bar.seq === first.seq ? row.id : first.machineId
  const planStart = toIso(targetStart)

  try {
    await ElMessageBox.confirm(
      `本次将移动工单「${bar.taskNo}」的整条工序链，当前工序「${bar.opName}」用于确定平移位置；前后工序会一起跟随调整。若整条工序链与其他任务冲突，弹窗会列出具体冲突工序，并可再选择仅移动本工序。系统会先解锁并记录原因，保存后自动重新锁定。`,
      '确认移动工序链',
      { type: 'warning', confirmButtonText: '移动整条工序链', cancelButtonText: '取消' },
    )
    pendingDrop.value = {
      taskId: bar.taskId,
      machineId,
      planStart,
      scheduleId: bar.id,
      operationMachineId: row.id,
      operationPlanStart: payload.startIso,
      opName: bar.opName,
    }
    if (bar.isLocked) {
      await unlockForAdjustment(bar.taskId, '甘特图整条工序链调整')
    }
    await scheduleApi.manualSave({
      items: [{ task_id: bar.taskId, machine_id: machineId, plan_start: planStart }],
      note: `甘特图整条工序链调整：${bar.opName}`,
    })
    ElMessage.success(`工序链已调整：${bar.taskNo}（各工序已同步移动）`)
    pendingDrop.value = null
    await reloadAll()
  } catch (e) {
    const resp = e as { response?: { status?: number; data?: { message?: string; data?: { errors?: typeof conflictList.value } } } }
    const errors = resp.response?.data?.data?.errors
    if (resp.response?.status === 409 && errors?.length) {
      const frozen = (errors as Array<{ code?: string; message?: string }>).find((item) => item.code === 'frozen_schedule')
      if (frozen) {
        pendingDrop.value = null
        await ElMessageBox.alert(frozen.message ?? '该任务处于当前起未来三天冻结区，暂不能直接移动', '冻结区不可调整', {
          type: 'warning',
          confirmButtonText: '知道了',
        })
        return
      }
      if (showResourceConfigError(errors as unknown as ScheduleError[], resp.response.data?.message ?? '工序资源配置不完整')) {
        return
      }
      conflictMessage.value = resp.response.data?.message ?? `工序「${bar.opName}」调整后与其他任务冲突`
      conflictList.value = errors
      conflictMode.value = 'chain'
      conflictVisible.value = true
      return
    }
    // 用户取消不会提示；解锁后保存失败则明确告诉用户当前状态，方便继续调整。
    if (!resp.response) pendingDrop.value = null
    if (resp.response) ElMessage.error(resp.response.data?.message ?? '排程调整失败，请检查冲突后重试')
  }
}

/** 调整当前工序单侧时间边界：不移动同工单其他工序。 */
async function onResizeBar(payload: {
  bar: GanttBar
  row: GanttRow
  edge: 'start' | 'end'
  startIso: string
  endIso: string
}): Promise<void> {
  const { bar, row } = payload
  if (bar.status === 'running' || bar.status === 'done' || bar.actualStart || bar.actualEnd) {
    ElMessage.warning('生产中/已完成或已有实际报工的工序不能调整计划')
    return
  }
  if (!bar.id) {
    ElMessage.error('无法定位当前工序排程，暂不能调整')
    return
  }
  const machineId = bar.machineId ?? row.id
  if (!machineId) {
    ElMessage.error('无法定位当前工序的机台/产线，暂不能调整')
    return
  }

  operationSaving.value = true
  try {
    if (bar.isLocked) {
      await unlockForAdjustment(bar.taskId, `甘特图调整${bar.opName}${payload.edge === 'start' ? '开始' : '结束'}时间`)
    }
    await scheduleApi.operationAdjust({
      schedule_id: bar.id,
      machine_id: machineId,
      plan_start: payload.startIso,
      plan_end: payload.endIso,
      edge: payload.edge,
      note: `甘特图调整${bar.opName}${payload.edge === 'start' ? '开始' : '结束'}时间`,
    })
    ElMessage.success(`已调整「${bar.opName}」${payload.edge === 'start' ? '开始' : '结束'}时间，其他工序保持原计划`)
    await reloadAll()
  } catch (e) {
    const resp = e as { response?: { status?: number; data?: { message?: string; data?: { errors?: typeof conflictList.value } } } }
    const errors = resp.response?.data?.data?.errors
    if (resp.response?.status === 409 && errors?.length) {
      if (showResourceConfigError(errors as unknown as ScheduleError[], resp.response.data?.message ?? '工序资源配置不完整')) {
        return
      }
      conflictMessage.value = resp.response.data?.message ?? `工序「${bar.opName}」调整后与其他任务冲突`
      conflictList.value = errors
      conflictMode.value = 'operation'
      pendingDrop.value = null
      conflictVisible.value = true
      return
    }
    if (resp.response) ElMessage.error(resp.response.data?.message ?? '当前工序时间调整失败，请检查冲突后重试')
  } finally {
    operationSaving.value = false
  }
}

/** 整链移动冲突后的备选方案：只移动当前工序，其他工序保持原计划 */
async function moveOnlyOperation(): Promise<void> {
  const drop = pendingDrop.value
  if (!drop?.scheduleId) return

  operationSaving.value = true
  try {
    await scheduleApi.operationAdjust({
      schedule_id: drop.scheduleId,
      machine_id: drop.operationMachineId ?? drop.machineId,
      plan_start: drop.operationPlanStart ?? drop.planStart,
      note: `整链冲突后仅移动本工序${drop.opName ? `：${drop.opName}` : ''}`,
    })
    ElMessage.success(`已仅移动本工序${drop.opName ? `「${drop.opName}」` : ''}，其他工序保持原计划`)
    conflictVisible.value = false
    pendingDrop.value = null
    await reloadAll()
  } catch (e) {
    const resp = e as { response?: { status?: number; data?: { message?: string; data?: { errors?: typeof conflictList.value } } } }
    const errors = resp.response?.data?.data?.errors
    if (resp.response?.status === 409 && errors?.length) {
      const frozen = (errors as Array<{ code?: string; message?: string }>).find((item) => item.code === 'frozen_schedule')
      if (frozen) {
        pendingDrop.value = null
        await ElMessageBox.alert(frozen.message ?? '该任务处于当前起未来三天冻结区，暂不能直接移动', '冻结区不可调整', {
          type: 'warning',
          confirmButtonText: '知道了',
        })
        conflictVisible.value = false
        return
      }
      if (showResourceConfigError(errors as unknown as ScheduleError[], resp.response.data?.message ?? '工序资源配置不完整')) {
        conflictVisible.value = false
        return
      }
      conflictMessage.value = resp.response.data?.message ?? '仅移动本工序后仍与其他任务冲突'
      conflictList.value = errors
      conflictMode.value = 'operation'
      pendingDrop.value = null
      return
    }
    if (resp.response) ElMessage.error(resp.response.data?.message ?? '仅移动本工序失败，请检查冲突后重试')
  } finally {
    operationSaving.value = false
  }
}

function detailOpen(): void {
  if (!selectedBar.value || !Number.isInteger(selectedBar.value.taskId) || selectedBar.value.taskId <= 0) {
    ElMessage.info('请先单击或双击甘特任务，再打开对应的排产明细')
    return
  }
  detailTaskId.value = selectedBar.value.taskId
  detailDrawer.value = true
}

function autoOpen(): void {
  autoDialogRef.value?.open()
}

async function recalculateSchedules(): Promise<void> {
  try {
    await ElMessageBox.confirm(
      '将按当前机台/产线最新产能重算已排但未开工的工序。已开工、已完工和已有报工的工序不会改变；这不是自动排产，不会生成新的算法建议。',
      '重算排程',
      { type: 'warning', confirmButtonText: '开始重算', cancelButtonText: '取消' },
    )
    recalculating.value = true
    const result = await scheduleApi.recalculate()
    ElMessage.success(`排程重算完成：涉及 ${result.task_count} 个工单，调整 ${result.changed_count} 条工序`)
    await reloadAll()
  } catch (error) {
    const response = error as { response?: { status?: number; data?: { message?: string } } }
    if (response.response) {
      ElMessage.error(response.response.data?.message ?? '排程重算失败，请检查冲突后重试')
    }
  } finally {
    recalculating.value = false
  }
}
</script>

<style scoped>
.gantt-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.toolbar-card {
  border-radius: 8px;
}
.toolbar {
  display: flex;
  flex-wrap: nowrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.toolbar-left {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.toolbar-right {
  flex: 0 0 auto;
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}
.zoom-label {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}
.zoom-slider {
  width: 140px;
}
.legend-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}
.legend-chip .dot {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  display: inline-block;
}
.dot-plan {
  background: #3b82f6;
}
.dot-actual {
  background: #059669;
  height: 5px;
  border-radius: 2px;
}
.dot-forecast {
  background: rgba(245, 158, 11, 0.25);
  border: 1px dashed #f59e0b;
}
.toolbar-hint {
  font-size: 12px;
  color: #94a3b8;
}
.gantt-control-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-top: 12px;
  margin-top: 12px;
  border-top: 1px solid #eef2f7;
}
.gantt-exception-inline,
.gantt-filter-inline {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.gantt-exception-inline {
  flex: 0 1 auto;
}
.gantt-filter-inline {
  flex: 1 1 auto;
  justify-content: flex-end;
  min-width: 0;
}
.gantt-filter-keyword {
  width: 230px;
}
.gantt-filter-select {
  width: 140px;
}
.gantt-filter-status {
  width: 120px;
}
.gantt-filter-summary {
  color: #94a3b8;
  font-size: 12px;
  white-space: nowrap;
}
.gantt-summary-label {
  flex: 0 0 auto;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
}
.gantt-stat {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 26px;
  padding: 0 8px;
  border: 1px solid #e2e8f0;
  border-radius: 5px;
  background: #fff;
  color: #475569;
  font-size: 12px;
  cursor: pointer;
}
.gantt-stat strong {
  font-size: 13px;
  line-height: 1;
}
.gantt-stat.is-danger strong { color: #dc2626; }
.gantt-stat.is-warning strong { color: #d97706; }
.gantt-stat.is-info strong { color: #2563eb; }
.gantt-stat.is-active {
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.14);
  border-color: #60a5fa;
  background: #eff6ff;
}
.gantt-stat-note {
  color: #94a3b8;
  font-size: 12px;
}
.gantt-main {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.gantt-main > :first-child {
  flex: 0 0 260px;
}
.gantt-card {
  flex: 1;
  border-radius: 8px;
  min-width: 0;
  overflow: hidden;
}
.gantt-help {
  margin-bottom: 8px;
  color: #64748b;
  font-size: 12px;
}
.selection-hint {
  display: flex;
  align-items: center;
  gap: 16px;
  min-height: 34px;
  padding: 0 12px;
  margin-bottom: 8px;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  background: #eff6ff;
  color: #475569;
  font-size: 12px;
}
.selection-hint strong {
  color: #1d4ed8;
  font-weight: 700;
}
.selection-metrics {
  margin-left: auto;
  color: #047857;
  font-weight: 600;
}
.empty-tip {
  padding: 20px;
  text-align: center;
  color: #94a3b8;
  font-size: 13px;
}
.mb-12 {
  margin-bottom: 12px;
}
.conflict-item {
  font-size: 12px;
  color: #b91c1c;
  padding: 4px 0;
  line-height: 1.6;
}

@media (max-width: 1200px) {
  .toolbar {
    flex-wrap: wrap;
    align-items: flex-start;
  }
  .toolbar-left,
  .toolbar-right {
    flex: 1 1 100%;
  }
  .toolbar-right {
    justify-content: flex-end;
  }
}

@media (max-width: 900px) {
  .gantt-control-row {
    align-items: flex-start;
  }
  .gantt-exception-inline,
  .gantt-filter-inline {
    flex: 1 1 100%;
    justify-content: flex-start;
    margin-left: 0;
  }
  .gantt-filter-summary {
    margin-left: 0;
  }
  .selection-hint {
    align-items: flex-start;
    flex-direction: column;
    gap: 3px;
    padding: 7px 12px;
  }
  .selection-metrics {
    margin-left: 0;
  }
}
</style>
