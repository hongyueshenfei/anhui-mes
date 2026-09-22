<template>
  <el-card shadow="never" class="page-card">
    <div class="toolbar">
      <div class="toolbar-left">
        <el-input v-model="taskIdInput" placeholder="任务 ID（查可入库量/分批入库）" clearable style="width: 180px" />
        <el-button type="primary" plain :disabled="!taskIdInput" @click="loadAvailable">
          <el-icon><Search /></el-icon>查询可入库量
        </el-button>
        <el-divider direction="vertical" />
        <el-select v-model="filterPush" placeholder="推送状态" clearable style="width: 130px">
          <el-option label="待推送" value="queued" />
          <el-option label="推送中" value="pushing" />
          <el-option label="已推送" value="success" />
          <el-option label="推送失败" value="failed" />
          <el-option label="重试中" value="retry" />
        </el-select>
        <el-button @click="loadIns">刷新入库单</el-button>
      </div>
      <div class="toolbar-right">
        <el-button v-if="available" type="success" :disabled="available.available_qty <= 0" @click="stockInVisible = true">
          <el-icon><Box /></el-icon>分批入库（可入库 {{ available.available_qty }}）
        </el-button>
      </div>
    </div>

    <!-- 可入库量 -->
    <el-descriptions v-if="available" :column="4" border size="small" class="mb-12">
      <el-descriptions-item label="任务号">{{ available.task_no }}</el-descriptions-item>
      <el-descriptions-item label="报工完成">
        <b>{{ available.reported_qty }}</b>
      </el-descriptions-item>
      <el-descriptions-item label="已入库">
        <b>{{ available.stocked_qty }}</b>
      </el-descriptions-item>
      <el-descriptions-item label="可入库量">
        <el-tag :type="available.available_qty > 0 ? 'success' : 'info'" size="small">{{ available.available_qty }}</el-tag>
      </el-descriptions-item>
    </el-descriptions>

    <!-- 入库单列表 -->
    <el-table v-loading="loading" :data="rows" stripe>
      <el-table-column prop="ins_no" label="入库单号" width="180" />
      <el-table-column label="任务/产品" min-width="150">
        <template #default="{ row }">
          {{ row.task?.task_no ?? '-' }}
          <div class="sub">{{ row.task?.product?.name ?? row.product?.name ?? '-' }}</div>
        </template>
      </el-table-column>
      <el-table-column prop="qty" label="数量" width="80" align="center" />
      <el-table-column prop="created_at" label="入库时间" width="160">
        <template #default="{ row }">{{ (row.created_at ?? '').slice(0, 16) }}</template>
      </el-table-column>
      <el-table-column label="推送状态" width="130">
        <template #default="{ row }">
          <el-tag size="small" :type="pushTag(row.k3_push_status)">{{ row.k3_push_status_label ?? row.k3_push_status }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="失败原因" min-width="180">
        <template #default="{ row }">
          <el-tooltip v-if="row.k3_push_status === 'failed'" :content="pushError(row)" placement="top">
            <span class="push-error">{{ pushError(row) }}</span>
          </el-tooltip>
          <span v-else class="sub">-</span>
        </template>
      </el-table-column>
      <el-table-column prop="k3_bill_no" label="金蝶单号" width="160">
        <template #default="{ row }">{{ row.k3_bill_no ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="110" align="center">
        <template #default="{ row }">
          <el-button v-if="row.k3_push_status !== 'success'" size="small" type="warning" plain :loading="pushingId === row.id" @click="onPush(row)">
            重推金蝶
          </el-button>
          <el-button v-else size="small" text type="success" @click="showK3Result(row)">回执</el-button>
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
        @current-change="loadIns"
      />
    </div>

    <!-- 分批入库弹窗 -->
    <el-dialog v-model="stockInVisible" title="分批入库" width="420px">
      <el-alert v-if="available" type="info" :closable="false" class="mb-12">
        {{ available.task_no }}：报工完成 {{ available.reported_qty }}，已入库 {{ available.stocked_qty }}，
        本次最多可入库 <b>{{ available.available_qty }}</b>。
      </el-alert>
      <el-form label-width="90px">
        <el-form-item label="入库数量" required>
          <el-input-number v-model="stockQty" :min="1" :max="available?.available_qty ?? 0" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="stockRemark" placeholder="可选" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="stockInVisible = false">取消</el-button>
        <el-button type="primary" :loading="stocking" @click="onStockIn">确认入库（自动推送金蝶）</el-button>
      </template>
    </el-dialog>

    <!-- 回执弹窗 -->
    <el-dialog v-model="resultVisible" title="金蝶回执" width="560px">
      <pre class="k3-result">{{ resultBody }}</pre>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Box, Search } from '@element-plus/icons-vue'
