<template>
  <div v-loading="loading" class="reports-page">
    <div v-if="loadError" class="load-error" role="alert">
      <span>{{ loadError }}</span>
      <el-button size="small" type="primary" plain @click="load">重新加载</el-button>
    </div>
    <!-- 指标卡 -->
    <el-row :gutter="12" class="metric-row">
      <el-col v-for="m in metrics" :key="m.key" :xs="12" :sm="8" :md="8" :lg="8" :xl="4">
        <el-card shadow="never" class="metric-card">
          <div class="metric-value">{{ m.value }}{{ m.unit }}</div>
          <div class="metric-label">{{ m.label }}</div>
          <div class="metric-trend" :class="`trend-${m.trend}`">
            {{ m.trend === 'up' ? '▲' : m.trend === 'down' ? '▼' : '■' }} {{ m.compare_text }}
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="12" class="charts-row">
      <!-- 交期达成率 -->
      <el-col :xs="24" :lg="12">
        <el-card shadow="never" class="panel">
          <template #header><span class="panel-title">{{ deliverySeries?.label ?? '交期达成率趋势' }}</span></template>
          <div v-if="deliveryHasValues" class="chart-scroll"><div class="bar-chart">
            <div class="bar-y">
              <span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span>
            </div>
            <div class="bar-area">
              <div v-for="(p, i) in deliverySeries?.data ?? []" :key="i" class="bar-col">
                <div class="bar-bar" :class="{ 'no-data': p.value === null }" :style="{ height: seriesValue(p.value) + '%', background: p.value === null ? '#e5e9ec' : p.value >= 85 ? 'var(--hy-green)' : p.value >= 70 ? 'var(--hy-amber)' : 'var(--hy-red)' }" :title="p.date + ' ' + seriesLabel(p.value)">
                  <span class="bar-val">{{ seriesLabel(p.value) }}</span>
                </div>
                <div class="bar-date">{{ shortLabel(p.date) }}</div>
              </div>
            </div>
          </div></div>
          <div v-else class="chart-empty">近 7 天暂无有效订单数据</div>
        </el-card>
      </el-col>
      <!-- 机台利用率 -->
      <el-col :xs="24" :lg="12">
        <el-card shadow="never" class="panel">
          <template #header><span class="panel-title">{{ utilSeries?.label ?? '机台利用率' }}</span></template>
          <div class="chart-scroll"><div class="bar-chart">
            <div class="bar-y">
              <span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span>
            </div>
            <div class="bar-area">
              <div v-for="(p, i) in utilSeries?.data ?? []" :key="i" class="bar-col">
                <div class="bar-bar" :class="{ 'no-data': p.value === null }" :style="{ height: seriesValue(p.value) + '%', background: p.value === null ? '#e5e9ec' : p.value > 85 ? 'var(--hy-red)' : 'var(--hy-blue)' }" :title="p.date + ' ' + seriesLabel(p.value)">
                  <span class="bar-val">{{ seriesLabel(p.value) }}</span>
                </div>
                <div class="bar-date">{{ shortLabel(p.date) }}</div>
              </div>
            </div>
          </div></div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="12" class="charts-row">
      <!-- 报工及时率 -->
      <el-col :xs="24" :md="12" :lg="8">
        <el-card shadow="never" class="panel small">
          <template #header><span class="panel-title">{{ timelySeries?.label ?? '报工及时率' }}</span></template>
          <div v-for="(p, i) in timelySeries?.data ?? []" :key="i" class="h-bar">
            <span class="h-label">{{ shortLabel(p.date) }}</span>
            <div class="h-track"><div class="h-fill" :style="{ width: seriesValue(p.value) + '%', background: p.value === null ? '#e5e9ec' : p.value >= 90 ? 'var(--hy-green)' : 'var(--hy-amber)' }"></div></div>
            <span class="h-val">{{ seriesLabel(p.value) }}</span>
          </div>
        </el-card>
      </el-col>
      <!-- 次品率 -->
      <el-col :xs="24" :md="12" :lg="8">
        <el-card shadow="never" class="panel small">
          <template #header><span class="panel-title">{{ defectSeries?.label ?? '次品率' }}</span></template>
          <div v-for="(p, i) in defectSeries?.data ?? []" :key="i" class="h-bar">
            <span class="h-label">{{ shortLabel(p.date) }}</span>
            <div class="h-track"><div class="h-fill" :style="{ width: Math.min(100, seriesValue(p.value) * 20) + '%', background: p.value === null ? '#e5e9ec' : p.value >= 3 ? 'var(--hy-red)' : 'var(--hy-amber)' }"></div></div>
            <span class="h-val">{{ seriesLabel(p.value) }}</span>
          </div>
        </el-card>
      </el-col>
      <!-- 模具寿命 TOP -->
      <el-col :xs="24" :md="12" :lg="8">
        <el-card shadow="never" class="panel small">
          <template #header><span class="panel-title">模具寿命预警 TOP</span></template>
          <div v-for="m in moldLife" :key="m.mold_id" class="mold-item">
            <div class="mold-info">
              <span class="mold-code">{{ m.mold_code }}</span>
              <span class="mold-name">{{ m.mold_name }}</span>
              <el-tag v-if="m.maintain_due || m.life_used_pct > 90" type="danger" size="small">到期</el-tag>
            </div>
            <el-progress :percentage="Math.min(100, m.life_used_pct)" :stroke-width="8" :color="m.life_used_pct > 90 ? 'var(--hy-red)' : m.life_used_pct > 70 ? 'var(--hy-amber)' : 'var(--hy-green)'" />
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { dashboardApi } from '@/api/dashboard'
import type { ReportsData } from '@/types/dashboard'

