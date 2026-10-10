<template>
  <div class="page">
    <van-nav-bar title="今日任务" left-text="退出" @click-left="onLogout" />

    <div class="date-bar">
      <van-button size="small" @click="shiftDay(-1)">前一天</van-button>
      <div class="date-label">{{ date }}<van-tag v-if="isToday" type="success" plain class="today-tag">今天</van-tag></div>
      <van-button size="small" @click="shiftDay(1)">后一天</van-button>
    </div>

    <van-tabs v-model:active="statusTab" @change="onStatusChange">
      <van-tab title="进行中" name="in_progress" />
      <van-tab title="已排产" name="scheduled" />
      <van-tab title="全部" name="" />
    </van-tabs>

    <div class="task-list" aria-label="生产任务列表">
      <div v-if="loading && tasks.length === 0" class="request-state" role="status" aria-live="polite">
        <van-loading vertical>正在读取任务</van-loading>
      </div>
      <van-notice-bar v-if="loading && tasks.length > 0" type="primary" left-icon="replay" text="正在更新任务，页面暂时显示本次查询前的记录。" />
      <van-notice-bar
        v-if="loadError && tasks.length > 0"
        class="state-notice"
        type="warning"
        left-icon="warning-o"
        :text="`${loadError}，当前仍显示上次成功读取的任务。`"
      >
        <template #right-icon><van-button size="mini" plain type="warning" @click="load">重试</van-button></template>
      </van-notice-bar>
      <van-empty v-if="!loading && loadError && tasks.length === 0" image="network" :description="loadError">
        <van-button round type="primary" size="small" @click="load">重新加载</van-button>
      </van-empty>
      <van-empty v-else-if="!loading && !loadError && tasks.length === 0" description="当天没有任务">
        <van-button round type="primary" size="small" @click="load">刷新</van-button>
      </van-empty>

      <TaskCard
        v-for="task in tasks"
        :key="task.id"
        :task="task"
        :can-report="canOperateReports"
        @open="openTask"
        @report="openReport"
      />
    </div>

    <MobileTabBar :can-report="canOperateReports" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { showToast } from 'vant'
import { mobileTasks } from '@/api/mobile'
import { flushOfflineQueue, pendingCount } from '@/utils/offlineQueue'
import type { MobileTask } from '@/types/task'
import TaskCard from '@/components/TaskCard.vue'
import MobileTabBar from '@/components/MobileTabBar.vue'
import { canCurrentUserOperateReports } from '@/utils/mobilePermissions'

const router = useRouter()
const date = ref(new Date().toISOString().slice(0, 10))
const statusTab = ref('in_progress')
const tasks = ref<MobileTask[]>([])
const loading = ref(true)
const loadError = ref('')

const isToday = computed(() => date.value === new Date().toISOString().slice(0, 10))
const canOperateReports = computed(canCurrentUserOperateReports)

onMounted(async () => {
  await load()
  await tryFlush()
})

function shiftDay(delta: number): void {
  const d = new Date(date.value)
  d.setDate(d.getDate() + delta)
  date.value = d.toISOString().slice(0, 10)
  tasks.value = []
  load()
}

function onStatusChange(): void {
  tasks.value = []
  load()
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const resp = await mobileTasks({ date: date.value, status: statusTab.value || undefined })
    const body = resp.data
    if (body?.code !== 0) throw new Error(body?.message || '任务读取失败，请稍后重试')
    tasks.value = body.data?.data ?? []
  } catch (error: any) {
    loadError.value = error?.response?.data?.message ?? error?.message ?? '任务读取失败，请检查网络后重试'
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

function openTask(t: MobileTask): void {
  router.push(`/task/${t.id}`)
}

function openReport(t: MobileTask): void {
  const schedule = t.schedules?.find((item) => item.machine_code || item.machine?.code)
  const machineCode = schedule?.machine_code || schedule?.machine?.code
  if (!machineCode) {
    showToast('任务尚未绑定机台，无法进入扫码报工')
    return
  }
  router.push({ path: '/report/qr', query: { machine_code: machineCode, date: date.value } })
}

function onLogout(): void {
  localStorage.removeItem('hy_mobile_token')
  localStorage.removeItem('hy_mobile_user')
  router.replace('/login')
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding-bottom: calc(74px + env(safe-area-inset-bottom));
  background: var(--hy-canvas);
}
.date-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid var(--hy-line);
  background: #fff;
}
.date-label {
  color: var(--hy-ink);
  font-weight: 600;
}
.today-tag {
  margin-left: 6px;
}
.task-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 12px;
}
.request-state {
  display: grid;
  min-height: 160px;
  place-items: center;
  color: var(--hy-muted);
}
.state-notice { border-radius: 8px; }
</style>
