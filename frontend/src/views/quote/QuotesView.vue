<template>
  <div class="quotes-page">
    <el-card shadow="never" class="panel">
      <template #header>
        <div class="panel-header">
          <span>报价单管理（草稿 / 已发 / 成交 / 未成交）</span>
          <div>
            <el-radio-group v-model="filterStatus" size="small" @change="load">
              <el-radio-button value="">全部</el-radio-button>
              <el-radio-button value="draft">草稿</el-radio-button>
              <el-radio-button value="sent">已发</el-radio-button>
              <el-radio-button value="won">成交</el-radio-button>
              <el-radio-button value="lost">未成交</el-radio-button>
            </el-radio-group>
          </div>
        </div>
      </template>

      <el-table :data="items" size="small" stripe>
        <el-table-column prop="quote_no" label="报价单号" width="160" />
        <el-table-column prop="customer_name" label="客户" min-width="130" />
        <el-table-column prop="total_qty" label="数量" width="90" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ row.status_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="成本/建议价" width="140">
          <template #default="{ row }">
            <div class="price-cell">
              <span>成本 {{ row.lines?.[0]?.total_cost?.toFixed(2) ?? '-' }}</span>
              <span class="suggest">建议 {{ row.lines?.[0]?.suggest_price?.toFixed(2) ?? '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="成交价" width="100">
          <template #default="{ row }">{{ row.final_price ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="异常" width="80">
          <template #default="{ row }">
            <el-tag v-if="row.lines?.[0]?.anomaly_flag" type="danger" size="small">异常价</el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="150" />
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button size="small" text type="primary" @click="openDetail(row)">详情</el-button>
            <el-button v-if="row.status === 'draft'" size="small" text type="warning" @click="sent(row)">发出</el-button>
            <el-button v-if="row.status === 'sent'" size="small" text type="success" @click="won(row)">成交</el-button>
            <el-button v-if="row.status === 'sent'" size="small" text type="info" @click="lost(row)">未成交</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="报价单详情" width="720px">
      <template v-if="current">
        <el-descriptions :column="3" border size="small" class="mb12">
          <el-descriptions-item label="单号">{{ current.quote_no }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ current.customer_name }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ current.status_label }}</el-descriptions-item>
          <el-descriptions-item label="目标毛利">{{ current.target_margin }}%</el-descriptions-item>
          <el-descriptions-item label="建议交期">{{ current.suggest_delivery ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="成交价">{{ current.final_price ?? '-' }}</el-descriptions-item>
        </el-descriptions>

        <el-table :data="current.lines" size="small" stripe>
          <el-table-column prop="spec_text" label="规格" min-width="160" />
          <el-table-column prop="qty" label="数量" width="80" />
          <el-table-column label="成本构成" min-width="200">
            <template #default="{ row }">
              <span class="cost-text">材{{ row.material_cost?.toFixed(2) }} 加{{ row.process_cost?.toFixed(2) }} 模{{ row.mold_amort?.toFixed(2) }} 损{{ row.loss_cost?.toFixed(2) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="建议价" width="90">
            <template #default="{ row }">{{ row.suggest_price?.toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="交期(天)" width="80">
            <template #default="{ row }">{{ row.delivery_days }}</template>
          </el-table-column>
        </el-table>

        <div v-if="current.lines?.[0]?.llm_remark" class="llm-remark">{{ current.lines[0].llm_remark }}</div>
      </template>

      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { quoteApi } from '@/api/quote'
import type { PageResult } from '@/types'
import type { Quote } from '@/types/quote'

const items = ref<Quote[]>([])
const filterStatus = ref('')
const detailVisible = ref(false)
const current = ref<Quote | null>(null)

function statusType(s: string): 'info' | 'warning' | 'success' | 'danger' {
  const map: Record<string, 'info' | 'warning' | 'success' | 'danger'> = { draft: 'info', sent: 'warning', won: 'success', lost: 'danger' }
  return map[s] ?? 'info'
}

async function load(): Promise<void> {
  try {
    const r = (await quoteApi.listQuotes({ status: filterStatus.value || undefined })) as PageResult<Quote>
    items.value = Array.isArray(r) ? r : r.data
  } catch {
    items.value = []
  }
}

function openDetail(row: Quote): void {
  current.value = row
  detailVisible.value = true
}

async function sent(row: Quote): Promise<void> {
  await quoteApi.transitionStatus(row.id, { status: 'sent' })
  ElMessage.success('报价单已发出')
  await load()
}

async function won(row: Quote): Promise<void> {
  await quoteApi.transitionStatus(row.id, { status: 'won', final_price: row.lines?.[0]?.suggest_price })
  ElMessage.success('已标记成交')
  await load()
}

async function lost(row: Quote): Promise<void> {
  await quoteApi.transitionStatus(row.id, { status: 'lost' })
  ElMessage.success('已标记未成交')
  await load()
}

onMounted(load)
</script>

<style scoped>
.quotes-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
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
.price-cell {
  display: flex;
  flex-direction: column;
  line-height: 1.4;
  font-size: 12px;
}
.suggest {
  color: #1677ff;
  font-weight: 600;
}
.cost-text {
  font-size: 12px;
  color: #6b7280;
}
.llm-remark {
  margin-top: 10px;
  font-size: 12px;
  color: #b45309;
  background: #fef3c7;
  border-radius: 6px;
  padding: 8px;
}
.mb12 {
  margin-bottom: 12px;
}
</style>
