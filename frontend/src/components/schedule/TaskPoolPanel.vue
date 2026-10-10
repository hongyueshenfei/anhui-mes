<template>
  <el-card shadow="never" class="pool-card">
    <template #header>
      <div class="pool-header">
        <span>待排任务池（{{ loading && !items.length ? error ? '读取失败' : '读取中' : items.length }}）</span>
        <el-input v-model="keyword" placeholder="搜索任务/产品" size="small" clearable style="width: 150px" />
      </div>
    </template>

    <div v-loading="loading" class="pool-list">
      <div
        v-for="item in filtered"
        :key="item.id"
        class="pool-item"
        :class="{
          'is-overdue': item.is_overdue,
          'is-picked': pickedId === item.id,
          'is-dragging': draggingId === item.id,
          'is-disabled': !canPlace(item),
        }"
        :draggable="canPlace(item)"
        :title="pickedId === item.id ? '再次点击取消选择' : '点击选择任务，或拖动到甘特图'"
        @dragstart="onDragStart($event, item)"
        @dragend="draggingId = null"
        @click="onPick(item)"
      >
        <div class="pool-item-title">
          <el-tag size="small" :type="priorityType(item.priority)" effect="dark">{{ 'P' + item.priority }}</el-tag>
          <span class="task-no">{{ item.task_no }}</span>
          <span v-if="pickedId === item.id" class="picked-flag">已选</span>
        </div>
        <div class="pool-item-name">{{ item.product_name }}（{{ item.product_code }}）</div>
        <div class="pool-item-resource">
          <span class="resource-badge" :class="item.required_resource_type || 'unknown'">
            {{ item.required_resource_label || '未配置首道工序' }}
          </span>
          <span v-if="item.required_op_name" class="resource-op">资源工序：{{ item.required_op_name }}</span>
        </div>
        <div v-if="item.mold_unavailable" class="pool-item-blocked">
          模具 {{ item.mold_code || '未配置' }} · {{ item.mold_status_label || '不可用' }}，暂不可排产
        </div>
        <div class="pool-item-meta">
          <span>{{ item.qty }}件</span>
          <span v-if="item.estimate_ok && item.estimated_hours">约{{ item.estimated_hours }}h</span>
          <span v-if="!canPlace(item)" class="warn">未配置可排工序</span>
        </div>
        <div class="pool-item-due">
          <span v-if="item.delivery_date" :class="{ overdue: item.is_overdue }">交期 {{ item.delivery_date }}</span>
          <span>{{ item.customer_name }}</span>
        </div>
      </div>
      <el-empty
        v-if="!loading && filtered.length === 0"
        :description="error ? '待排任务加载失败' : keyword ? '没有匹配的待排任务' : '暂无待排任务'"
        :image-size="error ? 44 : 60"
      >
        <el-button v-if="error" size="small" type="primary" link @click="emit('retry')">重试</el-button>
      </el-empty>
    </div>
  </el-card>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import type { PoolTask } from '@/types/schedule'

const props = defineProps<{ items: PoolTask[]; pickedId?: number | null; loading?: boolean; error?: string }>()
const emit = defineEmits<{
  (e: 'pick', task: PoolTask | null): void
  (e: 'retry'): void
}>()

const keyword = ref('')
const draggingId = ref<number | null>(null)
const pickedId = computed(() => props.pickedId ?? null)

const filtered = computed(() => {
  const kw = keyword.value.trim()
  if (!kw) return props.items
  return props.items.filter(
    (p) =>
      p.task_no.includes(kw) ||
      p.product_code.includes(kw) ||
      p.product_name.includes(kw) ||
      p.customer_name.includes(kw),
  )
})

function priorityType(p: number): 'danger' | 'warning' | 'primary' | 'info' {
  if (p === 0) return 'danger'
  if (p === 1) return 'warning'
  if (p === 2) return 'primary'
  return 'info'
}

function onPick(task: PoolTask): void {
  if (!canPlace(task)) return
  // 已选任务再次点击即取消，避免用户进入甘特图后只能改选其他任务。
  emit('pick', pickedId.value === task.id ? null : task)
}

function canPlace(task: PoolTask): boolean {
  return Boolean(
    task.required_resource_type
      && (task.route_ops_count === undefined || task.route_ops_count > 0)
      && !task.mold_unavailable,
  )
}

