<template>
  <div class="order-overview">
    <!-- 分区统计 -->
    <el-row :gutter="12" class="group-row">
      <el-col :span="6" v-for="g in groupCards" :key="g.key">
        <el-card shadow="never" class="group-card" :class="g.tone">
          <div class="group-count">{{ g.count }} <span class="group-unit">单</span></div>
          <div class="group-label">{{ g.label }}</div>
          <div class="group-qty">合计 {{ g.qty.toLocaleString() }} 件</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 筛选 -->
    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>订单进度明细</span>
          <div class="filters">
            <el-input v-model="keyword" placeholder="订单号/客户" clearable size="small" style="width: 180px" @input="load" />
            <el-select v-model="dueFlag" size="small" style="width: 130px" @change="load">
              <el-option label="全部状态" value="" />
              <el-option label="逾期（红）" value="overdue" />
              <el-option label="临期 3 天（黄）" value="due_soon" />
              <el-option label="正常" value="normal" />
            </el-select>
          </div>
        </div>
      </template>
      <el-table :data="items" size="small" stripe>
        <el-table-column prop="order_no" label="订单号" width="160" fixed />
        <el-table-column prop="customer_name" label="客户" min-width="130" />
        <el-table-column label="紧急" width="70" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.priority === 1" type="danger" size="small" effect="dark">紧急</el-tag>
            <span v-else-if="row.priority === 2" class="prio2">重要</span>
            <span v-else class="prio-n">-</span>
          </template>
        </el-table-column>
        <el-table-column label="交期" width="170">
          <template #default="{ row }">
            <span :class="{ 'text-overdue': row.due_flag === 'overdue', 'text-soon': row.due_flag === 'due_soon' }">{{ row.delivery_date ?? '-' }}</span>
            <el-tag v-if="row.due_flag === 'overdue'" type="danger" size="small" class="ml4">逾期 {{ row.delay_days }} 天</el-tag>
            <el-tag v-else-if="row.due_flag === 'due_soon'" type="warning" size="small" class="ml4">临期</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="数量" width="110" align="right">
          <template #default="{ row }">{{ row.total_qty.toLocaleString() }}</template>
        </el-table-column>
        <el-table-column label="完成进度" min-width="180">
          <template #default="{ row }">
            <el-progress :percentage="row.progress_pct" :stroke-width="9" :color="row.due_flag === 'overdue' ? '#f56c6c' : row.due_flag === 'due_soon' ? '#e6a23c' : '#67c23a'" />
            <span class="done-qty">{{ row.done_qty.toLocaleString() }} / {{ row.total_qty.toLocaleString() }}</span>
          </template>
        </el-table-column>
        <el-table-column label="任务状态" width="110">
          <template #default="{ row }">
            <el-tag :type="taskTag(row.task_status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="tasks_count" label="任务数" width="70" align="center" />
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { dashboardApi } from '@/api/dashboard'
import type { OrderProgress } from '@/types/dashboard'

const data = ref<{ items: OrderProgress[]; groups: { [k: string]: { count: number; qty: number } } } | null>(null)
const keyword = ref('')
const dueFlag = ref('')

const items = computed(() => data.value?.items ?? [])
const groupCards = computed(() => {
  const g = data.value?.groups
  return [
    { key: 'pending', label: '待排', count: g?.pending.count ?? 0, qty: g?.pending.qty ?? 0, tone: 'gray' },
    { key: 'in_progress', label: '在产（含已排）', count: g?.in_progress.count ?? 0, qty: g?.in_progress.qty ?? 0, tone: 'blue' },
    { key: 'overdue', label: '逾期', count: g?.overdue.count ?? 0, qty: g?.overdue.qty ?? 0, tone: 'danger' },
    { key: 'done', label: '已完成', count: g?.done.count ?? 0, qty: g?.done.qty ?? 0, tone: 'green' },
  ]
})

function statusLabel(status: string): string {
  return status === 'pending' ? '待排' : status === 'scheduled' ? '已排' : status === 'in_progress' ? '在产' : status === 'produced' ? '已产' : '已交付'
}
function taskTag(status: string): 'danger' | 'warning' | 'success' | 'info' | 'primary' {
  return status === 'unscheduled' ? 'danger' : status === 'scheduled' ? 'warning' : status === 'in_progress' ? 'primary' : 'success'
}

async function load(): Promise<void> {
  try {
    data.value = await dashboardApi.orderOverview({ keyword: keyword.value || undefined, due_flag: dueFlag.value || undefined })
  } catch {
    data.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.order-overview {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.group-row {
  margin-bottom: 0;
}
.group-card {
  border-radius: 10px;
  text-align: center;
}
.group-card.danger {
  border-top: 3px solid #f56c6c;
}
.group-card.blue {
  border-top: 3px solid #1677ff;
}
.group-card.green {
  border-top: 3px solid #67c23a;
}
.group-card.gray {
  border-top: 3px solid #94a3b8;
}
.group-count {
  font-size: 26px;
  font-weight: 700;
  color: #1f2937;
}
.group-unit {
  font-size: 13px;
  font-weight: 400;
  color: #94a3b8;
}
.group-label {
  font-size: 13px;
  color: #6b7280;
}
.group-qty {
  font-size: 12px;
  color: #94a3b8;
  margin-top: 2px;
}
.panel {
  border-radius: 10px;
}
.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
}
.filters {
  display: flex;
  gap: 8px;
}
.text-overdue {
  color: #f56c6c;
  font-weight: 600;
}
.text-soon {
  color: #e6a23c;
  font-weight: 600;
}
.ml4 {
  margin-left: 4px;
}
.prio2 {
  color: #e6a23c;
  font-size: 12px;
}
.prio-n {
  color: #cbd5e1;
  font-size: 12px;
}
.done-qty {
  font-size: 12px;
  color: #94a3b8;
}
</style>