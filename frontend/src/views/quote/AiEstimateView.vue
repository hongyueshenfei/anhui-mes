<template>
  <div class="ai-estimate-page">
    <el-row :gutter="12">
      <!-- 输入区 -->
      <el-col :span="9">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>AI 快速估价</span>
              <el-tag v-if="estimate?.ai_assist === 'not_enabled'" type="info" size="small">规则引擎 · AI 辅助未启用</el-tag>
              <el-tag v-else type="success" size="small">DeepSeek 辅助已启用</el-tag>
            </div>
          </template>

          <el-form label-width="90px">
            <el-form-item label="近似老品">
              <el-select v-model="productId" filterable clearable placeholder="选择近似产品（可选）" style="width: 100%" @change="recalc">
                <el-option v-for="p in products" :key="p.id" :label="`${p.code} · ${p.name}`" :value="p.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="规格描述">
              <el-input v-model="specText" type="textarea" :rows="2" placeholder="如：PP 材质 · 500ml · 带盖" @input="recalc" />
            </el-form-item>
            <el-form-item label="预估数量">
              <el-input-number v-model="qty" :min="1" :max="1000000" :step="100" style="width: 100%" @change="recalc" />
            </el-form-item>
            <el-form-item label="目标毛利">
              <el-slider v-model="margin" :min="5" :max="60" :step="1" show-input @change="recalc" />
            </el-form-item>
            <el-form-item label="客户名称">
              <el-input v-model="customerName" placeholder="如：江苏新巢日化" />
            </el-form-item>
          </el-form>

          <el-alert type="info" :closable="false" class="mb12">
            估价提交后由后端规则引擎与 AI 服务计算；页面输入变化仅用于即时预览。
          </el-alert>

          <el-button type="primary" class="full-btn" :disabled="qty <= 0" @click="doEstimate">
            <el-icon><MagicStick /></el-icon>开始估价
          </el-button>
          <el-button class="full-btn mt8" :disabled="!estimate" @click="saveAsQuote">
            <el-icon><DocumentAdd /></el-icon>一键转报价单
          </el-button>
        </el-card>
      </el-col>

      <!-- 结果区 -->
      <el-col :span="15">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>估价结果 · 成本构成</span>
              <el-tag v-if="estimate?.anomaly_flag" type="danger" size="small">⚠ 异常价提示</el-tag>
            </div>
          </template>

          <div v-if="!estimate" class="empty">请选择近似产品/填写规格与数量后点击「开始估价」</div>

          <template v-else>
            <!-- KPI -->
            <el-row :gutter="10" class="kpi-row">
              <el-col :span="6"><div class="kpi-card price"><div class="kpi-label">建议单价（元）</div><div class="kpi-value">{{ estimate.suggest_price.toFixed(2) }}</div></div></el-col>
              <el-col :span="6"><div class="kpi-card"><div class="kpi-label">单位总成本（元）</div><div class="kpi-value">{{ estimate.total_cost.toFixed(2) }}</div></div></el-col>
              <el-col :span="6"><div class="kpi-card"><div class="kpi-label">目标毛利</div><div class="kpi-value">{{ estimate.margin }}%</div></div></el-col>
              <el-col :span="6"><div class="kpi-card"><div class="kpi-label">建议交期（天）</div><div class="kpi-value">{{ estimate.delivery_days }}</div></div></el-col>
            </el-row>

            <!-- 成本构成 -->
            <el-table :data="costRows" size="small" stripe class="mb12">
              <el-table-column prop="label" label="成本构成" width="140" />
              <el-table-column prop="amount" label="金额（元/件）" width="140">
                <template #default="{ row }">{{ row.amount.toFixed(4) }}</template>
              </el-table-column>
              <el-table-column prop="desc" label="说明" />
            </el-table>

            <!-- 材料明细 -->
            <el-collapse v-if="estimate.material_detail.length" class="mb12">
              <el-collapse-item title="材料成本明细（BOM × 材料价）" name="material">
                <el-table :data="estimate.material_detail" size="small">
                  <el-table-column prop="item_code" label="材料编码" width="140" />
                  <el-table-column prop="item_name" label="材料名称" min-width="140" />
                  <el-table-column prop="qty" label="用量/件" width="90" />
                  <el-table-column prop="price" label="单价" width="90" />
                  <el-table-column prop="cost" label="成本" width="90" />
                </el-table>
              </el-collapse-item>
              <el-collapse-item v-if="estimate.process_detail.length" title="加工成本明细（工时 × 工序费率）" name="process">
                <el-table :data="estimate.process_detail" size="small">
                  <el-table-column prop="op_name" label="工序" min-width="120" />
                  <el-table-column prop="hours_per_unit" label="工时/件(h)" width="110" />
                  <el-table-column prop="rate" label="费率(元/h)" width="100" />
                  <el-table-column prop="cost" label="成本" width="90" />
                </el-table>
              </el-collapse-item>
            </el-collapse>

            <el-alert v-if="estimate.anomaly_flag" type="error" :closable="false" class="mb12" :title="estimate.anomaly_message" />
            <el-alert v-if="estimate.llm_remark" type="info" :closable="false" class="mb12" :title="estimate.llm_remark" />
          </template>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { DocumentAdd, MagicStick } from '@element-plus/icons-vue'
