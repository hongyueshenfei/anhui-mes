<template>
  <div class="page">
    <van-nav-bar title="任务详情" left-arrow @click-left="router.back()" />

    <div v-if="loading && !task" class="request-state" role="status" aria-live="polite">
      <van-loading vertical>正在读取任务详情</van-loading>
    </div>
    <van-empty v-else-if="loadError && !task" image="network" :description="loadError">
      <van-button round type="primary" size="small" @click="load">重新加载</van-button>
    </van-empty>
    <van-notice-bar v-if="loading && task" type="primary" left-icon="replay" text="正在刷新，当前显示上次成功读取的详情。" />
    <van-notice-bar v-if="loadError && task" type="warning" left-icon="warning-o" :text="`${loadError}，当前展示的是上次成功读取的详情。`">
      <template #right-icon><van-button size="mini" plain type="warning" @click="load">重试</van-button></template>
    </van-notice-bar>

    <van-cell-group inset v-if="task" class="card">
      <van-cell title="任务号" :value="task.task_no" />
      <van-cell title="产品">
        <template #value>{{ task.product?.name }}（{{ task.product?.code }}）</template>
      </van-cell>
      <van-cell title="数量" :value="`${task.qty} 件`" />
      <van-cell title="完成量" :value="`${task.report_summary?.done_qty ?? task.done_qty} 件`" />
      <van-cell title="次品" :value="`${task.report_summary?.defect_qty ?? 0} 件`" />
      <van-cell title="已入库" :value="`${task.report_summary?.stocked_qty ?? 0} 件`" />
      <van-cell title="状态">
        <van-tag :type="statusType(task.status)" plain>{{ statusText(task.status) }}</van-tag>
      </van-cell>
      <van-cell title="计划起止">
        <template #value>
          <div class="time">{{ task.plan_start ?? '-' }}</div>
          <div class="time">{{ task.plan_end ?? '-' }}</div>
        </template>
      </van-cell>
      <van-cell title="进度偏差" :value="`${task.deviation_pct ?? 0}%`" />
    </van-cell-group>

    <van-cell-group v-if="task" inset class="card" title="工序排程">
      <van-cell v-for="s in task?.schedules ?? []" :key="s.id" :title="s.op_name ?? s.op_type" :label="s.machine_code ?? s.team_name ?? ''">
        <template #value>
          <span class="time">{{ (s.plan_start ?? '').slice(5, 16) }} ~ {{ (s.plan_end ?? '').slice(5, 16) }}</span>
        </template>
      </van-cell>
      <van-empty v-if="!task?.schedules?.length" description="暂无排程" :image-size="50" />
    </van-cell-group>

    <div v-if="canOperateReports" class="actions">
      <van-button round block type="primary" size="large" @click="goReport">
        去报工
      </van-button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showToast } from 'vant'
import { mobileTask } from '@/api/mobile'

const route = useRoute()
const router = useRouter()
const task = ref<any>(null)
const loading = ref(true)
const loadError = ref('')
const canOperateReports = (() => {
  try {
    const user = JSON.parse(localStorage.getItem('hy_mobile_user') ?? 'null') as { job_type?: string; roles?: string[] } | null
    const allowed = ['admin', 'leader', 'statistician']
    return allowed.includes(user?.job_type ?? '') || (user?.roles ?? []).some((role) => allowed.includes(role))
  } catch {
    return false
  }
})()

onMounted(load)

async function load(): Promise<void> {
  loadError.value = ''
  const id = Number(route.params.id)
  if (!id) {
    loadError.value = '缺少任务编号，请从任务列表重新进入'
    loading.value = false
    return
  }
  loading.value = true
  try {
    const resp = await mobileTask(id)
    const body = resp.data
    if (body?.code !== 0) throw new Error(body?.message || '任务读取失败，请稍后重试')
    task.value = body.data
  } catch (error: any) {
    loadError.value = error?.response?.data?.message ?? error?.message ?? '任务读取失败，请检查网络后重试'
  } finally {
    loading.value = false
  }
}

function goReport(): void {
  const schedule = task.value?.schedules?.find((item: any) => item.machine?.code || item.machine_code)
  const machineCode = schedule?.machine?.code || schedule?.machine_code
  if (!machineCode) {
    showToast('任务尚未绑定机台，无法进入扫码报工')
    return
  }
  router.push({ path: '/report/qr', query: { machine_code: machineCode } })
}

function statusText(s: string): string {
  return ({ unscheduled: '待排', scheduled: '已排', in_progress: '生产中', paused: '暂停', done: '完成' } as Record<string, string>)[s] ?? s
}
function statusType(s: string): 'primary' | 'success' | 'warning' | 'default' {
  return ({ unscheduled: 'default', scheduled: 'primary', in_progress: 'primary', paused: 'warning', done: 'success' } as Record<string, 'primary' | 'success' | 'warning' | 'default'>)[s] ?? 'default'
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  background: var(--hy-canvas);
  padding-bottom: 40px;
}
.request-state { display: grid; min-height: 180px; place-items: center; color: var(--hy-muted); }
.card {
  margin-top: 12px;
}
.time {
  font-size: 12px;
  color: #969799;
  text-align: right;
}
.actions {
  margin: 20px 16px;
}
</style>
