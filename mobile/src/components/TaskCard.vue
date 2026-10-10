<template>
  <article class="task-card">
    <div class="task-card-head">
      <div class="task-heading">
        <div class="task-number">{{ task.task_no }}</div>
        <div class="task-product">{{ task.product?.name ?? '未关联产品' }}</div>
      </div>
      <van-tag :type="statusType" plain>{{ statusText }}</van-tag>
    </div>

    <div class="task-meta">
      <span>产品编码 {{ task.product?.code ?? '—' }}</span>
      <span>机台 {{ machineCode }}</span>
    </div>

    <div class="progress-block" role="group" :aria-label="`生产进度 ${task.done_qty} / ${task.qty} 件`">
      <div class="progress-copy">
        <span>已完成 <strong>{{ task.done_qty.toLocaleString() }}</strong> 件</span>
        <span>任务量 {{ task.qty.toLocaleString() }} 件</span>
      </div>
      <van-progress
        :percentage="progress"
        :show-pivot="false"
        :stroke-width="6"
        :color="task.status === 'done' ? 'var(--hy-green)' : 'var(--hy-blue)'"
      />
    </div>

    <div class="task-schedule">
      <span class="schedule-label">计划时段</span>
      <span>{{ scheduleText }}</span>
    </div>

    <div class="task-actions">
      <van-button size="small" plain type="primary" @click="emit('open', task)">查看任务</van-button>
      <van-button v-if="canReport" size="small" type="primary" @click="emit('report', task)">报工</van-button>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { MobileTask } from '@/types/task'

const props = defineProps<{
  task: MobileTask
  canReport: boolean
}>()

const emit = defineEmits<{
  open: [task: MobileTask]
  report: [task: MobileTask]
}>()

const progress = computed(() => {
  if (!props.task.qty) return 0
  return Math.min(100, Math.round((props.task.done_qty / props.task.qty) * 100))
})

const machineCode = computed(() => {
  const schedule = props.task.schedules?.find((item) => item.machine_code || item.machine?.code)
  return schedule?.machine_code || schedule?.machine?.code || '未分配'
})

const scheduleText = computed(() => {
  if (!props.task.plan_start || !props.task.plan_end) return '尚未排产'
  return `${props.task.plan_start.slice(5, 16)} 至 ${props.task.plan_end.slice(5, 16)}`
})

const statusText = computed(() => ({
  unscheduled: '待排产', scheduled: '已排产', in_progress: '生产中', paused: '已暂停', done: '已完成',
} as Record<string, string>)[props.task.status] ?? props.task.status)

const statusType = computed(() => ({
  unscheduled: 'default', scheduled: 'primary', in_progress: 'primary', paused: 'warning', done: 'success',
} as Record<string, 'primary' | 'success' | 'warning' | 'default'>)[props.task.status] ?? 'default')
</script>

<style scoped>
.task-card {
  padding: 15px;
  border: 1px solid var(--hy-line);
  border-radius: 10px;
  background: var(--hy-surface);
}

.task-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.task-heading {
  min-width: 0;
}

.task-number {
  overflow: hidden;
  color: var(--hy-ink);
  font-size: 14px;
  font-weight: 650;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-product {
  margin-top: 3px;
  color: var(--hy-muted);
  font-size: 12px;
}

.task-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 14px;
  margin-top: 12px;
  color: #617582;
  font-size: 12px;
}

.progress-block {
  margin-top: 14px;
}

.progress-copy {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 7px;
  color: var(--hy-muted);
  font-size: 12px;
}

.progress-copy strong {
  color: var(--hy-ink);
  font-size: 14px;
  font-weight: 650;
}

.task-schedule {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-top: 12px;
  color: #435866;
  font-size: 12px;
}

.schedule-label {
  color: var(--hy-muted);
}

.task-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid #edf1f3;
}
</style>
