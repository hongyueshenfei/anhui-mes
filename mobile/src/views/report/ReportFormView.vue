<template>
  <div class="page">
    <van-nav-bar title="日结报工" left-arrow @click-left="router.back()" />
    <div v-if="loading && !task" class="request-state" role="status" aria-live="polite">
      <van-loading vertical>正在读取任务和排程</van-loading>
    </div>
    <van-empty v-else-if="loadError && !task" image="network" :description="loadError">
      <van-button round type="primary" size="small" @click="loadData">重新加载</van-button>
    </van-empty>
    <van-notice-bar v-if="loading && task" type="primary" left-icon="replay" text="正在刷新，当前显示上次成功读取的任务信息。" />
    <van-notice-bar v-if="loadError && task" type="warning" left-icon="warning-o" :text="`${loadError}，当前仍显示上次成功读取的任务信息。`">
      <template #right-icon><van-button size="mini" plain type="warning" @click="loadData">重试</van-button></template>
    </van-notice-bar>
    <van-notice-bar v-if="contextWarning && task" type="warning" left-icon="info-o" :text="contextWarning" />

    <section v-if="task" class="report-section" aria-labelledby="task-context-title">
      <div class="section-heading">
        <h2 id="task-context-title">当前任务</h2>
        <van-tag type="primary" plain>{{ machineCode || '未带出机台' }}</van-tag>
      </div>
      <van-cell-group inset class="task-card">
        <van-cell title="工单" :value="task.task_no || '-'" />
        <van-cell title="产品" :value="productName" />
        <van-cell title="工序" :value="selectedSchedule?.operation?.name || selectedSchedule?.op_name || '-'" />
        <van-cell title="计划时段" :value="planText" />
        <van-cell title="当班可报" :value="remainingQty + ' 件'" />
      </van-cell-group>
    </section>

    <section v-if="task" class="report-section" aria-labelledby="report-entry-title">
      <div class="section-heading">
        <h2 id="report-entry-title">本次报工</h2>
        <span>合格数与次品数分开填写</span>
      </div>
      <van-cell-group inset class="qty-card">
        <van-cell title="实际完工数量" value="按本次作业填写" />
        <div class="qty-stepper">
          <van-button round size="small" @click="changeQty(-10)">减少 10</van-button>
          <van-button round size="small" @click="changeQty(-1)">−</van-button>
          <div class="qty-value"><div class="qty-num">{{ qtyInput || 0 }}</div><div class="qty-tail">件</div></div>
          <van-button round size="small" @click="changeQty(1)">＋</van-button>
          <van-button round size="small" @click="changeQty(10)">增加 10</van-button>
        </div>
        <van-field v-model="qtyInput" type="digit" label="合格数" placeholder="请输入实际合格数" />
        <van-field v-model="defectQty" type="digit" label="次品数" placeholder="0" />
        <van-field v-model="startTime" type="datetime-local" label="开始时间" />
        <van-field v-model="endTime" type="datetime-local" label="结束时间" />
        <van-button class="fill-button" size="small" plain type="primary" @click="fillRemaining">填入剩余计划量 {{ remainingQty }}</van-button>
      </van-cell-group>
    </section>
    <van-notice-bar v-if="task" inset left-icon="info-o" text="当前由班组长或统计员统一填报，不区分个人作业人。" />
    <div v-if="task" class="actions">
      <van-button round block type="primary" size="large" :loading="submitting" @click="onSubmit">
        提交日结报工
      </van-button>
      <van-button round block size="large" plain :loading="offlining" @click="onOfflineSave">暂存，联网后补传</van-button>
      <div v-if="pending > 0" class="offline-hint">待补传 {{ pending }} 条 · <van-button size="mini" @click="flush">立即补传</van-button></div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showSuccessToast, showToast } from 'vant'
import { mobileMachineReportContext, mobileReport, mobileTask } from '@/api/mobile'
import { enqueueReport, flushOfflineQueue, pendingCount, uuid } from '@/utils/offlineQueue'

interface ScheduleRow {
  id?: number; schedule_id?: number; op_name?: string; operation?: { name?: string }
  machine_id?: number | null; plan_start?: string | null; plan_end?: string | null
  planned_qty?: number; remaining_qty?: number
}
const route = useRoute()
const router = useRouter()
const task = ref<any>(null)
const selectedSchedule = ref<ScheduleRow | null>(null)
const machineCode = ref(String(route.query.machine_code ?? ''))
const machineId = ref(Number(route.query.machine_id ?? 0) || null)
const workDate = ref(String(route.query.work_date ?? localDate()))
const shiftCode = ref(String(route.query.shift_code ?? 'day'))
const qtyInput = ref('0')
const defectQty = ref('')
const startTime = ref(localDateTime(0))
const endTime = ref(localDateTime(0))
const submitting = ref(false)
const offlining = ref(false)
const pending = ref(0)
const loading = ref(true)
const loadError = ref('')
const contextWarning = ref('')

const taskId = computed(() => Number(route.query.task_id ?? 0))
const scheduleId = computed(() => Number(route.query.schedule_id ?? selectedSchedule.value?.schedule_id ?? selectedSchedule.value?.id ?? 0) || null)
const productName = computed(() => task.value?.product ? task.value.product.name + '（' + task.value.product.code + '）' : '-')
const remainingQty = computed(() => Math.max(0, Number(selectedSchedule.value?.remaining_qty ?? task.value?.qty ?? 0)))
const planText = computed(() => selectedSchedule.value?.plan_start && selectedSchedule.value?.plan_end ? selectedSchedule.value.plan_start + ' ~ ' + selectedSchedule.value.plan_end : '-')
const reportSource = computed(() => 'leader_batch')

onMounted(async () => { pending.value = pendingCount(); await loadData() })

