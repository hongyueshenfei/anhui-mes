<template>
  <div class="page">
    <van-nav-bar title="我的填报记录" left-arrow @click-left="router.back()" />

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

    <van-empty v-if="!loading && reports.length === 0" description="暂无报工记录" />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { mobileReportsMine } from '@/api/mobile'

const router = useRouter()
const reports = ref<any[]>([])
const loading = ref(false)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  try {
    const resp = await mobileReportsMine()
    const body = resp.data
    reports.value = body?.code === 0 ? (body.data ?? []) : []
  } catch {
    reports.value = []
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  background: #f7f8fa;
}
.card {
  margin-top: 12px;
}
</style>