import { warehouseApi } from '@/api/warehouse'
import { getApiErrorMessage } from '@/api/axios'
import type { AvailableQty, StockIn } from '@/types/production'

const taskIdInput = ref('')
const available = ref<AvailableQty | null>(null)
const rows = ref<StockIn[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = 15
const filterPush = ref('')

const stockInVisible = ref(false)
const stockQty = ref(1)
const stockRemark = ref('')
const stocking = ref(false)

const pushingId = ref<number | null>(null)
const resultVisible = ref(false)
const resultBody = ref('')

onMounted(loadIns)

const taskId = computed(() => Number(taskIdInput.value))

async function loadAvailable(): Promise<void> {
  if (!taskId.value) return
  try {
    available.value = await warehouseApi.availableQty(taskId.value)
    stockQty.value = Math.min(1, available.value.available_qty)
  } catch {
    available.value = null
  }
}

async function loadIns(): Promise<void> {
  loading.value = true
  try {
    const result = await warehouseApi.listStockIns({
      page: page.value,
      per_page: perPage,
      task_id: taskId.value || undefined,
      k3_push_status: filterPush.value || undefined,
    })
    rows.value = result.data
    total.value = result.total
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function onStockIn(): Promise<void> {
  if (!available.value) return
  stocking.value = true
  try {
    const result = await warehouseApi.createStockIn({
      task_id: available.value.task_id,
      qty: stockQty.value,
      remark: stockRemark.value || undefined,
    })
    ElMessage.success(`入库单 ${result.stock_in.ins_no} 已确认`)
    stockInVisible.value = false
    available.value = result.available
    await loadIns()
  } catch {
    // 拦截器提示（超量 422 等）
  } finally {
    stocking.value = false
  }
}

async function onPush(row: StockIn): Promise<void> {
  pushingId.value = row.id
  try {
    const result = await warehouseApi.pushStockIn(row.id)
    ElMessage[result.pushed ? 'success' : 'info'](result.message)
    await Promise.all([loadIns(), loadAvailable()])
  } catch (error) {
    ElMessage.error(getApiErrorMessage(error, '推送失败（已留痕，可稍后重试）'))
  } finally {
    pushingId.value = null
  }
}

function showK3Result(row: StockIn): void {
  resultBody.value = JSON.stringify(row.k3_result ?? {}, null, 2)
  resultVisible.value = true
}

function pushError(row: StockIn): string {
  const result = row.k3_result
  if (!result || typeof result !== 'object') return '未返回具体原因'
  const value = result.error ?? result.message ?? result.error_msg
  return typeof value === 'string' && value.trim() ? value : '未返回具体原因'
}

function pushTag(s: string): 'success' | 'danger' | 'warning' | 'info' {
  return ({ success: 'success', failed: 'danger', retry: 'warning', pushing: 'warning', queued: 'info' }[s] ?? 'info') as 'success' | 'danger' | 'warning' | 'info'
}
</script>

<style scoped>
.page-card {
  border-radius: 8px;
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
  align-items: center;
  gap: 8px;
}
.sub {
  font-size: 12px;
  color: #94a3b8;
}
.push-error {
  display: inline-block;
  max-width: 220px;
  overflow: hidden;
  color: #b91c1c;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.mb-12 {
  margin-bottom: 12px;
}
.k3-result {
  background: #0f172a;
  color: #a5f3fc;
  padding: 12px;
  border-radius: 6px;
  font-size: 12px;
  max-height: 320px;
  overflow: auto;
}
</style>
