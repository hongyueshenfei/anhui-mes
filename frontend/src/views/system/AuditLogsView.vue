<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <el-select v-model="filterModule" placeholder="模块" clearable style="width: 130px">
        <el-option label="plm" value="plm" />
        <el-option label="system" value="system" />
        <el-option label="auth" value="auth" />
      </el-select>
      <el-button type="primary" plain @click="load">查询</el-button>
      <div style="flex: 1" />
      <el-button plain @click="refresh">刷新</el-button>
    </div>

    <StaleDataNotice :visible="loadError && rows.length > 0" @retry="load" />

    <el-table v-loading="loading" :data="rows" stripe>
      <template #empty>
        <EmptyStatePanel
          :kind="loading ? 'loading' : loadError ? 'error' : filterModule ? 'filtered' : 'empty'"
          :title="loading ? '正在读取操作审计' : loadError ? '审计记录加载失败' : filterModule ? '没有匹配的操作记录' : '暂无操作记录'"
          :description="loading ? '正在读取系统操作留痕。' : loadError ? '请检查服务连接后重试；接口异常不会被当作“没有记录”。' : filterModule ? '切换模块条件，或清空筛选后查看全部记录。' : '系统管理操作发生后，会按时间顺序留存在这里。'"
          :action-label="loading ? '' : loadError ? '重新加载' : filterModule ? '清空筛选' : ''"
          @action="onEmptyAction"
        />
      </template>
      <el-table-column label="时间" width="170">
        <template #default="{ row }">{{ row.created_at }}</template>
      </el-table-column>
      <el-table-column label="操作人" width="130">
        <template #default="{ row }">{{ row.user?.name ?? '系统' }}</template>
      </el-table-column>
      <el-table-column prop="module" label="模块" width="90" />
      <el-table-column prop="action" label="动作" width="140" />
      <el-table-column label="对象" width="150">
        <template #default="{ row }">
          <span>{{ row.target_type ?? '—' }} <template v-if="row.target_id">#{{ row.target_id }}</template></span>
        </template>
      </el-table-column>
      <el-table-column label="详情" min-width="240">
        <template #default="{ row }">
          <el-tooltip :content="JSON.stringify(row.detail ?? {})" placement="top">
            <span class="detail">{{ JSON.stringify(row.detail ?? {}) }}</span>
          </el-tooltip>
        </template>
      </el-table-column>
      <el-table-column prop="ip" label="IP" width="120" />
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="perPage"
      class="pager"
      layout="total, prev, pager, next"
      :total="total"
      @change="load"
    />
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { systemApi } from '@/api/system'
import EmptyStatePanel from '@/components/common/EmptyStatePanel.vue'
import StaleDataNotice from '@/components/common/StaleDataNotice.vue'
import type { AuditLog } from '@/types'

const loading = ref(true)
const rows = ref<AuditLog[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const filterModule = ref('')
const loadError = ref(false)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    const result = await systemApi.listAuditLogs({
      page: page.value,
      per_page: perPage.value,
      module: filterModule.value || undefined,
    })
    if (Array.isArray(result)) {
      rows.value = result as AuditLog[]
      total.value = result.length
    } else {
      rows.value = result.data
      total.value = result.total
    }
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function onEmptyAction(): void {
  if (loadError.value) {
    void load()
  } else if (filterModule.value) {
    filterModule.value = ''
    page.value = 1
    void load()
  }
}

function refresh(): void {
  void load()
}

onMounted(load)
</script>

<style scoped>
.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.detail {
  display: block;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  color: #6b7280;
}
</style>
