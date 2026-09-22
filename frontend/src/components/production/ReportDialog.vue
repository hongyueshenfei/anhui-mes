<template>
  <el-dialog v-model="dialogVisible" title="手动报工 · 关联工单" width="620px" @closed="onClosed">
    <el-form label-width="100px">
      <el-form-item label="工单" required>
        <el-select
          v-model="reportForm.taskId"
          placeholder="选择工单（含产品）"
          filterable
          style="width: 100%"
          :disabled="presetTasks?.length === 1"
          @change="onTaskSelect"
        >
          <el-option
            v-for="o in taskOptions"
            :key="o.taskId"
            :label="`${o.order_no} · ${o.product}（${o.task_no}）`"
            :value="o.taskId"
          />
        </el-select>
      </el-form-item>
      <el-form-item v-if="currentTask" label="任务">
        <div class="report-task-info">
          <el-tag size="small">{{ currentTask.task_no }}</el-tag>
          <span>{{ currentTask.product?.name ?? '' }} · 计划 {{ currentTask.qty }} 件 · 已完工 {{ currentTask.done_qty ?? 0 }} 件</span>
        </div>
      </el-form-item>
      <el-form-item label="工序">
        <el-select v-model="reportForm.scheduleId" placeholder="不选=任务级（成品口径）" clearable style="width: 100%" @change="onScheduleChange">
          <el-option
            v-for="s in taskSchedules"
            :key="s.id"
            :label="`${s.op_name || s.op_type} #${s.seq} · 计划 ${s.qty} · 完工 ${s.reported_qty ?? s.done_qty ?? 0}`"
            :value="s.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="合格数" required>
        <!-- 不设 max 截断：超量时明确报错（工序链校验），不允许静默修改 -->
        <el-input-number v-model="reportForm.qty" :min="1" :max="10000000" style="width: 100%" />
      </el-form-item>
      <el-alert v-if="chainError" :title="chainError" type="warning" :closable="false" show-icon style="margin: 0 0 12px 100px" />
      <el-form-item label="次品数">
        <el-input-number v-model="reportForm.defectQty" :min="0" style="width: 100%" />
      </el-form-item>
      <el-alert title="当前由班组长或统计员统一填报，不区分个人作业人。" type="info" :closable="false" show-icon />
      <el-form-item label="机台/产线">
        <el-select v-model="reportForm.machineId" placeholder="默认带出排产资源，可修改" clearable filterable style="width: 100%">
          <el-option v-for="m in machines" :key="m.id" :label="m.name" :value="m.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="实际作业时间" required>
        <div class="report-time-range">
          <el-date-picker
            v-model="reportForm.startTime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            format="YYYY-MM-DD HH:mm:ss"
            placeholder="开始时间"
            style="width: 100%"
          />
          <span>至</span>
          <el-date-picker
            v-model="reportForm.endTime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            format="YYYY-MM-DD HH:mm:ss"
            placeholder="结束时间"
            style="width: 100%"
          />
        </div>
        <div class="time-hint">按本次实际生产区间填写；同一任务分段报工会在甘特图分段显示。</div>
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="reportForm.remark" type="textarea" :rows="2" placeholder="备注（可选）" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="reportSubmitting" @click="onReport">提交报工</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import dayjs from 'dayjs'
import { productionApi } from '@/api/production'
import { plmApi } from '@/api/plm'
import type { ProdTask, TaskSchedule } from '@/types/schedule'
import type { Machine } from '@/types'

/**
 * 手动报工弹窗（报工台账 / 订单详情共用）
 *
 * - 台账场景：不传 presetTasks → 加载全量任务（工单下拉自由选择）
 * - 订单详情场景：传 presetTasks（本订单任务）+ presetTaskId（默认选中首任务）
 *   → 工单下拉限定本订单任务，进入即带入订单信息
 */
const props = withDefaults(
  defineProps<{
    modelValue: boolean
    /** 限定可选工单（订单详情传入本订单任务；不传 = 加载全量） */
    presetTasks?: ProdTask[]
    /** 预选任务 id（订单详情默认首任务；此时 presetTasks 建议同时传入以限定选项） */
    presetTaskId?: number | null
  }>(),
  { presetTasks: undefined, presetTaskId: null },
)

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
  (e: 'success'): void
}>()

const dialogVisible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const reportSubmitting = ref(false)
const allTasks = ref<ProdTask[]>([])
const taskSchedules = ref<TaskSchedule[]>([])
const machines = ref<Machine[]>([])
const reportForm = ref<{
  taskId: number | null
  scheduleId: number | undefined
  qty: number
  defectQty: number
  machineId: number | undefined
  startTime: string
  endTime: string
  remark: string
}>({ taskId: null, scheduleId: undefined, qty: 1, defectQty: 0, machineId: undefined, startTime: '', endTime: '', remark: '' })

/** 工单（任务）候选：presetTasks 优先（订单上下文），否则全量可报任务 */
const taskOptions = computed(() => {
  const base = props.presetTasks?.length ? props.presetTasks : allTasks.value
  return base
    .filter((t) => ['scheduled', 'in_progress', 'paused', 'done'].includes(t.status))
    .map((t) => ({
      taskId: t.id,
      task_no: t.task_no,
      order_no: t.line?.order?.order_no ?? '',
      product: t.product?.name ?? '',
    }))
})

/** 当前选中任务 */
const currentTask = computed(() => {
  const base = props.presetTasks?.length ? props.presetTasks : allTasks.value
  return base.find((t) => t.id === reportForm.value.taskId) ?? null
})