const data = ref<ReportsData | null>(null)
const loading = ref(false)
const loadError = ref('')

const metrics = computed(() => data.value?.metrics ?? [])
const deliverySeries = computed(() => data.value?.delivery_series ?? null)
const deliveryHasValues = computed(() => deliverySeries.value?.data.some((point) => point.value !== null) ?? false)
const utilSeries = computed(() => data.value?.utilization_series ?? null)
const timelySeries = computed(() => data.value?.report_timely_series ?? null)
const defectSeries = computed(() => data.value?.defect_series ?? null)
const moldLife = computed(() => data.value?.mold_life ?? [])

function shortLabel(date: string): string {
  const week = date.match(/^\d{4}-W(\d{1,2})$/i)
  if (week) return `第${Number(week[1])}周`

  const parts = date.match(/\d+/g) ?? []
  if (date.includes('~') && parts.length >= 5) {
    return `${Number(parts[1])}/${Number(parts[2])}–${Number(parts[3])}/${Number(parts[4])}`
  }
  if (parts.length >= 3) return `${Number(parts[1])}/${Number(parts[2])}`
  if (parts.length === 2) return `${Number(parts[1])}月`
  return date
}

function seriesValue(value: number | null): number {
  return value ?? 0
}

function seriesLabel(value: number | null): string {
  return value === null ? '暂无数据' : String(value) + '%'
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await dashboardApi.reports()
  } catch {
    data.value = null
    loadError.value = '报表数据加载失败，请检查连接后重试。'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.reports-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.metric-row {
  margin-bottom: -10px;
}
.metric-row :deep(.el-col) {
  margin-bottom: 10px;
}
.metric-card {
  border-radius: 10px;
  border-left: 3px solid var(--hy-blue);
  text-align: left;
}
.metric-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--hy-ink);
  font-variant-numeric: tabular-nums;
}
.metric-label {
  font-size: 13px;
  color: #536776;
}
.metric-trend {
  font-size: 12px;
  margin-top: 4px;
  color: var(--hy-muted);
}
.trend-up {
  color: var(--hy-green);
}
.trend-down {
  color: var(--hy-red);
}
.trend-flat {
  color: var(--hy-muted);
}
.charts-row {
  margin-bottom: 0;
}
.panel {
  border-radius: 10px;
  margin-bottom: 0;
}
.panel.small {
  height: 100%;
}
.panel-title {
  font-weight: 600;
  color: var(--hy-ink);
}
.load-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 12px;
  border: 1px solid #ecc8c5;
  border-radius: 6px;
  color: var(--hy-red);
  background: #fff7f6;
}
.chart-scroll {
  overflow-x: auto;
}
.chart-empty {
  display: grid;
  min-height: 240px;
  place-items: center;
  color: var(--hy-muted);
  font-size: 13px;
  background: linear-gradient(to bottom, transparent calc(100% - 22px), #e7edf1 calc(100% - 22px), #e7edf1 calc(100% - 21px), transparent calc(100% - 21px));
}
.bar-chart {
  display: flex;
  height: 240px;
  min-width: 440px;
}
.bar-y {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  font-size: 11px;
  color: #94a3b8;
  padding-right: 6px;
  text-align: right;
  height: calc(100% - 22px);
}
.bar-area {
  flex: 1;
  min-width: 400px;
  display: flex;
  align-items: flex-end;
  justify-content: space-around;
  border-bottom: 1px solid #e5e7eb;
  position: relative;
}
.bar-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 8%;
}
.bar-bar {
  width: 100%;
  max-width: 34px;
  border-radius: 4px 4px 0 0;
  position: relative;
  min-height: 4px;
  transition: height 0.3s;
}
.bar-val {
  position: absolute;
  top: -18px;
  left: 50%;
  transform: translateX(-50%);
  font-size: 10px;
  color: #64748b;
  white-space: nowrap;
}
.bar-date {
  font-size: 11px;
  color: #94a3b8;
  margin-top: 4px;
}
.h-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.h-label {
  width: 52px;
  font-size: 12px;
  color: #475569;
  white-space: nowrap;
}
.h-track {
  flex: 1;
  height: 12px;
  background: #f1f5f9;
  border-radius: 6px;
  overflow: hidden;
}
.h-fill {
  height: 100%;
  border-radius: 6px;
}
.h-val {
  width: 40px;
  text-align: right;
  font-size: 12px;
  font-weight: 600;
  color: #1f2937;
}
.mold-item {
  margin-bottom: 10px;
}
.mold-info {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
  font-size: 12px;
}
.mold-code {
  font-weight: 700;
  color: #1f2937;
}
.mold-name {
  color: #6b7280;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 700px) {
  .load-error {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
