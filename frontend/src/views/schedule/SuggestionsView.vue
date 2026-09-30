<template>
  <el-card shadow="never">
    <template #header>
      <div class="page-header">
        <div>
          <span class="title">顺延建议（APS 建议层）</span>
          <el-text size="small" type="info" class="sub">
            报工滞后导致预计破交期/偏差超阈值时系统生成——采纳才生效，绝不自动改排程
          </el-text>
        </div>
        <div class="actions">
          <el-radio-group v-model="statusFilter" size="small" @change="load">
            <el-radio-button value="pending">待处理</el-radio-button>
            <el-radio-button value="all">全部</el-radio-button>
          </el-radio-group>
          <el-button size="small" type="primary" :loading="refreshing" @click="refreshAll">全量刷新建议</el-button>
          <el-button size="small" :icon="Refresh" @click="load">刷新</el-button>
        </div>
      </div>
    </template>

    <el-table v-loading="loading" :data="items" size="small" border stripe row-key="id">
      <el-table-column label="任务" width="120">
        <template #default="{ row }">{{ row.task?.task_no ?? `#${row.task_id}` }}</template>
      </el-table-column>
      <el-table-column label="生成原因" min-width="220">
        <template #default="{ row }">
          <span class="reason">{{ row.reason }}</span>
        </template>
      </el-table-column>
      <el-table-column label="延期订单" min-width="200">
        <template #default="{ row }">
          <template v-if="(row.delayed_orders ?? []).length">
            <div v-for="(o, i) in row.delayed_orders" :key="i" class="order-line">
              {{ o.order_no ?? '-' }} · {{ o.product_name ?? '-' }}
              <el-text v-if="o.delivery_date" size="small" type="danger">
                交期 {{ o.delivery_date }} → 预计 {{ short(o.forecast_end) }}
              </el-text>
            </div>
          </template>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="受影响工序" width="100" align="center">
        <template #default="{ row }">
          <el-popover placement="left" :width="380" trigger="hover">
            <template #reference>
              <el-link type="primary" :underline="false">{{ (row.affected ?? []).length }} 道</el-link>
            </template>
            <el-table :data="row.affected" size="small" max-height="240">
              <el-table-column prop="seq" label="序" width="40" />
              <el-table-column prop="op_name" label="工序" width="90" />
              <el-table-column label="原计划结束" min-width="120">
                <template #default="{ row: a }">{{ short(a.plan_end) }}</template>
              </el-table-column>
              <el-table-column label="滚动预计" min-width="120">
                <template #default="{ row: a }">
                  <el-text type="warning">{{ short(a.forecast_end) }}</el-text>
                </template>
              </el-table-column>
            </el-table>
          </el-popover>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90" align="center">
        <template #default="{ row }">
          <el-tag size="small" :type="statusTag(row.status)">{{ statusLabel(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="生成时间" width="150">
        <template #default="{ row }">{{ row.created_at ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <template v-if="row.status === 'pending'">
            <el-button size="small" type="primary" :loading="busy === row.id" @click="accept(row)">采纳</el-button>
            <el-button size="small" :disabled="busy === row.id" @click="dismiss(row)">驳回</el-button>
          </template>
          <span v-else class="processed-at">{{ row.processed_at ?? '-' }}</span>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty :description="statusFilter === 'pending' ? '暂无待处理的顺延建议' : '暂无建议记录'" :image-size="70" />
      </template>
    </el-table>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { scheduleApi } from '@/api/schedule'
import type { ScheduleSuggestion } from '@/types/schedule'

const items = ref<ScheduleSuggestion[]>([])
const loading = ref(false)
const refreshing = ref(false)
const busy = ref<number | null>(null)
const statusFilter = ref<'pending' | 'all'>('pending')

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  try {
    const data = await scheduleApi.suggestions(statusFilter.value)
    items.value = Array.isArray(data) ? data : []
  } catch {
    items.value = []
  } finally {
    loading.value = false
  }
}

async function refreshAll(): Promise<void> {
  refreshing.value = true
  try {
    const result = await scheduleApi.refreshSuggestions()
    ElMessage.success(`顺延建议已全量刷新，共检查 ${result.task_count} 个任务`)
    await load()
  } catch (e) {
    ElMessage.error(e instanceof Error ? e.message : '刷新顺延建议失败')
  } finally {
    refreshing.value = false
  }
}

async function accept(row: ScheduleSuggestion): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `采纳后，任务 ${row.task?.task_no ?? ''} 的未完工工序计划将对齐滚动预计，采纳后仍可手动调整。确认采纳？`,
      '采纳顺延建议',
      { type: 'warning', confirmButtonText: '采纳', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  busy.value = row.id
  try {
    const r = await scheduleApi.acceptSuggestion(row.id)
    ElMessage.success(`已采纳：${r.aligned} 道工序计划已对齐滚动预计`)
    await load()
  } catch (e) {
    ElMessage.error(e instanceof Error ? e.message : '采纳失败')
  } finally {
    busy.value = null
  }
}

async function dismiss(row: ScheduleSuggestion): Promise<void> {
  busy.value = row.id
  try {
    await scheduleApi.dismissSuggestion(row.id)
    ElMessage.info('已驳回（留痕，不改排程）')
    await load()
  } catch (e) {
    ElMessage.error(e instanceof Error ? e.message : '驳回失败')
  } finally {
    busy.value = null
  }
}

function statusTag(s: string): 'warning' | 'success' | 'info' {
  return ({ pending: 'warning', accepted: 'success', dismissed: 'info', expired: 'info' }[s] ?? 'info') as 'warning' | 'success' | 'info'
}
function statusLabel(s: string): string {
  return { pending: '待处理', accepted: '已采纳', dismissed: '已驳回', expired: '已自动失效' }[s] ?? s
}
function short(v: string | null): string {
  return v ? v.slice(0, 16) : '-'
}
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.title {
  font-weight: 600;
  margin-right: 10px;
}
.sub {
  font-size: 12px;
}
.actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
.reason {
  color: #b45309;
}
.order-line {
  line-height: 1.7;
}
.processed-at {
  font-size: 12px;
  color: #94a3b8;
}
</style>