import { quoteApi } from '@/api/quote'
import { plmApi } from '@/api/plm'
import type { Product } from '@/types'
import type { QuoteEstimate } from '@/types/quote'

const products = ref<Product[]>([])
const productId = ref<number | null>(null)
const specText = ref('')
const qty = ref(1000)
const margin = ref(20)
const customerName = ref('')
const estimate = ref<QuoteEstimate | null>(null)
const saving = ref(false)

const costRows = computed(() => {
  if (!estimate.value) return []
  const e = estimate.value
  return [
    { label: '材料成本', amount: e.material_cost, desc: `BOM 用量 × 材料价（${e.material_detail.length} 项）` },
    { label: '加工成本', amount: e.process_cost, desc: `工时 × 工序费率（${e.process_detail.length} 道工序）` },
    { label: '模具摊销', amount: e.mold_amort, desc: e.mold ? `${e.mold.mold_name} 造价 ${e.mold.purchase_cost.toLocaleString()} ÷ ${e.qty}` : '未选模具' },
    { label: '损耗', amount: e.loss_cost, desc: `（材料+加工）× ${e.loss_rate}%` },
    { label: '总成本', amount: e.total_cost, desc: '含损耗' },
  ]
})

async function recalc(): Promise<void> {
  if (qty.value > 0 && (productId.value || specText.value)) {
    await runEstimate(false)
  }
}

async function runEstimate(showMsg = true): Promise<void> {
  try {
    estimate.value = await quoteApi.aiEstimate({
      product_id: productId.value,
      spec_text: specText.value.trim() || undefined,
      qty: qty.value,
      margin: margin.value,
    })
    if (showMsg) ElMessage.success(`估价完成：建议价 ${estimate.value.suggest_price.toFixed(2)} 元/件`)
  } catch {
    ElMessage.error('估价失败')
  }
}

async function doEstimate(): Promise<void> {
  await runEstimate()
}

async function saveAsQuote(): Promise<void> {
  if (!estimate.value) return
  saving.value = true
  try {
    const quote = await quoteApi.createQuote({
      from_estimate: true,
      customer_name: customerName.value.trim() || '演示客户',
      estimate: estimate.value,
    })
    ElMessage.success(`报价单 ${quote.quote_no} 已生成（草稿）`)
  } catch {
    ElMessage.error('转报价单失败')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    const r = (await plmApi.listProducts({ per_page: 100 })) as Product[]
    products.value = Array.isArray(r) ? r : (r as unknown as { data: Product[] }).data
  } catch {
    products.value = []
  }
})
</script>

<style scoped>
.ai-estimate-page {
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
.full-btn {
  width: 100%;
}
.mt8 {
  margin-top: 8px;
}
.mb12 {
  margin-bottom: 12px;
}
.empty {
  text-align: center;
  color: #94a3b8;
  padding: 60px 0;
}
.kpi-row {
  margin-bottom: 12px;
}
.kpi-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 12px;
  text-align: center;
}
.kpi-card.price {
  border-top: 3px solid #1677ff;
}
.kpi-label {
  font-size: 12px;
  color: #94a3b8;
}
.kpi-value {
  font-size: 22px;
  font-weight: 700;
  color: #1f2937;
  margin-top: 4px;
}
</style>
