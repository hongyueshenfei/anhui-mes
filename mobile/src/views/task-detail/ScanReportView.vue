<template>
  <div class="page">
    <van-nav-bar title="机台扫码报工" left-arrow @click-left="router.back()" />

    <van-cell-group inset class="scan-card">
      <van-field
        v-model="machineCode"
        center
        clearable
        label="机台码"
        placeholder="扫码或输入机台码（如 I-01）"
        @keyup.enter="resolveMachine"
      >
        <template #button>
          <van-button size="small" type="primary" :loading="loading" @click="resolveMachine">查询</van-button>
        </template>
      </van-field>
      <van-field v-model="workDate" type="date" label="作业日期" :max="today" @change="loadContext" />
      <div class="scan-hint">排产确定的当日任务固定在这里；机台当天有多个任务时，只能从下面选择。</div>
    </van-cell-group>

    <van-cell-group v-if="context" inset class="machine-card">
      <van-cell title="机台" :value="`${context.machine.name}（${context.machine.code}）`" />
      <van-cell title="车间/班制" :value="`${context.machine.workshop_name || '-'} · ${shiftLabel}`" />
    </van-cell-group>

    <van-cell-group v-if="context" inset class="task-card" title="当日固定任务">
      <van-empty v-if="context.tasks.length === 0" description="当天没有已排产任务" :image-size="50" />
      <van-cell
        v-for="item in context.tasks"
        :key="item.schedule_id"
        :title="`${item.operation.seq} · ${item.operation.name}`"
        :label="taskLabel(item)"
        :class="{ disabled: !item.can_report }"
        :is-link="item.can_report"
        @click="item.can_report && goReport(item)"
      >
        <template #value>
          <div class="task-value">
            <span>{{ item.reported_qty }}/{{ item.planned_qty }}</span>
            <van-tag :type="item.can_report ? 'primary' : 'success'" plain>
              {{ item.can_report ? `剩余 ${item.remaining_qty}` : '已完成' }}
            </van-tag>
          </div>
        </template>
      </van-cell>
    </van-cell-group>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showToast } from 'vant'
import { mobileMachineReportContext } from '@/api/mobile'

interface DailyTask {
  schedule_id: number
  task_id: number
  task_no: string
  order_no?: string | null
  product?: { code: string; name: string } | null
  operation: { seq: number; name: string }
  plan_start: string
  plan_end: string
  planned_qty: number
  reported_qty: number
  remaining_qty: number
  can_report: boolean
}
interface ReportContext {
  machine: { id: number; code: string; name: string; workshop_name?: string | null }
  work_date: string
  default_shift: string
  shift_options: Array<{ code: string; name: string }>
  tasks: DailyTask[]
}

const route = useRoute()
const router = useRouter()
const machineCode = ref(String(route.query.machine_code ?? ''))
const today = localDate()
const workDate = ref(String(route.query.date ?? today))
const context = ref<ReportContext | null>(null)
const loading = ref(false)

const shiftLabel = computed(() => context.value?.shift_options.find((s) => s.code === context.value?.default_shift)?.name ?? '白班')

onMounted(async () => {
  if (machineCode.value) await loadContext()
  else if (route.query.task_id) {
    router.replace({ path: '/report/task', query: { task_id: String(route.query.task_id) } })
  }
})

async function resolveMachine(): Promise<void> {
  if (!machineCode.value.trim()) {
    showToast('请输入或扫描机台码')
    return
  }
  await loadContext()
}

async function loadContext(): Promise<void> {
  if (!machineCode.value.trim()) return
  loading.value = true
  try {
    const resp = await mobileMachineReportContext(machineCode.value.trim(), workDate.value)
    const body = resp.data
    if (body?.code === 0) context.value = body.data
    else showToast(body?.message ?? '机台任务加载失败')
  } catch (error: any) {
    showToast(error?.response?.data?.message ?? '机台任务加载失败')
  } finally {
    loading.value = false
  }
}

function taskLabel(item: DailyTask): string {
  const product = item.product ? `${item.product.name}（${item.product.code}）` : '-'
  return `${item.task_no} · ${product} · ${item.plan_start.slice(5, 16)} ~ ${item.plan_end.slice(5, 16)}`
}

function goReport(item: DailyTask): void {
  router.push({
    path: '/report/task',
    query: {
      task_id: String(item.task_id),
      schedule_id: String(item.schedule_id),
      machine_id: String(context.value?.machine.id ?? ''),
      machine_code: machineCode.value,
      work_date: workDate.value,
      shift_code: context.value?.default_shift ?? 'day',
    },
  })
}

function localDate(): string {
  const d = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding-bottom: 60px;
  background: #f7f8fa;
}
.scan-card,
.machine-card,
.task-card {
  margin-top: 12px;
}
.scan-hint {
  padding: 8px 16px;
  color: #969799;
  font-size: 11px;
  line-height: 1.5;
}
.task-card :deep(.van-cell__label) {
  line-height: 1.5;
}
.task-value {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}
.disabled {
  opacity: 0.65;
}
</style>