async function loadData(): Promise<void> {
  loadError.value = ''
  contextWarning.value = ''
  if (!taskId.value) {
    loadError.value = '缺少任务编号，请从任务列表或机台扫码入口重新进入'
    loading.value = false
    return
  }
  loading.value = true
  try {
    if (machineCode.value) {
      try {
        const response = await mobileMachineReportContext(machineCode.value, workDate.value)
        const body = response.data
        if (body?.code !== 0) throw new Error(body?.message ?? '机台排程读取失败')
        const item = (body.data.tasks ?? []).find((row: any) => row.task_id === taskId.value && (!scheduleId.value || row.schedule_id === scheduleId.value))
        if (item) {
          selectedSchedule.value = item
          machineId.value = body.data.machine.id
          shiftCode.value = String(route.query.shift_code ?? body.data.default_shift ?? 'day')
        } else {
          contextWarning.value = '机台当日任务列表中未找到此工序，将尝试使用工单排程信息。'
        }
      } catch (error: any) {
        contextWarning.value = error?.response?.data?.message ?? error?.message ?? '机台排程读取失败，请核对当前机台和日期。'
      }
    }
    const response = await mobileTask(taskId.value)
    const body = response.data
    if (body?.code !== 0) throw new Error(body?.message ?? '任务读取失败')
    task.value = body.data
    if (!selectedSchedule.value && scheduleId.value) {
      const raw = (body.data.schedules ?? []).find((row: any) => row.id === scheduleId.value)
      if (raw) selectedSchedule.value = raw
    }
    if (!machineId.value && selectedSchedule.value?.machine_id) machineId.value = selectedSchedule.value.machine_id
  } catch (error: any) {
    loadError.value = error?.response?.data?.message ?? error?.message ?? '任务加载失败，请检查网络后重试'
  } finally {
    loading.value = false
  }
}
function changeQty(delta: number): void { qtyInput.value = String(Math.max(0, Number(qtyInput.value || 0) + delta)) }
function fillRemaining(): void { qtyInput.value = String(remainingQty.value) }
function payload(clientRequestId: string) {
  return {
    task_id: taskId.value, schedule_id: scheduleId.value, machine_id: machineId.value,
    qty: Number(qtyInput.value || 0), defect_qty: Number(defectQty.value || 0),
    source: reportSource.value,
    report_time: toApiDateTime(endTime.value),
    start_time: toApiDateTime(startTime.value),
    end_time: toApiDateTime(endTime.value),
    work_date: workDate.value, shift_code: shiftCode.value, client_request_id: clientRequestId,
  }
}
function validate(): boolean {
  if (!taskId.value || !scheduleId.value || !machineId.value) { showToast('缺少机台或工序排程，请从机台扫码进入'); return false }
  if (Number(qtyInput.value || 0) <= 0 && Number(defectQty.value || 0) <= 0) { showToast('请填写实际完工数量或次品数'); return false }
  if (!startTime.value || !endTime.value) { showToast('请填写实际作业开始和结束时间'); return false }
  if (endTime.value < startTime.value) { showToast('结束时间不能早于开始时间'); return false }
  return true
}
async function onSubmit(): Promise<void> {
  if (!validate()) return
  submitting.value = true
  try {
    const response = await mobileReport(payload(uuid()))
    const body = response.data
    if (body?.code === 0) { showSuccessToast('报工成功：本次 ' + qtyInput.value + ' 件'); router.back() }
    else showToast(body?.message ?? '报工失败')
  } catch (error: any) { showToast(error?.response?.data?.message ?? '提交失败，可暂存离线') }
  finally { submitting.value = false }
}
async function onOfflineSave(): Promise<void> {
  if (!validate()) return
  offlining.value = true
  try {
    enqueueReport(payload(uuid())); pending.value = pendingCount()
    showToast('已暂存日结报工（' + pending.value + ' 条），联网自动补传'); router.back()
  } finally { offlining.value = false }
}
async function flush(): Promise<void> {
  const result = await flushOfflineQueue(); pending.value = pendingCount()
  showSuccessToast('补传完成：成功 ' + result.sent + '，待重试 ' + result.failed)
}
function localDate(): string {
  const d = new Date(); const pad = (value: number) => String(value).padStart(2, '0')
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
}
function localDateTime(offsetMinutes: number): string {
  const d = new Date(Date.now() + offsetMinutes * 60 * 1000); const pad = (value: number) => String(value).padStart(2, '0')
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
}
function toApiDateTime(value: string): string {
  return value ? value.replace('T', ' ') + ':00' : value
}
</script>

<style scoped>
.page { min-height: 100vh; padding: 0 0 40px; background: var(--hy-canvas); }
.request-state { display: grid; min-height: 180px; place-items: center; color: var(--hy-muted); }
.report-section { margin-top: 16px; }
.section-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0 16px 8px; }
.section-heading h2 { margin: 0; color: var(--hy-ink); font-size: 15px; font-weight: 650; }
.section-heading > span { color: var(--hy-muted); font-size: 11px; }
.task-card, .qty-card, .worker-card { margin-top: 0; }
.qty-stepper { display: flex; align-items: center; gap: 6px; padding: 12px 12px; }
.qty-value { flex: 1; min-width: 58px; text-align: center; }
.qty-num { color: var(--hy-blue); font-size: 32px; font-weight: 700; font-variant-numeric: tabular-nums; }
.qty-tail { color: var(--hy-muted); font-size: 12px; }
.fill-button { margin: 0 16px 12px; }
.worker-card .selected { background: #eaf3f9; }
.actions { display: flex; flex-direction: column; gap: 10px; margin: 20px 16px; }
.offline-hint { color: var(--hy-amber); font-size: 12px; text-align: center; }
</style>
