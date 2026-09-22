<template>
  <div class="page">
    <van-nav-bar title="日结报工" left-arrow @click-left="router.back()" />
    <van-cell-group inset v-if="task" class="task-card">
      <van-cell title="机台" :value="machineCode || '从任务排程带出'" />
      <van-cell title="工单" :value="task.task_no || '-'" />
      <van-cell title="产品" :value="productName" />
      <van-cell title="工序" :value="selectedSchedule?.operation?.name || selectedSchedule?.op_name || '-'" />
      <van-cell title="计划时段" :value="planText" />
      <van-cell title="当班可报" :value="remainingQty + ' 件'" />
    </van-cell-group>
    <van-cell-group inset class="qty-card">
      <van-cell title="本次实际完工数量" value="晚间日结填写" />
      <div class="qty-stepper">
        <van-button round size="large" @click="changeQty(-10)">-10</van-button>
        <van-button round size="large" @click="changeQty(-1)">-</van-button>
        <div class="qty-value"><div class="qty-num">{{ qtyInput || 0 }}</div><div class="qty-tail">件</div></div>
        <van-button round size="large" @click="changeQty(1)">+</van-button>
        <van-button round size="large" @click="changeQty(10)">+10</van-button>
      </div>
      <van-field v-model="qtyInput" type="digit" label="合格数" placeholder="请输入实际合格数" />
      <van-field v-model="defectQty" type="digit" label="次品数" placeholder="0" />
      <van-field v-model="startTime" type="datetime-local" label="开始时间" />
      <van-field v-model="endTime" type="datetime-local" label="结束时间" />
      <van-button class="fill-button" size="small" plain type="primary" @click="fillRemaining">填入剩余计划量 {{ remainingQty }}</van-button>
    </van-cell-group>
    <van-notice-bar inset left-icon="info-o" text="当前由班组长或统计员统一填报，不区分个人作业人。" />
    <div class="actions">
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

const taskId = computed(() => Number(route.query.task_id ?? 0))
const scheduleId = computed(() => Number(route.query.schedule_id ?? selectedSchedule.value?.schedule_id ?? selectedSchedule.value?.id ?? 0) || null)
const productName = computed(() => task.value?.product ? task.value.product.name + '（' + task.value.product.code + '）' : '-')
const remainingQty = computed(() => Math.max(0, Number(selectedSchedule.value?.remaining_qty ?? task.value?.qty ?? 0)))
const planText = computed(() => selectedSchedule.value?.plan_start && selectedSchedule.value?.plan_end ? selectedSchedule.value.plan_start + ' ~ ' + selectedSchedule.value.plan_end : '-')
const reportSource = computed(() => 'leader_batch')

onMounted(async () => { pending.value = pendingCount(); await loadData() })

async function loadData(): Promise<void> {
  if (!taskId.value) { showToast('缺少任务参数'); return }
  try {
    if (machineCode.value) {
      const response = await mobileMachineReportContext(machineCode.value, workDate.value)
      const body = response.data
      if (body?.code === 0) {
        const item = (body.data.tasks ?? []).find((row: any) => row.task_id === taskId.value && (!scheduleId.value || row.schedule_id === scheduleId.value))
        if (item) {
          selectedSchedule.value = item
          machineId.value = body.data.machine.id
          shiftCode.value = String(route.query.shift_code ?? body.data.default_shift ?? 'day')
        }
      }
    }
    const response = await mobileTask(taskId.value)
    const body = response.data
    if (body?.code === 0) {
      task.value = body.data
      if (!selectedSchedule.value && scheduleId.value) {
        const raw = (body.data.schedules ?? []).find((row: any) => row.id === scheduleId.value)
        if (raw) selectedSchedule.value = raw
      }
      if (!machineId.value && selectedSchedule.value?.machine_id) machineId.value = selectedSchedule.value.machine_id
    }
  } catch (error: any) { showToast(error?.response?.data?.message ?? '任务加载失败，可先暂存') }
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
.page { min-height: 100vh; padding-bottom: 40px; background: #f7f8fa; }
.task-card, .qty-card, .worker-card { margin-top: 12px; }
.qty-stepper { display: flex; align-items: center; gap: 8px; padding: 12px 16px; }
.qty-value { flex: 1; text-align: center; }
.qty-num { color: #1989fa; font-size: 34px; font-weight: 700; }
.qty-tail { color: #969799; font-size: 12px; }
.fill-button { margin: 0 16px 12px; }
.worker-card .selected { background: #ecf9ff; }
.actions { display: flex; flex-direction: column; gap: 10px; margin: 20px 16px; }
.offline-hint { color: #e6a23c; font-size: 12px; text-align: center; }
</style>
