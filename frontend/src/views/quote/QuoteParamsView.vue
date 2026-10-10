<template>
  <div class="quote-params-page">
    <el-alert v-if="loadError" :title="`${loadError}${hasLoaded ? '；当前仍显示上次成功读取的配置。' : ''}`" type="error" show-icon :closable="false">
      <template #default><el-button size="small" type="danger" plain @click="load">重试</el-button></template>
    </el-alert>
    <el-row :gutter="12">
      <!-- 材料价格 -->
      <el-col :xs="24" :sm="12" :lg="10">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>材料价格（生效日期版本化）</span>
              <el-button size="small" type="primary" plain @click="materialVisible = true">
                <el-icon><Plus /></el-icon>新增
              </el-button>
            </div>
          </template>
          <el-table :data="materials" size="small" stripe max-height="420" v-loading="loading" :empty-text="loadError ? '读取失败，请重试' : '暂无材料价格'">
            <el-table-column prop="material_code" label="材料编码" width="120" />
            <el-table-column prop="material_name" label="名称" min-width="120" />
            <el-table-column prop="price_per_unit" label="单价" width="80" />
            <el-table-column prop="unit" label="单位" width="60" />
            <el-table-column prop="effective_date" label="生效日期" width="110" />
          </el-table>
        </el-card>
      </el-col>

      <!-- 工序费率 -->
      <el-col :xs="24" :sm="12" :lg="8">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header">
              <span>工序费率（元/小时）</span>
              <el-button size="small" type="primary" plain @click="rateVisible = true">
                <el-icon><Plus /></el-icon>新增
              </el-button>
            </div>
          </template>
          <el-table :data="rates" size="small" stripe max-height="420" v-loading="loading" :empty-text="loadError ? '读取失败，请重试' : '暂无工序费率'">
            <el-table-column prop="rate_key" label="费率键" width="120" />
            <el-table-column prop="op_type" label="工序类型" min-width="110" />
            <el-table-column prop="rate" label="费率" width="80" />
            <el-table-column prop="effective_date" label="生效日期" width="110" />
          </el-table>
        </el-card>
      </el-col>

      <!-- 报价规则 -->
      <el-col :xs="24" :sm="24" :lg="6">
        <el-card shadow="never" class="panel">
          <template #header>
            <div class="panel-header"><span>报价规则</span></div>
          </template>
          <el-form label-position="top" size="small" v-loading="loading">
            <el-form-item v-for="rule in rules" :key="rule.rule_key" :label="ruleLabel(rule.rule_key)">
              <div class="rule-row">
                <el-input-number v-model="rule.rule_value" :min="0" :max="100" :step="1" controls-position="right" style="width: 110px" />
                <el-button size="small" type="primary" text @click="saveRule(rule)">保存</el-button>
              </div>
            </el-form-item>
            <el-alert type="info" :closable="false">
              材料价/费率按生效日期版本化：修改后新估价取新价，历史报价不受影响（报价单已落成本快照）。
            </el-alert>
          </el-form>
        </el-card>
      </el-col>
    </el-row>

    <!-- 材料新增 -->
    <el-dialog v-model="materialVisible" title="新增材料价格" width="480px">
      <el-form label-width="90px">
        <el-form-item label="材料编码" required><el-input v-model="materialForm.material_code" placeholder="如 RAW-PP-001" /></el-form-item>
        <el-form-item label="材料名称"><el-input v-model="materialForm.material_name" /></el-form-item>
        <el-form-item label="单价" required><el-input-number v-model="materialForm.price_per_unit" :min="0" :precision="4" style="width: 100%" /></el-form-item>
        <el-form-item label="单位"><el-input v-model="materialForm.unit" placeholder="kg/个" /></el-form-item>
        <el-form-item label="生效日期" required><el-date-picker v-model="materialForm.effective_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="materialVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!materialForm.material_code || !materialForm.effective_date" @click="saveMaterial">保存</el-button>
      </template>
    </el-dialog>

    <!-- 费率新增 -->
    <el-dialog v-model="rateVisible" title="新增工序费率" width="480px">
      <el-form label-width="90px">
        <el-form-item label="费率键" required><el-input v-model="rateForm.rate_key" placeholder="如 molding / paint" /></el-form-item>
        <el-form-item label="工序类型"><el-input v-model="rateForm.op_type" placeholder="如 molding_injection" /></el-form-item>
        <el-form-item label="费率(元/h)" required><el-input-number v-model="rateForm.rate" :min="0" :precision="2" style="width: 100%" /></el-form-item>
        <el-form-item label="生效日期" required><el-date-picker v-model="rateForm.effective_date" type="date" value-format="YYYY-MM-DD" style="width: 100%" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="rateVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!rateForm.rate_key || !rateForm.effective_date" @click="saveRate">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { quoteApi } from '@/api/quote'
import type { MaterialPrice, ProcessRate, QuoteRuleItem } from '@/types/quote'

const materials = ref<MaterialPrice[]>([])
const rates = ref<ProcessRate[]>([])
const rules = ref<QuoteRuleItem[]>([])
const loading = ref(true)
const hasLoaded = ref(false)
const loadError = ref('')

const materialVisible = ref(false)
const rateVisible = ref(false)
const materialForm = reactive({ material_code: '', material_name: '', price_per_unit: 0, unit: '', effective_date: '' })
const rateForm = reactive({ rate_key: '', op_type: '', rate: 0, effective_date: '' })

function ruleLabel(key: string): string {
  return { loss_rate: '行业损耗率 %', mold_amort_rule: '模具摊销规则', min_margin: '毛利下限 %', anomaly_deviation: '异常价偏差 %' }[key] ?? key
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const m = (await quoteApi.listMaterials({ all: true })) as MaterialPrice[]
    const r = (await quoteApi.listProcessRates({ all: true })) as ProcessRate[]
    const ruleRows = await quoteApi.listRules()
    const nextMaterials = Array.isArray(m) ? m : Array.isArray((m as { data?: MaterialPrice[] })?.data) ? (m as { data: MaterialPrice[] }).data : []
    const nextRates = Array.isArray(r) ? r : Array.isArray((r as { data?: ProcessRate[] })?.data) ? (r as { data: ProcessRate[] }).data : []
    materials.value = nextMaterials
    rates.value = nextRates
    rules.value = ruleRows
    hasLoaded.value = true
  } catch (error: any) {
    loadError.value = error?.response?.data?.message ?? '报价参数读取失败，请检查服务连接后重试。'
  } finally {
    loading.value = false
  }
}

async function saveMaterial(): Promise<void> {
  await quoteApi.upsertMaterial({ ...materialForm })
  ElMessage.success('材料价格已保存（生效日期版本化）')
  materialVisible.value = false
  materialForm.material_code = ''
  materialForm.material_name = ''
  materialForm.effective_date = ''
  await load()
}

async function saveRate(): Promise<void> {
  await quoteApi.upsertProcessRate({ ...rateForm })
  ElMessage.success('工序费率已保存')
  rateVisible.value = false
  rateForm.rate_key = ''
  rateForm.effective_date = ''
  await load()
}

async function saveRule(rule: QuoteRuleItem): Promise<void> {
  await quoteApi.upsertRule({ rule_key: rule.rule_key, rule_value: rule.rule_value, remark: rule.remark ?? undefined })
  ElMessage.success('报价规则已保存')
}

onMounted(load)
</script>

<style scoped>
.quote-params-page {
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
.rule-row {
  display: flex;
  align-items: center;
  gap: 4px;
}
</style>