/** 工序链上限：下道工序完工 ≤ 上道工序完工 */
const maxReportable = computed(() => {
  const s = taskSchedules.value.find((x) => x.id === reportForm.value.scheduleId)
  if (!s) return 10000000
  const prev = taskSchedules.value.filter((x) => x.seq < s.seq).sort((a, b) => b.seq - a.seq)[0]
  if (!prev) return 10000000 // 首道工序不限制
  const prevDone = prev.reported_qty ?? prev.done_qty ?? 0
  const curDone = s.reported_qty ?? s.done_qty ?? 0
  return Math.max(0, prevDone - curDone)
})

const chainError = computed(() => {
  if (!reportForm.value.scheduleId || reportForm.value.qty <= 0) return ''
  const s = taskSchedules.value.find((x) => x.id === reportForm.value.scheduleId)
  if (!s) return ''
  const prev = taskSchedules.value.filter((x) => x.seq < s.seq).sort((a, b) => b.seq - a.seq)[0]
  if (!prev) return ''
  const prevDone = prev.reported_qty ?? prev.done_qty ?? 0
  const curDone = s.reported_qty ?? s.done_qty ?? 0
  if (curDone + reportForm.value.qty > prevDone) {
    return `下道工序完工量不能超过上道工序完工量（上道已完工 ${prevDone}，本次最多还可报 ${Math.max(0, prevDone - curDone)}）`
  }
  return ''
})

/** 打开弹窗：准备表单 + 加载候选（preset 或全量） */
watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    reportForm.value = {
      taskId: null,
      scheduleId: undefined,
      qty: 1,
      defectQty: 0,
      machineId: undefined,
      startTime: currentReportTime(),
      endTime: currentReportTime(),
      remark: '',
    }
    taskSchedules.value = []
    try {
      if (!props.presetTasks?.length) {
        const [tasks, machinesRes] = await Promise.all([
          productionApi.listTasks({ all: true }),
          plmApi.listMachines({ all: true }),
        ])
        allTasks.value = Array.isArray(tasks) ? tasks : tasks.data
        machines.value = Array.isArray(machinesRes) ? machinesRes : machinesRes.data
      } else {
        // 订单上下文：机台走异步，任务用 preset
        const machinesRes = await plmApi.listMachines({ all: true })
        machines.value = Array.isArray(machinesRes) ? machinesRes : machinesRes.data
      }
      // 预选任务（订单详情带入）
      const presetId = props.presetTaskId ?? props.presetTasks?.[0]?.id ?? null
      if (presetId != null) {
        reportForm.value.taskId = presetId
        await onTaskSelect()
      }
    } catch {
      allTasks.value = []
    }
  },
)

function onClosed(): void {
  // 关闭后重置，避免下次打开闪现旧数据
  taskSchedules.value = []
}

/** 选择工单（即任务）后加载工序排程，默认带出首道工序机台 */
async function onTaskSelect(): Promise<void> {
  taskSchedules.value = []
  if (!reportForm.value.taskId) return
  try {
    const detail = await productionApi.getTask(reportForm.value.taskId)
    taskSchedules.value = detail.schedules ?? []
    reportForm.value.scheduleId = undefined
    reportForm.value.machineId = taskSchedules.value[0]?.machine_id ?? undefined
  } catch {
    taskSchedules.value = []
  }
}

/** 选择工序：默认带出该工序排产机台/产线（可修改） */
function onScheduleChange(): void {
  const s = taskSchedules.value.find((x) => x.id === reportForm.value.scheduleId)
  reportForm.value.machineId = s?.machine_id ?? undefined
}

async function onReport(): Promise<void> {
  if (!reportForm.value.taskId) {
    ElMessage.warning('请选择工单')
    return
  }
  if (reportForm.value.qty <= 0) {
    ElMessage.warning('合格数必须大于 0')
    return
  }
  if (!reportForm.value.startTime || !reportForm.value.endTime) {
    ElMessage.warning('请填写实际作业开始时间和结束时间')
    return
  }
  if (dayjs(reportForm.value.endTime).isBefore(dayjs(reportForm.value.startTime))) {
    ElMessage.warning('结束时间不能早于开始时间')
    return
  }
  if (chainError.value) {
    ElMessage.warning(chainError.value)
    return
  }
  reportSubmitting.value = true
  try {
    await productionApi.submitReport({
      task_id: reportForm.value.taskId,
      schedule_id: reportForm.value.scheduleId || undefined,
      qty: reportForm.value.qty,
      defect_qty: reportForm.value.defectQty || 0,
      machine_id: reportForm.value.machineId ?? null,
      report_time: reportForm.value.endTime,
      start_time: reportForm.value.startTime,
      end_time: reportForm.value.endTime,
      source: 'click',
      remark: reportForm.value.remark.trim() || undefined,
    })
    ElMessage.success('报工成功，已记入台账')
    dialogVisible.value = false
    emit('success')
  } catch {
    // 拦截器已提示（如累计超限/车间不匹配/未排产）
  } finally {
    reportSubmitting.value = false
  }
}

function currentReportTime(): string {
  return dayjs().second(0).format('YYYY-MM-DD HH:mm:ss')
}
</script>

<style scoped>
.report-task-info {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #334155;
}
.report-time-range { display: flex; align-items: center; gap: 8px; width: 100%; }
.report-time-range > span { color: #94a3b8; flex: 0 0 auto; }
.time-hint { margin-top: 5px; color: #94a3b8; font-size: 12px; line-height: 18px; }
</style>