/** 原生 HTML5 拖拽：写 taskId 到 dataTransfer（与 GanttChart @drop 兜底配套） */
function onDragStart(event: DragEvent, task: PoolTask): void {
  if (!canPlace(task)) {
    event.preventDefault()
    return
  }
  draggingId.value = task.id
  // 原生 HTML5 拖拽也同步选中任务，甘特图才能实时把不匹配资源行标红并禁止放置。
  emit('pick', task)
  if (event.dataTransfer) {
    event.dataTransfer.setData('text/plain', String(task.id))
    event.dataTransfer.setData('application/x-pool-task', String(task.id))
    event.dataTransfer.setData('application/x-resource-type', task.required_resource_type ?? '')
    event.dataTransfer.effectAllowed = 'copy'
    // 自定义半透明 ghost（视觉提示）
    if (event.target instanceof HTMLElement) {
      const ghost = event.target.cloneNode(true) as HTMLElement
      ghost.style.opacity = '0.5'
      event.dataTransfer.setDragImage(ghost, 20, 20)
    }
  }
}
</script>

<style scoped>
.pool-card {
  border-radius: 8px;
}
.pool-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
}
.pool-list {
  max-height: calc(100vh - 320px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.pool-item {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 8px 10px;
  cursor: grab;
  background: #f8fafc;
  transition: all 0.15s;
}
.pool-item:hover {
  border-color: #3b82f6;
  background: #eff6ff;
}
.pool-item.is-picked {
  border-color: #1a56db;
  background: #ebf2ff;
  box-shadow: 0 0 0 2px rgba(26, 86, 219, 0.2);
}
.pool-item.is-dragging {
  opacity: 0.5;
}
.pool-item.is-overdue {
  border-left: 3px solid #ef4444;
}
.pool-item.is-disabled {
  cursor: not-allowed;
  opacity: 0.68;
  background: #f8fafc;
}
.pool-item.is-disabled:hover {
  border-color: #e2e8f0;
  background: #f8fafc;
}
.pool-item-resource {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 5px 0 4px;
}
.pool-item-blocked {
  margin: 5px 0 4px;
  padding: 4px 7px;
  border: 1px solid #fecaca;
  border-radius: 4px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 11px;
  font-weight: 600;
  line-height: 16px;
}
.resource-badge {
  display: inline-flex;
  align-items: center;
  min-height: 20px;
  padding: 1px 7px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
  line-height: 18px;
}
.resource-badge::before {
  width: 6px;
  height: 6px;
  margin-right: 5px;
  border-radius: 50%;
  background: currentColor;
  content: '';
}
.resource-badge.injection { color: #2563eb; background: #dbeafe; }
.resource-badge.blow { color: #7c3aed; background: #ede9fe; }
.resource-badge.paint_line { color: #c2410c; background: #ffedd5; }
.resource-badge.effect_line { color: #047857; background: #d1fae5; }
.resource-badge.pack_line { color: #475569; background: #e2e8f0; }
.resource-badge.unknown { color: #64748b; background: #f1f5f9; }
.resource-op {
  overflow: hidden;
  color: #94a3b8;
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.picked-flag {
  margin-left: auto;
  background: #1a56db;
  color: #fff;
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 8px;
  font-weight: 600;
}
.pool-item-title {
  display: flex;
  align-items: center;
  gap: 6px;
}
.task-no {
  font-size: 12px;
  color: #64748b;
  font-family: monospace;
}
.pool-item-name {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  margin: 4px 0;
}
.pool-item-meta {
  display: flex;
  gap: 10px;
  font-size: 12px;
  color: #64748b;
}
.pool-item-meta .warn {
  color: #d97706;
}
.pool-item-due {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: #94a3b8;
  margin-top: 4px;
}
.pool-item-due .overdue {
  color: #ef4444;
  font-weight: 600;
}
.pool-tip {
  margin-top: 10px;
  font-size: 12px;
  color: #94a3b8;
  text-align: center;
}

@media (max-width: 1400px) {
  .pool-list {
    max-height: 76px;
  }
  .pool-list :deep(.el-empty) {
    padding: 0;
  }
  .pool-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      'title title'
      'name meta'
      'resource due';
    align-items: center;
    column-gap: 10px;
    row-gap: 2px;
    padding: 5px 8px;
  }
  .pool-item-title { grid-area: title; min-width: 0; }
  .pool-item-name { grid-area: name; min-width: 0; }
  .pool-item-resource { grid-area: resource; min-width: 0; }
  .pool-item-meta { grid-area: meta; justify-content: flex-end; white-space: nowrap; }
  .pool-item-due { grid-area: due; justify-content: flex-end; gap: 8px; white-space: nowrap; }
  .pool-item-blocked { grid-column: 1 / -1; }
  .pool-item-name {
    margin: 2px 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .pool-item-resource {
    margin: 2px 0;
    overflow: hidden;
  }
  .resource-op {
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .pool-item-due {
    margin-top: 2px;
  }
}
</style>
