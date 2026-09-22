<template>
  <el-dialog v-model="visible" title="自动排产（约束算法）" width="560px">
    <el-alert type="info" :closable="false" class="mb-12">
      提交后由约束排产算法异步计算（100 个任务 ≤ 30 秒）。本次会处理待排任务，以及已排但未开工且不在冻结窗口内的任务；已开工、已有报工或冻结任务不会被自动移动。结果可整版接受或放弃；算法服务不可用时可使用手工排产。
    </el-alert>

    <div class="auto-body">
      <!-- 空闲态：提交 -->
      <div v-if="state === 'idle'" class="auto-center">
        <el-button type="primary" size="large" :loading="submitting" @click="onSubmit">
          <el-icon><MagicStick /></el-icon>一键自动排
        </el-button>
        <div v-if="lastResult" class="last-result">
          上次：job <b>{{ lastResult.job_id }}</b> · {{ lastResult.task_count }} 任务
        </div>
      </div>

      <!-- 求解中：轮询 -->
      <div v-else-if="state === 'polling'" class="auto-center">
        <el-progress type="circle" :percentage="pollPercent" :width="90" />
        <div class="poll-status">求解中（{{ pollPercent }}%）… {{ pollElapsed }}s</div>
      </div>

      <!-- 完成态：结果与接受 -->
      <div v-else-if="state === 'done' && pollResult" class="auto-result">
        <el-alert v-if="submitExceptions.length" type="warning" :closable="false" show-icon class="mb-12" title="异常任务（未纳入本次自动排产）">
          <div class="exception-list">
            <div v-for="item in submitExceptions" :key="`${item.task_id}-${item.code}`" class="exception-item">
              <b>{{ item.task_no }}</b><span>{{ item.reason }}</span>
            </div>
          </div>
        </el-alert>
        <el-alert
          v-if="!assignments.length && submitExceptions.length"
          type="info"
          :closable="false"
          show-icon
          class="mb-12"
          title="本次未执行算法排产"
        >
          当前没有可提交的正常未开工任务，算法不会提交空任务；请先处理上面的异常任务，或补充可排任务后再执行。
        </el-alert>

        <el-descriptions :column="3" border size="small" class="mb-12">
          <el-descriptions-item label="求解算法">
            <el-tag size="small" type="success">{{ algorithmLabel }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="完工周期">{{ metaText('makespan_hours') }}h</el-descriptions-item>
          <el-descriptions-item label="总拖期">{{ metaText('total_tardiness_hours') }}h</el-descriptions-item>
        </el-descriptions>

        <div v-if="assignments.length" class="affected-title">建议排程（{{ assignments.length }} 任务）</div>
        <el-table v-if="assignments.length" :data="assignments" size="small" border max-height="260" class="mb-12">
          <el-table-column prop="task_id" label="任务ID" width="70" align="center" />
          <el-table-column label="机台" width="90">
            <template #default="{ row }">{{ machineCode(row.machine_id) }}</template>
          </el-table-column>
          <el-table-column prop="plan_start" label="计划开始" min-width="150" />
          <el-table-column label="时长" width="80" align="center">
            <template #default="{ row }">{{ row.duration_minutes ? row.duration_minutes + 'min' : '-' }}</template>
          </el-table-column>
        </el-table>

        <el-alert v-if="acceptError" type="error" :closable="false" show-icon class="mb-12" title="接受失败">
          {{ acceptError }}
        </el-alert>

        <el-empty v-else description="没有可提交算法排产的正常任务" :image-size="70" class="empty-result" />

        <div class="auto-actions">
          <el-button @click="state = 'idle'">{{ assignments.length ? '放弃建议' : '返回' }}</el-button>
          <template v-if="assignments.length">
            <el-button type="warning" @click="acceptPartial">局部采纳</el-button>
            <el-button type="primary" :loading="accepting" @click="acceptFull">
              <el-icon><Select /></el-icon>整版接受
            </el-button>
          </template>
        </div>
      </div>

      <!-- 失败态 -->
      <div v-else class="auto-center">
        <el-result icon="error" title="算法排产失败" :sub-title="pollResult?.error ?? '未返回失败原因'">
          <template #extra>
            <el-button type="primary" @click="state = 'idle'">返回（可使用手工排产）</el-button>
          </template>
        </el-result>
      </div>
    </div>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { MagicStick, Select } from '@element-plus/icons-vue'
import { scheduleApi } from '@/api/schedule'
import { getApiErrorMessage } from '@/api/axios'
import type { AutoPollResult, AutoScheduleException } from '@/types/schedule'

const props = defineProps<{
  modelValue?: boolean
  machines: Array<{ id: number; code: string; name: string }>
}>()

const emit = defineEmits<{ (e: 'update:modelValue', v: boolean): void; (e: 'accepted'): void }>()

const visible = ref(false)
const state = ref<'idle' | 'polling' | 'done' | 'failed'>('idle')
const submitting = ref(false)
const accepting = ref(false)
const pollResult = ref<AutoPollResult | null>(null)
const submitExceptions = ref<AutoScheduleException[]>([])
const acceptError = ref('')
const lastResult = ref<{ job_id: string; task_count: number } | null>(null)
const pollPercent = ref(5)
const pollElapsed = ref(0)
let pollTimer: ReturnType<typeof setInterval> | null = null
let jobId = ''

const machineMap = computed(() => new Map(props.machines.map((m) => [m.id, m.code])))
const assignments = computed(() => pollResult.value?.solution?.assignments ?? [])
const algorithmLabel = computed(() => pollResult.value?.meta?.algorithm?.toString() ?? '-')

function metaText(key: string): string {
  const v = pollResult.value?.meta?.[key]
  return v === undefined || v === null ? '-' : String(v)
}

function machineCode(id: number): string {
  return machineMap.value.get(id) ?? `#${id}`
}

watchVisible()
function watchVisible(): void {
  // 通过 prop 变更控制
}

function open(): void {
  visible.value = true
  state.value = 'idle'
  pollResult.value = null
  submitExceptions.value = []
  lastResult.value = null
  jobId = ''
  acceptError.value = ''
}
function close(): void {
  visible.value = false
  stopPolling()
}
defineExpose({ open, close })

async function onSubmit(): Promise<void> {
  submitting.value = true
  acceptError.value = ''
  try {
    const result = await scheduleApi.autoSubmit()
    submitExceptions.value = result.exceptions ?? []
    if (!result.job_id) {
      pollResult.value = {
        job_id: '',
        status: 'done',
        solution: { assignments: [], meta: {} },
        meta: { algorithm: '未提交', makespan_hours: 0, total_tardiness_hours: 0 },
        error: null,
      }
      state.value = 'done'
      return
    }
    jobId = result.job_id
    lastResult.value = { job_id: jobId, task_count: result.task_count }
    state.value = 'polling'
    pollPercent.value = 8
    pollElapsed.value = 0
    startPolling()
  } catch (error) {
    state.value = 'failed'
    pollResult.value = { job_id: '', status: 'failed', solution: null, meta: null, error: getApiErrorMessage(error, '自动排产提交失败，请检查算法服务连接') }
  } finally {
    submitting.value = false
  }
}

function startPolling(): void {
  stopPolling()
  pollTimer = setInterval(async () => {
    pollElapsed.value += 1
    pollPercent.value = Math.min(90, pollPercent.value + Math.max(2, (100 - pollPercent.value) / 10))
    try {
      const result = await scheduleApi.autoPoll(jobId)
      pollResult.value = result
      if (result.status === 'done') {
        pollPercent.value = 100
        stopPolling()
        state.value = 'done'
      } else if (result.status === 'failed') {
        stopPolling()
        state.value = 'failed'
      }
    } catch (error) {
      stopPolling()
      state.value = 'failed'
      pollResult.value = { job_id: jobId, status: 'failed', solution: null, meta: null, error: getApiErrorMessage(error, '排产结果查询失败，请检查算法服务连接') }
    }
  }, 1000)
}

function stopPolling(): void {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function acceptFull(): Promise<void> {
  await doAccept('full')
}

async function acceptPartial(): Promise<void> {
  await doAccept('partial')
}

async function doAccept(mode: 'full' | 'partial'): Promise<void> {
  accepting.value = true
  try {
    const result = await scheduleApi.autoAccept({
      job_id: jobId,
      mode,
      assignments: assignments.value.map((a) => ({
        task_id: a.task_id,
        machine_id: a.machine_id,
        plan_start: a.plan_start,
      })),
    })
    const machineAdjustments = (result.adjusted_assignments ?? []).filter((item) => item.original_machine_code || item.adjusted_machine_code)
    if (machineAdjustments.length) {
      ElMessage.warning(`已接受排产，并按当前模具适配关系调整 ${machineAdjustments.length} 个任务机台`)
    } else {
      ElMessage.success(mode === 'full' ? '排产结果已整版接受（任务已锁定）' : '已局部采纳选中结果')
    }
    visible.value = false
    emit('accepted')
  } catch (error) {
    acceptError.value = getApiErrorMessage(error, '排产结果接受失败，请检查排产冲突后重试')
  } finally {
    accepting.value = false
  }
}

onBeforeUnmount(stopPolling)
</script>

<style scoped>
.mb-12 {
  margin-bottom: 12px;
}
.auto-body {
  min-height: 200px;
}
.auto-center {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
  padding: 30px 0;
}
.last-result {
  font-size: 12px;
  color: #94a3b8;
}
.poll-status {
  color: #64748b;
}
.affected-title {
  font-weight: 600;
  margin-bottom: 6px;
  color: #334155;
}
.exception-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.exception-item {
  display: flex;
  gap: 8px;
  line-height: 1.5;
  word-break: break-all;
}
.exception-item b {
  flex: 0 0 auto;
}
.empty-result {
  padding: 4px 0 12px;
}
.auto-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
