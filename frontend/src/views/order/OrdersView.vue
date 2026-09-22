<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <div class="toolbar-left">
        <el-input v-model="keyword" placeholder="搜索单号/客户" clearable style="width: 200px" @keyup.enter="load" />
        <el-select v-model="filterStatus" placeholder="状态" clearable style="width: 130px">
          <el-option label="工艺确认" value="route_confirm" />
          <el-option label="待排产" value="pending" />
          <el-option label="已排产" value="scheduled" />
          <el-option label="生产中" value="in_progress" />
          <el-option label="已停工" value="paused" />
          <el-option label="已入库" value="produced" />
          <el-option label="已中止" value="aborted" />
          <el-option label="已关闭" value="closed" />
        </el-select>
        <el-select v-model="filterSrc" placeholder="来源" clearable style="width: 120px">
          <el-option label="手工" value="manual" />
          <el-option label="Excel 导入" value="excel" />
        </el-select>
        <el-button type="primary" plain @click="load">查询</el-button>
      </div>
      <div class="toolbar-right">
        <el-button @click="importVisible = true">
          <el-icon><Upload /></el-icon>Excel 导入
        </el-button>
        <el-button type="primary" @click="createVisible = true">
          <el-icon><Plus /></el-icon>手工建单
        </el-button>
      </div>
    </div>

    <el-table ref="tableRef" v-loading="loading" :data="rows" stripe class="order-table">
      <!-- 工单中心：一行=一个工单（订单按产品拆解），工单号唯一 -->
      <el-table-column prop="task_no" label="工单号" :width="colWidths.task_no" fixed="left">
        <template #default="{ row }">
          <el-link type="primary" @click="openDetail(row)">{{ row.task_no }}</el-link>
          <el-tag v-if="row.is_split_child" size="small" type="info" class="split-tag">拆分 {{ row.split_index }}/{{ row.split_total }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="order_no" label="订单号" :width="colWidths.order_no" show-overflow-tooltip />
      <el-table-column label="产品名称" :width="colWidths.product_name" show-overflow-tooltip>
        <template #default="{ row }">
          {{ row.product_name }}<span v-if="row.product_code" class="muted">（{{ row.product_code }}）</span>
        </template>
      </el-table-column>
      <el-table-column prop="customer_name" label="客户" :width="colWidths.customer_name" show-overflow-tooltip />
      <el-table-column label="来源" :width="colWidths.src">
        <template #default="{ row }">{{ srcLabel(row.src) }}</template>
      </el-table-column>
      <el-table-column prop="qty" label="数量" :width="colWidths.qty" align="center" />
      <el-table-column label="优先级" :width="colWidths.priority" align="center">
        <template #default="{ row }">
          <el-tag size="small" :type="priorityType(row.priority)">P{{ row.priority }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="delivery_date" label="期望交期" :width="colWidths.delivery_date" show-overflow-tooltip>
        <template #default="{ row }">{{ fmtDate(row.delivery_date) }}</template>
      </el-table-column>
      <el-table-column label="状态" :width="colWidths.status">
        <template #default="{ row }">
          <el-tag size="small" :type="statusType(row.status)">{{ statusLabel(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="总进度" :width="colWidths.progress" min-width="360" fixed="right">
        <template #default="{ row }">
          <OrderProgressSteps
            v-if="row.progress?.stages?.length"
            :stages="row.progress.stages"
            :percent="row.progress?.percent"
            compact
          />
          <span v-else class="muted">{{ row.progress?.percent ?? 0 }}%</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" :width="colWidths.actions" align="center" fixed="right">
        <template #default="{ row }">
          <el-dropdown trigger="click" @command="(cmd: string) => onAction(row, cmd)">
            <el-button size="small" text type="primary">
              操作<el-icon><ArrowDown /></el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item v-for="a in orderActions(row)" :key="a.key" :command="a.key" :disabled="a.disabled">
                  {{ a.label }}
                </el-dropdown-item>
                <el-dropdown-item divided command="delete">删除</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="page"
        :page-size="perPage"
        :total="total"
        layout="total, prev, pager, next"
        background
        @current-change="load"
      />
    </div>

    <!-- 手工建单 -->
    <el-dialog v-model="createVisible" title="手工建单（多产品明细）" width="680px">
      <el-form label-width="90px">
        <el-form-item label="客户名称" required>
          <el-input v-model="form.customer_name" placeholder="客户名称" />
        </el-form-item>
        <el-form-item label="期望交期">
          <el-date-picker v-model="form.delivery_date" type="date" value-format="YYYY-MM-DD" style="width: 180px" />
        </el-form-item>
        <el-form-item label="优先级">
          <el-rate v-model="form.priority" :max="5" show-score />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" placeholder="备注" />
        </el-form-item>
      </el-form>

      <div class="lines-title">订单明细</div>
      <div v-for="(line, idx) in form.lines" :key="idx" class="line-row">
        <el-input v-model="line.product_code" placeholder="产品编码（金蝶物料编码）" style="width: 180px" />
        <el-input-number v-model="line.qty" :min="1" :max="10000000" placeholder="数量" style="width: 140px" />
        <el-date-picker v-model="line.delivery_date" type="date" value-format="YYYY-MM-DD" style="width: 150px" placeholder="交期(可选)" />
        <el-button type="danger" text @click="form.lines.splice(idx, 1)">移除</el-button>
      </div>
      <el-button size="small" @click="form.lines.push({ product_code: '', qty: 100, delivery_date: null })">
        <el-icon><Plus /></el-icon>添加明细
      </el-button>

      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="onCreate">创建订单（生成生产任务）</el-button>
      </template>
    </el-dialog>

    <!-- 导入弹窗 -->
    <ImportView v-model="importVisible" @imported="load" />

    <!-- 生产拆单：这里只拆数量，资源在甘特图排产时选择和校验 -->
    <el-dialog v-model="splitVisible" title="生产拆单" width="720px" destroy-on-close>
      <el-alert type="info" :closable="false" show-icon>
        原任务 {{ splitTarget?.task_no }} 共 {{ splitTarget?.qty ?? 0 }} 件。拆分后生成多个子任务，分别进入任务池；拆单阶段不绑定模具或机台，进入甘特图排产时再选择并校验。
      </el-alert>
      <div class="split-head">
        <span>生产批次</span>
        <span>数量</span>
        <span />
      </div>
      <div v-for="(part, index) in splitParts" :key="index" class="split-row">
        <span class="split-index">子任务 {{ index + 1 }}</span>
        <el-input-number v-model="part.qty" :min="1" :max="splitTarget?.qty ?? 10000000" controls-position="right" />
        <el-button v-if="splitParts.length > 2" type="danger" text @click="splitParts.splice(index, 1)">移除</el-button>
      </div>
      <el-button type="primary" plain size="small" @click="splitParts.push({ qty: 1 })">
        <el-icon><Plus /></el-icon>添加子任务
      </el-button>
      <div class="split-summary" :class="{ invalid: splitRemaining !== 0 }">
        已分配 {{ splitAssignedQty }} 件；{{ splitRemaining === 0 ? '数量已匹配原任务' : splitRemaining > 0 ? `还差 ${splitRemaining} 件` : `超出 ${Math.abs(splitRemaining)} 件` }}
      </div>
      <template #footer>
        <el-button @click="splitVisible = false">取消</el-button>
        <el-button type="primary" :loading="splitting" @click="confirmSplit">确认拆单</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, Plus, Upload } from '@element-plus/icons-vue'
import { orderApi } from '@/api/order'
import type { Order } from '@/types/schedule'
import { useColumnResize } from '@/composables/useColumnResize'
import { formatDate, formatDateTime } from '@/utils/datetime'
import ImportView from './ImportView.vue'
import OrderProgressSteps from '@/components/order/OrderProgressSteps.vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const rows = ref<Order[]>([])
const tableRef = ref<{ $el: HTMLElement } | null>(null)
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = 15
const keyword = ref('')
const filterStatus = ref('')
const filterSrc = ref('')

const createVisible = ref(false)
const creating = ref(false)
const importVisible = ref(false)
const splitVisible = ref(false)
const splitting = ref(false)
const splitTarget = ref<Order | null>(null)
const splitParts = ref<Array<{ qty: number }>>([])
const splitAssignedQty = computed(() => splitParts.value.reduce((sum, part) => sum + Math.max(0, Number(part.qty) || 0), 0))
const splitRemaining = computed(() => (splitTarget.value?.qty ?? 0) - splitAssignedQty.value)

/** 列宽（可拖拽调整） */
const colWidths = reactive<Record<string, number>>({
  task_no: 200,
  order_no: 180,
  product_name: 180,
  customer_name: 140,
  src: 90,
  qty: 80,
  priority: 80,
  delivery_date: 110,
  status: 100,
  progress: 360, // 2026-09-01: 300 → 360，确保 4 节点进度条完整显示（之前第 4 节点"包装"被列宽裁切）
  actions: 110,
})
useColumnResize(tableRef, colWidths, [
  'task_no', 'order_no', 'product_name', 'customer_name', 'src', 'qty',
  'priority', 'delivery_date', 'status', 'progress', 'actions',
])

const fmtDateTime = formatDateTime
const fmtDate = formatDate
const form = ref<{
  customer_name: string
  delivery_date: string | null
  priority: number
  remark: string
  lines: Array<{ product_code: string; qty: number; delivery_date: string | null }>
}>({
  customer_name: '',
  delivery_date: null,
  priority: 3,
  remark: '',
  lines: [{ product_code: '', qty: 100, delivery_date: null }],
})

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await orderApi.listOrders({
      page: page.value,
      per_page: perPage,
      with_progress: true,
      keyword: keyword.value || undefined,
      status: filterStatus.value || undefined,
      src: filterSrc.value || undefined,
    })
    rows.value = result.data
    total.value = result.total
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function onCreate(): Promise<void> {
  if (!form.value.customer_name.trim()) {
    ElMessage.warning('请填写客户名称')
    return
  }
  const validLines = form.value.lines.filter((l) => l.product_code.trim() && l.qty > 0)
  if (validLines.length === 0) {
    ElMessage.warning('请至少填写一条有效明细')
    return
  }
  creating.value = true
  try {
    const order = await orderApi.createOrder({
      customer_name: form.value.customer_name.trim(),
      delivery_date: form.value.delivery_date,
      priority: form.value.priority,
      remark: form.value.remark,
      lines: validLines.map((l) => ({
        product_code: l.product_code.trim(),
        qty: l.qty,
        delivery_date: l.delivery_date,
      })),
    })
    ElMessage.success(`订单 ${order.order_no} 创建成功，已生成生产任务`)
    createVisible.value = false
    form.value = { customer_name: '', delivery_date: null, priority: 3, remark: '', lines: [{ product_code: '', qty: 100, delivery_date: null }] }
    await load()
  } catch {
    // 拦截器已提示（产品编码不存在等）
  } finally {
    creating.value = false
  }
}

async function onDelete(row: Order): Promise<void> {
  const ok = await ElMessageBox.confirm(`确定删除订单 ${row.order_no} 吗？`, '删除订单', { type: 'warning' }).catch(() => false)
  if (!ok) return
  try {
    await orderApi.deleteOrder(row.order_id!)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 拦截器提示（已排产不可删除）
  }
}

/** 订单操作清单（下拉框）：工艺确认 → 排产 → 开工 → 报工 → 停工 → 完结 → 中止 → 关闭 */
function orderActions(row: Order): Array<{ key: string; label: string; disabled: boolean }> {
  const s = row.status
  return [
    { key: 'split', label: '生产拆单', disabled: s !== 'route_confirm' && s !== 'pending' || row.task_status !== 'unscheduled' || !!row.parent_task_id || (row.qty ?? 0) < 2 },
    { key: 'route_confirm', label: '工艺确认', disabled: s !== 'route_confirm' },
    { key: 'schedule', label: '排产', disabled: !['route_confirm', 'pending', 'scheduled'].includes(s) },
    { key: 'start', label: '开工', disabled: !['pending', 'scheduled', 'paused'].includes(s) },
    { key: 'report', label: '报工', disabled: !['in_progress', 'paused'].includes(s) },
    { key: 'pause', label: '停工', disabled: s !== 'in_progress' },
    { key: 'complete', label: '完结', disabled: !['scheduled', 'in_progress', 'paused'].includes(s) },
    { key: 'abort', label: '中止', disabled: !['in_progress', 'paused'].includes(s) },
    { key: 'close', label: '关闭', disabled: ['produced', 'aborted', 'closed'].includes(s) },
  ]
}

async function onAction(row: Order, cmd: string): Promise<void> {
  switch (cmd) {
    case 'split':
      await openSplit(row)
      return
    case 'route_confirm': {
      const ok = await ElMessageBox.confirm(`确认工单 ${row.order_no} 的工艺路线无误并完成确认？确认后进入「待排产」。`, '工艺确认', { type: 'warning' }).catch(() => false)
      if (!ok) return
      await orderApi.confirmOrderRoute(row.order_id!)
      ElMessage.success('工艺确认完成，工单已进入待排产')
      break
    }
    case 'schedule':
      router.push({ path: '/schedule/gantt' })
      return
    case 'start': {
      const isResume = row.status === 'paused'
      const ok = await ElMessageBox.confirm(isResume ? `恢复工单 ${row.order_no} 生产？` : `开始生产工单 ${row.order_no}？`, isResume ? '恢复生产' : '开工', { type: 'warning' }).catch(() => false)
      if (!ok) return
      await orderApi.startOrder(row.order_id!)
      ElMessage.success(isResume ? '工单已恢复生产' : '工单已开始生产')
      break
    }
    case 'report':
      openDetail(row)
      return
    case 'pause': {
      const ok = await ElMessageBox.confirm(`确定停工工单 ${row.order_no}？生产中任务将暂停，可再次开工恢复。`, '停工', { type: 'warning' }).catch(() => false)
      if (!ok) return
      await orderApi.pauseOrder(row.order_id!)
      ElMessage.success('工单已停工')
      break
    }
    case 'complete': {
      const ok = await ElMessageBox.confirm(`确认完结入库工单 ${row.order_no}？所有任务与排程将置为「已完成」。`, '完结入库', { type: 'warning' }).catch(() => false)
      if (!ok) return
      await orderApi.completeOrder(row.order_id!)
      ElMessage.success('工单已完结入库')
      break
    }
    case 'abort': {
      const ok = await ElMessageBox.confirm(`确定中止工单 ${row.order_no}？中止后不再继续生产。`, '中止工单', { type: 'warning' }).catch(() => false)
      if (!ok) return
      await orderApi.abortOrder(row.order_id!)
      ElMessage.success('工单已中止')
      break
    }
    case 'close': {
      const ok = await ElMessageBox.confirm(`确定关闭工单 ${row.order_no}？关闭后订单归档。`, '关闭工单', { type: 'warning' }).catch(() => false)
      if (!ok) return
      await orderApi.closeOrder(row.order_id!)
      ElMessage.success('工单已关闭')
      break
    }
    case 'delete':
      await onDelete(row)
      return
  }
  await load()
}

async function openSplit(row: Order): Promise<void> {
  splitTarget.value = row
  const first = Math.max(1, Math.floor((row.qty ?? 0) / 2))
  splitParts.value = [
    { qty: first },
    { qty: Math.max(1, (row.qty ?? 0) - first) },
  ]
  splitVisible.value = true
}

async function confirmSplit(): Promise<void> {
  if (!splitTarget.value) return
  if (splitParts.value.length < 2 || splitRemaining.value !== 0) {
    ElMessage.warning('请保证至少 2 个子任务，且数量合计等于原任务数量')
    return
  }
  splitting.value = true
  try {
    await orderApi.splitTask(splitTarget.value.id, {
      parts: splitParts.value.map((part) => ({ qty: Number(part.qty) })),
    })
    ElMessage.success('生产拆单完成，子任务已进入任务池')
    splitVisible.value = false
    await load()
  } finally {
    splitting.value = false
  }
}

function openDetail(row: Order): void {
  // 工单粒度行：跳转所属订单详情（订单详情内按产品行展示各工单）
  router.push({ path: `/order/detail/${row.order_id ?? row.id}` })
}

function srcLabel(s: string): string {
  return { manual: '手工', excel: 'Excel', mini_program: '小程序', k3: '金蝶' }[s] ?? s
}
function statusLabel(s: string): string {
  return { route_confirm: '工艺确认', pending: '待排产', scheduled: '已排产', in_progress: '生产中', paused: '已停工', produced: '已入库', aborted: '已中止', closed: '已关闭' }[s] ?? s
}
function statusType(s: string): 'primary' | 'success' | 'info' | 'warning' {
  return ({ route_confirm: 'warning', pending: 'info', scheduled: 'success', in_progress: 'primary', paused: 'warning', produced: 'success', aborted: 'danger', closed: 'info' }[s] ?? 'info') as 'primary' | 'success' | 'info' | 'warning'
}
function priorityType(p: number): 'danger' | 'warning' | 'info' {
  return p >= 5 ? 'danger' : p >= 4 ? 'warning' : 'info'
}
</script>

<style scoped>
.page-card {
  border-radius: 8px;
}
.page-card :deep(.el-card__body) {
  /* 进度条列需要更多空间；卡片内允许横向滚动，避免挤压其他列 */
  overflow-x: auto;
}
.toolbar {
  display: flex;
  justify-content: space-between;
  margin-bottom: 14px;
  gap: 10px;
  flex-wrap: wrap;
}
.toolbar-left,
.toolbar-right {
  display: flex;
  gap: 8px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.lines-title {
  font-weight: 600;
  margin-bottom: 8px;
}
.line-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.muted {
  color: #94a3b8;
  font-size: 12px;
}
.split-tag {
  margin-left: 6px;
}
.split-head,
.split-row {
  display: grid;
  grid-template-columns: 110px 150px 60px;
  align-items: center;
  gap: 12px;
}
.split-head {
  color: #64748b;
  font-size: 12px;
  margin: 18px 0 8px;
}
.split-row {
  margin-bottom: 10px;
}
.split-index {
  color: #334155;
  font-size: 13px;
}
.split-summary {
  margin-top: 16px;
  padding: 10px 12px;
  background: #f0fdf4;
  color: #166534;
  font-size: 13px;
  border-radius: 4px;
}
.split-summary.invalid {
  background: #fff7ed;
  color: #c2410c;
}

/* 订单列表：单元格不换行；列宽固定，由父容器统一横向滚动 */
/* 外层（page-card / el-card__body）需有 overflow-x:auto，否则浏览器窗口横滚 */
.order-table :deep(.el-table__header th .cell),
.order-table :deep(.el-table__body td .cell) {
  white-space: nowrap;
}
.order-table :deep(th.el-table__cell) {
  position: relative;
}
.order-table :deep(.col-resize-handle) {
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 8px;
  cursor: col-resize;
  z-index: 6;
}
</style>
