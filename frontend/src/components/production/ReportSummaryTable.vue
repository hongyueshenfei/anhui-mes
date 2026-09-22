<template>
  <div class="summary-page">
    <div class="summary-toolbar">
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        range-separator="~"
        start-placeholder="作业开始日期"
        end-placeholder="作业结束日期"
        clearable
        style="width: 250px"
      />
      <el-input
        v-model="keyword"
        clearable
        placeholder="任务号/订单号/产品编码"
        style="width: 220px"
        @keyup.enter="load"
      />
      <el-input
        v-model="machineKeyword"
        clearable
        placeholder="机台/产线编码"
        style="width: 150px"
        @keyup.enter="load"
      />
      <el-button type="primary" @click="load">查询</el-button>
    </div>

    <el-alert
      v-if="errorMessage"
      type="error"
      :closable="false"
      show-icon
      class="summary-error"
      :title="errorMessage"
    />

    <div class="summary-note">按实际作业日期汇总；合格数、次品数均为净值，冲减记录会抵扣原报工。工序合计包含全部工序，成品口径只统计末工序/任务级报工。</div>

    <el-row v-loading="loading" :gutter="12" class="metric-row">
      <el-col :span="4">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value">{{ totals.task_count }}</div>
          <div class="metric-label">涉及任务</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value">{{ totals.row_count }}</div>
          <div class="metric-label">汇总行数</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value">{{ formatQty(totals.qualified_qty) }}</div>
          <div class="metric-label">工序合格合计</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value defect">{{ formatQty(totals.defect_qty) }}</div>
          <div class="metric-label">次品数</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card shadow="never" class="metric-card finished-card">
          <div class="metric-value finished">{{ formatQty(totals.finished_qualified_qty) }}</div>
          <div class="metric-label">成品报工合格</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value">{{ formatQty(totals.total_qty) }}</div>
          <div class="metric-label">工序总报工数</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value correction">{{ totals.correction_count }}</div>
          <div class="metric-label">冲减笔数</div>
        </el-card>
      </el-col>
    </el-row>

    <el-table v-loading="loading" :data="items" stripe border empty-text="暂无报工统计数据">
      <el-table-column prop="work_date" label="实际作业日期" width="120" />
      <el-table-column prop="task_no" label="任务号" min-width="190" />
      <el-table-column prop="order_no" label="订单号" min-width="150" />
      <el-table-column label="产品" min-width="180">
        <template #default="{ row }">
          {{ row.product?.name ?? '-' }}<span v-if="row.product?.code" class="sub">（{{ row.product.code }}）</span>
        </template>
      </el-table-column>
      <el-table-column label="工序" width="120">
        <template #default="{ row }">
          {{ row.operation ? `${row.operation.seq} · ${row.operation.name}` : '任务级' }}
        </template>
      </el-table-column>
      <el-table-column label="机台/产线" width="120">
        <template #default="{ row }">{{ row.machine?.code ?? '-' }}</template>
      </el-table-column>
      <el-table-column label="计划量" width="95" align="right">
        <template #default="{ row }">{{ formatQty(row.planned_qty) }}</template>
      </el-table-column>
      <el-table-column label="合格数" width="95" align="right">
        <template #default="{ row }">{{ formatQty(row.qualified_qty) }}</template>
      </el-table-column>
      <el-table-column label="次品数" width="95" align="right">
        <template #default="{ row }"><span class="defect">{{ formatQty(row.defect_qty) }}</span></template>
      </el-table-column>
      <el-table-column label="完成率" width="110" align="center">
        <template #default="{ row }"><el-progress :percentage="row.completion_pct" :stroke-width="8" /></template>
      </el-table-column>
      <el-table-column label="报工笔数" width="90" align="center">
        <template #default="{ row }">
          {{ row.report_count }}<span v-if="row.correction_count" class="sub">（冲{{ row.correction_count }}）</span>
        </template>
      </el-table-column>
      <el-table-column prop="last_report_time" label="最后报工时间" width="165" />
    </el-table>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { productionApi } from '@/api/production'
import { getApiErrorMessage } from '@/api/axios'
import type { ReportSummary, ReportSummaryItem } from '@/types/production'

const emptySummary: ReportSummary = {
  totals: {
    row_count: 0,
    task_count: 0,
    report_count: 0,
    qualified_qty: 0,
    defect_qty: 0,
    total_qty: 0,
    finished_qualified_qty: 0,
    finished_defect_qty: 0,
    correction_count: 0,
  },
  items: [],
}

const dateRange = ref<[string, string] | null>(null)
const keyword = ref('')
const machineKeyword = ref('')
const loading = ref(false)
const errorMessage = ref('')
const totals = ref(emptySummary.totals)
const items = ref<ReportSummaryItem[]>([])

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await productionApi.reportSummary({
      date_from: dateRange.value?.[0],
      date_to: dateRange.value?.[1],
      keyword: keyword.value.trim() || undefined,
      machine_keyword: machineKeyword.value.trim() || undefined,
    })
    totals.value = result.totals
    items.value = result.items
  } catch (error) {
    totals.value = emptySummary.totals
    items.value = []
    errorMessage.value = getApiErrorMessage(error, '报工统计加载失败')
  } finally {
    loading.value = false
  }
}

function formatQty(value: number): string {
  return Number(value ?? 0).toLocaleString('zh-CN')
}

onMounted(load)
</script>

<style scoped>
.summary-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.summary-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.summary-note {
  color: #64748b;
  font-size: 12px;
}
.summary-error {
  margin-bottom: 0;
}
.metric-row {
  margin: 0;
}
.metric-card {
  text-align: center;
}
.metric-value {
  color: #1f2937;
  font-size: 22px;
  font-weight: 700;
  line-height: 1.4;
}
.metric-label {
  color: #64748b;
  font-size: 12px;
}
.defect,
.correction {
  color: #e45656;
}
.finished {
  color: #16a34a;
}
.sub {
  color: #94a3b8;
  font-size: 12px;
}
</style>
