<template>
  <div class="page">
    <van-nav-bar title="今日任务" left-text="退出" @click-left="onLogout" />

    <div class="date-bar">
      <van-button size="small" @click="shiftDay(-1)">前一天</van-button>
      <div class="date-label">{{ date }}<van-tag v-if="isToday" type="success" plain class="today-tag">今天</van-tag></div>
      <van-button size="small" @click="shiftDay(1)">后一天</van-button>
    </div>

    <van-tabs v-model:active="statusTab" @change="load">
      <van-tab title="进行中" name="in_progress" />
      <van-tab title="已排产" name="scheduled" />
      <van-tab title="全部" name="" />
    </van-tabs>

    <div class="task-list">
      <van-empty v-if="!loading && tasks.length === 0" description="当天没有任务">
        <van-button round type="primary" size="small" @click="load">刷新</van-button>
      </van-empty>

      <van-swipe-cell v-for="t in tasks" :key="t.id">
        <van-cell
          :title="t.task_no"
          :label="taskLabel(t)"
          is-link
          @click="openTask(t)"
        >
          <template #value>
            <div class="task-value">
              <van-tag :type="statusType(t.status)" plain>{{ statusText(t.status) }}</van-tag>
              <van-progress
                :percentage="progressPct(t)"
                :show-pivot="false"
                :stroke-width="6"
                :color="t.status === 'done' ? '#07c160' : '#1989fa'"
                class="task-progress"
              />
              <span class="progress-text">{{ t.done_qty }}/{{ t.qty }}</span>
            </div>
          </template>
        </van-cell>
        <template #right>
          <van-button square type="primary" text="详情" @click="openTask(t)" />
          <van-button v-if="canOperateReports" square type="success" text="机台报工" @click="openReport(t)" />
        </template>
      </van-swipe-cell>
    </div>

    <van-tabbar route>
      <van-tabbar-item replace to="/tasks" icon="clock-o">今日任务</van-tabbar-item>
      <van-tabbar-item v-if="canOperateReports" replace to="/report/qr" icon="scan">扫码报工</van-tabbar-item>
      <van-tabbar-item replace to="/reports/mine" icon="records">我的填报</van-tabbar-item>
    </van-tabbar>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { showToast } from 'vant'
import { mobileTasks } from '@/api/mobile'
import { flushOfflineQueue, pendingCount } from '@/utils/offlineQueue'

interface TaskRow {
  id: number
  task_no: string
  product?: { code: string; name: string } | null
  qty: number
  done_qty: number
  status: string
  plan_start: string | null
  plan_end: string | null
  schedules?: Array<{ op_name?: string; machine_code?: string; machine?: { code?: string } }>
}

const router = useRouter()
const date = ref(new Date().toISOString().slice(0, 10))
const statusTab = ref('in_progress')
const tasks = ref<TaskRow[]>([])
const loading = ref(false)

const isToday = computed(() => date.value === new Date().toISOString().slice(0, 10))
const canOperateReports = computed(() => {
  try {
    const user = JSON.parse(localStorage.getItem('hy_mobile_user') ?? 'null') as { job_type?: string; roles?: string[] } | null
    const allowed = ['admin', 'leader', 'statistician']
    return allowed.includes(user?.job_type ?? '') || (user?.roles ?? []).some((role) => allowed.includes(role))
  } catch {
    return false
  }
})

onMounted(async () => {
  await load()
  await tryFlush()
})

function shiftDay(delta: number): void {
  const d = new Date(date.value)
  d.setDate(d.getDate() + delta)
  date.value = d.toISOString().slice(0, 10)
  load()
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const resp = await mobileTasks({ date: date.value, status: statusTab.value || undefined })
    const body = resp.data
    tasks.value = body?.code === 0 ? (body.data?.data ?? []) : []
  } catch {
    tasks.value = []
  } finally {
    loading.value = false
  }
}

async function tryFlush(): Promise<void> {
  const pending = pendingCount()
  if (pending <= 0) return
  const result = await flushOfflineQueue()
  showToast(result.failed > 0 ? `补传 ${result.sent} 条，${result.failed} 条待重试` : `离线补传 ${result.sent} 条成功`)
  load()
}

function openTask(t: TaskRow): void {
  router.push(`/task/${t.id}`)
}

function openReport(t: TaskRow): void {
  const schedule = t.schedules?.find((item) => item.machine_code || item.machine?.code)
  const machineCode = schedule?.machine_code || schedule?.machine?.code
  if (!machineCode) {
    showToast('任务尚未绑定机台，无法进入扫码报工')
    return
  }
  router.push({ path: '/report/qr', query: { machine_code: machineCode, date: date.value } })
}

function taskLabel(t: TaskRow): string {
  const product = t.product ? `${t.product.name}（${t.product.code}）` : ''
  const time = t.plan_start ? `${(t.plan_start ?? '').slice(5, 16)} ~ ${(t.plan_end ?? '').slice(5, 16)}` : '未排计划'
  return `${product} · ${time}`
}

function progressPct(t: TaskRow): number {
  if (!t.qty) return 0
  return Math.min(100, Math.round((t.done_qty / t.qty) * 100))
}

function statusText(s: string): string {
  return ({ unscheduled: '待排', scheduled: '已排', in_progress: '生产中', paused: '暂停', done: '完成' } as Record<string, string>)[s] ?? s
}
function statusType(s: string): 'primary' | 'success' | 'warning' | 'default' {
  return ({ unscheduled: 'default', scheduled: 'primary', in_progress: 'primary', paused: 'warning', done: 'success' } as Record<string, 'primary' | 'success' | 'warning' | 'default'>)[s] ?? 'default'
}

function onLogout(): void {
  localStorage.removeItem('hy_mobile_token')
  localStorage.removeItem('hy_mobile_user')
  router.replace('/login')
}
</script>

<style scoped>
.page {
  padding-bottom: 60px;
  background: #f7f8fa;
  min-height: 100vh;
}
.date-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  background: #fff;
}
.date-label {
  font-weight: 600;
}
.today-tag {
  margin-left: 6px;
}
.task-list {
  margin-top: 4px;
}
.task-value {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  width: 120px;
}
.task-progress {
  width: 100%;
}
.progress-text {
  font-size: 11px;
  color: #969799;
}
</style>
