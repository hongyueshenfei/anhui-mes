<template>
  <el-dialog v-model="visible" title="插单确认" width="640px" :close-on-click-modal="false">
    <div v-if="preview" class="insert-dialog">
      <el-alert type="warning" :closable="false" class="mb-12">
        插入任务 <b>{{ preview.task.task_no }}</b>（{{ preview.task.product_name }} × {{ preview.task.qty }}件）
        → {{ strategyLabel }}策略
      </el-alert>

      <!-- 策略选择 -->
      <el-radio-group v-model="strategy" class="mb-12">
        <el-radio-button value="wait">等待（排队，不打断任何任务）</el-radio-button>
        <el-radio-button value="interrupt">打断（占当前机台，被打断任务顺延）</el-radio-button>
      </el-radio-group>

      <el-checkbox v-if="strategy === 'interrupt'" v-model="forceInterrupt" class="mb-12">
        被插任务已锁定，仍要打断（显式确认）
      </el-checkbox>

      <!-- 打断信息 -->
      <el-descriptions v-if="preview.interrupted" :column="3" border size="small" class="mb-12">
        <el-descriptions-item label="被打断任务">{{ preview.interrupted.task_no }}</el-descriptions-item>
        <el-descriptions-item label="已完成留档">{{ preview.interrupted.done_qty }}件</el-descriptions-item>
        <el-descriptions-item label="剩余重排">{{ preview.interrupted.remaining_qty }}件</el-descriptions-item>
      </el-descriptions>

      <!-- 受影响延期清单 -->
      <div class="affected-title">受影响订单延期清单（{{ preview.affected_orders.length }}）</div>
      <el-table :data="preview.affected_orders" size="small" border max-height="220" class="mb-12">
        <el-table-column prop="order_no" label="订单号" width="150" />
        <el-table-column prop="customer_name" label="客户" min-width="100" />
        <el-table-column prop="task_count" label="任务数" width="70" align="center" />
        <el-table-column label="将延期" width="90" align="center">
          <template #default="{ row }">
            <el-tag type="danger" size="small">{{ row.delay_hours }}h</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="明细" min-width="140">
          <template #default="{ row }">
            <div v-for="t in row.tasks" :key="t.task_id" class="affected-task">
              {{ t.task_no }}：{{ t.old_end }} → {{ t.new_end }}
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- 插入链预览 -->
      <div class="affected-title">插入后任务链（自动计算结束时间）</div>
      <div v-for="s in preview.insert_chain" :key="s.seq" class="chain-row">
        <el-tag size="small">{{ s.op_name }}</el-tag>
        <span class="chain-time">{{ s.plan_start }} → {{ s.plan_end }}</span>
      </div>
    </div>

    <el-alert v-else type="info" :closable="false">加载插入预览…</el-alert>

    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="confirming" @click="onConfirm">
        <el-icon><Check /></el-icon>一键确认生效
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Check } from '@element-plus/icons-vue'
import { scheduleApi } from '@/api/schedule'
import type { InsertPreview } from '@/types/schedule'

const props = defineProps<{
  modelValue: boolean
  payload: { taskId: number; machineId: number; planStart: string } | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
  (e: 'confirmed'): void
}>()

const visible = ref(false)
const strategy = ref<'wait' | 'interrupt'>('wait')
const forceInterrupt = ref(false)
const preview = ref<InsertPreview | null>(null)
const confirming = ref(false)

watch(
  () => props.modelValue,
  async (v) => {
    visible.value = v
    if (v && props.payload) {
      await loadPreview()
    }
  },
)

watch(visible, (v) => emit('update:modelValue', v))
watch([strategy, forceInterrupt], () => loadPreview())

const strategyLabel = computed(() => (strategy.value === 'wait' ? '等待' : '打断'))

async function loadPreview(): Promise<void> {
  if (!props.payload) return
  try {
    preview.value = await scheduleApi.insertPreview({
      task_id: props.payload.taskId,
      machine_id: props.payload.machineId,
      plan_start: props.payload.planStart,
      strategy: strategy.value,
      force_interrupt: forceInterrupt.value,
    })
  } catch {
    preview.value = null
  }
}

async function onConfirm(): Promise<void> {
  if (!props.payload || !preview.value) return
  confirming.value = true
  try {
    const result = await scheduleApi.insertConfirm({
      task_id: props.payload.taskId,
      machine_id: props.payload.machineId,
      plan_start: props.payload.planStart,
      strategy: strategy.value,
      force_interrupt: forceInterrupt.value,
    })
    ElMessage.success(`插单已生效：${result.affected_orders.length} 个订单受影响`)
    visible.value = false
    emit('confirmed')
  } finally {
    confirming.value = false
  }
}
</script>

<style scoped>
.insert-dialog {
  display: flex;
  flex-direction: column;
}
.mb-12 {
  margin-bottom: 12px;
}
.affected-title {
  font-weight: 600;
  margin-bottom: 6px;
  color: #334155;
}
.affected-task {
  font-size: 12px;
  color: #475569;
  line-height: 1.5;
}
.chain-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 0;
  font-size: 13px;
}
.chain-time {
  color: #64748b;
  font-family: monospace;
  font-size: 12px;
}
</style>
