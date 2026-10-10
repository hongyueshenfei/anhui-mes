<template>
  <div class="page">
    <van-nav-bar title="我的填报记录" left-arrow @click-left="router.back()" />

    <div v-if="loading && reports.length === 0" class="request-state" role="status" aria-live="polite">
      <van-loading vertical>正在读取报工记录</van-loading>
    </div>
    <van-notice-bar v-if="loading && reports.length > 0" class="state-notice" type="primary" left-icon="replay" text="正在更新，页面暂时显示本次读取前的记录。" />
    <van-notice-bar
      v-if="loadError && reports.length > 0"
      class="state-notice"
      type="warning"
      left-icon="warning-o"
      :text="`${loadError}，当前仍显示上次成功读取的记录。`"
    >
      <template #right-icon><van-button size="mini" plain type="warning" @click="load">重试</van-button></template>
    </van-notice-bar>
    <van-empty v-if="!loading && loadError && reports.length === 0" image="network" :description="loadError">
      <van-button round type="primary" size="small" @click="load">重新加载</van-button>
    </van-empty>
    <van-cell-group inset v-for="r in reports" :key="r.id" class="card">
      <van-cell
        :title="r.task?.task_no ?? '-'"
        :label="`${r.task?.product?.name ?? ''} · ${(r.report_time ?? '').slice(0, 16)}`"
      >
        <template #value>
          <van-tag :type="r.qty >= 0 ? 'success' : 'danger'" plain>{{ r.qty >= 0 ? '+' + r.qty : r.qty }} 件</van-tag>
        </template>
      </van-cell>
      <van-cell v-if="r.remark" title="备注" :value="r.remark" />
    </van-cell-group>

    <van-empty v-if="!loading && !loadError && reports.length === 0" description="暂无报工记录" />
    <MobileTabBar :can-report="canOperateReports" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { mobileReportsMine } from '@/api/mobile'
import MobileTabBar from '@/components/MobileTabBar.vue'
import { canCurrentUserOperateReports } from '@/utils/mobilePermissions'

const router = useRouter()
const reports = ref<any[]>([])
const loading = ref(true)
const loadError = ref('')
const canOperateReports = computed(canCurrentUserOperateReports)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const resp = await mobileReportsMine()
    const body = resp.data
    if (body?.code !== 0) throw new Error(body?.message || '报工记录读取失败，请稍后重试')
    reports.value = body.data ?? []
  } catch (error: any) {
    loadError.value = error?.response?.data?.message ?? error?.message ?? '报工记录读取失败，请检查网络后重试'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding-bottom: calc(70px + env(safe-area-inset-bottom));
  background: var(--hy-canvas);
}
.card {
  margin: 12px 12px 0;
}
.request-state {
  display: grid;
  min-height: 180px;
  place-items: center;
  color: var(--hy-muted);
}
.state-notice { margin: 12px; border-radius: 8px; }
</style>
